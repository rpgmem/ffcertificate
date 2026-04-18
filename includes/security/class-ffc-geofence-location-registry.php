<?php
declare(strict_types=1);

/**
 * GeofenceLocationRegistry
 *
 * Manages a registry of named geofence locations stored as a WordPress option.
 * Provides CRUD operations, default-flag management, and conversion to the
 * area-text format consumed by Geofence::parse_areas().
 *
 * @package FFC
 * @since   4.10.0
 */

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GeofenceLocationRegistry {

	const OPTION_KEY = 'ffc_geofence_locations';

	/**
	 * Return every registered location.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all(): array {
		$locations = get_option( self::OPTION_KEY, array() );

		return is_array( $locations ) ? $locations : array();
	}

	/**
	 * Return a single location by its ID.
	 *
	 * @param string $id Location ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_by_id( string $id ): ?array {
		foreach ( self::get_all() as $location ) {
			if ( ( $location['id'] ?? '' ) === $id ) {
				return $location;
			}
		}

		return null;
	}

	/**
	 * Return locations whose IDs are present in the given list.
	 *
	 * Unknown IDs are silently skipped.
	 *
	 * @param array<int, string> $ids Location IDs.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_by_ids( array $ids ): array {
		$ids_flip = array_flip( $ids );
		$matched  = array();

		foreach ( self::get_all() as $location ) {
			if ( isset( $ids_flip[ $location['id'] ?? '' ] ) ) {
				$matched[] = $location;
			}
		}

		return $matched;
	}

	/**
	 * Insert or update a location.
	 *
	 * When `$location['id']` is empty a new ID is generated. Setting
	 * `default_gps` or `default_ip` to true clears that flag from every
	 * other location (mutual exclusivity).
	 *
	 * @param array<string, mixed> $location Location data.
	 * @return string The saved location ID.
	 */
	public static function save( array $location ): string {
		if ( empty( $location['id'] ) ) {
			$location['id'] = 'loc_' . wp_generate_uuid4();
		}

		$location  = self::sanitize_location( $location );
		$locations = self::get_all();
		$found     = false;

		foreach ( $locations as $index => $existing ) {
			if ( ( $existing['id'] ?? '' ) === $location['id'] ) {
				$locations[ $index ] = $location;
				$found               = true;
				break;
			}
		}

		if ( ! $found ) {
			$locations[] = $location;
		}

		if ( ! empty( $location['default_gps'] ) ) {
			foreach ( $locations as $index => $item ) {
				if ( ( $item['id'] ?? '' ) !== $location['id'] ) {
					$locations[ $index ]['default_gps'] = false;
				}
			}
		}

		if ( ! empty( $location['default_ip'] ) ) {
			foreach ( $locations as $index => $item ) {
				if ( ( $item['id'] ?? '' ) !== $location['id'] ) {
					$locations[ $index ]['default_ip'] = false;
				}
			}
		}

		self::save_all( $locations );

		return $location['id'];
	}

	/**
	 * Delete a location by ID.
	 *
	 * @param string $id Location ID.
	 * @return bool True when the location was found and removed.
	 */
	public static function delete( string $id ): bool {
		$locations = self::get_all();
		$filtered  = array();
		$deleted   = false;

		foreach ( $locations as $location ) {
			if ( ( $location['id'] ?? '' ) === $id ) {
				$deleted = true;
				continue;
			}
			$filtered[] = $location;
		}

		if ( $deleted ) {
			self::save_all( $filtered );
		}

		return $deleted;
	}

	/**
	 * Return the location flagged as the GPS default.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_default_gps(): ?array {
		foreach ( self::get_all() as $location ) {
			if ( ! empty( $location['default_gps'] ) ) {
				return $location;
			}
		}

		return null;
	}

	/**
	 * Return the location flagged as the IP default.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_default_ip(): ?array {
		foreach ( self::get_all() as $location ) {
			if ( ! empty( $location['default_ip'] ) ) {
				return $location;
			}
		}

		return null;
	}

	/**
	 * Resolve location IDs into the "lat, lng, radius" text format
	 * expected by Geofence::parse_areas().
	 *
	 * Unknown IDs are silently skipped.
	 *
	 * @param array<int, string> $location_ids Location IDs.
	 * @return string One "lat, lng, radius" entry per line.
	 */
	public static function resolve_to_areas_text( array $location_ids ): string {
		$locations = self::get_by_ids( $location_ids );
		$lines     = array();

		foreach ( $locations as $location ) {
			$lines[] = $location['lat'] . ', ' . $location['lng'] . ', ' . $location['radius'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Persist the full locations array to the database.
	 *
	 * @param array<int, array<string, mixed>> $locations Locations to store.
	 */
	private static function save_all( array $locations ): void {
		update_option( self::OPTION_KEY, array_values( $locations ) );
	}

	/**
	 * Sanitize a single location entry.
	 *
	 * @param array<string, mixed> $location Raw location data.
	 * @return array<string, mixed> Sanitized location data.
	 */
	private static function sanitize_location( array $location ): array {
		$lat = floatval( $location['lat'] ?? 0 );
		$lng = floatval( $location['lng'] ?? 0 );

		if ( $lat < -90.0 ) {
			$lat = -90.0;
		} elseif ( $lat > 90.0 ) {
			$lat = 90.0;
		}

		if ( $lng < -180.0 ) {
			$lng = -180.0;
		} elseif ( $lng > 180.0 ) {
			$lng = 180.0;
		}

		$radius = floatval( $location['radius'] ?? 1000 );
		if ( $radius <= 0 ) {
			$radius = 1000.0;
		}

		$name = sanitize_text_field( $location['name'] ?? '' );
		if ( mb_strlen( $name ) > 100 ) {
			$name = mb_substr( $name, 0, 100 );
		}

		return array(
			'id'          => sanitize_key( $location['id'] ?? '' ),
			'name'        => $name,
			'lat'         => $lat,
			'lng'         => $lng,
			'radius'      => $radius,
			'default_gps' => ! empty( $location['default_gps'] ),
			'default_ip'  => ! empty( $location['default_ip'] ),
		);
	}
}
