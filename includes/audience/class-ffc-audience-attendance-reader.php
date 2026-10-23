<?php
/**
 * Audience Attendance Reader
 *
 * Read-side of the attendance repository (#1947). Writes live in
 * {@see AudienceAttendanceWriter}.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_audience_attendance table, which WordPress exposes no API for. Nothing is cached: the attendance list is read live while check-in is open.
/**
 * Read operations for attendance records.
 *
 * @phpstan-type AttendanceRow array{id: int, booking_id: int, user_id: int|null, attendance_date: string, checked_at: int, method: string, marked_by: int|null}
 *
 * @since 6.42.0
 */
class AudienceAttendanceReader {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Attendance table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_audience_attendance';
	}

	/**
	 * The days a person was recorded present at a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id    User ID.
	 * @return list<string> Y-m-d, oldest first.
	 */
	public static function days_present( int $booking_id, int $user_id ): array {
		$wpdb = self::db();

		$days = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT attendance_date FROM %i WHERE booking_id = %d AND user_id = %d ORDER BY attendance_date ASC',
				self::get_table_name(),
				$booking_id,
				$user_id
			)
		);

		return array_map( 'strval', $days );
	}

	/**
	 * Whether a person is already recorded present on one day of a booking.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param int    $user_id    User ID.
	 * @param string $date       Day (Y-m-d).
	 * @return bool
	 */
	public static function is_present( int $booking_id, int $user_id, string $date ): bool {
		$wpdb = self::db();

		return null !== $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE booking_id = %d AND user_id = %d AND attendance_date = %s LIMIT 1',
				self::get_table_name(),
				$booking_id,
				$user_id,
				$date
			)
		);
	}

	/**
	 * The attendance rows of one day of a booking.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $date       Day (Y-m-d).
	 * @return list<AttendanceRow>
	 */
	public static function for_day( int $booking_id, string $date ): array {
		$wpdb = self::db();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, booking_id, user_id, attendance_date, checked_at, method, marked_by FROM %i WHERE booking_id = %d AND attendance_date = %s ORDER BY checked_at ASC, id ASC',
				self::get_table_name(),
				$booking_id,
				$date
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'shape' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Type one raw row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return AttendanceRow
	 */
	private static function shape( array $row ): array {
		return array(
			'id'              => (int) ( $row['id'] ?? 0 ),
			'booking_id'      => (int) ( $row['booking_id'] ?? 0 ),
			'user_id'         => null === ( $row['user_id'] ?? null ) ? null : (int) $row['user_id'],
			'attendance_date' => (string) ( $row['attendance_date'] ?? '' ),
			'checked_at'      => (int) ( $row['checked_at'] ?? 0 ),
			'method'          => (string) ( $row['method'] ?? '' ),
			'marked_by'       => null === ( $row['marked_by'] ?? null ) ? null : (int) $row['marked_by'],
		);
	}
}
