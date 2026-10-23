<?php
/**
 * Audience Post-Event Pending
 *
 * The post-event form a participant can still fill in for a booking, for
 * their dashboard (#2028).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\DateFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers, for one booking and one person, whether a post-event form waits
 * for them, and with which link.
 *
 * The invitation e-mail is one way to the form; this is the other, for a
 * participant whose e-mail was lost. It asks the same
 * {@see AudiencePostEventForm::eligibility()} the form applies, so the
 * dashboard never offers a form that would refuse the person, and it builds
 * the same personal link {@see AudienceFormInviteMailer::link()} sends.
 *
 * @since 6.42.0
 */
class AudiencePostEventPending {

	/**
	 * The form waiting for a person on a booking.
	 *
	 * The window is checked first, from the booking alone, so a booking whose
	 * form is not open costs no query on its participants or its answers.
	 *
	 * @param object $booking Booking row.
	 * @param int    $user_id User ID.
	 * @param int    $now     Unix time.
	 * @param bool   $with_link Whether to build the person's link; false when
	 *                          someone else is looking ("view as user").
	 * @return array{form_title: string, url: string, closes_at: int, closes_label: string}|null
	 *         Null when no form waits for them.
	 */
	public static function for_user( object $booking, int $user_id, int $now, bool $with_link = true ): ?array {
		if ( 'cancelled' === ( $booking->status ?? '' ) ) {
			return null;
		}

		$settings = AudiencePostEventForm::settings( $booking );
		if ( ! AudiencePostEventForm::is_form( $settings['form_id'] ) ) {
			return null;
		}

		$opens  = AudiencePostEventForm::opens_at( $booking );
		$closes = AudiencePostEventForm::closes_at( $booking, $settings['window_days'] );
		if ( 0 === $opens || $now < $opens || $now >= $closes ) {
			return null;
		}

		if ( true !== AudiencePostEventForm::eligibility( $booking, $user_id, $now ) ) {
			return null;
		}

		$booking_id = (int) ( $booking->id ?? 0 );
		if ( isset( AudienceFormInviteReader::answers( $booking_id )[ $user_id ] ) ) {
			return null;
		}

		// Without a page there is nowhere to send the person; the operator
		// is warned on the Attendance screen (#2024).
		$url = AudienceFormInviteMailer::link( $booking_id, $user_id, $settings['form_id'] );
		if ( '' === $url ) {
			return null;
		}

		return array(
			'form_title'   => get_the_title( $settings['form_id'] ),
			'url'          => $with_link ? $url : '',
			'closes_at'    => $closes,
			'closes_label' => DateFormatter::format_datetime( $closes ),
		);
	}
}
