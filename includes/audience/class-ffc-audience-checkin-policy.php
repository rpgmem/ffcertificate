<?php
/**
 * Audience Check-in Policy
 *
 * Who may check in at an event and by which paths (#2748).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads an event's check-in switches off its booking row.
 *
 * Every path asks here, so the projected QR code, the terminal and the
 * pairing agree on what an event allows. A row read before the columns
 * existed answers as every event did then: enrolled only, both paths on.
 */
final class AudienceCheckinPolicy {

	/**
	 * The booking columns an operator may switch, as the Check-in tab saves them.
	 *
	 * @var list<string>
	 */
	public const FIELDS = array( 'checkin_open', 'checkin_qr', 'checkin_terminal' );

	/**
	 * Minutes a window opens before each day starts, when an event sets none.
	 *
	 * @var int
	 */
	public const OPENS_BEFORE = 0;

	/**
	 * Minutes a window closes after each day ends, when an event sets none.
	 *
	 * @var int
	 */
	public const CLOSES_AFTER = 30;

	/**
	 * The furthest either end of a window may sit from the day's hours, in
	 * minutes: twelve hours before the start or after the end.
	 *
	 * @var int
	 */
	public const OFFSET_MAX = 720;

	/**
	 * Minutes the event's check-in window opens before each day starts. A
	 * negative value opens it after the start.
	 *
	 * @param object $booking Booking row.
	 * @return int
	 */
	public static function opens_before( object $booking ): int {
		$value = $booking->checkin_opens_before ?? null;
		return null === $value || '' === $value ? self::OPENS_BEFORE : self::clamp_offset( (int) $value );
	}

	/**
	 * Minutes the event's check-in window closes after each day ends. A
	 * negative value closes it before the end.
	 *
	 * @param object $booking Booking row.
	 * @return int
	 */
	public static function closes_after( object $booking ): int {
		$value = $booking->checkin_closes_after ?? null;
		return null === $value || '' === $value ? self::CLOSES_AFTER : self::clamp_offset( (int) $value );
	}

	/**
	 * An offset held inside the bounds the screen offers.
	 *
	 * @param int $minutes Offset.
	 * @return int
	 */
	public static function clamp_offset( int $minutes ): int {
		return max( -self::OFFSET_MAX, min( self::OFFSET_MAX, $minutes ) );
	}

	/**
	 * Whether anyone signed in may check in, enrolled or not. Attendance is
	 * then recorded without enrolling the person, so it counts toward no
	 * attendance rule and earns no post-event invitation.
	 *
	 * @param object $booking Booking row.
	 * @return bool
	 */
	public static function is_open( object $booking ): bool {
		return 1 === (int) ( $booking->checkin_open ?? 0 );
	}

	/**
	 * Whether the event takes check-in by the projected QR code.
	 *
	 * @param object $booking Booking row.
	 * @return bool
	 */
	public static function qr_allowed( object $booking ): bool {
		return 1 === (int) ( $booking->checkin_qr ?? 1 );
	}

	/**
	 * Whether the event takes check-in by a terminal.
	 *
	 * @param object $booking Booking row.
	 * @return bool
	 */
	public static function terminal_allowed( object $booking ): bool {
		return 1 === (int) ( $booking->checkin_terminal ?? 1 );
	}

	/**
	 * Whether a person may check in at the event: anyone, when it is open;
	 * otherwise only someone the booking expects.
	 *
	 * @param object $booking Booking row.
	 * @param int    $user_id The person.
	 * @return bool
	 */
	public static function may_check_in( object $booking, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( self::is_open( $booking ) ) {
			return true;
		}
		return in_array( $user_id, array_map( 'intval', AudienceBookingReader::get_all_affected_users( (int) ( $booking->id ?? 0 ) ) ), true );
	}
}
