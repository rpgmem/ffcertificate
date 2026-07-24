<?php
/**
 * PublicFormsExportSource
 *
 * The public (front-end, anonymous-reachable) forms source for the shared
 * {@see \FreeFormCertificate\Core\BatchedCsvExport} engine. Holds everything
 * specific to the public CSV download: its layered authorization (IP
 * rate-limit + page nonce + honeypot/CAPTCHA + form-access hash + per-form CPF
 * gate at start; a job-scoped nonce + IP-hash fence on every subsequent
 * request), the download-quota bump, the column layout (shared with the admin
 * export via {@see Csv\PublicCsvRowFormatter}), the keyset cursor query, and the
 * "delivered" audit row. The job lifecycle lives in the engine. Extracted from
 * the batched half of the former monolithic {@see PublicCsvExporter}; the
 * synchronous (no-JS) fallback stays on that class. (Issue #772.)
 *
 * @package FreeFormCertificate\Frontend
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\BatchedExportSourceInterface;
use FreeFormCertificate\Core\CsvExportTrait;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Frontend\Csv\PublicCsvRowFormatter;
use FreeFormCertificate\Repositories\SubmissionRepository;
use FreeFormCertificate\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public forms CSV export source.
 */
class PublicFormsExportSource implements BatchedExportSourceInterface {

	use CsvExportTrait;

	/**
	 * Repository.
	 *
	 * @var SubmissionRepository
	 */
	private SubmissionRepository $repository;

	/**
	 * Row formatter (headers / row formatting / key scan). Lazily built.
	 *
	 * @var PublicCsvRowFormatter|null
	 */
	private ?PublicCsvRowFormatter $row_formatter = null;

	/**
	 * Constructor.
	 *
	 * @param SubmissionRepository $repository Repository.
	 */
	public function __construct( SubmissionRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Lazily build the row formatter.
	 */
	private function row_formatter(): PublicCsvRowFormatter {
		if ( null === $this->row_formatter ) {
			$this->row_formatter = new PublicCsvRowFormatter();
		}
		return $this->row_formatter;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function type(): string {
		return 'public_forms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function authorize_start(): void {
		// 1. Rate limit by IP.
		if ( class_exists( RateLimiter::class ) ) {
			$rate_check = RateLimiter::check_ip_limit( RequestInput::get_user_ip() );
			if ( empty( $rate_check['allowed'] ) ) {
				wp_send_json_error(
					array(
						'message'    => $rate_check['message'] ?? __( 'Too many requests. Please wait.', 'ffcertificate' ),
						'rate_limit' => true,
					)
				);
			}
		}

		// 2. Page nonce.
		if ( ! wp_verify_nonce( RequestInput::get_post_string( '_ffc_pcd_nonce' ), PublicCsvDownload::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'ffcertificate' ) ) );
		}

		// 3. Honeypot + CAPTCHA.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$security_check = \FreeFormCertificate\Core\SecurityService::validate_security_fields( $_POST );
		if ( true !== $security_check ) {
			wp_send_json_error( array( 'message' => (string) $security_check ) );
		}

		// 4. Form id + access hash present.
		$form_id     = $this->request_form_id();
		$posted_hash = RequestInput::get_post_string( 'hash' );
		if ( $form_id <= 0 || '' === $posted_hash ) {
			wp_send_json_error( array( 'message' => __( 'Please inform both the Form ID and the Access Hash.', 'ffcertificate' ) ) );
		}

		// 5. Form-access + CPF-gate business rules.
		$validator = new PublicCsvDownload();
		$error     = $validator->validate_form_access( $form_id, $posted_hash );
		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ) );
		}
		$cpf_error = $validator->validate_cpf_requirement( $form_id, RequestInput::get_post_string( 'cpf' ) );
		if ( null !== $cpf_error ) {
			wp_send_json_error( array( 'message' => $cpf_error ) );
		}

		// 6. Increment the download quota BEFORE generating (race-safe).
		$count = (int) get_post_meta( $form_id, PublicCsvDownload::META_COUNT, true );
		update_post_meta( $form_id, PublicCsvDownload::META_COUNT, $count + 1 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_batch( array $job ): void {
		$job_id = RequestInput::get_post_string( 'job_id' );
		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'nonce_batch' ), 'ffc_public_csv_batch_' . $job_id ) ) {
			wp_send_json_error( __( 'Security check failed.', 'ffcertificate' ) );
		}
		if ( ! hash_equals( (string) ( $job['ip_hash'] ?? '' ), sha1( RequestInput::get_user_ip() ) ) ) {
			wp_send_json_error( __( 'Session mismatch.', 'ffcertificate' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_download( array $job ): void {
		$job_id = RequestInput::get_get_string( 'job_id' );
		if ( ! wp_verify_nonce( RequestInput::get_get_string( 'nonce_batch' ), 'ffc_public_csv_batch_' . $job_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}
		if ( ! hash_equals( (string) ( $job['ip_hash'] ?? '' ), sha1( RequestInput::get_user_ip() ) ) ) {
			wp_die( esc_html__( 'Session mismatch.', 'ffcertificate' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function job_owner_fields(): array {
		$cpf_digits = preg_replace( '/\D/', '', RequestInput::get_post_string( 'cpf' ) );
		return array(
			'ip_hash'    => sha1( RequestInput::get_user_ip() ),
			'form_id'    => $this->request_form_id(),
			'cpf_digits' => is_string( $cpf_digits ) ? $cpf_digits : '',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_filters(): array {
		$form_id = $this->request_form_id();
		return array(
			'form_id'  => $form_id,
			'form_ids' => array( $form_id ),
			'status'   => 'publish',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function count( array $filters ): int {
		return $this->repository->count(
			array(
				'form_id' => (int) $filters['form_id'],
				'status'  => (string) $filters['status'],
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, mixed>
	 */
	public function build_context( array $filters ): array {
		return array(
			'dynamic_keys'         => $this->row_formatter()->scan_dynamic_keys( $this->repository, $filters['form_ids'], (string) $filters['status'] ),
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
			$this->row_formatter()->get_fixed_headers( $include_edit ),
			$this->build_dynamic_headers( $context['dynamic_keys'] )
		);

		/** This filter is documented in PublicCsvExporter::stream_form_csv(). */
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
		$form_id        = (int) $filters['form_id'];
		$form_title_raw = get_the_title( $form_id );
		$filename       = \FreeFormCertificate\Core\FilenameHelper::sanitize_filename(
			$form_title_raw ? $form_title_raw : ( 'form-' . $form_id )
		) . '-' . gmdate( 'Y-m-d-His' ) . '.csv';

		/** This filter is documented in PublicCsvExporter::stream_form_csv(). */
		return (string) apply_filters( 'ffcertificate_csv_export_filename', $filename, $filters['form_ids'], (string) $filters['status'] );
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

		/** This filter is documented in PublicCsvExporter::stream_form_csv(). */
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
		return $this->row_formatter()->format_csv_row( $row, $context['dynamic_keys'], (bool) $context['include_edit_columns'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Job state.
	 * @return array<string, mixed>
	 */
	public function extra_start_response( string $job_id, array $job ): array {
		return array( 'nonce_batch' => wp_create_nonce( 'ffc_public_csv_batch_' . $job_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Final job state.
	 * @return void
	 */
	public function on_complete( string $job_id, array $job ): void {
		/** This action is documented in PublicCsvExporter::stream_form_csv(). */
		do_action(
			'ffcertificate_csv_export_completed',
			$job_id,
			isset( $job['file'] ) ? (string) $job['file'] : '',
			(int) $job['processed'],
			array_merge( $job, array( 'mode' => 'public-batch' ) )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function on_before_download( array $job ): void {
		// Audit row recording the actual delivery (post-#241 follow-up), written
		// just before readfile since the client may abort mid-stream.
		$form_id = isset( $job['form_id'] ) ? (int) $job['form_id'] : 0;
		if ( $form_id <= 0 ) {
			return;
		}
		$audit_validator = new CsvDownloadValidator();
		$audit_validator->record_download_log_entry(
			$form_id,
			(string) get_post_meta( $form_id, '_ffc_csv_public_cpf_mode', true ),
			isset( $job['cpf_digits'] ) ? (string) $job['cpf_digits'] : '',
			'download_delivered'
		);
	}

	/**
	 * Read + sanitize the requested form id from POST.
	 *
	 * @return int
	 */
	private function request_form_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- absint() sanitizes; nonce verified in authorize_start().
		return isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
	}
}
