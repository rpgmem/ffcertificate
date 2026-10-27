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

use FreeFormCertificate\Core\ArrayValue;

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
	 * The days every recorded person was present at a booking, in one query.
	 *
	 * Lines whose person was deleted (`user_id` NULL) are left out: they
	 * count for nobody.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<int, list<string>> User ID => Y-m-d, oldest first.
	 */
	public static function days_by_user( int $booking_id ): array {
		$wpdb = self::db();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, attendance_date FROM %i WHERE booking_id = %d AND user_id IS NOT NULL ORDER BY attendance_date ASC',
				self::get_table_name(),
				$booking_id
			),
			ARRAY_A
		);

		$days = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$days[ ArrayValue::int( $row, 'user_id' ) ][] = ArrayValue::string( $row, 'attendance_date' );
		}

		return $days;
	}

	/**
	 * Whether a person is already recorded present on one day of a booking.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param int    $user_id    User ID.
	 * @param string $date       Day (Y-m-d).
	 * @return bool
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function is_present( int $booking_id, int $user_id, string $date ): bool {
		_deprecated_function( __METHOD__, '6.43.0' );

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
			'id'              => ArrayValue::int( $row, 'id' ),
			'booking_id'      => ArrayValue::int( $row, 'booking_id' ),
			'user_id'         => null === ( $row['user_id'] ?? null ) ? null : ArrayValue::int( $row, 'user_id' ),
			'attendance_date' => ArrayValue::string( $row, 'attendance_date' ),
			'checked_at'      => ArrayValue::int( $row, 'checked_at' ),
			'method'          => ArrayValue::string( $row, 'method' ),
			'marked_by'       => null === ( $row['marked_by'] ?? null ) ? null : ArrayValue::int( $row, 'marked_by' ),
		);
	}
}
