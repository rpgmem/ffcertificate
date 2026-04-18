<?php
declare(strict_types=1);

/**
 * PublicCsvExporter
 *
 * CSV export for the public-facing download feature.
 *
 * Two execution paths:
 *
 *  **AJAX batched (primary, when JS is available):**
 *   1. `ajax_start()`    — validate, scan keys, count rows, create temp file,
 *                          write headers, return job_id + total.
 *   2. `ajax_batch()`    — process EXPORT_BATCH_SIZE rows, append to temp file,
 *                          return processed/total. Repeat until done.
 *   3. `ajax_download()` — serve the completed temp file and clean up.
 *
 *  **Synchronous fallback (no-JS):**
 *   `stream_form_csv()` — streams the entire CSV in one request.
 *
 * The column layout intentionally mirrors `CsvExporter::get_fixed_headers()`
 * and `CsvExporter::format_csv_row()` so that admins can compare/download
 * the two sources interchangeably.
 *
 * @since 5.1.0
 */

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\CsvExportTrait;
use FreeFormCertificate\Repositories\SubmissionRepository;
use FreeFormCertificate\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PublicCsvExporter {

	use CsvExportTrait;

	/**
	 * Rows pulled per batch while streaming.
	 */
	const EXPORT_BATCH_SIZE = 50;

	/**
	 * Rows pulled per batch during dynamic-key discovery.
	 */
	const KEYS_BATCH_SIZE = 500;

	/**
	 * How long (seconds) the job transient lives before auto-cleanup.
	 */
	const JOB_TTL = 3600;

	/**
	 * @var SubmissionRepository
	 */
	protected $repository;

	/**
	 * Cached form titles to avoid repeated get_the_title() lookups.
	 *
	 * @var array<int, string>
	 */
	private array $form_title_cache = array();

	public function __construct() {
		$this->repository = new SubmissionRepository();
	}

	/**
	 * Stream the CSV file for a single form to the browser.
	 *
	 * Sends HTTP headers, writes BOM + column headers + data rows, then exits.
	 * On empty result sets the caller is expected to short-circuit first;
	 * this method still handles the zero-row case by sending a header-only file.
	 *
	 * @param int    $form_id Form post ID.
	 * @param string $status  Submission status to filter by (default 'publish').
	 * @return void Exits after output.
	 */
	public function stream_form_csv( int $form_id, string $status = 'publish' ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit may be disabled.
		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$form_ids = array( $form_id );

		$dynamic_keys         = $this->scan_dynamic_keys( $form_ids, $status );
		$include_edit_columns = $this->repository->hasEditInfo();

		$filename = \FreeFormCertificate\Core\Utils::sanitize_filename(
			get_the_title( $form_id ) ?: ( 'form-' . $form_id )
		) . '-' . gmdate( 'Y-m-d-His' ) . '.csv';

		// Discard any buffered output so the CSV is the only payload on the wire.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.CodeAnalysis.EmptyStatement.DetectedWhile -- body intentionally empty; @ swallows the "no buffer" notice.
		while ( @ob_end_clean() ) {
			/* no-op */
		}

		$safe_filename = str_replace( array( "\r", "\n", '"' ), '', $filename );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			exit;
		}

		// BOM for Excel UTF-8 recognition.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV binary output.
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		$headers = array_merge(
			$this->get_fixed_headers( $include_edit_columns ),
			$this->build_dynamic_headers( $dynamic_keys )
		);
		$headers = array_map(
			function ( $h ) {
				return mb_convert_encoding( (string) $h, 'UTF-8', 'UTF-8' );
			},
			$headers
		);
		fputcsv( $output, $headers, ';' );

		// Stream rows in batches using cursor pagination.
		$cursor = PHP_INT_MAX;
		while ( true ) {
			$batch = $this->repository->getExportBatch(
				$form_ids,
				$status,
				$cursor,
				self::EXPORT_BATCH_SIZE
			);

			if ( empty( $batch ) ) {
				break;
			}

			/**
			 * Same filter as the admin CSV export so existing hooks keep working
			 * for the public download path.
			 *
			 * @since 5.1.0
			 */
			$batch = apply_filters( 'ffcertificate_csv_export_data', $batch, $form_ids, $status );

			foreach ( $batch as $row ) {
				$csv_row = $this->format_csv_row( $row, $dynamic_keys, $include_edit_columns );
				$csv_row = array_map(
					function ( $v ) {
						return mb_convert_encoding( (string) $v, 'UTF-8', 'UTF-8' );
					},
					$csv_row
				);
				fputcsv( $output, $csv_row, ';' );
			}

			$last_row = end( $batch );
			$cursor   = (int) $last_row['id'];
			unset( $batch );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );
		exit;
	}

	// ──────────────────────────────────────────────────────────────.
	// AJAX: Start.
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Start a new AJAX export job.
	 *
	 * Validates request security and form access, scans dynamic keys,
	 * counts rows, creates a temp file with CSV headers, stores the
	 * job state in a transient, and returns job_id + total to JS.
	 *
	 * @since 5.1.0
	 */
	public function ajax_start(): void {
		// 1. Rate limit.
		if ( class_exists( RateLimiter::class ) ) {
			$ip         = \FreeFormCertificate\Core\Utils::get_user_ip();
			$rate_check = RateLimiter::check_ip_limit( $ip );
			if ( empty( $rate_check['allowed'] ) ) {
				wp_send_json_error(
					array(
						'message'    => $rate_check['message'] ?? __( 'Too many requests. Please wait.', 'ffcertificate' ),
						'rate_limit' => true,
					)
				);
			}
		}

		// 2. Nonce.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( ! isset( $_POST['_ffc_pcd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ffc_pcd_nonce'] ) ), PublicCsvDownload::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'ffcertificate' ) ) );
		}

		// 3. Honeypot + CAPTCHA.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$security_check = \FreeFormCertificate\Core\Utils::validate_security_fields( $_POST );
		if ( true !== $security_check ) {
			wp_send_json_error( array( 'message' => (string) $security_check ) );
		}

		// 4. Sanitize input.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$posted_hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';

		if ( $form_id <= 0 || '' === $posted_hash ) {
			wp_send_json_error( array( 'message' => __( 'Please inform both the Form ID and the Access Hash.', 'ffcertificate' ) ) );
		}

		// 5–9. Business-logic validation via PublicCsvDownload.
		$validator = new PublicCsvDownload();
		$error     = $validator->validate_form_access( $form_id, $posted_hash );
		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ) );
		}

		// 10. Increment quota BEFORE generating (race-condition safe).
		$count = (int) get_post_meta( $form_id, PublicCsvDownload::META_COUNT, true );
		update_post_meta( $form_id, PublicCsvDownload::META_COUNT, $count + 1 );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit may be disabled.
		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$form_ids             = array( $form_id );
		$status               = 'publish';
		$dynamic_keys         = $this->scan_dynamic_keys( $form_ids, $status );
		$include_edit_columns = $this->repository->hasEditInfo();

		$total = $this->repository->count(
			array(
				'form_id' => $form_id,
				'status'  => $status,
			)
		);

		if ( 0 === $total ) {
			wp_send_json_error( array( 'message' => __( 'No records found to export.', 'ffcertificate' ) ) );
		}

		$filename = \FreeFormCertificate\Core\Utils::sanitize_filename(
			get_the_title( $form_id ) ?: ( 'form-' . $form_id )
		) . '-' . gmdate( 'Y-m-d-His' ) . '.csv';

		// Create temp file.
		$upload_dir = wp_upload_dir();
		$tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'ffc-tmp';
		wp_mkdir_p( $tmp_dir );

		$htaccess = $tmp_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		$job_id   = wp_generate_uuid4();
		$tmp_file = $tmp_dir . '/ffc-public-export-' . $job_id . '.csv';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $tmp_file, 'w' );
		if ( ! $fh ) {
			wp_send_json_error( array( 'message' => __( 'Cannot create temp file.', 'ffcertificate' ) ) );
		}

		// BOM + headers.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV binary output.
		fprintf( $fh, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		$headers = array_merge(
			$this->get_fixed_headers( $include_edit_columns ),
			$this->build_dynamic_headers( $dynamic_keys )
		);
		$headers = array_map(
			function ( $h ) {
				return mb_convert_encoding( (string) $h, 'UTF-8', 'UTF-8' );
			},
			$headers
		);
		fputcsv( $fh, $headers, ';' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );

		$ip_hash     = sha1( \FreeFormCertificate\Core\Utils::get_user_ip() );
		$nonce_batch = wp_create_nonce( 'ffc_public_csv_batch_' . $job_id );

		$job = array(
			'form_id'              => $form_id,
			'form_ids'             => $form_ids,
			'status'               => $status,
			'dynamic_keys'         => $dynamic_keys,
			'include_edit_columns' => $include_edit_columns,
			'cursor'               => PHP_INT_MAX,
			'processed'            => 0,
			'total'                => $total,
			'file'                 => $tmp_file,
			'filename'             => $filename,
			'ip_hash'              => $ip_hash,
		);
		set_transient( 'ffc_public_csv_' . $job_id, $job, self::JOB_TTL );

		wp_send_json_success(
			array(
				'job_id'      => $job_id,
				'total'       => $total,
				'nonce_batch' => $nonce_batch,
			)
		);
	}

	// ──────────────────────────────────────────────────────────────.
	// AJAX: Batch.
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Process one batch and append rows to the temp file.
	 *
	 * @since 5.1.0
	 */
	public function ajax_batch(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified via job-scoped nonce below.
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job    = get_transient( 'ffc_public_csv_' . $job_id );

		if ( ! $job ) {
			wp_send_json_error( __( 'Export job not found or expired.', 'ffcertificate' ) );
		}

		// Verify job-scoped nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified here.
		$nonce = isset( $_POST['nonce_batch'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce_batch'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ffc_public_csv_batch_' . $job_id ) ) {
			wp_send_json_error( __( 'Security check failed.', 'ffcertificate' ) );
		}

		// IP scope check.
		$current_ip_hash = sha1( \FreeFormCertificate\Core\Utils::get_user_ip() );
		if ( ! hash_equals( $job['ip_hash'], $current_ip_hash ) ) {
			wp_send_json_error( __( 'Session mismatch.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit may be disabled.
		@set_time_limit( 60 );

		$batch = $this->repository->getExportBatch(
			$job['form_ids'],
			$job['status'],
			$job['cursor'],
			self::EXPORT_BATCH_SIZE
		);

		if ( empty( $batch ) ) {
			wp_send_json_success(
				array(
					'done'      => true,
					'processed' => $job['processed'],
					'total'     => $job['total'],
				)
			);
		}

		/** @since 5.1.0 Same filter as admin CSV + synchronous public export. */
		$batch = apply_filters( 'ffcertificate_csv_export_data', $batch, $job['form_ids'], $job['status'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $job['file'], 'a' );
		if ( ! $fh ) {
			wp_send_json_error( __( 'Cannot write to temp file.', 'ffcertificate' ) );
		}

		foreach ( $batch as $row ) {
			$csv_row = $this->format_csv_row( $row, $job['dynamic_keys'], $job['include_edit_columns'] );
			$csv_row = array_map(
				function ( $v ) {
					return mb_convert_encoding( (string) $v, 'UTF-8', 'UTF-8' );
				},
				$csv_row
			);
			fputcsv( $fh, $csv_row, ';' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );

		$last_row          = end( $batch );
		$job['cursor']     = (int) $last_row['id'];
		$job['processed'] += count( $batch );

		set_transient( 'ffc_public_csv_' . $job_id, $job, self::JOB_TTL );

		wp_send_json_success(
			array(
				'done'      => false,
				'processed' => $job['processed'],
				'total'     => $job['total'],
			)
		);
	}

	// ──────────────────────────────────────────────────────────────.
	// AJAX: Download.
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Serve the completed CSV file and clean up.
	 *
	 * @since 5.1.0
	 */
	public function ajax_download(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$job    = get_transient( 'ffc_public_csv_' . $job_id );

		if ( ! $job ) {
			wp_die( esc_html__( 'Export job not found or expired.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$nonce = isset( $_GET['nonce_batch'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce_batch'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ffc_public_csv_batch_' . $job_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}

		$current_ip_hash = sha1( \FreeFormCertificate\Core\Utils::get_user_ip() );
		if ( ! hash_equals( $job['ip_hash'], $current_ip_hash ) ) {
			wp_die( esc_html__( 'Session mismatch.', 'ffcertificate' ) );
		}

		$file = $job['file'];
		if ( ! file_exists( $file ) ) {
			wp_die( esc_html__( 'Export file not found.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.CodeAnalysis.EmptyStatement.DetectedWhile -- body intentionally empty; @ swallows the "no buffer" notice.
		while ( @ob_end_clean() ) {
			/* no-op */
		}

		$safe_filename = str_replace( array( "\r", "\n", '"' ), '', $job['filename'] );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file );

		// Cleanup.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $file );
		delete_transient( 'ffc_public_csv_' . $job_id );

		exit;
	}

	// ──────────────────────────────────────────────────────────────.
	// Synchronous fallback (no-JS)
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Fixed CSV headers — mirrors `CsvExporter::get_fixed_headers()`.
	 *
	 * Kept in sync with the admin export so both files have identical columns.
	 *
	 * @param bool $include_edit_columns Whether to append edit-tracking columns.
	 * @return array<int, string>
	 */
	private function get_fixed_headers( bool $include_edit_columns = false ): array {
		$headers = array(
			__( 'ID', 'ffcertificate' ),
			__( 'Form', 'ffcertificate' ),
			__( 'User ID', 'ffcertificate' ),
			__( 'Submission Date', 'ffcertificate' ),
			__( 'E-mail', 'ffcertificate' ),
			__( 'User IP', 'ffcertificate' ),
			__( 'CPF', 'ffcertificate' ),
			__( 'RF', 'ffcertificate' ),
			__( 'Auth Code', 'ffcertificate' ),
			__( 'Token', 'ffcertificate' ),
			__( 'Consent Given', 'ffcertificate' ),
			__( 'Consent Date', 'ffcertificate' ),
			__( 'Consent IP', 'ffcertificate' ),
			__( 'Consent Text', 'ffcertificate' ),
			__( 'Status', 'ffcertificate' ),
		);

		if ( $include_edit_columns ) {
			$headers[] = __( 'Was Edited', 'ffcertificate' );
			$headers[] = __( 'Edit Date', 'ffcertificate' );
			$headers[] = __( 'Edited By', 'ffcertificate' );
		}

		return $headers;
	}

	/**
	 * Format one submission row as a CSV line — mirrors
	 * `CsvExporter::format_csv_row()`.
	 *
	 * @param array<string, mixed> $row
	 * @param array<int, string>   $dynamic_keys
	 * @param bool                 $include_edit_columns
	 * @return array<int, mixed>
	 */
	private function format_csv_row( array $row, array $dynamic_keys, bool $include_edit_columns = false ): array {
		$form_display = $this->get_form_title_cached( (int) $row['form_id'] );

		$email   = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'email' );
		$user_ip = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'user_ip' );
		$cpf_val = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'cpf' );
		$rf_val  = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'rf' );

		$line = array(
			$row['id'],
			$form_display,
			! empty( $row['user_id'] ) ? $row['user_id'] : '',
			$row['submission_date'],
			$email,
			$user_ip,
			$cpf_val,
			$rf_val,
			! empty( $row['auth_code'] ) ? $row['auth_code'] : '',
			! empty( $row['magic_token'] ) ? $row['magic_token'] : '',
			isset( $row['consent_given'] ) ? ( $row['consent_given'] ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' ) ) : '',
			! empty( $row['consent_date'] ) ? $row['consent_date'] : '',
			$user_ip, // Consent IP.
			! empty( $row['consent_text'] ) ? $row['consent_text'] : '',
			! empty( $row['status'] ) ? $row['status'] : 'publish',
		);

		if ( $include_edit_columns ) {
			$was_edited = '';
			$edit_date  = '';
			$edited_by  = '';
			if ( ! empty( $row['edited_at'] ) ) {
				$was_edited = __( 'Yes', 'ffcertificate' );
				$edit_date  = $row['edited_at'];
				if ( ! empty( $row['edited_by'] ) ) {
					$user      = get_userdata( (int) $row['edited_by'] );
					$edited_by = $user ? $user->display_name : 'ID: ' . $row['edited_by'];
				}
			}
			$line[] = $was_edited;
			$line[] = $edit_date;
			$line[] = $edited_by;
		}

		$data = $this->decode_json_field( $row, 'data', 'data_encrypted' );
		foreach ( $dynamic_keys as $key ) {
			$value  = $data[ $key ] ?? '';
			$line[] = is_array( $value ) ? implode( ', ', $value ) : $value;
		}

		return $line;
	}

	/**
	 * Scan all matching records to discover dynamic JSON keys.
	 *
	 * @param array<int, int> $form_ids
	 * @param string          $status
	 * @return array<int, string>
	 */
	private function scan_dynamic_keys( array $form_ids, string $status ): array {
		$all_keys = array();
		$cursor   = PHP_INT_MAX;

		while ( true ) {
			$batch = $this->repository->getExportKeysBatch( $form_ids, $status, $cursor, self::KEYS_BATCH_SIZE );
			if ( empty( $batch ) ) {
				break;
			}
			$all_keys = array_merge( $all_keys, $this->extract_dynamic_keys( $batch, 'data', 'data_encrypted' ) );
			$last_row = end( $batch );
			$cursor   = (int) $last_row['id'];
			unset( $batch );
		}

		return array_values( array_unique( $all_keys ) );
	}

	/**
	 * @param int $form_id Form post ID.
	 * @return string Form title or "(Deleted)" placeholder.
	 */
	private function get_form_title_cached( int $form_id ): string {
		if ( ! isset( $this->form_title_cache[ $form_id ] ) ) {
			$title                              = get_the_title( $form_id );
			$this->form_title_cache[ $form_id ] = $title ? $title : __( '(Deleted)', 'ffcertificate' );
		}
		return $this->form_title_cache[ $form_id ];
	}
}
