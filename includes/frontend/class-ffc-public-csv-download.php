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

	/**
	 * 6.3.3: schema flag for the audit-log payload. Bumped when the
	 * structure of {@see META_DOWNLOAD_LOG} entries changes incompatibly.
	 */
	const DOWNLOAD_LOG_FORMAT = '1.3.0';
	const OPTION_LOG_FORMAT   = 'ffc_csv_public_download_log_format';
	const EXPORT_LOG_ACTION   = 'ffc_export_csv_public_download_log';
	const EXPORT_LOG_NONCE    = 'ffc_export_csv_public_download_log';

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
	 * Wire up the small collaborators that hold the extracted logic.
	 */
	public function __construct() {
		$this->validator         = new CsvDownloadValidator();
		$this->form_info_builder = new CsvDownloadFormInfoBuilder();
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

		// AJAX batched export (JS path).
		$exporter = new PublicCsvExporter();
		add_action( 'wp_ajax_ffc_public_csv_start', array( $exporter, 'ajax_start' ) );
		add_action( 'wp_ajax_nopriv_ffc_public_csv_start', array( $exporter, 'ajax_start' ) );
		add_action( 'wp_ajax_ffc_public_csv_batch', array( $exporter, 'ajax_batch' ) );
		add_action( 'wp_ajax_nopriv_ffc_public_csv_batch', array( $exporter, 'ajax_batch' ) );
		add_action( 'wp_ajax_ffc_public_csv_download', array( $exporter, 'ajax_download' ) );
		add_action( 'wp_ajax_nopriv_ffc_public_csv_download', array( $exporter, 'ajax_download' ) );

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
		$prefill_hash    = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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
		// 1. Rate limit by IP — run BEFORE anything expensive.
		if ( class_exists( RateLimiter::class ) ) {
			$ip         = \FreeFormCertificate\Core\Utils::get_user_ip();
			$rate_check = RateLimiter::check_ip_limit( $ip );
			if ( empty( $rate_check['allowed'] ) ) {
				$this->fail_redirect( $rate_check['message'] ?? __( 'Too many requests. Please wait.', 'ffcertificate' ) );
			}
		}

		// 2. Nonce.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( ! isset( $_POST['_ffc_pcd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ffc_pcd_nonce'] ) ), self::NONCE_ACTION ) ) {
			$this->fail_redirect( __( 'Security check failed. Please refresh the page and try again.', 'ffcertificate' ) );
		}

		// 3. Honeypot + CAPTCHA.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$security_check = \FreeFormCertificate\Core\SecurityService::validate_security_fields( $_POST );
		if ( true !== $security_check ) {
			$this->fail_redirect( (string) $security_check );
		}

		// 4. Form ID + hash input.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$posted_hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';

		if ( $form_id <= 0 || '' === $posted_hash ) {
			$this->fail_redirect( __( 'Please inform both the Form ID and the Access Hash.', 'ffcertificate' ) );
		}

		// 5–9. Business-logic validation.
		$error = $this->validate_form_access( $form_id, $posted_hash );
		if ( null !== $error ) {
			$this->fail_redirect( $error );
		}

		// 9b. CPF gate (per-form opt-in, no-op when mode = 'none').
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$cpf_input = isset( $_POST['cpf'] ) ? sanitize_text_field( wp_unslash( $_POST['cpf'] ) ) : '';
		$cpf_error = $this->validate_cpf_requirement( $form_id, $cpf_input );
		if ( null !== $cpf_error ) {
			$this->fail_redirect( $cpf_error );
		}

		// 10. Increment BEFORE streaming to avoid race conditions under rapid retries.
		$count = (int) get_post_meta( $form_id, self::META_COUNT, true );
		update_post_meta( $form_id, self::META_COUNT, $count + 1 );

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
		// 1. Rate limit.
		if ( class_exists( RateLimiter::class ) ) {
			$ip         = \FreeFormCertificate\Core\Utils::get_user_ip();
			$rate_check = RateLimiter::check_ip_limit( $ip );
			if ( empty( $rate_check['allowed'] ) ) {
				wp_send_json_error( array( 'message' => $rate_check['message'] ?? __( 'Too many requests. Please wait.', 'ffcertificate' ) ) );
			}
		}

		// 2. Nonce.
		if ( ! isset( $_POST['_ffc_pcd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ffc_pcd_nonce'] ) ), self::NONCE_ACTION ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'ffcertificate' ) ) );
		}

		// 3. Honeypot + CAPTCHA.
		$security_check = \FreeFormCertificate\Core\SecurityService::validate_security_fields( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( true !== $security_check ) {
			wp_send_json_error( array( 'message' => (string) $security_check ) );
		}

		// 4. Sanitize input.
		$form_id     = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $form_id <= 0 || '' === $posted_hash ) {
			wp_send_json_error( array( 'message' => __( 'Please inform both the Form ID and the Access Hash.', 'ffcertificate' ) ) );
		}

		// 5–7. Hash-only validation (form exists, feature enabled, hash matches).
		$error = $this->validate_hash_only( $form_id, $posted_hash );
		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ) );
		}

		// 7b. CPF gate (per-form opt-in, no-op when mode = 'none').
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$cpf_input = isset( $_POST['cpf'] ) ? sanitize_text_field( wp_unslash( $_POST['cpf'] ) ) : '';
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
		if ( ! isset( $_POST['_ffc_pcd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ffc_pcd_nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ffcertificate' ) ) );
		}

		$form_id     = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

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
				'html'     => wp_kses_post( $config['pdf_layout'] ?? '' ),
				'bg_image' => esc_url( $config['bg_image'] ?? '' ),
				'fields'   => $field_names,
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
		if ( ! isset( $_POST['_ffc_pcd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ffc_pcd_nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ffcertificate' ) ), 403 );
		}

		$form_id     = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$audit_meta = array(
			'user_id' => get_current_user_id(),
			'ip'      => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'ua'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);

		$result = EarlyOpenAction::execute( $form_id, $posted_hash, $audit_meta );

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
	// Audit log maintenance + export.
	// ──────────────────────────────────────────────────────────────.

	/**
	 * One-shot wipe of pre-6.3.3 audit-log entries.
	 *
	 * Pre-6.3.3 entries used a write-only `cpf_hash` field (sha256 of the
	 * digits) which was never read by any code path — it was kept "just in
	 * case" we ever needed CPF lookups in the log. The 6.3.3 schema replaces
	 * that with a reversible `cpf_encrypted` field consumed by the new
	 * audit-CSV exporter. Mixing the two formats would force the exporter
	 * to render entire columns of "[legacy: hashed only]" placeholders for
	 * stale 6.3.0–6.3.2 entries that no human can ever recover. Since
	 * 6.3.0 → 6.3.2 all shipped within the same 24h window and the install
	 * base for those releases is effectively zero, the cleanest path is to
	 * delete the legacy log meta on first plugins_loaded after the upgrade
	 * and let new entries accumulate fresh.
	 *
	 * Idempotent: gated on the {@see OPTION_LOG_FORMAT} option flag. Runs
	 * exactly once per install no matter how many requests fire it.
	 */
	public static function maybe_wipe_legacy_logs(): void {
		if ( self::DOWNLOAD_LOG_FORMAT === (string) get_option( self::OPTION_LOG_FORMAT, '' ) ) {
			return;
		}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		delete_metadata( 'post', 0, self::META_DOWNLOAD_LOG, '', true );
		update_option( self::OPTION_LOG_FORMAT, self::DOWNLOAD_LOG_FORMAT, true );
	}

	/**
	 * Admin-post handler for the audit-log CSV export. Streams
	 * `_ffc_csv_public_download_log` for a single form as a CSV download
	 * with CPFs decrypted on the fly via {@see Encryption::decrypt}.
	 *
	 * Auth: nonce + user must satisfy {@see Utils::current_user_can_admin_or}
	 * with `ffc_manage_settings` AND have `edit_post` on the target form.
	 *
	 * @return void Streams CSV and exits; never returns on success.
	 */
	public function handle_export_log_request(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce validated below.
		$form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::EXPORT_LOG_NONCE . '_' . $form_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ), 403 );
		}
		if ( $form_id <= 0 || get_post_type( $form_id ) !== 'ffc_form' ) {
			wp_die( esc_html__( 'Form not found.', 'ffcertificate' ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $form_id ) ) {
			wp_die( esc_html__( 'You do not have permission to export this log.', 'ffcertificate' ), 403 );
		}
		$can_audit = class_exists( '\FreeFormCertificate\Core\Utils' )
			? \FreeFormCertificate\Core\Utils::current_user_can_admin_or( 'ffc_manage_settings' )
			: current_user_can( 'manage_options' );
		if ( ! $can_audit ) {
			wp_die( esc_html__( 'You do not have permission to export this log.', 'ffcertificate' ), 403 );
		}

		$log = get_post_meta( $form_id, self::META_DOWNLOAD_LOG, true );
		$log = is_array( $log ) ? $log : array();

		$encryption_ok = class_exists( '\FreeFormCertificate\Core\Encryption' )
			&& \FreeFormCertificate\Core\Encryption::is_configured();

		$filename = 'ffc-csv-download-log-' . $form_id . '-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV download to php://output.
		$fh = fopen( 'php://output', 'w' );
		if ( false === $fh ) {
			wp_die( esc_html__( 'Could not open output stream for CSV export.', 'ffcertificate' ), 500 );
		}

		$writer = \FreeFormCertificate\Core\Csv::writer( $fh );
		if ( ! $encryption_ok ) {
			// One-line preamble so the admin knows why CPFs come out empty.
			$writer->row( array( '# Encryption is not configured on this site; CPF column will be empty for new entries. See plugin docs.' ) );
		}
		$writer->row( array( 'timestamp_utc', 'ip', 'mode', 'cpf', 'result' ) );

		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$ts  = isset( $entry['ts'] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry['ts'] ) : '';
			$ip  = isset( $entry['ip'] ) ? (string) $entry['ip'] : '';
			$mod = isset( $entry['mode'] ) ? (string) $entry['mode'] : '';
			$res = isset( $entry['result'] ) ? (string) $entry['result'] : '';
			$cpf = self::decrypt_log_entry_cpf( $entry );
			$writer->row( array( $ts, $ip, $mod, $cpf, $res ) );
		}
		$writer->close();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output handle this method opened.
		fclose( $fh );
		exit;
	}

	/**
	 * Decrypt a single log entry's CPF for display in the export.
	 *
	 * @param array<string, mixed> $entry Log entry row.
	 * @return string Formatted CPF, '' when blank, or a marker for failures.
	 */
	private static function decrypt_log_entry_cpf( array $entry ): string {
		$cipher = isset( $entry['cpf_encrypted'] ) ? (string) $entry['cpf_encrypted'] : '';
		if ( '' === $cipher ) {
			return '';
		}
		if ( ! class_exists( '\FreeFormCertificate\Core\Encryption' )
			|| ! \FreeFormCertificate\Core\Encryption::is_configured() ) {
			return '[encryption disabled]';
		}
		$plain = \FreeFormCertificate\Core\Encryption::decrypt( $cipher );
		if ( ! is_string( $plain ) || '' === $plain ) {
			return '[decrypt failed]';
		}
		if ( class_exists( '\FreeFormCertificate\Core\DocumentFormatter' ) ) {
			return \FreeFormCertificate\Core\DocumentFormatter::format_cpf( $plain );
		}
		return $plain;
	}

	/**
	 * Public read-only count + URL builder for the metabox button.
	 *
	 * @param int $form_id Form ID.
	 * @return array{count: int, success: int, fail: int, url: string|null}
	 */
	public static function get_audit_log_summary( int $form_id ): array {
		$log   = get_post_meta( $form_id, self::META_DOWNLOAD_LOG, true );
		$log   = is_array( $log ) ? $log : array();
		$count = count( $log );

		// Categorise each entry's `result` tag into success / fail buckets.
		// Success = the download was actually delivered. Failures cover all
		// reasons the gate denied the request before streaming the CSV.
		// `voluntary` is treated as success (it means the visitor passed an
		// optional CPF and the download proceeded). Unknown future tags are
		// counted as failures by default to avoid silently inflating success.
		$success_tags = array( 'success', 'audit_pass', 'voluntary' );
		$success      = 0;
		$fail         = 0;
		foreach ( $log as $entry ) {
			$result = is_array( $entry ) && isset( $entry['result'] ) ? (string) $entry['result'] : '';
			if ( in_array( $result, $success_tags, true ) ) {
				++$success;
			} else {
				++$fail;
			}
		}

		$url = null;
		if ( $count > 0 ) {
			$url = add_query_arg(
				array(
					'action'   => self::EXPORT_LOG_ACTION,
					'form_id'  => $form_id,
					'_wpnonce' => wp_create_nonce( self::EXPORT_LOG_NONCE . '_' . $form_id ),
				),
				admin_url( 'admin-post.php' )
			);
		}
		return array(
			'count'   => $count,
			'success' => $success,
			'fail'    => $fail,
			'url'     => $url,
		);
	}

	// ──────────────────────────────────────────────────────────────.
	// Flash messages (transient keyed by IP hash)
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Build a transient key scoped to the current visitor's IP.
	 */
	private function flash_transient_key(): string {
		$ip = \FreeFormCertificate\Core\Utils::get_user_ip();
		return 'ffc_pcd_flash_' . sha1( $ip );
	}

	/**
	 * Redirect back to the referring page after saving a flash message.
	 *
	 * @param string $message User-facing error message.
	 * @return never
	 */
	private function fail_redirect( string $message ): void {
		set_transient(
			$this->flash_transient_key(),
			array(
				'type'    => 'error',
				'message' => $message,
			),
			self::FLASH_TRANSIENT_TTL
		);

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Pull and clear the current visitor's flash message, if any.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function get_flash_message(): ?array {
		$key  = $this->flash_transient_key();
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['message'] ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'type'    => isset( $data['type'] ) ? (string) $data['type'] : 'error',
			'message' => (string) $data['message'],
		);
	}
}
