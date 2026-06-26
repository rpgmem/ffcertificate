<?php
/**
 * GeofenceGuard — pipeline stage 11 (#563 Sprint 1).
 *
 * Geofence validation (date/time + geolocation), run BEFORE the
 * consolidated rate-limit check. Geofence::can_access_form is read-only
 * (single get_post_meta + optional IP geolocation lookup); the rate-limit
 * block, in contrast, records attempts (writes). Doing the cheaper
 * read-only check first preserves a near-miss visitor's rate-limit budget
 * for their next legitimate retry. The IP throttle (stage 2) already
 * catches the DoS case before geofence — geofence is an authorization
 * gate, not a DoS gate.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Date/time + geolocation authorization gate.
 */
class GeofenceGuard {

	/**
	 * Reject the request when the visitor is outside the geofence.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected When geofence access is denied.
	 */
	public function apply( SubmissionContext $ctx ): void {
		if ( class_exists( '\FreeFormCertificate\Security\Geofence' ) ) {
			// Get form geofence config to check if IP validation is enabled.
			$geofence_config    = \FreeFormCertificate\Security\Geofence::get_form_config( $ctx->form_id );
			$should_validate_ip = false;

			// Backend validation logic:
			// - Always validate datetime (server-side is authoritative)
			// - Only validate IP geolocation if explicitly enabled.
			// - GPS validation happens on the frontend (browser geolocation API).
			if ( $geofence_config && ! empty( $geofence_config['geo_enabled'] ) && ! empty( $geofence_config['geo_ip_enabled'] ) ) {
				$should_validate_ip = true;
			}

			$geofence_check = \FreeFormCertificate\Security\Geofence::can_access_form(
				$ctx->form_id,
				array(
					'check_datetime' => true,                  // Always validate date/time server-side.
					'check_geo'      => $should_validate_ip,   // Only validate IP if explicitly enabled.
				)
			);

			if ( ! $geofence_check['allowed'] ) {
				throw new SubmissionRejected(
					array(
						'message'          => $geofence_check['message'] ?? '',
						'geofence_blocked' => true,
						'reason'           => $geofence_check['reason'] ?? '',
					)
				);
			}
		}
	}
}
