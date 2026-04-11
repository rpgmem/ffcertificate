<?php
declare(strict_types=1);

/**
 * PublicCsvExporter
 *
 * Synchronous CSV stream used by the public-facing CSV download feature.
 *
 * Unlike the admin `CsvExporter` (which uses a 3-step AJAX batched flow to
 * survive slow hosting), this exporter runs in a single request via
 * `admin-post.php`:
 *
 *  1. Caller validates the request (hash, expiration, quota).
 *  2. This class streams the file straight to the browser and exits.
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
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		while ( @ob_end_clean() ) {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedWhile

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
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
			$title                                 = get_the_title( $form_id );
			$this->form_title_cache[ $form_id ]    = $title ? $title : __( '(Deleted)', 'ffcertificate' );
		}
		return $this->form_title_cache[ $form_id ];
	}
}
