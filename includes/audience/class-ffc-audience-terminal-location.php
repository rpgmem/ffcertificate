<?php
/**
 * AudienceTerminalLocation
 *
 * A terminal paired to a GPS location of Settings → Geolocation (#2900):
 * what the location holds, and keeping the terminal's queue in step with it.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.52.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Security\GeofenceLocationRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a location holds, and the terminal that follows it.
 *
 * A location holds the events booked in the environments linked to it and
 * the certificate forms whose geolocation names it, open today or later
 * within {@see AudienceTerminalPairing::DAYS_AHEAD} days. A terminal paired
 * to a location stores the location and rebuilds its queue from it every
 * time it asks for its schedule, so an item created later joins with no new
 * pairing. Items may overlap: each reading names its own item, and the
 * schedule shows one at a time ({@see AudienceTerminalSchedule::plan()}).
 *
 * @phpstan-import-type TerminalRow from AudienceTerminalReader
 */
final class AudienceTerminalLocation {

	/**
	 * How long a refresh holds before the next request rebuilds the queue.
	 */
	public const FOLLOW_SECONDS = 60;

	/**
	 * The target keys a location holds now, soonest first, at most
	 * {@see AudienceTerminalPairing::MAX_BOOKINGS}.
	 *
	 * An item already at its terminal limit is left out unless this terminal
	 * holds it already.
	 *
	 * @param string $location_id GPS location id.
	 * @param int    $now         Unix time.
	 * @param array  $held        Keys the terminal holds already.
	 * @phpstan-param list<int|string> $held
	 * @return list<int|string>
	 */
	public static function items( string $location_id, int $now, array $held = array() ): array {
		$keys = array();
		foreach ( self::offered( $location_id, $now ) as $item ) {
			if ( $item['full'] && ! in_array( $item['id'], $held, true ) ) {
				continue;
			}
			$keys[] = $item['id'];
			if ( count( $keys ) >= AudienceTerminalPairing::MAX_BOOKINGS ) {
				break;
			}
		}
		return $keys;
	}

	/**
	 * Every GPS location, with what it holds, for the setup screens.
	 *
	 * @param int $now Unix time.
	 * @return list<array{id: string, name: string, items: list<array<string, mixed>>}>
	 */
	public static function locations( int $now ): array {
		$offered = AudienceTerminalRestController::offered_bookings( $now );
		$out     = array();
		foreach ( GeofenceLocationRegistry::get_all() as $location ) {
			$id = (string) ( $location['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'name'  => (string) ( $location['name'] ?? $id ),
				'items' => array_values( array_filter( $offered, static fn( array $item ): bool => in_array( $id, $item['location_ids'], true ) ) ),
			);
		}
		return $out;
	}

	/**
	 * Whether a GPS location exists.
	 *
	 * @param string $location_id GPS location id.
	 * @return bool
	 */
	public static function exists( string $location_id ): bool {
		return '' !== $location_id && null !== GeofenceLocationRegistry::get_by_id( $location_id );
	}

	/**
	 * When a terminal that follows a location ends by itself: it does not
	 * end after an event, because more come; the date moves forward every
	 * time it follows the location.
	 *
	 * @param int $now Unix time.
	 * @return int Unix time.
	 */
	public static function expiry( int $now ): int {
		return $now + ( AudienceTerminalPairing::DAYS_AHEAD + 1 ) * DAY_IN_SECONDS;
	}

	/**
	 * Bring a location terminal's queue in step with its location.
	 *
	 * A terminal paired to chosen events comes back unchanged. The rebuild
	 * runs at most once a {@see self::FOLLOW_SECONDS}; an item that leaves
	 * keeps the moment it left, as an operator's change does, so a reading
	 * taken offline before then still records.
	 *
	 * @param array $terminal Terminal row.
	 * @param int   $now      Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return TerminalRow
	 */
	public static function follow( array $terminal, int $now ): array {
		if ( '' === $terminal['location_id'] || null !== $terminal['revoked_at'] ) {
			return $terminal;
		}
		$throttle = 'ffc_terminal_follow_' . $terminal['id'];
		if ( false !== get_transient( $throttle ) ) {
			return $terminal;
		}
		set_transient( $throttle, 1, self::FOLLOW_SECONDS );

		$items   = self::items( $terminal['location_id'], $now, $terminal['targets'] );
		$added   = array_values( array_diff( $items, $terminal['targets'] ) );
		$dropped = array_values( array_diff( $terminal['targets'], $items ) );
		$expires = self::expiry( $now );
		$removed = $terminal['removed'];
		foreach ( $dropped as $key ) {
			$removed[ $key ] = $now;
		}
		foreach ( $added as $key ) {
			unset( $removed[ $key ] );
		}
		if ( ! AudienceTerminalWriter::set_bookings( $terminal['id'], $items, $removed, $expires ) ) {
			return $terminal;
		}
		if ( array() !== $added || array() !== $dropped ) {
			ActivityLog::log(
				'terminal_events_changed',
				ActivityLog::LEVEL_INFO,
				array(
					'terminal_id' => $terminal['id'],
					'location_id' => $terminal['location_id'],
					'added'       => $added,
					'removed'     => $dropped,
				)
			);
		}
		$terminal['targets']    = $items;
		$terminal['removed']    = $removed;
		$terminal['expires_at'] = $expires;
		return $terminal;
	}

	/**
	 * The offered items a location holds.
	 *
	 * @param string $location_id GPS location id.
	 * @param int    $now         Unix time.
	 * @return list<array{id: int|string, full: bool}>
	 */
	private static function offered( string $location_id, int $now ): array {
		if ( '' === $location_id ) {
			return array();
		}
		$out = array();
		foreach ( AudienceTerminalRestController::offered_bookings( $now ) as $item ) {
			if ( in_array( $location_id, $item['location_ids'], true ) ) {
				$out[] = array(
					'id'   => $item['id'],
					'full' => $item['full'],
				);
			}
		}
		return $out;
	}
}
