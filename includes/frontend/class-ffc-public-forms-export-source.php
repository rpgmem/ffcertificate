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
use FreeFormCertificate\Security\ReadRateGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public forms CSV export source.
 */
class PublicFormsExportSource implements BatchedExportSourceInterface {

	use CsvExportTrait;

	/**
	 * Stable source type routed by the dispatcher / registry.
	 */
	public const TYPE = 'public_forms';

	/**
	 * Prefix of the cookie that tells the page a job's file was sent; the
	 * job id follows it (#2341).
	 *
	 * @var string
	 */
	public const DELIVERED_COOKIE_PREFIX = 'ffc_csv_delivered_';

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
		return self::TYPE;
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
						'message'    => $rate_check['message'],
						'rate_limit' => true,
					)
				);
			}
		}
		// The screen's read pool, which counts the call (#2341).
		$rate_error = ReadRateGuard::check( PublicCsvDownload::RATE_ENDPOINT );
		if ( null !== $rate_error ) {
			wp_send_json_error(
				array(
					'message'    => $rate_error->get_error_message(),
					'rate_limit' => true,
				)
			);
		}

		// 2. Page nonce.
		if ( ! wp_verify_nonce( RequestInput::get_post_string( '_ffc_pcd_nonce' ), PublicCsvDownload::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'ffcertificate' ) ) );
		}

		// 3. Honeypot + CAPTCHA.
		$security_check = \FreeFormCertificate\Core\SecurityService::validate_security_fields( $_POST, \FreeFormCertificate\Core\Captcha\CaptchaContext::PUBLIC_CSV );
		if ( true !== $security_check ) {
			wp_send_json_error( \FreeFormCertificate\Core\SecurityService::with_fresh_challenge( array( 'message' => (string) $security_check ), \FreeFormCertificate\Core\Captcha\CaptchaContext::PUBLIC_CSV ) );
		}

		// 4. Form id + access hash present.
		$form_id     = $this->request_form_id();
		$posted_hash = RequestInput::get_post_string( 'hash' );
		if ( $form_id <= 0 || '' === $posted_hash ) {
			wp_send_json_error( \FreeFormCertificate\Core\SecurityService::with_fresh_challenge( array( 'message' => __( 'Please inform both the Form ID and the Access Hash.', 'ffcertificate' ) ), \FreeFormCertificate\Core\Captcha\CaptchaContext::PUBLIC_CSV ) );
		}

		// 5. Form-access + CPF-gate business rules.
		$validator = new PublicCsvDownload();
		$error     = $validator->validate_form_access( $form_id, $posted_hash );
		if ( null !== $error ) {
			wp_send_json_error( \FreeFormCertificate\Core\SecurityService::with_fresh_challenge( array( 'message' => $error ), \FreeFormCertificate\Core\Captcha\CaptchaContext::PUBLIC_CSV ) );
		}
		$cpf_error = $validator->validate_cpf_requirement( $form_id, RequestInput::get_post_string( 'cpf' ) );
		if ( null !== $cpf_error ) {
			wp_send_json_error( \FreeFormCertificate\Core\SecurityService::with_fresh_challenge( array( 'message' => $cpf_error ), \FreeFormCertificate\Core\Captcha\CaptchaContext::PUBLIC_CSV ) );
		}

		// The quota is checked above and spent at delivery (#2341), so an
		// export with no records, or one that is never downloaded, costs
		// nothing.
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
		if ( ! hash_equals( (string) ( $job['ip_hash'] ?? '' ), \FreeFormCertificate\Core\IpAddress::fingerprint( RequestInput::get_user_ip() ) ) ) {
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
		if ( ! hash_equals( (string) ( $job['ip_hash'] ?? '' ), \FreeFormCertificate\Core\IpAddress::fingerprint( RequestInput::get_user_ip() ) ) ) {
			wp_die( esc_html__( 'Session mismatch.', 'ffcertificate' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function job_owner_fields(): array {
		$cpf_digits = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( RequestInput::get_post_string( 'cpf' ) );
		return array(
			// The job's owner by address fingerprint, never the address (#2109).
			'ip_hash'    => \FreeFormCertificate\Core\IpAddress::fingerprint( RequestInput::get_user_ip() ),
			'form_id'    => $this->request_form_id(),
			'cpf_digits' => $cpf_digits,
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
		return (array) apply_filters( 'ffc_export_headers', $headers, $include_edit, $filters['form_ids'] );
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
		return (string) apply_filters( 'ffc_export_filename', $filename, $filters['form_ids'], (string) $filters['status'] );
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
		return (array) apply_filters( 'ffc_export_data', $batch, $filters['form_ids'], (string) $filters['status'] );
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
		return array(
			'nonce_batch'       => wp_create_nonce( 'ffc_public_csv_batch_' . $job_id ),
			// The start spent the screen's challenge (#2341): a fresh one
			// for the next download from the same screen, which the
			// operator solves there instead of going back to the form.
			'download_security' => \FreeFormCertificate\Core\SecurityService::render_security_fields( \FreeFormCertificate\Core\Captcha\CaptchaContext::PUBLIC_CSV ),
		);
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
			'ffc_export_completed',
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
		// The download is spent here, when the file is delivered (#2341),
		// and atomically: a second job racing for the last download of the
		// quota is refused.
		if ( ! $audit_validator->claim_download( $form_id ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %d is the configured download limit */
						__( 'The maximum number of downloads (%d) for this form has been reached.', 'ffcertificate' ),
						CsvDownloadValidator::download_limit( $form_id )
					)
				)
			);
		}
		$audit_validator->record_download_log_entry(
			$form_id,
			(string) get_post_meta( $form_id, '_ffc_csv_public_cpf_mode', true ),
			isset( $job['cpf_digits'] ) ? (string) $job['cpf_digits'] : '',
			'download_delivered'
		);
		// The file carries CPF, e-mail and RF in clear (#2341): the delivery
		// is a PII-access audit entry, as on the download without JavaScript.
		\FreeFormCertificate\Core\PiiExport::log( PublicCsvDownload::PII_EXPORT_TYPE, (int) ( $job['processed'] ?? 0 ), array( 'form_id' => $form_id ) );
		self::send_delivered_cookie( RequestInput::get_get_string( 'job_id' ) );
	}

	/**
	 * Tell the page the file is on its way (#2341).
	 *
	 * The file arrives in a hidden frame, which fires no event for an
	 * attachment, so the screen said "Download complete" on a timer. The
	 * cookie travels with the file's own response, and the screen waits for
	 * it. It carries no data, only that this job's file was sent, so a
	 * script may read it (`httponly` off) and it expires in two minutes.
	 *
	 * @param string $job_id The job being delivered.
	 * @return void
	 */
	private static function send_delivered_cookie( string $job_id ): void {
		$job_id = (string) preg_replace( '/[^a-f0-9-]/', '', strtolower( $job_id ) );
		if ( '' === $job_id || headers_sent() ) {
			return;
		}
		setcookie(
			self::DELIVERED_COOKIE_PREFIX . $job_id,
			'1',
			array(
				'expires'  => time() + 2 * MINUTE_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl() || 0 === strpos( (string) home_url(), 'https://' ),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Read + sanitize the requested form id from POST.
	 *
	 * @return int
	 */
	private function request_form_id(): int {
		// No annotation needed: the read, its unslash and its nonce note all
		// live inside the accessor now.
		return \FreeFormCertificate\Core\RequestInput::get_post_int( 'form_id', 0 );
	}
}
