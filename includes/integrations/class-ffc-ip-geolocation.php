<?php
/**
 * IpGeolocation
 *
 * Handles IP-based geolocation using external APIs
 *
 * Supported services:
 * - ip-api.com (free, 45 req/min, no API key)
 * - ipinfo.io (50k/month free, requires API key)
 *
 * @package FreeFormCertificate\Integrations
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 * @since 3.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Integrations;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ip Geolocation.
 */
class IpGeolocation {

	/**
	 * The ip-api.com service, asked only over its paid HTTPS plan.
	 *
	 * @var string
	 */
	public const SERVICE_IPAPI = 'ip-api';

	/**
	 * The ipinfo.io service, HTTPS on its free tier: the default.
	 *
	 * @var string
	 */
	public const SERVICE_IPINFO = 'ipinfo';

	/**
	 * How long a failed lookup is remembered, in seconds.
	 *
	 * @var int
	 */
	public const FAILURE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Get location data by IP address
	 *
	 * @param string|null $ip IP address (optional, defaults to current user IP).
	 * @param bool        $use_cache Whether to use cached results.
	 * @return array<string, mixed>|\WP_Error|null Array with location data, WP_Error on failure, or null
	 */
	public static function get_location( ?string $ip = null, bool $use_cache = true ) {
		// Get settings.
		$settings = \FreeFormCertificate\Settings\GeolocationSettingsReader::all();

		if ( empty( $settings['ip_api_enabled'] ) ) {
			return new WP_Error( 'ip_api_disabled', __( 'IP Geolocation API is disabled.', 'ffcertificate' ) );
		}

		// Get user IP if not provided.
		if ( empty( $ip ) ) {
			$ip = \FreeFormCertificate\Core\RequestInput::get_user_ip();
		}

		// Validate IP.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return new WP_Error( 'invalid_ip', __( 'Invalid IP address for geolocation.', 'ffcertificate' ) );
		}

		// A lookup that failed a moment ago is not repeated (#2339): a service
		// that is down or rate-limiting would otherwise be asked again on every
		// submission, each one waiting out the timeout.
		if ( $use_cache && self::failed_recently( $ip ) ) {
			return new WP_Error( 'ip_lookup_failed_recently', __( 'The IP geolocation service failed a moment ago. It will be asked again in a few minutes.', 'ffcertificate' ) );
		}

		// Check cache.
		if ( $use_cache && ! empty( $settings['ip_cache_enabled'] ) ) {
			$cached = self::get_cached_location( $ip );
			if ( false !== $cached ) {
				self::debug_log(
					'IP location from cache',
					array(
						'ip_hash'  => self::hash_ip( $ip ),
						'location' => self::redact_location_ip( $cached ),
					)
				);
				return $cached;
			}
		}

		// Try primary service.
		$primary_service = self::effective_service( (string) ( $settings['ip_api_service'] ?? self::SERVICE_IPINFO ) );
		$location        = self::fetch_from_service( $ip, $primary_service, $settings );

		// If primary failed and cascade is enabled, try alternative — only
		// when the alternative can also be asked over HTTPS.
		$alternative_service = self::SERVICE_IPAPI === $primary_service ? self::SERVICE_IPINFO : self::SERVICE_IPAPI;
		if ( is_wp_error( $location ) && ! empty( $settings['ip_api_cascade'] ) && self::effective_service( $alternative_service ) === $alternative_service ) {
			self::debug_log(
				'Primary service failed, trying alternative',
				array(
					'primary'     => $primary_service,
					'alternative' => $alternative_service,
					'error'       => $location->get_error_message(),
				)
			);
			$location = self::fetch_from_service( $ip, $alternative_service, $settings );
		}

		// Cache successful result.
		if ( ! is_wp_error( $location ) && $use_cache && ! empty( $settings['ip_cache_enabled'] ) ) {
			self::cache_location( $ip, $location, \FreeFormCertificate\Settings\GeolocationSettingsReader::ip_cache_ttl() );
		}

		if ( is_wp_error( $location ) && $use_cache ) {
			self::remember_failure( $ip );
		}

		return $location;
	}

	/**
	 * The service actually asked, given the one configured (#2339).
	 *
	 * Visitor addresses are never sent in clear text. ip-api.com serves HTTPS
	 * only on its paid plan, so it is asked only when the `ffc_ipapi_use_https`
	 * filter says the site has one; otherwise ipinfo.io, which serves HTTPS on
	 * its free tier, is asked instead.
	 *
	 * @since 6.44.0
	 * @param string $service Configured service id.
	 * @return string `ip-api` or `ipinfo`.
	 */
	public static function effective_service( string $service ): string {
		if ( self::SERVICE_IPAPI === $service && self::ipapi_over_https() ) {
			return self::SERVICE_IPAPI;
		}

		return self::SERVICE_IPINFO;
	}

	/**
	 * Whether the site has ip-api.com's HTTPS (paid) plan.
	 *
	 * @return bool
	 */
	private static function ipapi_over_https(): bool {
		/**
		 * Whether ip-api.com may be asked, over HTTPS (its paid plan).
		 *
		 * @since 3.0.0
		 * @param bool $https Default false.
		 */
		return (bool) apply_filters( 'ffc_ipapi_use_https', false );
	}

	/**
	 * Fetch location from specific service
	 *
	 * @param string               $ip IP address.
	 * @param string               $service Service name ('ip-api' or 'ipinfo').
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array<string, mixed>|\WP_Error Location data or WP_Error on failure
	 */
	private static function fetch_from_service( string $ip, string $service, array $settings ) {
		// Hash the IP in debug logs so that debug-mode logging never stores raw PII per LGPD/GDPR.
		self::debug_log(
			'Fetching from service',
			array(
				'service' => $service,
				'ip_hash' => self::hash_ip( $ip ),
			)
		);

		if ( self::SERVICE_IPAPI === $service ) {
			return self::fetch_from_ipapi( $ip );
		} elseif ( self::SERVICE_IPINFO === $service ) {
			$api_key = $settings['ipinfo_api_key'] ?? '';
			return self::fetch_from_ipinfo( $ip, $api_key );
		}

		return new WP_Error( 'unknown_service', __( 'Unknown geolocation service.', 'ffcertificate' ) );
	}

	/**
	 * Fetch location from ip-api.com
	 *
	 * @param string $ip IP address.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function fetch_from_ipapi( string $ip ) {
		// HTTPS only (#2339): this is reached only when effective_service()
		// allowed ip-api, i.e. the site has its HTTPS plan.
		$url = sprintf( 'https://ip-api.com/json/%s?fields=status,message,country,countryCode,region,regionName,city,lat,lon', $ip );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 5,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::debug_log( 'ip-api request failed', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response from ip-api.com', 'ffcertificate' ) );
		}

		if ( 'success' !== $data['status'] ) {
			return new WP_Error( 'api_error', $data['message'] ?? __( 'Unknown error from ip-api.com', 'ffcertificate' ) );
		}

		// Normalize response format.
		$location = array(
			'ip'           => $ip,
			'country'      => $data['country'] ?? '',
			'country_code' => $data['countryCode'] ?? '',
			'region'       => $data['regionName'] ?? '',
			'region_code'  => $data['region'] ?? '',
			'city'         => $data['city'] ?? '',
			'latitude'     => floatval( $data['lat'] ?? 0 ),
			'longitude'    => floatval( $data['lon'] ?? 0 ),
			'service'      => 'ip-api',
			'timestamp'    => time(),
		);

		self::debug_log( 'ip-api success', self::redact_location_ip( $location ) );
		return $location;
	}

	/**
	 * Fetch location from ipinfo.io
	 *
	 * @param string $ip IP address.
	 * @param string $api_key API key (optional for free tier).
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function fetch_from_ipinfo( string $ip, string $api_key = '' ) {
		$url = sprintf( 'https://ipinfo.io/%s/json', $ip );

		if ( ! empty( $api_key ) ) {
			$url .= '?token=' . rawurlencode( $api_key );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 5,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::debug_log( 'ipinfo request failed', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response from ipinfo.io', 'ffcertificate' ) );
		}

		// Check for API errors.
		if ( isset( $data['error'] ) ) {
			return new WP_Error( 'api_error', $data['error']['title'] ?? __( 'Unknown error from ipinfo.io', 'ffcertificate' ) );
		}

		// Parse location coordinates.
		$loc_parts = explode( ',', $data['loc'] ?? '0,0' );
		$latitude  = floatval( $loc_parts[0] );
		$longitude = floatval( $loc_parts[1] ?? 0 );

		// Normalize response format.
		$location = array(
			'ip'           => $ip,
			'country'      => $data['country'] ?? '',
			'country_code' => $data['country'] ?? '',
			'region'       => $data['region'] ?? '',
			'region_code'  => $data['region'] ?? '',
			'city'         => $data['city'] ?? '',
			'latitude'     => $latitude,
			'longitude'    => $longitude,
			'service'      => 'ipinfo',
			'timestamp'    => time(),
		);

		self::debug_log( 'ipinfo success', self::redact_location_ip( $location ) );
		return $location;
	}

	/**
	 * Get cached location for IP
	 *
	 * @param string $ip IP address.
	 * @return array<string, mixed>|false Location data or false if not cached
	 */
	private static function get_cached_location( string $ip ) {
		$cached = get_transient( self::cache_key( $ip ) );
		if ( ! is_array( $cached ) || ! empty( $cached['failed'] ) ) {
			return false;
		}

		// The cache holds no address (#2339); the caller gets back the one it asked about.
		$cached['ip'] = $ip;
		return $cached;
	}

	/**
	 * Whether a lookup for this address failed within the last few minutes.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function failed_recently( string $ip ): bool {
		$cached = get_transient( self::cache_key( $ip ) );
		return is_array( $cached ) && ! empty( $cached['failed'] );
	}

	/**
	 * Remember a failed lookup for {@see self::FAILURE_TTL} seconds.
	 *
	 * @param string $ip IP address.
	 * @return void
	 */
	private static function remember_failure( string $ip ): void {
		set_transient( self::cache_key( $ip ), array( 'failed' => true ), self::FAILURE_TTL );
	}

	/**
	 * Transient key for an address: a keyed hash, so the key is not a table
	 * an attacker can reverse the way an md5 of the 2^32 IPv4 addresses is.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	private static function cache_key( string $ip ): string {
		return 'ffc_ip_geo_' . substr( \FreeFormCertificate\Core\Encryption::keyed_hash( $ip, 'ip_geo_cache' ), 0, 32 );
	}

	/**
	 * Cache location for IP
	 *
	 * @param string               $ip IP address.
	 * @param array<string, mixed> $location Location data.
	 * @param int                  $ttl Cache duration in seconds.
	 * @return bool Success
	 */
	private static function cache_location( string $ip, array $location, int $ttl = 600 ): bool {
		unset( $location['ip'] );
		return set_transient( self::cache_key( $ip ), $location, absint( $ttl ) );
	}

	/**
	 * Calculate distance between two coordinates using Haversine formula
	 *
	 * @param float $lat1 Latitude of point 1.
	 * @param float $lon1 Longitude of point 1.
	 * @param float $lat2 Latitude of point 2.
	 * @param float $lon2 Longitude of point 2.
	 * @return float Distance in meters
	 */
	public static function calculate_distance( float $lat1, float $lon1, float $lat2, float $lon2 ): float {
		$earth_radius = 6371000; // Earth radius in meters.

		$lat1 = deg2rad( $lat1 );
		$lon1 = deg2rad( $lon1 );
		$lat2 = deg2rad( $lat2 );
		$lon2 = deg2rad( $lon2 );

		$dlat = $lat2 - $lat1;
		$dlon = $lon2 - $lon1;

		$a = sin( $dlat / 2 ) * sin( $dlat / 2 ) +
			cos( $lat1 ) * cos( $lat2 ) *
			sin( $dlon / 2 ) * sin( $dlon / 2 );

		$c        = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
		$distance = $earth_radius * $c;

		return $distance;
	}

	/**
	 * Check if location is within allowed areas
	 *
	 * @param array<string, mixed>             $location Location data (must have 'latitude' and 'longitude').
	 * @param array<int, array<string, float>> $areas Array of areas (each with 'lat', 'lng', 'radius').
	 * @param string                           $logic 'or' or 'and' (default: 'or').
	 * @return bool True if location is within allowed areas
	 */
	public static function is_within_areas( array $location, array $areas, string $logic = 'or' ): bool {
		if ( empty( $location['latitude'] ) || empty( $location['longitude'] ) ) {
			return false;
		}

		if ( empty( $areas ) ) {
			return false;
		}

		$matches = array();

		foreach ( $areas as $area ) {
			$distance = self::calculate_distance(
				$location['latitude'],
				$location['longitude'],
				$area['lat'],
				$area['lng']
			);

			$within    = ( $distance <= $area['radius'] );
			$matches[] = $within;

			// The decision only: never the visitor's position, nor the
			// distance, which three areas would turn back into one (#2040).
			self::debug_log(
				'Area check',
				array(
					'area'   => count( $matches ) - 1,
					'radius' => $area['radius'],
					'within' => $within,
				)
			);
		}

		// Apply logic.
		if ( 'and' === $logic ) {
			return ! in_array( false, $matches, true ); // All must match.
		} else {
			return in_array( true, $matches, true ); // At least one must match.
		}
	}

	/**
	 * Truncated SHA-256 of an IP, for privacy-preserving debug logs (LGPD).
	 *
	 * @param string $ip Raw IP address.
	 * @return string 16-hex-char digest.
	 */
	private static function hash_ip( string $ip ): string {
		// Keyed, never a bare digest: an unsalted hash of an IPv4 address is
		// reversed by hashing all 2^32 of them (#2109).
		return substr( \FreeFormCertificate\Core\Encryption::keyed_hash( $ip, 'debug_ip' ), 0, 16 );
	}

	/**
	 * Return a copy of a location array with the raw `ip` field replaced by its
	 * truncated hash and the coordinates removed, for safe debug logging. The
	 * returned/cached location structure is unchanged — only the log copy is
	 * redacted.
	 *
	 * @param mixed $location Location array (or anything; passed through if not an array).
	 * @return mixed
	 */
	private static function redact_location_ip( $location ) {
		if ( ! is_array( $location ) ) {
			return $location;
		}
		if ( isset( $location['ip'] ) && is_string( $location['ip'] ) && '' !== $location['ip'] ) {
			$location['ip'] = self::hash_ip( $location['ip'] );
		}
		// A position, even the city one an IP resolves to, is not logged (#2040).
		unset( $location['latitude'], $location['longitude'] );
		return $location;
	}

	/**
	 * Debug logging
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context.
	 */
	private static function debug_log( string $message, array $context = array() ): void {
		// Log via centralized debug system (gated on ffc_settings['debug_geofence']).
		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_geofence( $message, $context );
		}

		// The activity log copy obeys the same toggle as the debug log: it is
		// debug output, and it used to be written whenever the log was on
		// (#2040).
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' )
			&& class_exists( '\FreeFormCertificate\Core\Debug' )
			&& \FreeFormCertificate\Core\Debug::is_enabled( \FreeFormCertificate\Core\Debug::AREA_GEOFENCE ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'ip_geolocation_debug',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_DEBUG,
				array_merge( array( 'message' => $message ), $context )
			);
		}
	}

	/**
	 * Clear IP geolocation cache
	 *
	 * @param string|null $ip Specific IP to clear, or null to clear all.
	 * @return int Number of entries cleared
	 */
	public static function clear_cache( ?string $ip = null ): int {
		global $wpdb;

		if ( null !== $ip ) {
			// Clear specific IP.
			delete_transient( self::cache_key( $ip ) );
			return 1;
		} else {
			// Clear all IP geolocation transients.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LIKE sweep over the options table: WordPress exposes no API to enumerate or delete options by prefix. This is the flush path for the geolocation transients.
			$result = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
					$wpdb->options,
					'_transient_ffc_ip_geo_%',
					'_transient_timeout_ffc_ip_geo_%'
				)
			);
			return false !== $result ? (int) $result : 0;
		}
	}
}
