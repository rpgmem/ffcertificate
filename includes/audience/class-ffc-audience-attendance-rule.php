<?php
/**
 * Audience Attendance Rule
 *
 * Whether a person's recorded attendance satisfies a booking's rule (#1947).
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
 * The attendance rule of a booking and the answer to "did this person attend".
 *
 * Two rules: *single*, where one day present is enough, and *total*, where the
 * person must be present on a minimum share of the booking's days. The days are
 * {@see AudienceBookingRules::booked_days()}, so the blocked days a range
 * skipped (#1946) never count against anybody.
 *
 * @since 6.42.0
 */
class AudienceAttendanceRule {

	/**
	 * One day present is enough.
	 *
	 * @var string
	 */
	public const SINGLE = 'single';

	/**
	 * A minimum share of the booking's days.
	 *
	 * @var string
	 */
	public const TOTAL = 'total';

	/**
	 * The share a *total* rule asks for when none is given.
	 *
	 * @var int
	 */
	public const DEFAULT_PERCENT = 75;

	/**
	 * Clamp a rule to one that can be stored.
	 *
	 * An unknown mode reads as *single*; a percentage is clamped to 1–100.
	 *
	 * @param string $mode    Requested mode.
	 * @param int    $percent Requested minimum share.
	 * @return array{mode: string, percent: int}
	 */
	public static function normalize( string $mode, int $percent ): array {
		if ( self::TOTAL !== $mode ) {
			return array(
				'mode'    => self::SINGLE,
				'percent' => 0,
			);
		}

		return array(
			'mode'    => self::TOTAL,
			'percent' => max( 1, min( 100, $percent ) ),
		);
	}

	/**
	 * The rule a stored booking row carries.
	 *
	 * @param object $booking Booking row.
	 * @return array{mode: string, percent: int}
	 */
	public static function of( object $booking ): array {
		$mode    = isset( $booking->attendance_mode ) ? (string) $booking->attendance_mode : '';
		$percent = isset( $booking->attendance_min_percent ) ? (int) $booking->attendance_min_percent : self::DEFAULT_PERCENT;

		return self::normalize( $mode, $percent );
	}

	/**
	 * The days a booking counts attendance on.
	 *
	 * @param object $booking Booking row.
	 * @return list<string> Y-m-d.
	 */
	public static function counted_days( object $booking ): array {
		return AudienceBookingRules::booked_days(
			(string) ( $booking->booking_date ?? '' ),
			isset( $booking->end_date ) ? (string) $booking->end_date : null,
			isset( $booking->skipped_dates ) ? (string) $booking->skipped_dates : null
		);
	}

	/**
	 * How many days present the rule asks for.
	 *
	 * Rounded up, so 75% of 3 days is 3 days, never 2: a threshold read as
	 * "at least" must not be met by a share below it.
	 *
	 * @param object $booking Booking row.
	 * @return int At least 1.
	 */
	public static function required_days( object $booking ): int {
		$rule = self::of( $booking );
		if ( self::TOTAL !== $rule['mode'] ) {
			return 1;
		}

		$days = count( self::counted_days( $booking ) );

		return max( 1, (int) ceil( $days * $rule['percent'] / 100 ) );
	}

	/**
	 * Whether a set of days present satisfies the booking's rule.
	 *
	 * Only days the booking counts are taken into account, so a stray row for
	 * a skipped day cannot tip anybody over the threshold.
	 *
	 * @param object   $booking      Booking row.
	 * @param string[] $present_days Days the person was recorded present (Y-m-d).
	 * @return bool
	 */
	public static function satisfied_by( object $booking, array $present_days ): bool {
		$counted = array_intersect( array_unique( $present_days ), self::counted_days( $booking ) );

		return count( $counted ) >= self::required_days( $booking );
	}

	/**
	 * Whether a person attended a booking, by its rule.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id    User ID.
	 * @return bool False for an unknown or cancelled booking.
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function has_attended( int $booking_id, int $user_id ): bool {
		_deprecated_function( __METHOD__, '6.43.0' );

		$booking = AudienceBookingReader::get_by_id( $booking_id );
		if ( null === $booking || 'cancelled' === ( $booking->status ?? '' ) || $user_id <= 0 ) {
			return false;
		}

		return self::satisfied_by( $booking, AudienceAttendanceReader::days_present( $booking_id, $user_id ) );
	}
}
