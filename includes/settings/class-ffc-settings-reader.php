<?php
/**
 * Settings Reader.
 *
 * Centralized accessor for `ffc_settings` option reads.
 *
 * @package FreeFormCertificate\Settings
 * @since   6.6.1
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-side facade over the `ffc_settings` WP option.
 *
 * WordPress already caches the option via the autoloaded-options cache
 * (`wp_load_alloptions()`) — so this class adds no perf layer of its
 * own. Its job is:
 *
 *   1. Single source of truth for the option key.
 *   2. Typed accessors with explicit casts + defaults for the
 *      high-value boolean / integer keys, replacing scattered
 *      `$settings['key'] ?? default` patterns.
 *   3. Generic `get()` for the long tail of keys that don't warrant
 *      a dedicated typed accessor yet.
 *
 * Debug-area toggles are NOT exposed here — they're already typed via
 * {@see \FreeFormCertificate\Core\Debug::is_enabled()}, which is the
 * canonical reader for that subset.
 */
final class SettingsReader {

	/**
	 * WP option key that backs every read in this class.
	 */
	public const OPTION_KEY = 'ffc_settings';

	// ──────────────────────────────────────────────────────────────.
	// Generic accessors.
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Read a value from `ffc_settings`.
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
	 * Return the raw `ffc_settings` array. Prefer the typed accessors
	 * or `get()` when reading 1-2 keys; reach for `all()` only when a
	 * caller reads 5+ keys from the same array and array-style access
	 * stays clearer than repeated method calls (e.g. SMTP config block).
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Bool-typed read with explicit cast.
	 *
	 * @param string $key     Settings key.
	 * @param bool   $default Returned when the key is absent.
	 * @return bool
	 */
	public static function get_bool( string $key, bool $default = false ): bool {
		$value = self::get( $key, $default );
		return (bool) $value;
	}

	/**
	 * Int-typed read with explicit cast.
	 *
	 * @param string $key     Settings key.
	 * @param int    $default Returned when the key is absent.
	 * @return int
	 */
	public static function get_int( string $key, int $default = 0 ): int {
		$value = self::get( $key, $default );
		return (int) $value;
	}

	// ──────────────────────────────────────────────────────────────.
	// Typed bool accessors (high-value keys).
	// ──────────────────────────────────────────────────────────────.

	/** Whether the "disable all outgoing emails" master toggle is on. */
	public static function emails_disabled(): bool {
		return self::get_bool( 'disable_all_emails' );
	}

	/** Whether the activity-log subsystem is enabled. */
	public static function activity_log_enabled(): bool {
		return self::get_bool( 'enable_activity_log' );
	}

	/** Whether the WP admin bar is allowed for the FFC user role. */
	public static function admin_bar_allowed(): bool {
		return self::get_bool( 'allow_admin_bar' );
	}

	/** Whether the FFC user role is blocked from wp-admin. */
	public static function wp_admin_blocked(): bool {
		return self::get_bool( 'block_wp_admin' );
	}

	/**
	 * Whether site administrators bypass the FFC user-side restrictions
	 * (admin-bar gating, wp-admin block, etc.).
	 */
	public static function admins_bypassed(): bool {
		return self::get_bool( 'bypass_for_admins' );
	}

	/** Whether the QR-code cache is enabled. */
	public static function qr_cache_enabled(): bool {
		return self::get_bool( 'qr_cache_enabled' );
	}

	/**
	 * Whether the URL-shortener feature is enabled. Defaults to true
	 * (the feature is on out-of-the-box).
	 */
	public static function url_shortener_enabled(): bool {
		return self::get_bool( 'url_shortener_enabled', true );
	}

	/**
	 * Whether auto-creation of short URLs is enabled. Defaults to true.
	 */
	public static function url_shortener_auto_create_enabled(): bool {
		return self::get_bool( 'url_shortener_auto_create', true );
	}

	/** Whether IP-geolocation lookups are cached. */
	public static function ip_cache_enabled(): bool {
		return self::get_bool( 'ip_cache_enabled' );
	}

	/** Whether the IP-geolocation provider lookup is enabled. */
	public static function ip_api_enabled(): bool {
		return self::get_bool( 'ip_api_enabled' );
	}

	/** Whether admins are notified when a capability is granted. */
	public static function notify_capability_grant_enabled(): bool {
		return self::get_bool( 'notify_capability_grant' );
	}

	// ──────────────────────────────────────────────────────────────.
	// Typed int accessors (TTLs / limits).
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Retention window for `ffc_activity_log` rows, in days. Rows
	 * older than this are eligible for the daily cleanup cron.
	 */
	public static function activity_log_retention_days(): int {
		return self::get_int( 'activity_log_retention_days', 90 );
	}

	/** TTL for the WP object cache entries the plugin manages. */
	public static function cache_expiration_seconds(): int {
		return self::get_int( 'cache_expiration', 3600 );
	}

	/**
	 * Number of days after which an obsolete shortcode reference
	 * surfaces a warning in the admin notices stream.
	 */
	public static function obsolete_shortcode_days(): int {
		return self::get_int( 'obsolete_shortcode_days', 30 );
	}

	/** TTL for cached GPS-resolved locations. */
	public static function gps_cache_ttl(): int {
		return self::get_int( 'gps_cache_ttl', 600 );
	}

	/** TTL for cached IP-resolved locations. */
	public static function ip_cache_ttl(): int {
		return self::get_int( 'ip_cache_ttl', 600 );
	}

	/** Default row count returned by the public CSV download endpoint. */
	public static function public_csv_default_limit(): int {
		return self::get_int( 'public_csv_default_limit', 100 );
	}

	/** Hard ceiling on rows the synchronous CSV export will emit. */
	public static function public_csv_sync_max_rows(): int {
		return self::get_int( 'public_csv_sync_max_rows', 5000 );
	}

	/** Default pixel size for generated QR codes. */
	public static function qr_default_size(): int {
		return self::get_int( 'qr_default_size', 256 );
	}

	/** Length (characters) of generated short-URL slugs. */
	public static function url_shortener_code_length(): int {
		return self::get_int( 'url_shortener_code_length', 6 );
	}

	// ──────────────────────────────────────────────────────────────.
	// Typed array accessors.
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Admin-editable Divisão → Setor map for the reregistration
	 * `divisao_setor` dependent-select field. Shape:
	 * `array<string, array<string>>` (division name => list of sectors).
	 *
	 * Returns null when the option is absent or not an array, so the
	 * domain layer (`ReregistrationFieldOptions::get_divisao_setor_map()`)
	 * applies its hardcoded fallback. Kept fallback-free here to avoid a
	 * Settings → Reregistration dependency cycle.
	 *
	 * @return array<string, array<string>>|null
	 */
	public static function divisao_setor_map(): ?array {
		$value = self::get( 'divisao_setor_map', null );
		return is_array( $value ) ? $value : null;
	}
}
