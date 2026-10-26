<?php
/**
 * Audience Post-Event Form
 *
 * The certificate form a booking leads its participants to (#1948).
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
 * Resolves a booking's post-event form and answers who may fill it in.
 *
 * A calendar names the form, how many days it stays open after the event and
 * whether only those present may answer. A booking follows its calendar until
 * its own settings are switched on (`post_event_custom`), and then reads its
 * own three columns, where no form means none for that booking.
 *
 * The window opens when the booking's last day ends, in the site's timezone,
 * and closes the given number of days later. The form is a bridge, not an
 * evaluation module: the questions stay ordinary fields of a certificate form.
 *
 * @since 6.42.0
 */
class AudiencePostEventForm {

	/**
	 * Days the form stays open when none are given.
	 *
	 * @var int
	 */
	public const DEFAULT_WINDOW_DAYS = 7;

	/**
	 * Longest window an operator may choose, in days.
	 *
	 * @var int
	 */
	public const MAX_WINDOW_DAYS = 90;

	/**
	 * A window an operator may choose: 1 to {@see self::MAX_WINDOW_DAYS} days.
	 *
	 * @param int $days Requested days.
	 * @return int
	 */
	public static function normalize_window( int $days ): int {
		return max( 1, min( self::MAX_WINDOW_DAYS, $days ) );
	}

	/**
	 * Whether a post ID names a published certificate form.
	 *
	 * @param int $form_id Post ID.
	 * @return bool
	 */
	public static function is_form( int $form_id ): bool {
		if ( $form_id <= 0 ) {
			return false;
		}

		$post = get_post( $form_id );

		return $post instanceof \WP_Post && 'ffc_form' === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * The settings a calendar row carries.
	 *
	 * @param object|null $schedule Calendar row.
	 * @return array{form_id: int, window_days: int, attendees_only: bool}
	 */
	public static function of_schedule( ?object $schedule ): array {
		return self::shape(
			(int) ( $schedule->post_event_form_id ?? 0 ),
			isset( $schedule->post_event_window_days ) ? (int) $schedule->post_event_window_days : self::DEFAULT_WINDOW_DAYS,
			! empty( $schedule->post_event_attendees_only )
		);
	}

	/**
	 * Whether a booking reads its own settings instead of its calendar's.
	 *
	 * @param object $booking Booking row.
	 * @return bool
	 */
	public static function is_custom( object $booking ): bool {
		return ! empty( $booking->post_event_custom );
	}

	/**
	 * The settings that apply to a booking: its own when switched on, its
	 * calendar's otherwise.
	 *
	 * @param object      $booking  Booking row.
	 * @param object|null $schedule The booking's calendar row, when the caller
	 *                              already holds it; read otherwise.
	 * @return array{form_id: int, window_days: int, attendees_only: bool}
	 */
	public static function settings( object $booking, ?object $schedule = null ): array {
		if ( self::is_custom( $booking ) ) {
			return self::shape(
				(int) ( $booking->post_event_form_id ?? 0 ),
				isset( $booking->post_event_window_days ) ? (int) $booking->post_event_window_days : self::DEFAULT_WINDOW_DAYS,
				! empty( $booking->post_event_attendees_only )
			);
		}

		if ( null === $schedule ) {
			$schedule_id = (int) ( $booking->schedule_id ?? 0 );
			$schedule    = $schedule_id > 0 ? AudienceScheduleRepository::get_by_id( $schedule_id ) : null;
		}

		return self::of_schedule( $schedule );
	}

	/**
	 * When a booking's form opens: the end of its last counted day, in the
	 * site's timezone. An all-day booking ends at 23:59.
	 *
	 * @param object $booking Booking row.
	 * @return int Unix time; 0 when the booking has no day.
	 */
	public static function opens_at( object $booking ): int {
		$days = AudienceAttendanceRule::counted_days( $booking );
		if ( array() === $days ) {
			return 0;
		}

		$last = $days[ count( $days ) - 1 ];
		$end  = ! empty( $booking->is_all_day ) ? '23:59' : AudienceBookingRules::stored_day_times( $booking, $last )['end_time'];
		if ( ! preg_match( '/^\d{2}:\d{2}$/', $end ) ) {
			$end = '23:59';
		}

		$moment = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $last . ' ' . $end, wp_timezone() );

		return false === $moment ? 0 : $moment->getTimestamp();
	}

	/**
	 * When a booking's form closes.
	 *
	 * @param object $booking     Booking row.
	 * @param int    $window_days Days the form stays open.
	 * @return int Unix time; 0 when the booking has no day.
	 */
	public static function closes_at( object $booking, int $window_days ): int {
		$opens = self::opens_at( $booking );

		return 0 === $opens ? 0 : $opens + self::normalize_window( $window_days ) * DAY_IN_SECONDS;
	}

	/**
	 * Whether a person may fill in a booking's post-event form now.
	 *
	 * The checks run from the booking outwards: a form must apply, the
	 * booking must stand, the person must be on it, the window must be open,
	 * and under *attendees only* the booking's attendance rule must be met.
	 *
	 * @param object $booking Booking row, as {@see AudienceBookingReader::get_by_id()} returns it.
	 * @param int    $user_id User ID.
	 * @param int    $now     Unix time.
	 * @return true|\WP_Error An error whose code says which check failed.
	 */
	public static function eligibility( object $booking, int $user_id, int $now ) {
		$settings = self::settings( $booking );
		if ( ! self::is_form( $settings['form_id'] ) ) {
			return self::error( 'no_form' );
		}

		if ( 'cancelled' === ( $booking->status ?? '' ) ) {
			return self::error( 'cancelled' );
		}

		$booking_id = (int) ( $booking->id ?? 0 );
		if ( $user_id <= 0 || ! in_array( $user_id, self::participants( $booking_id ), true ) ) {
			return self::error( 'not_participant' );
		}

		$opens = self::opens_at( $booking );
		if ( 0 === $opens || $now < $opens ) {
			return self::error( 'not_open' );
		}

		if ( $now >= self::closes_at( $booking, $settings['window_days'] ) ) {
			return self::error( 'closed' );
		}

		if ( $settings['attendees_only'] && ! AudienceAttendanceRule::satisfied_by( $booking, AudienceAttendanceReader::days_present( $booking_id, $user_id ) ) ) {
			return self::error( 'absent' );
		}

		return true;
	}

	/**
	 * The people on a booking: its users and its audiences' members.
	 *
	 * @param int $booking_id Booking ID.
	 * @return list<int>
	 */
	public static function participants( int $booking_id ): array {
		return array_values( array_unique( array_map( 'intval', AudienceBookingReader::get_all_affected_users( $booking_id ) ) ) );
	}

	/**
	 * What to tell a person an eligibility check refused.
	 *
	 * @param string $code Error code from {@see self::eligibility()}.
	 * @return string
	 */
	public static function message( string $code ): string {
		switch ( $code ) {
			case 'not_participant':
				return __( 'This invitation is not for a participant of this event.', 'ffcertificate' );
			case 'not_open':
				return __( 'This form opens when the event ends.', 'ffcertificate' );
			case 'closed':
				return __( 'The period to fill in this form has ended.', 'ffcertificate' );
			case 'absent':
				return __( 'This form is only for people whose attendance at the event was recorded.', 'ffcertificate' );
			case 'cancelled':
				return __( 'This event was cancelled.', 'ffcertificate' );
			default:
				return __( 'This event has no form to fill in.', 'ffcertificate' );
		}
	}

	/**
	 * Clamp stored settings to ones that can be used.
	 *
	 * @param int  $form_id        Form post ID, 0 for none.
	 * @param int  $window_days    Days the form stays open.
	 * @param bool $attendees_only Whether only those present may answer.
	 * @return array{form_id: int, window_days: int, attendees_only: bool}
	 */
	private static function shape( int $form_id, int $window_days, bool $attendees_only ): array {
		return array(
			'form_id'        => max( 0, $form_id ),
			'window_days'    => self::normalize_window( $window_days ),
			'attendees_only' => $attendees_only,
		);
	}

	/**
	 * An eligibility error.
	 *
	 * @param string $code Error code.
	 * @return \WP_Error
	 */
	private static function error( string $code ): \WP_Error {
		return new \WP_Error( $code, self::message( $code ) );
	}
}
