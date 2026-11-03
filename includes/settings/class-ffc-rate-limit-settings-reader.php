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
 * Defaults ownership: {@see self::defaults()} is the one declaration of the
 * nested defaults, and {@see self::effective()} merges the stored option over
 * them recursively. The runtime ({@see \FreeFormCertificate\Security\RateLimitChecker::get_settings()})
 * and the admin tab both read `effective()`, so the screen shows the values
 * the runtime uses (#2339). They were declared twice and merged two ways:
 * the runtime's shallow `wp_parse_args` let a partially saved group
 * (`ip => ['enabled' => true]`) drop `max_per_hour` and refuse every
 * submission, while the tab rendered the defaults. `all()` still returns the
 * raw stored array for the readers that apply their own per-leaf fallback.
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
	 * A caller whose address could not be resolved is let through uncounted.
	 */
	public const UNRESOLVED_ALLOW = 'allow';

	/**
	 * Every caller whose address could not be resolved counts in one bucket.
	 */
	public const UNRESOLVED_SHARE = 'share';

	/**
	 * The two policies for an unresolved address (#2106), the default first.
	 */
	public const UNRESOLVED_POLICIES = array( self::UNRESOLVED_ALLOW, self::UNRESOLVED_SHARE );

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
	 * The defaults of every rate-limit setting, nested by group.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'ip'        => array(
				'enabled'                     => true,
				'max_per_hour'                => 5,
				'max_per_day'                 => 20,
				'cooldown_seconds'            => 60,
				'apply_to'                    => 'all',
				'message'                     => __( 'Limit reached. Please wait {time}.', 'ffcertificate' ),
				// Challenges the ALTCHA endpoint issues per address per
				// window (#1111). Lives in the IP group because that is
				// what it limits, and next to the submission cap an
				// administrator raises for the same NAT reason. 0 = no cap.
				'captcha_max_per_window'      => \FreeFormCertificate\Core\Captcha\CaptchaSettings::MINT_CAP_DEFAULT,
				'captcha_window_seconds'      => \FreeFormCertificate\Core\Captcha\CaptchaSettings::MINT_WINDOW_DEFAULT,
				// Device-test requests per address per window (#2064), the
				// same per-address shape as the captcha cap. 0 = no cap.
				'device_check_max_per_window' => self::DEVICE_CHECK_CAP_DEFAULT,
				'device_check_window_seconds' => self::DEVICE_CHECK_WINDOW_DEFAULT,
				// What each throttle does with a caller the resolver could
				// not read (#2106): let it through, or count it with every
				// other unresolved caller.
				'captcha_unresolved'          => self::UNRESOLVED_ALLOW,
				'device_check_unresolved'     => self::UNRESOLVED_ALLOW,
			),
			'email'     => array(
				'enabled'        => true,
				'max_per_day'    => 3,
				'max_per_week'   => 10,
				'max_per_month'  => 30,
				'wait_hours'     => 24,
				'apply_to'       => 'all',
				'message'        => __( 'You already have {count} certificates.', 'ffcertificate' ),
				'check_database' => true,
			),
			'cpf'       => array(
				'enabled'         => false,
				'max_per_month'   => 5,
				'max_per_year'    => 50,
				'block_threshold' => 3,
				'block_hours'     => 1,
				'block_duration'  => 24,
				'apply_to'        => 'all',
				'message'         => __( 'CPF/RF limit reached.', 'ffcertificate' ),
				'check_database'  => true,
			),
			'global'    => array(
				'enabled'        => false,
				'max_per_minute' => 100,
				'max_per_hour'   => 1000,
				'message'        => __( 'System unavailable.', 'ffcertificate' ),
			),
			'device'    => array(
				'enabled'                   => false,
				'max_per_form'              => 1,
				// About half of the signals: 7 of 13 (6.3.2).
				'match_threshold'           => 7,
				// A fuzzy block also needs this many STRONG_SIGNALS to match,
				// so weak signals shared by a fleet of one model never block
				// (6.7.8). 0 turns the strong tier off.
				'match_strong_min'          => 2,
				'signals_enabled'           => array( 'cookie', 'ua', 'screen', 'tz', 'concurrency', 'memory', 'canvas', 'audio', 'webgl', 'fonts', 'plugins', 'permissions', 'mediaqueries', 'math' ),
				'bypass_logged_in_managers' => true,
				'bypass_whitelist_signals'  => array(),
				'message'                   => __( 'Multiple submissions detected from this device. Please contact the organizer.', 'ffcertificate' ),
				'retention_days'            => 90,
				'log_blocks'                => true,
			),
			'whitelist' => array(
				// UI visibility flag — when false the rate-limit settings
				// page collapses the Whitelist card to declutter; the
				// lists themselves still apply at runtime if populated.
				// Defaults true so existing installs see no UI change.
				'enabled'       => true,
				'ips'           => array(),
				'emails'        => array(),
				'email_domains' => array(),
				'cpfs'          => array(),
			),
			'blacklist' => array(
				// UI visibility flag — see whitelist['enabled'] above.
				'enabled'       => true,
				'ips'           => array(),
				'emails'        => array(),
				'email_domains' => array(),
				'cpfs'          => array(),
			),
			'logging'   => array(
				'enabled'        => true,
				'log_allowed'    => false,
				'log_blocked'    => true,
				'retention_days' => 30,
				'max_logs'       => 10000,
			),
			'ui'        => array(
				'show_remaining'  => true,
				'show_wait_time'  => true,
				'countdown_timer' => true,
			),
			'read'      => array(
				'respect_whitelist' => true,
				'bypass_logged_in'  => true,
				'message'           => __( 'Too many requests. Please wait {time}.', 'ffcertificate' ),
				// Per-endpoint thresholds (#259), owned by the checker so
				// an endpoint never saved here still reads its default.
				'endpoints'         => \FreeFormCertificate\Security\RateLimitChecker::READ_ENDPOINT_DEFAULTS,
			),
		);
	}

	/**
	 * The stored settings merged over the defaults, recursively.
	 *
	 * A group or a map recurses, so a partially stored group keeps every
	 * default leaf it does not set; a list (the signals enabled, the
	 * whitelisted addresses) is replaced as a whole, because merging a
	 * shorter stored list over a longer default one by index would put back
	 * entries the administrator removed.
	 *
	 * @return array<string, mixed>
	 */
	public static function effective(): array {
		return self::merge( self::defaults(), self::all() );
	}

	/**
	 * Merge stored values over defaults: maps recurse, lists replace.
	 *
	 * @param array<mixed> $defaults Defaults.
	 * @param array<mixed> $stored   Stored values.
	 * @return array<mixed>
	 */
	private static function merge( array $defaults, array $stored ): array {
		foreach ( $stored as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && array() !== $defaults[ $key ] && ! array_is_list( $defaults[ $key ] ) ) {
				$defaults[ $key ] = self::merge( $defaults[ $key ], $value );
				continue;
			}
			$defaults[ $key ] = $value;
		}
		return $defaults;
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
	 * A stored policy for an unresolved address, or the default.
	 *
	 * @param mixed $value Stored or posted value.
	 * @return string One of {@see self::UNRESOLVED_POLICIES}.
	 */
	public static function clamp_unresolved_policy( $value ): string {
		return in_array( $value, self::UNRESOLVED_POLICIES, true ) ? (string) $value : self::UNRESOLVED_ALLOW;
	}

	/**
	 * Whether the ALTCHA challenge counts unresolved callers together.
	 *
	 * @return bool
	 */
	public static function captcha_counts_unresolved(): bool {
		return self::UNRESOLVED_SHARE === self::clamp_unresolved_policy( self::ip()['captcha_unresolved'] ?? null );
	}

	/**
	 * Whether the device test's ping counts unresolved callers together.
	 *
	 * @return bool
	 */
	public static function device_check_counts_unresolved(): bool {
		return self::UNRESOLVED_SHARE === self::clamp_unresolved_policy( self::ip()['device_check_unresolved'] ?? null );
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
