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
	const FLASH_TRANSIENT_TTL = 60; // Seconds.

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
		$security_check = \FreeFormCertificate\Core\Utils::validate_security_fields( $_POST );
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
		$security_check = \FreeFormCertificate\Core\Utils::validate_security_fields( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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

		return array(
			'has_dates'      => $has_dates,
			'date_start'     => '' !== $date_start ? wp_date( $date_format, (int) strtotime( $date_start ), $tz ) : null,
			'date_start_raw' => '' !== $date_start ? $date_start : null,
			'date_end'       => '' !== $date_end ? wp_date( $date_format, (int) strtotime( $date_end ), $tz ) : null,
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
