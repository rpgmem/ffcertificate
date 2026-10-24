<?php
/**
 * GeofenceGuard — pipeline stage 11 (#563 Sprint 1).
 *
 * Geofence validation (date/time + geolocation), run BEFORE the
 * consolidated rate-limit check. Geofence::check_submission is read-only
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
		if ( ! class_exists( '\FreeFormCertificate\Security\Geofence' ) ) {
			return;
		}

		// The server decides the date window, the GPS reading the browser
		// sent and the IP, combined by the form's "GPS or IP" setting
		// (#2040). The browser's own check only decides what it shows.
		$geofence_check = \FreeFormCertificate\Security\Geofence::check_submission(
			$ctx->form_id,
			\FreeFormCertificate\Security\GpsReading::from_request()
		);

		if ( ! $geofence_check['allowed'] ) {
			throw new SubmissionRejected(
				array(
					'message'          => $geofence_check['message'],
					'geofence_blocked' => true,
					'reason'           => $geofence_check['reason'],
				)
			);
		}
	}
}
