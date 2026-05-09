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
 * @package FreeFormCertificate
 * @since   5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Security\Geofence;
use FreeFormCertificate\Security\GeofenceLocationRegistry;
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
		<div class="ffc-verification-container ffc-verification-manual ffc-public-csv-download">
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

		wp_send_json_success( $this->build_form_info( $form_id ) );
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

	// ──────────────────────────────────────────────────────────────.
	// Shared validation (used by handle_request + PublicCsvExporter)
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Validate form access for CSV download (steps 5–9).
	 *
	 * Checks that the form exists, has the feature enabled, the hash matches,
	 * the form has expired, and the download quota has not been exceeded.
	 *
	 * @param int    $form_id     Sanitized form ID.
	 * @param string $posted_hash Sanitized access hash.
	 * @return string|null Error message on failure, null on success.
	 */
	public function validate_form_access( int $form_id, string $posted_hash ): ?string {
		// 5. Form exists and is a ffc_form.
		if ( get_post_type( $form_id ) !== 'ffc_form' ) {
			return __( 'Form not found.', 'ffcertificate' );
		}

		// 6. Feature enabled on this form.
		$enabled = (string) get_post_meta( $form_id, self::META_ENABLED, true );
		if ( '1' !== $enabled ) {
			return __( 'Public CSV download is not enabled for this form.', 'ffcertificate' );
		}

		// 7. Hash match (constant-time).
		$stored_hash = (string) get_post_meta( $form_id, self::META_HASH, true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $posted_hash ) ) {
			return __( 'Invalid access hash.', 'ffcertificate' );
		}

		// 8. Form must have ended.
		$end_ts = Geofence::get_form_end_timestamp( $form_id );
		if ( null === $end_ts ) {
			return __( 'This form has no end date configured. The administrator must set a Geolocation "End Date" to enable public downloads.', 'ffcertificate' );
		}
		if ( time() <= $end_ts ) {
			return __( 'This form is still active. Downloads are only allowed after the form end date has passed.', 'ffcertificate' );
		}

		// 9. Quota.
		$limit = (int) get_post_meta( $form_id, self::META_LIMIT, true );
		if ( $limit <= 0 ) {
			$settings_default = 0;
			$settings         = get_option( 'ffc_settings', array() );
			if ( is_array( $settings ) && isset( $settings['public_csv_default_limit'] ) ) {
				$settings_default = (int) $settings['public_csv_default_limit'];
			}
			$limit = $settings_default > 0 ? $settings_default : 1;
		}

		$count = (int) get_post_meta( $form_id, self::META_COUNT, true );
		if ( $count >= $limit ) {
			return sprintf(
				/* translators: %d is the configured download limit */
				__( 'The maximum number of downloads (%d) for this form has been reached.', 'ffcertificate' ),
				$limit
			);
		}

		return null;
	}

	/**
	 * Validate only steps 5–7 (form exists, feature enabled, hash match).
	 *
	 * Used by the info endpoint which must succeed even when the form is
	 * still active or the download quota is exhausted.
	 *
	 * @param int    $form_id     Sanitized form ID.
	 * @param string $posted_hash Sanitized access hash.
	 * @return string|null Error message on failure, null on success.
	 */
	public function validate_hash_only( int $form_id, string $posted_hash ): ?string {
		if ( $form_id <= 0 ) {
			return __( 'Form not found.', 'ffcertificate' );
		}
		if ( get_post_type( $form_id ) !== 'ffc_form' ) {
			return __( 'Form not found.', 'ffcertificate' );
		}

		$enabled = (string) get_post_meta( $form_id, self::META_ENABLED, true );
		if ( '1' !== $enabled ) {
			return __( 'Public CSV download is not enabled for this form.', 'ffcertificate' );
		}

		$stored_hash = (string) get_post_meta( $form_id, self::META_HASH, true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $posted_hash ) ) {
			return __( 'Invalid access hash.', 'ffcertificate' );
		}

		return null;
	}

	/**
	 * Apply the per-form CPF gate.
	 *
	 * Modes (configured per form via _ffc_csv_public_cpf_mode):
	 *   - none         : skip everything (legacy behaviour).
	 *   - audit        : require a valid-format CPF, log it, never block.
	 *   - participants : CPF must hash-match a submission of this form.
	 *   - owner        : CPF must match the form author's stored CPF.
	 *   - whitelist    : CPF must be present in _ffc_csv_public_cpf_whitelist.
	 *
	 * Always records a single audit log row (success or failure) when the
	 * mode is anything other than `none`.
	 *
	 * @param int    $form_id   Form post ID.
	 * @param string $cpf_input Raw CPF as posted by the user.
	 * @return string|null Error message on block, null when allowed.
	 */
	public function validate_cpf_requirement( int $form_id, string $cpf_input ): ?string {
		$mode = (string) get_post_meta( $form_id, self::META_CPF_MODE, true );
		if ( '' === $mode ) {
			$mode = 'none';
		}
		if ( 'none' === $mode ) {
			// 6.3.3: even when CPF is not required for this form, if the user
			// volunteered a syntactically valid one, audit it. Useful when the
			// shortcode renders the field for safety (no prefill in URL) and a
			// well-meaning user fills it anyway. Junk input is silently dropped
			// — we don't want garbage rows competing for DOWNLOAD_LOG_MAX slots.
			$voluntary_digits = preg_replace( '/\D/', '', $cpf_input );
			$voluntary_digits = is_string( $voluntary_digits ) ? $voluntary_digits : '';
			if ( '' !== $voluntary_digits
				&& \FreeFormCertificate\Core\DocumentFormatter::validate_cpf( $voluntary_digits ) ) {
				$this->record_download_log_entry( $form_id, 'none', $voluntary_digits, 'voluntary' );
			}
			return null;
		}

		$digits = preg_replace( '/\D/', '', $cpf_input );
		$digits = is_string( $digits ) ? $digits : '';

		// Format gate: we require a syntactically valid 11-digit CPF before
		// touching the database.
		if ( '' === $digits ) {
			$this->record_download_log_entry( $form_id, $mode, '', 'fail_missing' );
			return __( 'CPF is required to download this CSV.', 'ffcertificate' );
		}
		if ( ! \FreeFormCertificate\Core\DocumentFormatter::validate_cpf( $digits ) ) {
			$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_format' );
			return __( 'Invalid CPF.', 'ffcertificate' );
		}

		if ( 'audit' === $mode ) {
			$this->record_download_log_entry( $form_id, $mode, $digits, 'audit_pass' );
			return null;
		}

		if ( 'whitelist' === $mode ) {
			$wl_raw = (string) get_post_meta( $form_id, self::META_CPF_WHITELIST, true );
			$found  = false;
			$lines  = preg_split( '/[\r\n,]+/', $wl_raw );
			$lines  = is_array( $lines ) ? $lines : array();
			foreach ( $lines as $line ) {
				$candidate = preg_replace( '/\D/', '', (string) $line );
				if ( $candidate === $digits ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_match' );
				return __( 'This CPF is not authorized to download this CSV.', 'ffcertificate' );
			}
			$this->record_download_log_entry( $form_id, $mode, $digits, 'success' );
			return null;
		}

		if ( 'owner' === $mode ) {
			$author_id = (int) get_post_field( 'post_author', $form_id );
			if ( $author_id <= 0 ) {
				$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_match' );
				return __( 'Form has no author to validate against.', 'ffcertificate' );
			}
			$author_cpf = (string) get_user_meta( $author_id, 'ffc_user_cpf', true );
			$author_dig = preg_replace( '/\D/', '', $author_cpf );
			if ( ! is_string( $author_dig ) || $author_dig !== $digits ) {
				$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_match' );
				return __( 'CPF does not match the form author.', 'ffcertificate' );
			}
			$this->record_download_log_entry( $form_id, $mode, $digits, 'success' );
			return null;
		}

		if ( 'participants' === $mode ) {
			global $wpdb;
			$encryption_class = '\FreeFormCertificate\Core\Encryption';
			$cpf_hash         = ( class_exists( $encryption_class ) && $encryption_class::is_configured() )
				? $encryption_class::hash( $digits )
				: hash( 'sha256', $digits );
			$table            = $wpdb->prefix . 'ffc_submissions';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_id = %d AND cpf_hash = %s', $table, $form_id, $cpf_hash ) );
			if ( $count <= 0 ) {
				$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_match' );
				return __( 'No submission with this CPF was found for this form.', 'ffcertificate' );
			}
			$this->record_download_log_entry( $form_id, $mode, $digits, 'success' );
			return null;
		}

		// Unknown mode -> fail closed.
		$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_unknown_mode' );
		return __( 'CPF gate misconfigured. Contact the administrator.', 'ffcertificate' );
	}

	/**
	 * Append an entry to the per-form download audit log.
	 *
	 * Stores the latest DOWNLOAD_LOG_MAX rows in a single post meta. CPF
	 * is encrypted at-rest via the plugin's Encryption helper (same pipeline
	 * that protects ffc_submissions.cpf_encrypted) so the form owner can
	 * later decrypt and audit who downloaded the CSV. When Encryption is not
	 * configured on the site, the CPF field falls back to '' and the export
	 * shows a placeholder — admins can configure encryption to retroactively
	 * make new entries auditable.
	 *
	 * Schema (6.3.3): { ts, ip, mode, cpf_encrypted, result }. Pre-6.3.3
	 * entries (which used cpf_hash) are wiped on the first plugins_loaded
	 * after upgrade by maybe_wipe_legacy_logs() — see that method for the
	 * justification (install base reality + clean break).
	 *
	 * @param int    $form_id Form ID.
	 * @param string $mode    CPF gate mode that was active.
	 * @param string $digits  Digits-only CPF (may be '' for fail_missing).
	 * @param string $result  Outcome tag: success | fail_missing |
	 *                        fail_format | fail_match | fail_unknown_mode |
	 *                        audit_pass | voluntary.
	 */
	private function record_download_log_entry( int $form_id, string $mode, string $digits, string $result ): void {
		$cpf_encrypted = '';
		if ( '' !== $digits
			&& class_exists( '\FreeFormCertificate\Core\Encryption' )
			&& \FreeFormCertificate\Core\Encryption::is_configured() ) {
			$encrypted = \FreeFormCertificate\Core\Encryption::encrypt( $digits );
			if ( is_string( $encrypted ) ) {
				$cpf_encrypted = $encrypted;
			}
		}

		$existing   = get_post_meta( $form_id, self::META_DOWNLOAD_LOG, true );
		$existing   = is_array( $existing ) ? $existing : array();
		$existing[] = array(
			'ts'            => time(),
			'ip'            => \FreeFormCertificate\Core\Utils::get_user_ip(),
			'mode'          => $mode,
			'cpf_encrypted' => $cpf_encrypted,
			'result'        => $result,
		);
		if ( count( $existing ) > self::DOWNLOAD_LOG_MAX ) {
			$existing = array_slice( $existing, -self::DOWNLOAD_LOG_MAX );
		}
		update_post_meta( $form_id, self::META_DOWNLOAD_LOG, $existing );
	}

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

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( 'php://output', 'w' );
		if ( false === $fh ) {
			wp_die( esc_html__( 'Could not open output stream for CSV export.', 'ffcertificate' ), 500 );
		}

		// UTF-8 BOM for Excel-friendliness, same convention as PublicCsvExporter.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $fh, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		if ( ! $encryption_ok ) {
			// One-line preamble so the admin knows why CPFs come out empty.
			fputcsv( $fh, array( '# Encryption is not configured on this site; CPF column will be empty for new entries. See plugin docs.' ), ';' );
		}
		fputcsv( $fh, array( 'timestamp_utc', 'ip', 'mode', 'cpf', 'result' ), ';' );

		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$ts  = isset( $entry['ts'] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry['ts'] ) : '';
			$ip  = isset( $entry['ip'] ) ? (string) $entry['ip'] : '';
			$mod = isset( $entry['mode'] ) ? (string) $entry['mode'] : '';
			$res = isset( $entry['result'] ) ? (string) $entry['result'] : '';
			$cpf = self::decrypt_log_entry_cpf( $entry );
			fputcsv( $fh, array( $ts, $ip, $mod, $cpf, $res ), ';' );
		}
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
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
	 * @return array{count: int, url: string|null}
	 */
	public static function get_audit_log_summary( int $form_id ): array {
		$log   = get_post_meta( $form_id, self::META_DOWNLOAD_LOG, true );
		$count = is_array( $log ) ? count( $log ) : 0;
		$url   = null;
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
			'count' => $count,
			'url'   => $url,
		);
	}

	// ──────────────────────────────────────────────────────────────.
	// Form info builder (intermediate screen data).
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Build the form metadata payload for the intermediate preview screen.
	 *
	 * @param int $form_id Validated form ID.
	 * @return array<string, mixed>
	 */
	private function build_form_info( int $form_id ): array {
		$form_config     = get_post_meta( $form_id, '_ffc_form_config', true );
		$form_config     = is_array( $form_config ) ? $form_config : array();
		$geofence_config = get_post_meta( $form_id, '_ffc_geofence_config', true );
		$geofence_config = is_array( $geofence_config ) ? $geofence_config : array();

		$now      = time();
		$start_ts = Geofence::get_form_start_timestamp( $form_id );
		$end_ts   = Geofence::get_form_end_timestamp( $form_id );

		$before_start = null !== $start_ts && $now < $start_ts;
		$form_ended   = null !== $end_ts && $now > $end_ts;
		$has_end_date = null !== $end_ts;

		// Quota.
		$limit = (int) get_post_meta( $form_id, self::META_LIMIT, true );
		if ( $limit <= 0 ) {
			$settings = get_option( 'ffc_settings', array() );
			$default  = is_array( $settings ) && isset( $settings['public_csv_default_limit'] )
				? (int) $settings['public_csv_default_limit']
				: 0;
			$limit    = $default > 0 ? $default : 1;
		}
		$count           = (int) get_post_meta( $form_id, self::META_COUNT, true );
		$quota_exhausted = $count >= $limit;

		// Download blocked reason.
		$download_reason = null;
		if ( ! $has_end_date ) {
			$download_reason = 'no_end_date';
		} elseif ( ! $form_ended ) {
			$download_reason = 'active';
		} elseif ( $quota_exhausted ) {
			$download_reason = 'quota_exhausted';
		}

		// Submission count.
		$repo             = new \FreeFormCertificate\Repositories\SubmissionRepository();
		$submission_count = $repo->countForExport( array( $form_id ), 'publish' );

		$tz = wp_timezone();

		return array(
			'form_title'       => get_the_title( $form_id ),
			'submission_count' => $submission_count,
			'restrictions'     => $this->build_restrictions_info( $form_config ),
			'datetime'         => $this->build_datetime_info( $geofence_config, $tz ),
			'geolocation'      => $this->build_geolocation_info( $geofence_config ),
			'quiz'             => $this->build_quiz_info( $form_config ),
			'csv'              => array(
				'limit'     => $limit,
				'count'     => $count,
				'remaining' => max( 0, $limit - $count ),
			),
			'status'           => array(
				'has_start_date'          => null !== $start_ts,
				'has_end_date'            => $has_end_date,
				'before_start'            => $before_start,
				'form_ended'              => $form_ended,
				'can_download'            => $form_ended && ! $quota_exhausted,
				'can_preview_cert'        => $before_start,
				'download_blocked_reason' => $download_reason,
				'start_date_formatted'    => null !== $start_ts
					? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start_ts, $tz )
					: null,
				'end_date_formatted'      => null !== $end_ts
					? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $end_ts, $tz )
					: null,
			),
		);
	}

	/**
	 * Build access restrictions data for the info response.
	 *
	 * @param array<string, mixed> $config Form config.
	 * @return array<string, bool>
	 */
	private function build_restrictions_info( array $config ): array {
		$restrictions = $config['restrictions'] ?? array();
		$result       = array();

		if ( ! empty( $restrictions['password'] ) && '1' === (string) $restrictions['password'] ) {
			$result['password'] = true;
		}
		if ( ! empty( $restrictions['allowlist'] ) && '1' === (string) $restrictions['allowlist'] ) {
			$result['allowlist'] = true;
		}
		if ( ! empty( $restrictions['denylist'] ) && '1' === (string) $restrictions['denylist'] ) {
			$result['denylist'] = true;
		}
		if ( ! empty( $restrictions['ticket'] ) && '1' === (string) $restrictions['ticket'] ) {
			$result['ticket'] = true;
		}

		return $result;
	}

	/**
	 * Build date/time availability data for the info response.
	 *
	 * @param array<string, mixed> $config   Geofence config.
	 * @param \DateTimeZone        $tz       Site timezone.
	 * @return array<string, mixed>
	 */
	private function build_datetime_info( array $config, \DateTimeZone $tz ): array {
		$date_start = isset( $config['date_start'] ) ? trim( (string) $config['date_start'] ) : '';
		$date_end   = isset( $config['date_end'] ) ? trim( (string) $config['date_end'] ) : '';
		$time_start = isset( $config['time_start'] ) ? trim( (string) $config['time_start'] ) : '';
		$time_end   = isset( $config['time_end'] ) ? trim( (string) $config['time_end'] ) : '';
		$time_mode  = isset( $config['time_mode'] ) ? (string) $config['time_mode'] : 'daily';

		$has_dates = '' !== $date_start || '' !== $date_end;
		$has_times = '' !== $time_start || '' !== $time_end;

		$date_format = get_option( 'date_format' );

		// Anchor each date in the site timezone before formatting. Naive
		// strtotime() reads "Y-m-d" as PHP-process-local (typically UTC)
		// midnight, which then drifts to the previous day after wp_date()
		// converts to a westward TZ like America/Sao_Paulo — manifesting
		// as "Data de início: 11/05/2026" for a configured 2026-05-12. The
		// footer status message uses Geofence::get_form_*_timestamp() and
		// is unaffected; this brings the body in line with the same TZ
		// anchoring approach.
		$to_local_ts = static function ( string $date, \DateTimeZone $tz ): ?int {
			if ( '' === $date ) {
				return null;
			}
			try {
				return ( new \DateTimeImmutable( $date, $tz ) )->getTimestamp();
			} catch ( \Exception $e ) {
				return null;
			}
		};
		$start_ts    = $to_local_ts( $date_start, $tz );
		$end_ts      = $to_local_ts( $date_end, $tz );

		return array(
			'has_dates'      => $has_dates,
			'date_start'     => null !== $start_ts ? wp_date( $date_format, $start_ts, $tz ) : null,
			'date_start_raw' => '' !== $date_start ? $date_start : null,
			'date_end'       => null !== $end_ts ? wp_date( $date_format, $end_ts, $tz ) : null,
			'date_end_raw'   => '' !== $date_end ? $date_end : null,
			'has_times'      => $has_times,
			'time_start'     => '' !== $time_start ? $time_start : null,
			'time_end'       => '' !== $time_end ? $time_end : null,
			'time_mode'      => $time_mode,
		);
	}

	/**
	 * Build geolocation data for the info response.
	 *
	 * @param array<string, mixed> $config Geofence config.
	 * @return array<string, mixed>
	 */
	private function build_geolocation_info( array $config ): array {
		$geo_enabled = ! empty( $config['geo_enabled'] );
		if ( ! $geo_enabled ) {
			return array( 'enabled' => false );
		}

		$gps_enabled = ! empty( $config['geo_gps_enabled'] );
		$ip_enabled  = ! empty( $config['geo_ip_enabled'] );

		$result = array(
			'enabled'     => true,
			'gps_enabled' => $gps_enabled,
			'ip_enabled'  => $ip_enabled,
		);

		// GPS locations.
		if ( $gps_enabled ) {
			$gps_source = $config['geo_area_source'] ?? 'locations';
			if ( 'locations' === $gps_source && ! empty( $config['geo_area_location_ids'] ) ) {
				$locations               = GeofenceLocationRegistry::get_by_ids( (array) $config['geo_area_location_ids'] );
				$result['gps_locations'] = $this->format_locations_for_info( $locations );
			} else {
				$result['gps_custom'] = true;
			}
		}

		// IP locations (only when separate areas are configured).
		if ( $ip_enabled && ! empty( $config['geo_ip_areas_permissive'] ) ) {
			$ip_source = $config['geo_ip_area_source'] ?? 'locations';
			if ( 'locations' === $ip_source && ! empty( $config['geo_ip_area_location_ids'] ) ) {
				$locations              = GeofenceLocationRegistry::get_by_ids( (array) $config['geo_ip_area_location_ids'] );
				$result['ip_locations'] = $this->format_locations_for_info( $locations );
			} else {
				$result['ip_custom'] = true;
			}
		}

		return $result;
	}

	/**
	 * Format registered locations for the info response.
	 *
	 * @param array<int, array<string, mixed>> $locations Raw location data.
	 * @return list<array<string, float|string>>
	 */
	private function format_locations_for_info( array $locations ): array {
		$formatted = array();
		foreach ( $locations as $loc ) {
			$formatted[] = array(
				'name'     => sanitize_text_field( $loc['name'] ?? '' ),
				'lat'      => (float) ( $loc['lat'] ?? 0 ),
				'lng'      => (float) ( $loc['lng'] ?? 0 ),
				'radius'   => (float) ( $loc['radius'] ?? 0 ),
				'maps_url' => 'https://www.google.com/maps/search/?api=1&query=' . (float) $loc['lat'] . ',' . (float) $loc['lng'],
			);
		}
		return $formatted;
	}

	/**
	 * Build quiz/evaluation data for the info response.
	 *
	 * @param array<string, mixed> $config Form config.
	 * @return array<string, mixed>
	 */
	private function build_quiz_info( array $config ): array {
		$enabled = ! empty( $config['quiz_enabled'] ) && '1' === (string) $config['quiz_enabled'];
		if ( ! $enabled ) {
			return array( 'enabled' => false );
		}

		return array(
			'enabled'       => true,
			'passing_score' => (int) ( $config['quiz_passing_score'] ?? 0 ),
			'max_attempts'  => (int) ( $config['quiz_max_attempts'] ?? 0 ),
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
