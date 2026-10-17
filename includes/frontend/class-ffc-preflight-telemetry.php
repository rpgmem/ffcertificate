<?php
/**
 * Pre-flight Telemetry
 *
 * AJAX endpoint that records `ActivityLog` rows for every pre-flight
 * banner the client renders (cookie wall, GPS denied wall, GPS prompt
 * pre-explainer). Lets admins see the volume of visitors hit by each
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
	 * Allowed `reason` values. Mirrors the three pre-flight banners in
	 * ffc-geofence-frontend.js: cookie probe, GPS denied, GPS prompt.
	 */
	private const ALLOWED_REASONS = array(
		'cookies',
		'gps_denied',
		'gps_prompt',
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

		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$reason  = RequestInput::get_post_string( 'reason' );

		if ( $form_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'invalid_form_id' ) );
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
			array(
				'form_id' => $form_id,
				'reason'  => $reason,
				'ip_hash' => $ip_hash,
			)
		);

		wp_send_json_success( array( 'logged' => true ) );
	}
}
