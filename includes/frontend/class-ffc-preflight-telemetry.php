<?php
/**
 * Pre-flight Telemetry
 *
 * AJAX endpoint that records `ActivityLog` rows for every pre-flight
 * banner the client renders (cookie wall, GPS denied wall, GPS prompt
 * pre-explainer, HTTPS block, the browser step's blocks, the boot
 * watchdog). Lets admins see the volume of visitors hit by each
 * gate from a single screen instead of relying on user reports.
 *
 * @package FreeFormCertificate\Frontend
 * @since   6.6.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Security\RateCounter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint: `wp_ajax_ffc_log_preflight_bail` /
 * `wp_ajax_nopriv_ffc_log_preflight_bail`.
 *
 * Nonce-protected via `ffc_frontend_nonce` (the same nonce the form
 * submit uses, so we get auto-recovery from #356 for free if the
 * cached nonce is stale).
 *
 * @since 6.6.4
 */
class PreflightTelemetry {

	/**
	 * Allowed `reason` values: the pre-flight steps that stop a visitor
	 * (ffc-preflight.js and the geofence steps). `browser_inapp_continue` is
	 * the visitor going on from the in-app block; `browser_boot` is the boot
	 * watchdog taking the form away.
	 */
	public const ALLOWED_REASONS = array(
		'cookies',
		'gps_denied',
		'gps_prompt',
		'secure',
		'browser_inapp',
		'browser_inapp_continue',
		'browser_features',
		'browser_boot',
	);

	/**
	 * What a row is about (#2040): a certificate form, an audience booking's
	 * check-in, a public CSV download page or an appointment receipt. A
	 * request with no type is a form's, which is what every row written
	 * before the types existed is.
	 */
	public const ALLOWED_TYPES = array(
		'form',
		'booking',
		'csv_download',
		'receipt',
	);

	/**
	 * Rows one visitor (IP hash) may write per window (#1861). A real
	 * visitor hits a banner a handful of times; the endpoint is anonymous,
	 * so without a cap a script could fill the activity log.
	 */
	public const MAX_PER_WINDOW = 20;

	/**
	 * Throttle window, in seconds.
	 */
	public const WINDOW_SECONDS = 3600;

	/**
	 * Wire the AJAX hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_ffc_log_preflight_bail', array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_nopriv_ffc_log_preflight_bail', array( $this, 'handle_ajax' ) );
	}

	/**
	 * Handle the AJAX request.
	 */
	public function handle_ajax(): void {
		// Verify nonce — same action as the submit flow, so a stale
		// nonce here triggers the same #356 auto-recover path on
		// the client.
		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'nonce' ), 'ffc_frontend_nonce' ) ) {
			wp_send_json_error(
				array(
					'message'       => __( 'Security check failed.', 'ffcertificate' ),
					'refresh_nonce' => true,
					'new_nonce'     => wp_create_nonce( 'ffc_frontend_nonce' ),
				)
			);
		}

		// A page cached before the types existed posts `form_id` alone.
		$type   = RequestInput::get_post_string( 'type', 'form' );
		$id     = absint( RequestInput::get_post_string( 'id', RequestInput::get_post_string( 'form_id' ) ) );
		$reason = RequestInput::get_post_string( 'reason' );

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			wp_send_json_error( array( 'message' => 'invalid_type' ) );
		}
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => 'invalid_id' ) );
		}
		if ( ! in_array( $reason, self::ALLOWED_REASONS, true ) ) {
			wp_send_json_error( array( 'message' => 'invalid_reason' ) );
		}

		// Don't write the raw IP — hash it so the audit row doesn't
		// hold PII. Admins doing analysis can still group by IP-hash
		// to see "is this the same visitor bouncing 3 times" without
		// learning the IP itself.
		$ip      = RequestInput::get_user_ip();
		$ip_hash = '' !== $ip ? substr( hash( 'sha256', $ip . wp_salt( 'auth' ) ), 0, 12 ) : '';

		// Persistent per-visitor throttle (a transient, so it holds without
		// an object cache). Over the cap the request still succeeds -- the
		// banner is telemetry, nothing the visitor should see fail -- but
		// writes nothing.
		$throttle_key = 'ffc_preflight_log_' . ( '' !== $ip_hash ? $ip_hash : 'unknown' );
		if ( RateCounter::get( $throttle_key ) >= self::MAX_PER_WINDOW ) {
			wp_send_json_success( array( 'logged' => false ) );
		}
		RateCounter::increment( $throttle_key, self::WINDOW_SECONDS );

		// `'info'` matches ActivityLog::LEVEL_INFO. Literal used here
		// rather than the const to keep unit-test mocks simple (Mockery
		// alias mocks don't auto-define class constants).
		ActivityLog::log(
			'preflight_blocked',
			'info',
			self::row_context( $type, $id, $reason, $ip_hash )
		);

		wp_send_json_success( array( 'logged' => true ) );
	}

	/**
	 * The context a row stores. A form's keeps `form_id`, which the form
	 * editor's badges count; every other type names itself, so a form's
	 * badges never count a booking or a download page that shares its ID.
	 *
	 * @param string $type    One of {@see self::ALLOWED_TYPES}.
	 * @param int    $id      The form, booking, page or appointment ID.
	 * @param string $reason  One of {@see self::ALLOWED_REASONS}.
	 * @param string $ip_hash Truncated, salted hash of the visitor's IP.
	 * @return array<string, int|string>
	 */
	public static function row_context( string $type, int $id, string $reason, string $ip_hash ): array {
		$about = 'form' === $type
			? array( 'form_id' => $id )
			: array(
				'type' => $type,
				'id'   => $id,
			);
		return $about + array(
			'reason'  => $reason,
			'ip_hash' => $ip_hash,
		);
	}
}
