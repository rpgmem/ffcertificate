<?php
/**
 * Audience Check-in Window Reader
 *
 * Read-side of the check-in window repository (#1947). Writes live in
 * {@see AudienceCheckinWindowWriter}.
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

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_audience_checkin_windows table, which WordPress exposes no API for. Nothing is cached: whether a window is open is read at the moment of a scan.
/**
 * Read operations for check-in windows.
 *
 * @phpstan-type WindowRow array{id: int, booking_id: int, window_date: string, rotation_seconds: int, opened_at: int, opened_by: int|null, closes_at: int, closed_at: int|null, closed_by: int|null, geofence_locations: list<string>}
 *
 * @since 6.42.0
 */
class AudienceCheckinWindowReader {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Check-in windows table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_audience_checkin_windows';
	}

	/**
	 * Whether a window is open at a given moment: not closed by hand and not
	 * past its automatic close.
	 *
	 * @param array<string, mixed> $window Window.
	 * @phpstan-param WindowRow $window
	 * @param int                  $now    Unix time.
	 * @return bool
	 */
	public static function is_open( array $window, int $now ): bool {
		return null === $window['closed_at'] && $now < $window['closes_at'];
	}

	/**
	 * One window by ID.
	 *
	 * @param int $id Window ID.
	 * @return WindowRow|null
	 */
	public static function get( int $id ): ?array {
		$wpdb = self::db();

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::get_table_name(), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * The most recent window of one day of a booking, open or not.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $date       Day (Y-m-d).
	 * @return WindowRow|null
	 */
	public static function latest_for( int $booking_id, string $date ): ?array {
		$wpdb = self::db();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE booking_id = %d AND window_date = %s ORDER BY id DESC LIMIT 1',
				self::get_table_name(),
				$booking_id,
				$date
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * The window open now for one day of a booking, if any.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $date       Day (Y-m-d).
	 * @param int    $now        Unix time.
	 * @return WindowRow|null
	 */
	public static function open_for( int $booking_id, string $date, int $now ): ?array {
		$window = self::latest_for( $booking_id, $date );

		return null !== $window && self::is_open( $window, $now ) ? $window : null;
	}

	/**
	 * Type one raw row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return WindowRow
	 */
	private static function shape( array $row ): array {
		$nullable = static function ( string $key ) use ( $row ): ?int {
			return null === ( $row[ $key ] ?? null ) ? null : ArrayValue::int( $row, $key );
		};

		return array(
			'id'                 => ArrayValue::int( $row, 'id' ),
			'booking_id'         => ArrayValue::int( $row, 'booking_id' ),
			'window_date'        => ArrayValue::string( $row, 'window_date' ),
			'rotation_seconds'   => ArrayValue::int( $row, 'rotation_seconds', AudienceCheckinCode::DEFAULT_ROTATION ),
			'opened_at'          => ArrayValue::int( $row, 'opened_at' ),
			'opened_by'          => $nullable( 'opened_by' ),
			'closes_at'          => ArrayValue::int( $row, 'closes_at' ),
			'closed_at'          => $nullable( 'closed_at' ),
			'closed_by'          => $nullable( 'closed_by' ),
			'geofence_locations' => AudienceCheckinWindowWriter::location_ids( ArrayValue::string( $row, 'geofence_locations' ) ),
		);
	}
}
