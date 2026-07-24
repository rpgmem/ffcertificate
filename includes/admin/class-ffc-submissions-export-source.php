<?php
/**
 * SubmissionsExportSource
 *
 * The certificate-submissions source for the shared {@see
 * \FreeFormCertificate\Core\BatchedCsvExport} engine. Holds everything specific
 * to exporting `ffc_submissions`: authorization (the `ffc_export_certificates`
 * cap + the `ffc_csv_export` nonce), the fixed + dynamic column layout, per-row
 * formatting with PII decryption, and the keyset (id-cursor) page query. The job
 * lifecycle (temp file, transient, batching, download, cleanup) lives in the
 * engine. Extracted from the former monolithic `Admin\CsvExporter`. (Issue #772.)
 *
 * @package FreeFormCertificate\Admin
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\BatchedExportSourceInterface;
use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate-submissions export source.
 */
class SubmissionsExportSource implements BatchedExportSourceInterface {

	use \FreeFormCertificate\Core\CsvExportTrait;

	/**
	 * Capability gating every phase.
	 */
	private const CAP = 'ffc_export_certificates';

	/**
	 * Nonce action shared by start / batch / download.
	 */
	private const NONCE = 'ffc_csv_export';

	/**
	 * Records per query during dynamic-key discovery (lighter query).
	 */
	private const KEYS_BATCH_SIZE = 500;

	/**
	 * Repository.
	 *
	 * @var SubmissionRepository
	 */
	private SubmissionRepository $repository;

	/**
	 * Cached form titles.
	 *
	 * @var array<int, string>
	 */
	private array $form_title_cache = array();

	/**
	 * Constructor.
	 *
	 * @param SubmissionRepository $repository Repository.
	 */
	public function __construct( SubmissionRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function type(): string {
		return 'submissions';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function authorize_start(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_send_json_error( __( 'Permission denied.', 'ffcertificate' ), 403 );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_batch( array $job ): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_send_json_error( __( 'Permission denied.', 'ffcertificate' ), 403 );
		}
		if ( get_current_user_id() !== (int) ( $job['user_id'] ?? 0 ) ) {
			wp_send_json_error( __( 'Export job not found or expired.', 'ffcertificate' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_download( array $job ): void {
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_get_string( 'nonce' ), self::NONCE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ffcertificate' ) );
		}
		if ( get_current_user_id() !== (int) ( $job['user_id'] ?? 0 ) ) {
			wp_die( esc_html__( 'Export job not found or expired.', 'ffcertificate' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function job_owner_fields(): array {
		return array( 'user_id' => get_current_user_id() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_filters(): array {
		// The nonce is verified in authorize_start(), which the engine calls
		// before this. NonceVerification can't see across methods, so disable it
		// for this request-reading block; inputs are unslashed + sanitized
		// (absint() per element / sanitize_key()).
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$form_ids = null;
		if ( ! empty( $_POST['form_ids'] ) && is_array( $_POST['form_ids'] ) ) {
			$form_ids = array_map( 'absint', wp_unslash( $_POST['form_ids'] ) );
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'publish';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		return array(
			'form_ids' => $form_ids,
			'status'   => $status,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function count( array $filters ): int {
		return $this->repository->countForExport( $filters['form_ids'], (string) $filters['status'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, mixed>
	 */
	public function build_context( array $filters ): array {
		return array(
			'dynamic_keys'         => $this->scan_dynamic_keys( $filters['form_ids'], (string) $filters['status'] ),
			'include_edit_columns' => $this->repository->hasEditInfo(),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, string>
	 */
	public function header( array $filters, array $context ): array {
		$include_edit = (bool) $context['include_edit_columns'];
		$headers      = array_merge(
			$this->get_fixed_headers( $include_edit ),
			$this->get_dynamic_headers( $context['dynamic_keys'] )
		);

		/**
		 * Filters the header row of the admin CSV export.
		 *
		 * @since 5.4.0
		 *
		 * @param array<int, string>   $headers      Column headers in order.
		 * @param bool                 $include_edit Whether edit-tracking columns are included.
		 * @param array<int, int>|null $form_ids     Array of form IDs, or null for "all".
		 */
		return (array) apply_filters( 'ffcertificate_csv_export_headers', $headers, $include_edit, $filters['form_ids'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return string
	 */
	public function filename( array $filters, array $context ): string {
		$form_ids = $filters['form_ids'];
		if ( $form_ids && count( $form_ids ) === 1 ) {
			$form_title = get_the_title( $form_ids[0] );
		} elseif ( $form_ids && count( $form_ids ) > 1 ) {
			$form_title = count( $form_ids ) . '-forms';
		} else {
			$form_title = 'all-forms';
		}
		$filename = \FreeFormCertificate\Core\FilenameHelper::sanitize_filename( $form_title ) . '-' . gmdate( 'Y-m-d' ) . '.csv';

		/**
		 * Filters the filename used for admin CSV export downloads.
		 *
		 * @since 5.4.0
		 *
		 * @param string               $filename Default filename (already sanitized, ends in .csv).
		 * @param array<int, int>|null $form_ids Array of form IDs being exported, or null for "all".
		 * @param string               $status   Submission status filter.
		 */
		return (string) apply_filters( 'ffcertificate_csv_export_filename', $filename, $form_ids, (string) $filters['status'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @param int                  $cursor  Exclusive upper-bound id.
	 * @param int                  $size    Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function fetch_page( array $filters, array $context, int $cursor, int $size ): array {
		$batch = $this->repository->getExportBatch( $filters['form_ids'], (string) $filters['status'], $cursor, $size );

		/**
		 * Filters a batch of submission rows during CSV export.
		 *
		 * @since 5.0.0
		 */
		return (array) apply_filters( 'ffcertificate_csv_export_data', $batch, $filters['form_ids'], (string) $filters['status'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row Row.
	 * @return int
	 */
	public function cursor_of( array $row ): int {
		return (int) ( $row['id'] ?? 0 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row     Raw row.
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, mixed>
	 */
	public function format_row( array $row, array $context ): array {
		return $this->format_csv_row( $row, $context['dynamic_keys'], (bool) $context['include_edit_columns'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Job state.
	 * @return array<string, mixed>
	 */
	public function extra_start_response( string $job_id, array $job ): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Final job state.
	 * @return void
	 */
	public function on_complete( string $job_id, array $job ): void {
		/**
		 * Fires when an admin CSV export has processed all rows and the temp
		 * file is ready to be served. The file still exists on disk at this
		 * point — integrators can copy it to external storage, notify, etc.
		 *
		 * @since 5.4.0
		 *
		 * @param string               $job_id    Export job identifier.
		 * @param string               $file      Absolute path to the generated CSV file.
		 * @param int                  $processed Number of rows written.
		 * @param array<string, mixed> $job       Full job state.
		 */
		do_action( 'ffcertificate_csv_export_completed', $job_id, $job['file'], (int) $job['processed'], $job );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function on_before_download( array $job ): void {
		// No pre-delivery side effect for the admin submissions export.
		unset( $job );
	}

	// ──────────────────────────────────────────────────────────────.
	// Domain helpers (moved verbatim from the former CsvExporter).
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Get form title cached.
	 *
	 * @param int $form_id Form post ID.
	 * @return string Form title or "(Deleted)".
	 */
	private function get_form_title_cached( int $form_id ): string {
		if ( ! isset( $this->form_title_cache[ $form_id ] ) ) {
			$title                              = get_the_title( $form_id );
			$this->form_title_cache[ $form_id ] = $title ? $title : __( '(Deleted)', 'ffcertificate' );
		}
		return $this->form_title_cache[ $form_id ];
	}

	/**
	 * Get fixed headers.
	 *
	 * @param bool $include_edit_columns Include edit columns.
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
	 * Get dynamic headers.
	 *
	 * @param array<int, string> $dynamic_keys Dynamic keys.
	 * @return array<int, string>
	 */
	private function get_dynamic_headers( array $dynamic_keys ): array {
		return $this->build_dynamic_headers( $dynamic_keys );
	}

	/**
	 * Format csv row.
	 *
	 * @param array<string, mixed> $row                  Row.
	 * @param array<int, string>   $dynamic_keys         Dynamic keys.
	 * @param bool                 $include_edit_columns Include edit columns.
	 * @return array<int, mixed>
	 */
	private function format_csv_row( array $row, array $dynamic_keys, bool $include_edit_columns = false ): array {
		$form_display = $this->get_form_title_cached( (int) $row['form_id'] );

		$email   = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'email' );
		$user_ip = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'user_ip' );
		$cpf_val = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'cpf' );
		$rf_val  = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'rf' );

		// `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a).
		// Format for human eyes in the CSV; admins reading raw would expect a
		// string here, not an epoch number.
		$line = array(
			$row['id'],
			$form_display,
			! empty( $row['user_id'] ) ? $row['user_id'] : '',
			\FreeFormCertificate\Core\DateFormatter::format_datetime( (int) ( $row['submission_date'] ?? 0 ) ),
			$email,
			$user_ip,
			$cpf_val,
			$rf_val,
			! empty( $row['auth_code'] ) ? $row['auth_code'] : '',
			! empty( $row['magic_token'] ) ? $row['magic_token'] : '',
			isset( $row['consent_given'] ) ? ( $row['consent_given'] ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' ) ) : '',
			// `consent_date` is unix UTC int since 6.6.0 (#249 sub-escopo d); format for human eyes.
			! empty( $row['consent_date'] ) ? \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['consent_date'] ) : '',
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
				// `edited_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
				$edit_date = \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['edited_at'] );
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
	 * @param array<int, int>|null $form_ids Form ids.
	 * @param string               $status   Status.
	 * @return array<int, string>
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
}
