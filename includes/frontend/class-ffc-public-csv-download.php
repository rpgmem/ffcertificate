<?php
declare(strict_types=1);

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
 * @since 5.1.0
 */

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Security\Geofence;
use FreeFormCertificate\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PublicCsvDownload {

	const SHORTCODE           = 'ffc_csv_download';
	const ACTION              = 'ffc_public_csv_download';
	const NONCE_ACTION        = 'ffc_public_csv_download';
	const META_ENABLED        = '_ffc_csv_public_enabled';
	const META_HASH           = '_ffc_csv_public_hash';
	const META_LIMIT          = '_ffc_csv_public_limit';
	const META_COUNT          = '_ffc_csv_public_count';
	const FLASH_TRANSIENT_TTL = 60; // Seconds.

	/**
	 * Register shortcode + admin-post + AJAX handlers.
	 */
	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

		// Synchronous fallback (no-JS path).
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_request' ) );

		// AJAX batched export (JS path).
		$exporter = new PublicCsvExporter();
		add_action( 'wp_ajax_ffc_public_csv_start', array( $exporter, 'ajax_start' ) );
		add_action( 'wp_ajax_nopriv_ffc_public_csv_start', array( $exporter, 'ajax_start' ) );
		add_action( 'wp_ajax_ffc_public_csv_batch', array( $exporter, 'ajax_batch' ) );
		add_action( 'wp_ajax_nopriv_ffc_public_csv_batch', array( $exporter, 'ajax_batch' ) );
		add_action( 'wp_ajax_ffc_public_csv_download', array( $exporter, 'ajax_download' ) );
		add_action( 'wp_ajax_nopriv_ffc_public_csv_download', array( $exporter, 'ajax_download' ) );
	}

	// ──────────────────────────────────────────────────────────────
	//  Shortcode rendering
	// ──────────────────────────────────────────────────────────────

	/**
	 * Render the public download form.
	 *
	 * @param array<string, mixed>|string $atts
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

		ob_start();
		?>
		<div class="ffc-verification-container ffc-verification-manual ffc-public-csv-download">
			<div class="ffc-verification-header">
				<h2><?php echo esc_html( $atts['title'] ); ?></h2>
				<p><?php esc_html_e( 'Enter the Form ID and the access hash to download the submissions CSV.', 'ffcertificate' ); ?></p>
			</div>

			<?php if ( $flash ) : ?>
				<div class="ffc-verify-result ffc-pcd-message">
					<div class="<?php echo esc_attr( $flash['type'] === 'error' ? 'ffc-verify-error' : 'ffc-verify-success' ); ?>">
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

				<?php
				// generate_security_fields() emits honeypot + captcha — both are
				// already escaped inside that helper.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<div class="ffc-no-js-security"><?php echo $security_html; ?></div>

				<button type="submit" class="ffc-submit-btn">
					<?php esc_html_e( 'Download CSV', 'ffcertificate' ); ?>
				</button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ──────────────────────────────────────────────────────────────
	//  Request handling (admin-post.php)
	// ──────────────────────────────────────────────────────────────

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
		$security_check = \FreeFormCertificate\Core\Utils::validate_security_fields( $_POST );
		if ( $security_check !== true ) {
			$this->fail_redirect( (string) $security_check );
		}

		// 4. Form ID + hash input.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$posted_hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';

		if ( $form_id <= 0 || $posted_hash === '' ) {
			$this->fail_redirect( __( 'Please inform both the Form ID and the Access Hash.', 'ffcertificate' ) );
		}

		// 5–9. Business-logic validation.
		$error = $this->validate_form_access( $form_id, $posted_hash );
		if ( $error !== null ) {
			$this->fail_redirect( $error );
		}

		// 10. Increment BEFORE streaming to avoid race conditions under rapid retries.
		$count = (int) get_post_meta( $form_id, self::META_COUNT, true );
		update_post_meta( $form_id, self::META_COUNT, $count + 1 );

		// 11. Stream the CSV. This exits the request.
		$exporter = new PublicCsvExporter();
		$exporter->stream_form_csv( $form_id, 'publish' );
	}

	// ──────────────────────────────────────────────────────────────
	//  Shared validation (used by handle_request + PublicCsvExporter)
	// ──────────────────────────────────────────────────────────────

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
		if ( $enabled !== '1' ) {
			return __( 'Public CSV download is not enabled for this form.', 'ffcertificate' );
		}

		// 7. Hash match (constant-time).
		$stored_hash = (string) get_post_meta( $form_id, self::META_HASH, true );
		if ( $stored_hash === '' || ! hash_equals( $stored_hash, $posted_hash ) ) {
			return __( 'Invalid access hash.', 'ffcertificate' );
		}

		// 8. Form must have ended.
		$end_ts = Geofence::get_form_end_timestamp( $form_id );
		if ( $end_ts === null ) {
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

	// ──────────────────────────────────────────────────────────────
	//  Flash messages (transient keyed by IP hash)
	// ──────────────────────────────────────────────────────────────

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
