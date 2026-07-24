<?php
/**
 * PublicCsvDownload
 *
 * Public-facing CSV download feature.
 *
 * Flow (with JavaScript — AJAX batched):
 *  1. Admin enables the feature on a form and generates a hash.
 *  2. Admin embeds [ffc_csv_download] on a WP page and shares it with
 *     the form ID + hash.
 *  3. JS intercepts form submit and drives a 3-step AJAX flow via
 *     {@see PublicCsvExporter}: start → batch (×N) → download.
 *     A progress bar shows real processed/total feedback.
 *
 * Fallback (without JavaScript):
 *  The form submits normally via admin-post.php to {@see handle_request()},
 *  which streams the CSV synchronously (legacy path).
 *
 * S5 split (#141): the validation gate moved to {@see CsvDownloadValidator}
 * and the intermediate-screen payload builder moved to
 * {@see CsvDownloadFormInfoBuilder}. This class is now the thin facade that
 * owns hooks, request orchestration, the audit-log export, and the flash
 * message helpers. The validation public methods remain on the facade as
 * forwarders so existing callers (PublicCsvExporter, unit tests) keep
 * working unchanged.
 *
 * @package FreeFormCertificate
 * @since   5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;

use FreeFormCertificate\Frontend\Csv\CsvDownloadAuditLog;
use FreeFormCertificate\Frontend\Csv\CsvDownloadFlash;

use FreeFormCertificate\Security\Geofence;
use FreeFormCertificate\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-facing CSV download handler with intermediate info screen.
 *
 * @since 5.1.0
 */
class PublicCsvDownload {

	const SHORTCODE           = 'ffc_csv_download';
	const ACTION              = 'ffc_public_csv_download';
	const NONCE_ACTION        = 'ffc_public_csv_download';
	const META_ENABLED        = '_ffc_csv_public_enabled';
	const META_HASH           = '_ffc_csv_public_hash';
	const META_LIMIT          = '_ffc_csv_public_limit';
	const META_COUNT          = '_ffc_csv_public_count';
	const META_CPF_MODE       = '_ffc_csv_public_cpf_mode';
	const META_CPF_WHITELIST  = '_ffc_csv_public_cpf_whitelist';
	const META_DOWNLOAD_LOG   = '_ffc_csv_public_download_log';
	const DOWNLOAD_LOG_MAX    = 100;
	const FLASH_TRANSIENT_TTL = 60; // Seconds.

	const EXPORT_LOG_ACTION = 'ffc_export_csv_public_download_log';
	const EXPORT_LOG_NONCE  = 'ffc_export_csv_public_download_log';

	/**
	 * Validation collaborator (form-access / hash / CPF gates).
	 *
	 * @var CsvDownloadValidator
	 */
	private CsvDownloadValidator $validator;

	/**
	 * Intermediate-screen payload builder.
	 *
	 * @var CsvDownloadFormInfoBuilder
	 */
	private CsvDownloadFormInfoBuilder $form_info_builder;

	/**
	 * Flash-message helper (transient keyed by visitor IP).
	 *
	 * @var CsvDownloadFlash
	 */
	private CsvDownloadFlash $flash;

	/**
	 * Wire up the small collaborators that hold the extracted logic.
	 */
	public function __construct() {
		$this->validator         = new CsvDownloadValidator();
		$this->form_info_builder = new CsvDownloadFormInfoBuilder();
		$this->flash             = new CsvDownloadFlash();
	}

	/**
	 * Register shortcode + admin-post + AJAX handlers.
	 */
	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

		// Synchronous fallback (no-JS path).
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_request' ) );

		// AJAX: form info (intermediate screen).
		add_action( 'wp_ajax_ffc_public_csv_info', array( $this, 'ajax_info' ) );
		add_action( 'wp_ajax_nopriv_ffc_public_csv_info', array( $this, 'ajax_info' ) );

		// AJAX: certificate preview.
		add_action( 'wp_ajax_ffc_public_cert_preview', array( $this, 'ajax_cert_preview' ) );
		add_action( 'wp_ajax_nopriv_ffc_public_cert_preview', array( $this, 'ajax_cert_preview' ) );

		// AJAX: open the form ahead of its scheduled start.
		add_action( 'wp_ajax_ffc_public_open_early', array( $this, 'ajax_open_early' ) );
		add_action( 'wp_ajax_nopriv_ffc_public_open_early', array( $this, 'ajax_open_early' ) );

		// AJAX: postpone the form's close time (one-shot, same day).
		add_action( 'wp_ajax_ffc_public_extend_end', array( $this, 'ajax_extend_end' ) );
		add_action( 'wp_ajax_nopriv_ffc_public_extend_end', array( $this, 'ajax_extend_end' ) );

		// AJAX: stage a per-submission schedule exception (#366).
		add_action( 'wp_ajax_ffc_public_schedule_exception', array( $this, 'ajax_schedule_exception' ) );
		add_action( 'wp_ajax_nopriv_ffc_public_schedule_exception', array( $this, 'ajax_schedule_exception' ) );

		// AJAX batched export (JS path): register the public source with the
		// shared registry; the unified dispatcher (wired in Loader, #772) routes
		// `type=public_forms` requests through the `ffc_export_*` endpoints.
		\FreeFormCertificate\Core\SourceRegistry::register(
			PublicFormsExportSource::TYPE,
			static function (): PublicFormsExportSource {
				return new PublicFormsExportSource( new \FreeFormCertificate\Repositories\SubmissionRepository() );
			}
		);

		// 6.3.3: admin-only audit log export. Logged-in only, no nopriv.
		add_action( 'admin_post_' . self::EXPORT_LOG_ACTION, array( $this, 'handle_export_log_request' ) );
	}

	// ──────────────────────────────────────────────────────────────.
	// Shortcode rendering.
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Render the public download form.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'title' => __( 'Download form submissions (CSV)', 'ffcertificate' ),
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$flash         = $this->get_flash_message();
		$shortcodes    = new Shortcodes();
		$security_html = $shortcodes->generate_security_fields();

		$prefill_form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$prefill_hash    = RequestInput::get_get_string( 'hash' );

		// CPF gate mode is per-form. We can't read it without a known form_id;
		// when prefilled, honour that form's setting. Otherwise render the
		// CPF field unconditionally so the validator can apply per-form
		// rules server-side.
		$cpf_mode = 'optional';
		if ( $prefill_form_id > 0 && get_post_type( $prefill_form_id ) === 'ffc_form' ) {
			$cpf_mode = (string) get_post_meta( $prefill_form_id, '_ffc_csv_public_cpf_mode', true );
			if ( '' === $cpf_mode ) {
				$cpf_mode = 'none';
			}
		}

		ob_start();
		?>
		<div class="ffc-shortcode ffc-verification-container ffc-verification-manual ffc-public-csv-download">
			<div class="ffc-verification-header">
				<h2><?php echo esc_html( $atts['title'] ); ?></h2>
				<p><?php esc_html_e( 'Enter the Form ID and the access hash to download the submissions CSV.', 'ffcertificate' ); ?></p>
			</div>

			<?php if ( $flash ) : ?>
				<div class="ffc-verify-result ffc-pcd-message">
					<div class="<?php echo esc_attr( 'error' === $flash['type'] ? 'ffc-verify-error' : 'ffc-verify-success' ); ?>">
						<?php echo esc_html( $flash['message'] ); ?>
					</div>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-verification-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, '_ffc_pcd_nonce' ); ?>

				<div class="ffc-form-field">
					<label for="ffc-pcd-form-id">
						<?php esc_html_e( 'Form ID', 'ffcertificate' ); ?> <span class="required">*</span>
					</label>
					<input
						type="number"
						id="ffc-pcd-form-id"
						name="form_id"
						class="ffc-input"
						min="1"
						step="1"
						required
						aria-required="true"
						value="<?php echo $prefill_form_id ? esc_attr( (string) $prefill_form_id ) : ''; ?>">
				</div>

				<div class="ffc-form-field">
					<label for="ffc-pcd-hash">
						<?php esc_html_e( 'Access Hash', 'ffcertificate' ); ?> <span class="required">*</span>
					</label>
					<input
						type="text"
						id="ffc-pcd-hash"
						name="hash"
						class="ffc-input"
						required
						aria-required="true"
						autocomplete="off"
						value="<?php echo esc_attr( $prefill_hash ); ?>">
				</div>

				<?php if ( 'none' !== $cpf_mode ) : ?>
					<div class="ffc-lgpd-consent ffc-pcd-cpf-consent">
						<div class="ffc-form-field">
							<label for="ffc-pcd-cpf" class="ffc-consent-text">
								<?php esc_html_e( 'CPF', 'ffcertificate' ); ?>
								<?php if ( 'optional' !== $cpf_mode ) : ?>
									<span class="required">*</span>
								<?php endif; ?>
							</label>
							<input
								type="text"
								id="ffc-pcd-cpf"
								name="cpf"
								class="ffc-input"
								inputmode="numeric"
								autocomplete="off"
								placeholder="000.000.000-00"
								<?php if ( 'optional' !== $cpf_mode ) : ?>
									required aria-required="true"
								<?php endif; ?>>
						</div>
						<p class="ffc-consent-description">
							<?php
							if ( 'audit' === $cpf_mode ) {
								// Audit mode: CPF IS required and the format is validated;
								// what doesn't happen is matching against any allow-list.
								esc_html_e( 'Your CPF is required for traceability and is recorded in this form\'s audit log (encrypted at rest). It is not validated against any allow-list.', 'ffcertificate' );
							} elseif ( 'optional' === $cpf_mode ) {
								esc_html_e( 'Optional. If the form requires a CPF, enter it here. If filled, it will be recorded (encrypted at rest) in the form\'s audit log even when the form does not require it.', 'ffcertificate' );
							} else {
								esc_html_e( 'Enter the CPF authorized to download this CSV. The CPF is encrypted at rest in the form\'s audit log.', 'ffcertificate' );
							}
							?>
						</p>
					</div>
				<?php endif; ?>

				<div class="ffc-no-js-security">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside generate_security_fields().
					echo $security_html;
					?>
				</div>

				<button type="submit" class="ffc-submit-btn">
					<?php esc_html_e( 'View form details', 'ffcertificate' ); ?>
				</button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ──────────────────────────────────────────────────────────────.
	// Request handling (admin-post.php)
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Process a CSV download request (synchronous fallback for no-JS).
	 *
	 * Exits on success (streams the CSV) or on error (redirects back
	 * with a flash message transient).
	 */
	public function handle_request(): void {
		/*
		 * 1. Rate limit by IP — run BEFORE anything expensive.
		 *
		 * Pre-form_id checks (rate-limit, nonce) are NOT logged into
		 * the per-form audit ring buffer: those rejections happen
		 * before we know which form the request was aimed at, and
		 * overwhelmingly come from scanners / stale tabs anyway. The
		 * audit dashboard cares about CPF / CAPTCHA / hash / quota
		 * outcomes, which all run after we've parsed form_id below.
		 */
		if ( class_exists( RateLimiter::class ) ) {
			$ip         = \FreeFormCertificate\Core\RequestInput::get_user_ip();
			$rate_check = RateLimiter::check_ip_limit( $ip );
			if ( empty( $rate_check['allowed'] ) ) {
				$this->fail_redirect( $rate_check['message'] ?? __( 'Too many requests. Please wait.', 'ffcertificate' ) );
			}
		}

		// 2. Nonce.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( ! wp_verify_nonce( RequestInput::get_post_string( '_ffc_pcd_nonce' ), self::NONCE_ACTION ) ) {
			$this->fail_redirect( __( 'Security check failed. Please refresh the page and try again.', 'ffcertificate' ) );
		}

		/*
		 * 3. Parse form_id + hash up front so subsequent failures can
		 * be attributed to the right form's audit log. This is a
		 * read-only POST extraction — no DB access, no auth
		 * implications; captcha still runs as a gate before any heavy
		 * work below.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$posted_hash = RequestInput::get_post_string( 'hash' );

		// 4. Honeypot + CAPTCHA.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$security_check = \FreeFormCertificate\Core\SecurityService::validate_security_fields( $_POST );
		if ( true !== $security_check ) {
			if ( $form_id > 0 ) {
				$this->validator->record_download_log_entry( $form_id, 'captcha', '', 'fail_captcha' );
			}
			$this->fail_redirect( (string) $security_check );
		}

		// 5. Form-id / hash presence.
		if ( $form_id <= 0 || '' === $posted_hash ) {
			$this->fail_redirect( __( 'Please inform both the Form ID and the Access Hash.', 'ffcertificate' ) );
		}

		// 6–9. Business-logic validation (hash mismatch, form ended, quota).
		$error = $this->validate_form_access( $form_id, $posted_hash );
		if ( null !== $error ) {
			$this->validator->record_download_log_entry( $form_id, 'access', '', 'fail_other' );
			$this->fail_redirect( $error );
		}

		// 9b. CPF gate (per-form opt-in, no-op when mode = 'none').
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$cpf_input = RequestInput::get_post_string( 'cpf' );
		$cpf_error = $this->validate_cpf_requirement( $form_id, $cpf_input );
		if ( null !== $cpf_error ) {
			$this->fail_redirect( $cpf_error );
		}

		// 10. Increment BEFORE streaming to avoid race conditions under rapid retries.
		$count = (int) get_post_meta( $form_id, self::META_COUNT, true );
		update_post_meta( $form_id, self::META_COUNT, $count + 1 );

		// 10b. Record an explicit delivery row in the audit ring buffer
		// (post-#241). The pre-existing `success` / `audit_pass` /
		// `voluntary` rows are written by the CPF validator and only
		// confirm the CPF gate passed — they're emitted BEFORE the
		// counter increment, and in `none` mode without a volunteered
		// CPF no row is written at all. This `download_delivered` row
		// is the canonical "the operator actually received the file"
		// audit record, and runs in every mode (CPF + anonymous).
		// Captures the CPF digits when provided so the exported audit
		// CSV always identifies who pulled the file.
		$cpf_digits = preg_replace( '/\D/', '', (string) $cpf_input );
		$this->validator->record_download_log_entry(
			$form_id,
			(string) get_post_meta( $form_id, '_ffc_csv_public_cpf_mode', true ),
			is_string( $cpf_digits ) ? $cpf_digits : '',
			'download_delivered'
		);

		// 10c. Mirror the delivery into the global Activity Log (the
		// ring-buffer above is per-form; this gives a site-wide audit trail).
		\FreeFormCertificate\Core\ActivityLog::log(
			'csv_downloaded',
			\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
			array( 'form_id' => $form_id )
		);

		// 11. Stream the CSV. This exits the request.
		$exporter = new PublicCsvExporter();
		$exporter->stream_form_csv( $form_id, 'publish' );
	}

	// ──────────────────────────────────────────────────────────────.
	// AJAX: Form info (intermediate screen).
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Return form metadata for the intermediate preview screen.
	 *
	 * Validates rate-limit, nonce, honeypot/captcha, and hash only
	 * (does NOT require the form to be ended or quota available).
	 */
	public function ajax_info(): void {
		/*
		 * 1. Rate limit.
		 *
		 * See `handle_request()` for why pre-form_id rejections
		 * (rate-limit, nonce) are intentionally not audit-logged.
		 */
		if ( class_exists( RateLimiter::class ) ) {
			$ip         = \FreeFormCertificate\Core\RequestInput::get_user_ip();
			$rate_check = RateLimiter::check_ip_limit( $ip );
			if ( empty( $rate_check['allowed'] ) ) {
				wp_send_json_error( array( 'message' => $rate_check['message'] ?? __( 'Too many requests. Please wait.', 'ffcertificate' ) ) );
			}
		}

		// 2. Nonce.
		if ( ! wp_verify_nonce( RequestInput::get_post_string( '_ffc_pcd_nonce' ), self::NONCE_ACTION ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'ffcertificate' ) ) );
		}

		/*
		 * 3. Sanitize input up front so subsequent failures (captcha,
		 * hash mismatch, etc.) can be attributed to the right form's
		 * audit log.
		 */
		$form_id     = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_hash = RequestInput::get_post_string( 'hash' );

		// 4. Honeypot + CAPTCHA.
		$security_check = \FreeFormCertificate\Core\SecurityService::validate_security_fields( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( true !== $security_check ) {
			if ( $form_id > 0 ) {
				$this->validator->record_download_log_entry( $form_id, 'captcha', '', 'fail_captcha' );
			}
			wp_send_json_error( array( 'message' => (string) $security_check ) );
		}

		// 5. Form-id / hash presence.
		if ( $form_id <= 0 || '' === $posted_hash ) {
			wp_send_json_error( array( 'message' => __( 'Please inform both the Form ID and the Access Hash.', 'ffcertificate' ) ) );
		}

		// 6–7. Hash-only validation (form exists, feature enabled, hash matches).
		$error = $this->validate_hash_only( $form_id, $posted_hash );
		if ( null !== $error ) {
			$this->validator->record_download_log_entry( $form_id, 'access', '', 'fail_other' );
			wp_send_json_error( array( 'message' => $error ) );
		}

		// 7b. CPF gate (per-form opt-in, no-op when mode = 'none').
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$cpf_input = RequestInput::get_post_string( 'cpf' );
		$cpf_error = $this->validate_cpf_requirement( $form_id, $cpf_input );
		if ( null !== $cpf_error ) {
			wp_send_json_error( array( 'message' => $cpf_error ) );
		}

		wp_send_json_success( $this->form_info_builder->build_form_info( $form_id ) );
	}

	// ──────────────────────────────────────────────────────────────.
	// AJAX: Certificate preview.
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Return certificate template data for public preview.
	 *
	 * Only available before the form start date (before collection begins).
	 */
	public function ajax_cert_preview(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! wp_verify_nonce( RequestInput::get_post_string( '_ffc_pcd_nonce' ), self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ffcertificate' ) ) );
		}

		$form_id     = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_hash = RequestInput::get_post_string( 'hash' );

		$error = $this->validate_hash_only( $form_id, $posted_hash );
		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ) );
		}

		// Only available before collection starts.
		$start_ts = Geofence::get_form_start_timestamp( $form_id );
		if ( null === $start_ts || time() >= $start_ts ) {
			wp_send_json_error( array( 'message' => __( 'Certificate preview is only available before the form collection period begins.', 'ffcertificate' ) ) );
		}

		$config = get_post_meta( $form_id, '_ffc_form_config', true );
		$config = is_array( $config ) ? $config : array();
		$fields = get_post_meta( $form_id, '_ffc_form_fields', true );
		$fields = is_array( $fields ) ? $fields : array();

		$field_names = array();
		foreach ( $fields as $field ) {
			if ( ! empty( $field['name'] ) && ! in_array( ( $field['type'] ?? '' ), array( 'info', 'embed' ), true ) ) {
				$field_names[] = array(
					'name'  => sanitize_text_field( $field['name'] ),
					'label' => sanitize_text_field( $field['label'] ?? $field['name'] ),
				);
			}
		}

		wp_send_json_success(
			array(
				'html'           => wp_kses_post( $config['pdf_layout'] ?? '' ),
				'bg_image'       => esc_url( $config['bg_image'] ?? '' ),
				'fields'         => $field_names,
				// Canonical placeholder → sample-value map (single source of
				// truth) so the preview fills system placeholders, not just
				// the form's own fields. Pass $form_id so the {{schedule}}
				// sample matches what the operator configured on the form's
				// Time tab — same lookup the real PDF generator uses.
				'previewSamples' => \FreeFormCertificate\Core\CertificatePreviewSamples::get_map( $form_id ),
			)
		);
	}

	/**
	 * Flip the form's scheduled start datetime to "now".
	 *
	 * Reuses the public CSV hash as the credential — only callable
	 * before the form's scheduled start, and only when CSV public is
	 * enabled (otherwise no hash exists). The action is naturally
	 * one-shot via the form's own state machine: once `date_start` is
	 * now/past, subsequent calls return `already_started` and the JS
	 * never even renders the button.
	 *
	 * @since 6.5.6
	 */
	public function ajax_open_early(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! wp_verify_nonce( RequestInput::get_post_string( '_ffc_pcd_nonce' ), self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ffcertificate' ) ), 403 );
		}

		$form_id     = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_hash = RequestInput::get_post_string( 'hash' );
		$cpf_input   = RequestInput::get_post_string( 'cpf' );

		$audit_meta = array(
			'user_id' => get_current_user_id(),
			'ip'      => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'ua'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);

		// #243 Sprint 6: re-validate CPF here even though it was checked
		// at info-screen time. Closes a small pre-existing security gap
		// where holding the hash alone was enough to invoke the action.
		// `silent_audit = true` so the validator's success row is NOT
		// written — EarlyOpenAction::execute() writes a single
		// `action_early_open` row capturing the same outcome.
		$cpf_error = $this->validator->validate_cpf_requirement( $form_id, $cpf_input, true );
		if ( null !== $cpf_error ) {
			wp_send_json_error( array( 'message' => $cpf_error ), 403 );
		}
		$cpf_digits_clean = preg_replace( '/\D/', '', (string) $cpf_input );
		$cpf_digits_clean = is_string( $cpf_digits_clean ) ? $cpf_digits_clean : '';

		$result = EarlyOpenAction::execute( $form_id, $posted_hash, $audit_meta, $cpf_digits_clean );

		if ( ! $result['ok'] ) {
			// Map the eligibility reason to a localised user-facing
			// message — keeps the EarlyOpenAction service free of UX
			// strings.
			$reason   = $result['reason'];
			$messages = array(
				'unknown_form'        => __( 'Form not found.', 'ffcertificate' ),
				'csv_disabled'        => __( 'Public access is disabled for this form.', 'ffcertificate' ),
				'early_open_disabled' => __( 'Early-start is disabled for this form.', 'ffcertificate' ),
				'bad_hash'            => __( 'Invalid access hash.', 'ffcertificate' ),
				'datetime_disabled'   => __( 'This form does not have a scheduled start time.', 'ffcertificate' ),
				'no_start_date'       => __( 'This form does not have a scheduled start time.', 'ffcertificate' ),
				'not_today'           => __( 'Early-start is only available on the form\'s scheduled start day.', 'ffcertificate' ),
				'already_started'     => __( 'This form has already started.', 'ffcertificate' ),
				'already_ended'       => __( 'This form has already ended.', 'ffcertificate' ),
			);
			$message  = $messages[ $reason ] ?? __( 'Unable to open the form right now.', 'ffcertificate' );
			wp_send_json_error(
				array(
					'reason'  => $reason,
					'message' => $message,
				),
				409
			);
		}

		wp_send_json_success(
			array(
				'message'            => __( 'Form is now open.', 'ffcertificate' ),
				'new_start_iso'      => $result['new_start_iso'],
				'original_start_iso' => $result['original_start_iso'],
			)
		);
	}

	/**
	 * AJAX: postpone the form's `time_end` within the same day.
	 *
	 * Mirrors `ajax_open_early()` in shape — nonce + hash gate, full
	 * eligibility re-check via `ExtendEndAction::execute()`, reason
	 * tags mapped to localised messages. Strictly one-shot per form
	 * via `_ffc_csv_public_end_postponed_at`.
	 *
	 * @since 6.5.12
	 */
	public function ajax_extend_end(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! wp_verify_nonce( RequestInput::get_post_string( '_ffc_pcd_nonce' ), self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ffcertificate' ) ), 403 );
		}

		$form_id      = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_hash  = RequestInput::get_post_string( 'hash' );
		$new_time_end = RequestInput::get_post_string( 'new_time_end' );
		$cpf_input    = RequestInput::get_post_string( 'cpf' );

		$audit_meta = array(
			'user_id' => get_current_user_id(),
			'ip'      => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'ua'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);

		// #243 Sprint 6: re-validate CPF (see parallel comment in
		// ajax_open_early). `silent_audit = true` so only the action's
		// own `action_postpone_close` row is written.
		$cpf_error = $this->validator->validate_cpf_requirement( $form_id, $cpf_input, true );
		if ( null !== $cpf_error ) {
			wp_send_json_error( array( 'message' => $cpf_error ), 403 );
		}
		$cpf_digits_clean = preg_replace( '/\D/', '', (string) $cpf_input );
		$cpf_digits_clean = is_string( $cpf_digits_clean ) ? $cpf_digits_clean : '';

		$result = ExtendEndAction::execute( $form_id, $posted_hash, $new_time_end, $audit_meta, $cpf_digits_clean );

		if ( ! $result['ok'] ) {
			$reason   = $result['reason'];
			$messages = array(
				'unknown_form'        => __( 'Form not found.', 'ffcertificate' ),
				'csv_disabled'        => __( 'Public access is disabled for this form.', 'ffcertificate' ),
				'bad_hash'            => __( 'Invalid access hash.', 'ffcertificate' ),
				'extend_end_disabled' => __( 'Postponing the close is disabled for this form.', 'ffcertificate' ),
				'datetime_disabled'   => __( 'This form does not have a scheduled window.', 'ffcertificate' ),
				'no_end_date'         => __( 'This form does not have a scheduled close.', 'ffcertificate' ),
				'not_today'           => __( 'You can only postpone the close on the form\'s scheduled close day.', 'ffcertificate' ),
				'not_started_yet'     => __( 'The form has not started yet — wait until it opens before postponing the close.', 'ffcertificate' ),
				'already_ended'       => __( 'This form has already ended.', 'ffcertificate' ),
				'already_postponed'   => __( 'This form\'s close has already been postponed once.', 'ffcertificate' ),
				'bad_time_format'     => __( 'Please pick a valid time (HH:MM).', 'ffcertificate' ),
				'not_extending'       => __( 'The new close time must be later than the current one.', 'ffcertificate' ),
				'past_now'            => __( 'The new close time must be in the future.', 'ffcertificate' ),
				'out_of_day'          => __( 'The new close time must stay within the same day.', 'ffcertificate' ),
			);
			$message  = $messages[ $reason ] ?? __( 'Unable to postpone the close right now.', 'ffcertificate' );
			wp_send_json_error(
				array(
					'reason'  => $reason,
					'message' => $message,
				),
				409
			);
		}

		wp_send_json_success(
			array(
				'message'          => __( 'Close time postponed.', 'ffcertificate' ),
				'new_end_iso'      => $result['new_end_iso'],
				'original_end_iso' => $result['original_end_iso'],
			)
		);
	}

	/**
	 * AJAX: stage a per-submission schedule exception (#366 Sprint 4).
	 *
	 * Hand-off shape: posts `form_id`, `hash`, `cpf`, `start_override`,
	 * `end_override` (last two may be empty to mean "leave at baseline").
	 * On success returns `{ token, form_url }` — the JS layer then opens
	 * the form URL in a new tab (user-gesture preserved) where Sprint 5
	 * reads the cookie + embeds the token in the form body.
	 */
	public function ajax_schedule_exception(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! wp_verify_nonce( RequestInput::get_post_string( '_ffc_pcd_nonce' ), self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ffcertificate' ) ), 403 );
		}

		$form_id        = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_hash    = RequestInput::get_post_string( 'hash' );
		$start_override = RequestInput::get_post_string( 'start_override' );
		$end_override   = RequestInput::get_post_string( 'end_override' );
		$cpf_input      = RequestInput::get_post_string( 'cpf' );

		// Mirror ajax_extend_end's CPF re-validation. `silent_audit = true`
		// keeps the per-form audit ring buffer untouched here — the row
		// for this action lands in Sprint 6's submission handler, tagged
		// with the FULL exception context (override values + participant
		// CPF). Recording it now would emit a half-formed entry that the
		// admin Activity Log renderer (Sprint 9) cannot pretty-print.
		$cpf_error = $this->validator->validate_cpf_requirement( $form_id, $cpf_input, true );
		if ( null !== $cpf_error ) {
			wp_send_json_error( array( 'message' => $cpf_error ), 403 );
		}
		$cpf_digits_clean = preg_replace( '/\D/', '', (string) $cpf_input );
		$cpf_digits_clean = is_string( $cpf_digits_clean ) ? $cpf_digits_clean : '';

		$result = ScheduleExceptionAction::execute( $form_id, $posted_hash, $start_override, $end_override, $cpf_digits_clean );

		if ( ! $result['ok'] ) {
			$reason   = $result['reason'];
			$messages = array(
				'unknown_form'                => __( 'Form not found.', 'ffcertificate' ),
				'csv_disabled'                => __( 'Public access is disabled for this form.', 'ffcertificate' ),
				'bad_hash'                    => __( 'Invalid access hash.', 'ffcertificate' ),
				'schedule_exception_disabled' => __( 'Schedule exceptions are disabled for this form.', 'ffcertificate' ),
				'datetime_disabled'           => __( 'This form does not have a scheduled window.', 'ffcertificate' ),
				'no_window'                   => __( 'This form does not have a scheduled start or end.', 'ffcertificate' ),
				'not_started_yet'             => __( 'The form has not started yet.', 'ffcertificate' ),
				'already_ended'               => __( 'This form has already ended.', 'ffcertificate' ),
				'bad_time_format'             => __( 'Please pick valid times (HH:MM).', 'ffcertificate' ),
				'range_inverted'              => __( 'The start time must be earlier than the end time.', 'ffcertificate' ),
				'out_of_window'               => __( 'The override range must stay within the form\'s open window.', 'ffcertificate' ),
				'no_change'                   => __( 'The override is identical to the baseline — nothing to do.', 'ffcertificate' ),
			);
			$message  = $messages[ $reason ] ?? __( 'Unable to create the schedule exception right now.', 'ffcertificate' );
			wp_send_json_error(
				array(
					'reason'  => $reason,
					'message' => $message,
				),
				409
			);
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Schedule exception staged. Open the participant form in the next tab.', 'ffcertificate' ),
				'token'    => $result['token'],
				'form_url' => $result['form_url'],
			)
		);
	}

	// ──────────────────────────────────────────────────────────────.
	// Shared validation forwarders (delegating to CsvDownloadValidator).
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Validate form access for CSV download (steps 5–9).
	 *
	 * Thin forwarder to {@see CsvDownloadValidator::validate_form_access()}.
	 * Kept on the facade because {@see PublicCsvExporter} and unit tests
	 * call it through a `new PublicCsvDownload()` handle.
	 *
	 * @param int    $form_id     Sanitized form ID.
	 * @param string $posted_hash Sanitized access hash.
	 * @return string|null Error message on failure, null on success.
	 */
	public function validate_form_access( int $form_id, string $posted_hash ): ?string {
		return $this->validator->validate_form_access( $form_id, $posted_hash );
	}

	/**
	 * Validate only steps 5–7 (form exists, feature enabled, hash match).
	 *
	 * Thin forwarder to {@see CsvDownloadValidator::validate_hash_only()}.
	 *
	 * @param int    $form_id     Sanitized form ID.
	 * @param string $posted_hash Sanitized access hash.
	 * @return string|null Error message on failure, null on success.
	 */
	public function validate_hash_only( int $form_id, string $posted_hash ): ?string {
		return $this->validator->validate_hash_only( $form_id, $posted_hash );
	}

	/**
	 * Apply the per-form CPF gate.
	 *
	 * Thin forwarder to {@see CsvDownloadValidator::validate_cpf_requirement()}.
	 *
	 * @param int    $form_id   Form post ID.
	 * @param string $cpf_input Raw CPF as posted by the user.
	 * @return string|null Error message on block, null when allowed.
	 */
	public function validate_cpf_requirement( int $form_id, string $cpf_input ): ?string {
		return $this->validator->validate_cpf_requirement( $form_id, $cpf_input );
	}

	// ──────────────────────────────────────────────────────────────.
	// Audit log export.
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Admin-post handler for the audit-log CSV export. Streams
	 * `_ffc_csv_public_download_log` for a single form as a CSV download
	 * with CPFs decrypted on the fly via {@see Encryption::decrypt}.
	 *
	 * Auth: nonce + user must satisfy {@see Capabilities::current_user_can_admin_or}
	 * with `ffc_manage_settings` AND have `edit_post` on the target form.
	 *
	 * @return void Streams CSV and exits; never returns on success.
	 */
	public function handle_export_log_request( ?\FreeFormCertificate\Core\SyncCsvExport $exporter = null ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- form_id sanitized via absint; nonce verified in the source's authorize().
		$form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;

		// The nonce + form + capability gate, the column layout and the
		// decrypting row generator live in the source; the download lifecycle
		// lives in the driver. The `$exporter` param is kept for test injection.
		$exporter = $exporter ?? new \FreeFormCertificate\Core\SyncCsvExport();
		$exporter->handle( new \FreeFormCertificate\Frontend\Csv\CsvDownloadLogExportSource( $form_id ) );
	}

	/**
	 * Public read-only count + URL builder for the metabox button.
	 *
	 * Returns three operator-facing buckets (6.5.13):
	 *
	 *   - `access_success` — captcha + CPF gate both passed (i.e. the
	 *     visitor reached the info / preview screen successfully).
	 *     Computed from the `success` / `audit_pass` / `voluntary` rows
	 *     emitted by `CsvDownloadValidator::validate_cpf_requirement()`;
	 *     by construction those rows imply captcha already passed,
	 *     since captcha runs as an earlier gate.
	 *   - `download_success` — count of CSV files actually delivered
	 *     by `handle_request()`. Sourced from the long-lived
	 *     `_ffc_csv_public_count` counter rather than from the audit
	 *     ring buffer (which only keeps the most recent
	 *     DOWNLOAD_LOG_MAX rows). A single counter survives log
	 *     rotation and never under-counts after the buffer fills.
	 *   - `failed_access` — every `fail_*` row in the ring buffer
	 *     (`fail_missing`, `fail_format`, `fail_match`,
	 *     `fail_unknown_mode`, `fail_captcha`, `fail_other`). Unknown
	 *     future tags fall through to this bucket so a silent
	 *     "success" inflation is impossible.
	 *
	 * The legacy `success` / `fail` keys are **deprecated** (see #730) and
	 * scheduled for removal no earlier than the second feature release after
	 * the announcement — use `access_success` / `download_success` /
	 * `failed_access` instead. They are still returned for now so any
	 * unforeseen external consumer survives the deprecation window. `count`
	 * is NOT deprecated (the metabox reads it) and stays.
	 *
	 * @param int $form_id Form ID.
	 * @return array{count: int, success: int, fail: int, access_success: int, download_success: int, failed_access: int, url: string|null}
	 */
	public static function get_audit_log_summary( int $form_id ): array {
		// Thin public delegator. The implementation lives in
		// {@see CsvDownloadAuditLog::get_summary()} (#589 phase-2,
		// Sprint E3). This method stays put because it is a public API
		// contract (consumed by the form-editor metabox + pinned by
		// PublicCsvDownloadTest); the returned array shape — including the
		// legacy count/success/fail keys — is unchanged.
		return CsvDownloadAuditLog::get_summary( $form_id );
	}

	// ──────────────────────────────────────────────────────────────.
	// Flash messages (transient keyed by IP hash)
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Redirect back to the referring page after saving a flash message.
	 *
	 * Thin wrapper around {@see CsvDownloadFlash::fail_redirect()}.
	 *
	 * @param string $message User-facing error message.
	 * @return never
	 */
	private function fail_redirect( string $message ): void {
		$this->flash->fail_redirect( $message );
	}

	/**
	 * Pull and clear the current visitor's flash message, if any.
	 *
	 * Thin wrapper around {@see CsvDownloadFlash::get_flash_message()}.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function get_flash_message(): ?array {
		return $this->flash->get_flash_message();
	}
}
