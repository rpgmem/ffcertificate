<?php
/**
 * Audience Form Invite Scanner
 *
 * The hourly run that sends post-event invitations once a booking ends (#1948).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds the bookings whose form window is open and invites each eligible
 * participant who has not been invited yet.
 *
 * Hourly because a booking's form opens when its last day ends, which may be
 * any hour. A run reads the bookings whose last day falls inside the longest
 * window a form may stay open; each one is then judged by the same
 * {@see AudiencePostEventForm::eligibility()} the form applies, so a person
 * the form would refuse is never sent a link. A person is invited once: the
 * invitation record is what stops the next run, and a resend is the
 * operator's, from the Attendance screen.
 *
 * @since 6.42.0
 */
class AudienceFormInviteScanner {

	/**
	 * The cron hook.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'ffc_audience_post_event_invites';

	/**
	 * Most invitations one run hands to the mailer; the rest wait an hour.
	 *
	 * @var int
	 */
	public const PER_RUN = 200;

	/**
	 * Schedule the hourly run when it is not scheduled.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the hourly run.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * The cron callback.
	 *
	 * @return void
	 */
	public static function run(): void {
		self::scan( time() );
	}

	/**
	 * Send the invitations due at a moment.
	 *
	 * @param int $now Unix time.
	 * @return int How many were sent.
	 */
	public static function scan( int $now ): int {
		$today = wp_date( 'Y-m-d', $now );
		$from  = wp_date( 'Y-m-d', $now - AudiencePostEventForm::MAX_WINDOW_DAYS * DAY_IN_SECONDS );
		if ( ! is_string( $today ) || ! is_string( $from ) ) {
			return 0;
		}

		$sent = 0;
		foreach ( AudienceBookingReader::ended_between( $from, $today ) as $booking_id ) {
			$booking = AudienceBookingReader::get_by_id( $booking_id );
			if ( null === $booking ) {
				continue;
			}

			$sent += self::invite_booking( $booking, $now, self::PER_RUN - $sent );
			if ( $sent >= self::PER_RUN ) {
				break;
			}
		}

		return $sent;
	}

	/**
	 * Invite a booking's eligible participants who were not invited yet.
	 *
	 * @param object $booking Booking row.
	 * @param int    $now     Unix time.
	 * @param int    $limit   Most to send.
	 * @return int How many were sent.
	 */
	public static function invite_booking( object $booking, int $now, int $limit ): int {
		$booking_id = (int) ( $booking->id ?? 0 );
		$invited    = AudienceFormInviteReader::for_booking( $booking_id );

		$sent = 0;
		foreach ( AudiencePostEventForm::participants( $booking_id ) as $user_id ) {
			if ( $sent >= $limit ) {
				break;
			}
			if ( isset( $invited[ $user_id ] ) ) {
				continue;
			}

			$eligible = AudiencePostEventForm::eligibility( $booking, $user_id, $now );
			if ( $eligible instanceof \WP_Error ) {
				if ( in_array( $eligible->get_error_code(), array( 'no_form', 'not_open', 'closed', 'cancelled' ), true ) ) {
					// A refusal about the booking holds for everyone on it.
					break;
				}
				continue;
			}

			$result = AudienceFormInviteMailer::send( $booking, $user_id, $now );
			if ( true === $result ) {
				++$sent;
			} elseif ( 'no_page' === $result->get_error_code() ) {
				// Nothing to link to until an operator embeds the form.
				break;
			}
		}

		return $sent;
	}
}
