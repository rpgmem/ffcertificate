<?php
/**
 * Audience Attendance Writer
 *
 * Write-side of the attendance repository (#1947). Reads live in
 * {@see AudienceAttendanceReader}.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_audience_attendance table, which WordPress exposes no API for; its reader caches nothing.
/**
 * Write operations for attendance records.
 *
 * @since 6.42.0
 */
class AudienceAttendanceWriter {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Recorded now.
	 *
	 * @var string
	 */
	public const RECORDED = 'recorded';

	/**
	 * Already recorded for that day; nothing was written.
	 *
	 * @var string
	 */
	public const ALREADY = 'already';

	/**
	 * The write failed.
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * Check-in by the participant's own scan.
	 *
	 * @var string
	 */
	public const METHOD_QR = 'qr';

	/**
	 * Marked by an operator.
	 *
	 * @var string
	 */
	public const METHOD_MANUAL = 'manual';

	/**
	 * Record a person present on one day of a booking.
	 *
	 * Once per day is the table's unique key, not a read before the write: two
	 * scans arriving together cannot both insert, and the second reads as
	 * {@see self::ALREADY}.
	 *
	 * @param int      $booking_id Booking ID.
	 * @param int      $user_id    User ID.
	 * @param string   $date       Day (Y-m-d).
	 * @param string   $method     {@see self::METHOD_QR} or {@see self::METHOD_MANUAL}.
	 * @param int|null $marked_by  Operator who marked it, for a manual mark.
	 * @param int      $now        Unix time of the check-in.
	 * @return string One of the result constants.
	 */
	public static function record( int $booking_id, int $user_id, string $date, string $method, ?int $marked_by, int $now ): string {
		if ( $booking_id <= 0 || $user_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return self::FAILED;
		}

		global $wpdb;

		$table  = AudienceAttendanceReader::get_table_name();
		$method = self::METHOD_MANUAL === $method ? self::METHOD_MANUAL : self::METHOD_QR;

		$inserted = null === $marked_by
			? $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (booking_id, user_id, attendance_date, checked_at, method) VALUES (%d, %d, %s, %d, %s)',
					$table,
					$booking_id,
					$user_id,
					$date,
					$now,
					$method
				)
			)
			: $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (booking_id, user_id, attendance_date, checked_at, method, marked_by) VALUES (%d, %d, %s, %d, %s, %d)',
					$table,
					$booking_id,
					$user_id,
					$date,
					$now,
					$method,
					$marked_by
				)
			);

		if ( false === $inserted ) {
			return self::FAILED;
		}

		return $inserted > 0 ? self::RECORDED : self::ALREADY;
	}

	/**
	 * Remove a person's attendance on one day of a booking (an operator's
	 * correction).
	 *
	 * @param int    $booking_id Booking ID.
	 * @param int    $user_id    User ID.
	 * @param string $date       Day (Y-m-d).
	 * @return bool Whether a row was removed.
	 */
	public static function remove( int $booking_id, int $user_id, string $date ): bool {
		$removed = self::db()->delete(
			AudienceAttendanceReader::get_table_name(),
			array(
				'booking_id'      => $booking_id,
				'user_id'         => $user_id,
				'attendance_date' => $date,
			),
			array( '%d', '%d', '%s' )
		);

		return is_int( $removed ) && $removed > 0;
	}
}
