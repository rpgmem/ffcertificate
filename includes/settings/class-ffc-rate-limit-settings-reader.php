<?php
/**
 * Rate Limit Settings Reader.
 *
 * Centralized accessor for `ffc_rate_limit_settings` option reads.
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
 * Read-side facade over the `ffc_rate_limit_settings` WP option.
 *
 * Mirrors {@see SettingsReader} but scoped to the rate-limit option group.
 *
 * IMPORTANT — defaults ownership:
 *   The canonical (deeply nested) defaults for this option group live in
 *   {@see \FreeFormCertificate\Security\RateLimitChecker::get_settings()}
 *   (which merges them via `wp_parse_args`) and the admin tab merges them
 *   recursively in {@see \FreeFormCertificate\Settings\Tabs\TabRateLimit}.
 *   This reader intentionally does NOT merge any defaults — `all()` returns
 *   the raw stored array (exactly like `get_option( KEY, array() )`),
 *   matching the ad-hoc consumer in the REST controller that read the
 *   stored array directly and applied inline `?? N` per-leaf fallbacks.
 */
final class RateLimitSettingsReader {

	/**
	 * WP option key that backs every read in this class.
	 */
	public const OPTION_KEY = 'ffc_rate_limit_settings';

	/**
	 * Read a value from `ffc_rate_limit_settings`.
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
	 * Return the raw `ffc_rate_limit_settings` array — no defaults merge.
	 *
	 * Behaves exactly like `get_option( self::OPTION_KEY, array() )`,
	 * coercing non-arrays to `array()`.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * The `ip` sub-group of the stored settings (empty array when absent).
	 *
	 * @return array<string, mixed>
	 */
	public static function ip(): array {
		$ip = self::all()['ip'] ?? array();
		return is_array( $ip ) ? $ip : array();
	}
}
