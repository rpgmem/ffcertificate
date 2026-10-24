<?php
/**
 * Rate Limit Settings Reader.
 *
 * Centralized accessor for `ffc_rate_limit_settings` option reads.
 *
 * @package FreeFormCertificate\Settings
 * @since   6.12.0
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
	 * Device-test runs one address may make per window by default: a
	 * classroom behind one institutional address, each person reloading a
	 * few times (#2064).
	 */
	public const DEVICE_CHECK_CAP_DEFAULT = 60;

	/**
	 * Lowest device-test cap; 0 turns the counter off.
	 */
	public const DEVICE_CHECK_CAP_MIN = 0;

	/**
	 * Highest device-test cap.
	 */
	public const DEVICE_CHECK_CAP_MAX = 10000;

	/**
	 * Default device-test window, in seconds.
	 */
	public const DEVICE_CHECK_WINDOW_DEFAULT = 600;

	/**
	 * Shortest device-test window, in seconds: a value the counter can use,
	 * not a recommendation.
	 */
	public const DEVICE_CHECK_WINDOW_MIN = 1;

	/**
	 * Longest device-test window, in seconds.
	 */
	public const DEVICE_CHECK_WINDOW_MAX = 3600;

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

	/**
	 * Clamp a device-test cap into the allowed range.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function clamp_device_check_cap( $value ): int {
		return self::clamp( $value, self::DEVICE_CHECK_CAP_MIN, self::DEVICE_CHECK_CAP_MAX, self::DEVICE_CHECK_CAP_DEFAULT );
	}

	/**
	 * Clamp a device-test window into the allowed range.
	 *
	 * @param mixed $value Raw value, in seconds.
	 * @return int
	 */
	public static function clamp_device_check_window( $value ): int {
		return self::clamp( $value, self::DEVICE_CHECK_WINDOW_MIN, self::DEVICE_CHECK_WINDOW_MAX, self::DEVICE_CHECK_WINDOW_DEFAULT );
	}

	/**
	 * How many times one address may run the device test per window,
	 * bounded on read so a value written before a bound moved still lands in
	 * range (#2064). Stored in the `ip` group, beside the captcha cap,
	 * because it is limited per address.
	 *
	 * @return int Runs per window; 0 means no cap.
	 */
	public static function device_check_cap(): int {
		return self::clamp_device_check_cap( self::ip()['device_check_max_per_window'] ?? self::DEVICE_CHECK_CAP_DEFAULT );
	}

	/**
	 * The device test's window, bounded on read.
	 *
	 * @return int Window length in seconds.
	 */
	public static function device_check_window(): int {
		return self::clamp_device_check_window( self::ip()['device_check_window_seconds'] ?? self::DEVICE_CHECK_WINDOW_DEFAULT );
	}

	/**
	 * Bound a number, falling back when it is not one.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Lowest value.
	 * @param int   $max      Highest value.
	 * @param int   $fallback Value for anything not numeric.
	 * @return int
	 */
	private static function clamp( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}
}
