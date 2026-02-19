<?php
declare(strict_types=1);

/**
 * CsvExporter
 *
 * Handles CSV export via AJAX-driven batches to avoid web-server timeouts.
 *
 * Flow:
 *  1. JS  → wp_ajax_ffc_csv_export_start   → creates job, discovers keys, writes header
 *  2. JS  → wp_ajax_ffc_csv_export_batch   → processes N rows, appends to temp file (repeat)
 *  3. JS  → wp_ajax_ffc_csv_export_download → serves completed file and deletes it
 *
 * @since 5.0.0  Rewritten as AJAX-driven batched export.
 * @since 4.0.0  Multi-form ID support.
 */

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CsvExporter {

	use \FreeFormCertificate\Core\CsvExportTrait;

	/**
	 * Records per AJAX batch request.
	 */
	const EXPORT_BATCH_SIZE = 100;

	/**
	 * Records per query during dynamic-key discovery (lighter query).
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
	 * Cached form titles.
	 *
	 * @var array<int, string>
	 */
	private array $form_title_cache = array();

	public function __construct() {
		$this->repository = new SubmissionRepository();
	}

	// ──────────────────────────────────────────────────────────────
	//  Hook registration (called from Admin __construct)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Register AJAX handlers for the three-step export flow.
	 */
	public function register_ajax_hooks(): void {
		add_action( 'wp_ajax_ffc_csv_export_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_ffc_csv_export_batch', array( $this, 'ajax_batch' ) );
		add_action( 'wp_ajax_ffc_csv_export_download', array( $this, 'ajax_download' ) );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Start
	// ──────────────────────────────────────────────────────────────

	/**
	 * Start a new export job.
	 *
	 * - Validates permissions / nonce.
	 * - Scans all matching rows for dynamic JSON keys (lightweight).
	 * - Counts total rows.
	 * - Writes CSV header + BOM to a temp file.
	 * - Returns job_id + total to JS.
	 */
	public function ajax_start(): void {
		check_ajax_referer( 'ffc_csv_export', 'nonce' );

		if ( ! \FreeFormCertificate\Core\Utils::current_user_can_manage() ) {
			wp_send_json_error( __( 'Permission denied.', 'ffcertificate' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitised below.
		$form_ids = null;
		if ( ! empty( $_POST['form_ids'] ) && is_array( $_POST['form_ids'] ) ) {
			$form_ids = array_map( 'absint', wp_unslash( $_POST['form_ids'] ) );
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'publish';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit may be disabled.
		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		// Discover dynamic keys in batches (lightweight: only id + data columns).
		$dynamic_keys = $this->scan_dynamic_keys( $form_ids, $status );

		// Count total rows to report progress to JS.
		$total = $this->count_export_rows( $form_ids, $status );

		if ( $total === 0 ) {
			wp_send_json_error( __( 'No records available for export.', 'ffcertificate' ) );
		}

		$include_edit_columns = $this->repository->hasEditInfo();

		// Build filename.
		if ( $form_ids && count( $form_ids ) === 1 ) {
			$form_title = get_the_title( $form_ids[0] );
		} elseif ( $form_ids && count( $form_ids ) > 1 ) {
			$form_title = count( $form_ids ) . '-forms';
		} else {
			$form_title = 'all-forms';
		}
		$filename = \FreeFormCertificate\Core\Utils::sanitize_filename( $form_title ) . '-' . gmdate( 'Y-m-d' ) . '.csv';

		// Create temp file.
		$upload_dir = wp_upload_dir();
		$tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'ffc-tmp';
		wp_mkdir_p( $tmp_dir );

		// Protect the temp dir from direct HTTP access.
		$htaccess = $tmp_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		$job_id   = wp_generate_uuid4();
		$tmp_file = $tmp_dir . '/ffc-export-' . $job_id . '.csv';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $tmp_file, 'w' );
		if ( ! $fh ) {
			wp_send_json_error( __( 'Cannot create temp file.', 'ffcertificate' ) );
		}

		// BOM + headers.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV binary output.
		fprintf( $fh, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		$headers = array_merge(
			$this->get_fixed_headers( $include_edit_columns ),
			$this->get_dynamic_headers( $dynamic_keys )
		);
		$headers = array_map( function ( $h ) {
			return mb_convert_encoding( $h, 'UTF-8', 'UTF-8' );
		}, $headers );
		fputcsv( $fh, $headers, ';' );
		fclose( $fh );

		// Store job state in a transient.
		$job = array(
			'form_ids'             => $form_ids,
			'status'               => $status,
			'dynamic_keys'         => $dynamic_keys,
			'include_edit_columns' => $include_edit_columns,
			'cursor'               => PHP_INT_MAX,
			'processed'            => 0,
			'total'                => $total,
			'file'                 => $tmp_file,
			'filename'             => $filename,
			'user_id'              => get_current_user_id(),
		);
		set_transient( 'ffc_csv_export_' . $job_id, $job, self::JOB_TTL );

		wp_send_json_success( array(
			'job_id' => $job_id,
			'total'  => $total,
		) );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Batch
	// ──────────────────────────────────────────────────────────────

	/**
	 * Process one batch (EXPORT_BATCH_SIZE rows) and append to temp file.
	 */
	public function ajax_batch(): void {
		check_ajax_referer( 'ffc_csv_export', 'nonce' );

		if ( ! \FreeFormCertificate\Core\Utils::current_user_can_manage() ) {
			wp_send_json_error( __( 'Permission denied.', 'ffcertificate' ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job    = get_transient( 'ffc_csv_export_' . $job_id );

		if ( ! $job || (int) $job['user_id'] !== get_current_user_id() ) {
			wp_send_json_error( __( 'Export job not found or expired.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 60 );

		$batch = $this->repository->getExportBatch(
			$job['form_ids'],
			$job['status'],
			$job['cursor'],
			self::EXPORT_BATCH_SIZE
		);

		if ( empty( $batch ) ) {
			// All done.
			wp_send_json_success( array(
				'done'      => true,
				'processed' => $job['processed'],
				'total'     => $job['total'],
			) );
		}

		/**
		 * Filters a batch of submission rows during CSV export.
		 *
		 * @since 5.0.0
		 */
		$batch = apply_filters( 'ffcertificate_csv_export_data', $batch, $job['form_ids'], $job['status'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $job['file'], 'a' );
		if ( ! $fh ) {
			wp_send_json_error( __( 'Cannot write to temp file.', 'ffcertificate' ) );
		}

		foreach ( $batch as $row ) {
			$csv_row = $this->format_csv_row( $row, $job['dynamic_keys'], $job['include_edit_columns'] );
			$csv_row = array_map( function ( $v ) {
				return mb_convert_encoding( (string) $v, 'UTF-8', 'UTF-8' );
			}, $csv_row );
			fputcsv( $fh, $csv_row, ';' );
		}
		fclose( $fh );

		// Advance cursor.
		$last_row          = end( $batch );
		$job['cursor']     = (int) $last_row['id'];
		$job['processed'] += count( $batch );

		set_transient( 'ffc_csv_export_' . $job_id, $job, self::JOB_TTL );

		wp_send_json_success( array(
			'done'      => false,
			'processed' => $job['processed'],
			'total'     => $job['total'],
		) );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Download
	// ──────────────────────────────────────────────────────────────

	/**
	 * Serve the completed CSV file and clean up.
	 */
	public function ajax_download(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'ffc_csv_export' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}

		if ( ! \FreeFormCertificate\Core\Utils::current_user_can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'ffcertificate' ) );
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$job    = get_transient( 'ffc_csv_export_' . $job_id );

		if ( ! $job || (int) $job['user_id'] !== get_current_user_id() ) {
			wp_die( esc_html__( 'Export job not found or expired.', 'ffcertificate' ) );
		}

		$file = $job['file'];
		if ( ! file_exists( $file ) ) {
			wp_die( esc_html__( 'Export file not found.', 'ffcertificate' ) );
		}

		// Serve file.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		while ( @ob_end_clean() ) {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedWhile

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $job['filename'] );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file );

		// Cleanup.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $file );
		delete_transient( 'ffc_csv_export_' . $job_id );

		exit;
	}

	// ──────────────────────────────────────────────────────────────
	//  Legacy entry point (kept for backwards compat)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Legacy handler called from admin_post_ffc_export_csv.
	 *
	 * Redirects back to submissions page — the actual export now happens
	 * via AJAX. This avoids 503 errors on slow hosting.
	 *
	 * @deprecated 5.0.0 Use AJAX endpoints instead.
	 */
	public function handle_export_request(): void {
		// If someone hits the old POST endpoint, redirect back.
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions' ) );
		exit;
	}

	// ──────────────────────────────────────────────────────────────
	//  Helpers (private)
	// ──────────────────────────────────────────────────────────────

	/**
	 * @param int $form_id Form post ID.
	 * @return string Form title or "(Deleted)".
	 */
	private function get_form_title_cached( int $form_id ): string {
		if ( ! isset( $this->form_title_cache[ $form_id ] ) ) {
			$title = get_the_title( $form_id );
			$this->form_title_cache[ $form_id ] = $title ? $title : __( '(Deleted)', 'ffcertificate' );
		}
		return $this->form_title_cache[ $form_id ];
	}

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

	private function get_dynamic_headers( array $dynamic_keys ): array {
		return $this->build_dynamic_headers( $dynamic_keys );
	}

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
			$cpf_val ?? '',
			$rf_val ?? '',
			! empty( $row['auth_code'] ) ? $row['auth_code'] : '',
			! empty( $row['magic_token'] ) ? $row['magic_token'] : '',
			isset( $row['consent_given'] ) ? ( $row['consent_given'] ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' ) ) : '',
			! empty( $row['consent_date'] ) ? $row['consent_date'] : '',
			$user_ip, // Consent IP
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
	 */
	private function scan_dynamic_keys( ?array $form_ids, string $status ): array {
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
	 * Count total matching rows for progress reporting.
	 */
	private function count_export_rows( ?array $form_ids, string $status ): int {
		return $this->repository->countForExport( $form_ids, $status );
	}
}
