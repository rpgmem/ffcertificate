<?php
/**
 * Geolocation Settings Reader.
 *
 * Centralized accessor for `ffc_geolocation_settings` option reads.
 *
 * @package FreeFormCertificate\Settings
 * @since   6.7.x
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-side facade over the `ffc_geolocation_settings` WP option.
 *
 * Mirrors {@see SettingsReader} but scoped to the geolocation option group.
 *
 * IMPORTANT — defaults / merge ownership:
 *   The admin-facing canonical defaults + the legacy `gps_fallback`
 *   migration live in {@see \FreeFormCertificate\Settings\Tabs\TabGeolocation}.
 *   This reader intentionally does NOT merge that default set — `all()`
 *   returns the raw stored array (exactly like `get_option(KEY, array())`),
 *   and the per-key typed accessors below reproduce the *consumer-side*
 *   `?? default` / `! empty()` semantics that each runtime call site used
 *   before this refactor, NOT the admin tab defaults. The two diverge on
 *   purpose for `ip_cache_enabled` (tab default is true, but every consumer
 *   gates on `! empty()`, so an absent key reads false here).
 */
final class GeolocationSettingsReader {

	/**
	 * WP option key that backs every read in this class.
	 */
	public const OPTION_KEY = 'ffc_geolocation_settings';

	/**
	 * Read a value from `ffc_geolocation_settings`.
	 *
	 * @param string $key     Settings key.
	 * @param mixed  $default Value returned when the key is absent.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$settings = self::all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Return the raw `ffc_geolocation_settings` array — no defaults merge.
	 *
	 * Behaves exactly like `get_option( self::OPTION_KEY, array() )`,
	 * coercing non-arrays to `array()` so consumers that pass the whole
	 * array around are unaffected.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/** Whether the IP-geolocation provider lookup is enabled (default false). */
	public static function ip_api_enabled(): bool {
		return ! empty( self::all()['ip_api_enabled'] );
	}

	/**
	 * Whether IP-geolocation lookups are cached.
	 *
	 * NOTE: the admin tab default is `true`, but every consumer gates on
	 * `! empty( $settings['ip_cache_enabled'] )`, so an absent key reads
	 * false here — matching the runtime behaviour, not the tab default.
	 */
	public static function ip_cache_enabled(): bool {
		return ! empty( self::all()['ip_cache_enabled'] );
	}

	/** IP-geolocation provider service id (default 'ip-api'). */
	public static function ip_api_service(): string {
		return self::all()['ip_api_service'] ?? 'ip-api';
	}

	/** Whether both IP providers are used with fallback (default false). */
	public static function ip_api_cascade(): bool {
		return ! empty( self::all()['ip_api_cascade'] );
	}

	/** TTL for cached IP-resolved locations, in seconds (default 600). */
	public static function ip_cache_ttl(): int {
		$opt = self::all();
		return ! empty( $opt['ip_cache_ttl'] ) ? absint( $opt['ip_cache_ttl'] ) : 600;
	}

	/** The ipinfo.io API key (default empty string). */
	public static function ipinfo_api_key(): string {
		return (string) ( self::all()['ipinfo_api_key'] ?? '' );
	}

	/** TTL for cached GPS-resolved locations, in seconds (default 600). */
	public static function gps_cache_ttl(): int {
		$opt = self::all();
		return ! empty( $opt['gps_cache_ttl'] ) ? absint( $opt['gps_cache_ttl'] ) : 600;
	}

	/** Fallback behaviour when the IP API fails (default 'gps_only'). */
	public static function api_fallback(): string {
		return self::all()['api_fallback'] ?? 'gps_only';
	}

	/** Whether admins bypass datetime restrictions (default false). */
	public static function admin_bypass_datetime(): bool {
		return ! empty( self::all()['admin_bypass_datetime'] );
	}

	/** Whether admins bypass geolocation restrictions (default false). */
	public static function admin_bypass_geo(): bool {
		return ! empty( self::all()['admin_bypass_geo'] );
	}
}
