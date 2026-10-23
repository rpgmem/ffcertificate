<?php
/**
 * Audience Check-in Window Writer
 *
 * Write-side of the check-in window repository (#1947). Reads live in
 * {@see AudienceCheckinWindowReader}.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_audience_checkin_windows table, which WordPress exposes no API for; its reader caches nothing.
/**
 * Write operations for check-in windows.
 *
 * @since 6.42.0
 */
class AudienceCheckinWindowWriter {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * How long after the day's end time a window closes by itself.
	 *
	 * @var int
	 */
	public const AUTO_CLOSE_GRACE = 1800;

	/**
	 * When a window opened for one day of a booking closes by itself: 30
	 * minutes after that day's end time, read in the site's timezone. An
	 * all-day booking ends at 23:59.
	 *
	 * @param object $booking Booking row.
	 * @param string $date    Day (Y-m-d).
	 * @return int Unix time.
	 */
	public static function auto_close_at( object $booking, string $date ): int {
		$end = ! empty( $booking->is_all_day ) ? '23:59' : AudienceBookingRules::stored_day_times( $booking, $date )['end_time'];
		if ( ! preg_match( '/^\d{2}:\d{2}$/', $end ) ) {
			$end = '23:59';
		}

		$moment = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $end, wp_timezone() );
		$base   = false === $moment ? time() : $moment->getTimestamp();

		return $base + self::AUTO_CLOSE_GRACE;
	}

	/**
	 * Open check-in for one day of a booking.
	 *
	 * A window still open for that day is closed first, so a day has at most
	 * one open window and a reopened day gets a new id, which invalidates
	 * every code shown under the old one.
	 *
	 * @param int      $booking_id Booking ID.
	 * @param string   $date       Day (Y-m-d).
	 * @param int      $rotation   Seconds between codes ({@see AudienceCheckinCode::ROTATIONS}).
	 * @param int      $closes_at  Automatic close (Unix time).
	 * @param int|null $opened_by  Operator.
	 * @param int      $now        Unix time.
	 * @param string[] $geofence   Saved geofence location ids a check-in must fall inside; empty for none.
	 * @return int The new window's ID, or 0 on failure.
	 */
	public static function open( int $booking_id, string $date, int $rotation, int $closes_at, ?int $opened_by, int $now, array $geofence = array() ): int {
		if ( $booking_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $closes_at <= $now ) {
			return 0;
		}

		$current = AudienceCheckinWindowReader::open_for( $booking_id, $date, $now );
		if ( null !== $current ) {
			self::close( $current['id'], $opened_by, $now );
		}

		$wpdb   = self::db();
		$data   = array(
			'booking_id'       => $booking_id,
			'window_date'      => $date,
			'rotation_seconds' => AudienceCheckinCode::normalize_rotation( $rotation ),
			'opened_at'        => $now,
			'closes_at'        => $closes_at,
		);
		$format = array( '%d', '%s', '%d', '%d', '%d' );
		if ( null !== $opened_by ) {
			$data['opened_by'] = $opened_by;
			$format[]          = '%d';
		}
		$locations = implode( ',', self::location_ids( implode( ',', $geofence ) ) );
		if ( '' !== $locations ) {
			$data['geofence_locations'] = $locations;
			$format[]                   = '%s';
		}

		$inserted = $wpdb->insert( AudienceCheckinWindowReader::get_table_name(), $data, $format );

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * The geofence location ids a stored list names: trimmed, without empty
	 * or duplicate entries, and only the characters a registry id is made of.
	 *
	 * @param string $stored Comma-separated ids.
	 * @return list<string>
	 */
	public static function location_ids( string $stored ): array {
		$ids = array();
		foreach ( explode( ',', $stored ) as $id ) {
			$id = trim( $id );
			if ( '' !== $id && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Close a window by hand. A window already closed is left as it was.
	 *
	 * @param int      $id        Window ID.
	 * @param int|null $closed_by Operator.
	 * @param int      $now       Unix time.
	 * @return bool Whether the window was open and is now closed.
	 */
	public static function close( int $id, ?int $closed_by, int $now ): bool {
		global $wpdb;

		$updated = null === $closed_by
			? $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET closed_at = %d WHERE id = %d AND closed_at IS NULL',
					AudienceCheckinWindowReader::get_table_name(),
					$now,
					$id
				)
			)
			: $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET closed_at = %d, closed_by = %d WHERE id = %d AND closed_at IS NULL',
					AudienceCheckinWindowReader::get_table_name(),
					$now,
					$closed_by,
					$id
				)
			);

		return is_int( $updated ) && $updated > 0;
	}
}
