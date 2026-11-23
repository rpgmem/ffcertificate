<?php
/**
 * Audience Attendance Target
 *
 * The audience booking as an attendance target (#2740): what
 * {@see \FreeFormCertificate\Core\Attendance::record()} calls for the type
 * `audience_booking`, so a caller outside this module — the self-service
 * terminal — records attendance without knowing the audience tables.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.46.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\AttendanceTargetInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records attendance at an audience booking.
 */
final class AudienceAttendanceTarget implements AttendanceTargetInterface {

	/**
	 * Target type id.
	 *
	 * @var string
	 */
	public const TYPE = 'audience_booking';

	/**
	 * Target type id.
	 *
	 * @return string
	 */
	public function type(): string {
		return self::TYPE;
	}

	/**
	 * Record a person present at a booking, on the site-local day of the
	 * reading.
	 *
	 * The day comes from the reading's own time in the site's timezone, so a
	 * reading synced after midnight still lands on the day it was taken. The
	 * table's unique key on booking, person and day is what makes every path
	 * — projected QR, by hand, terminal — count a person once a day.
	 *
	 * @param int      $target_id  Booking id.
	 * @param int      $user_id    The person.
	 * @param int      $checked_at Unix UTC seconds of the reading.
	 * @param string   $method     One of {@see \FreeFormCertificate\Core\Attendance::METHODS}.
	 * @param int|null $marked_by  The operator, for a mark by hand.
	 * @return string
	 */
	public function record( int $target_id, int $user_id, int $checked_at, string $method, ?int $marked_by ): string {
		$day = wp_date( 'Y-m-d', $checked_at );

		return AudienceAttendanceWriter::record( $target_id, $user_id, false === $day ? '' : $day, $method, $marked_by, $checked_at );
	}
}
