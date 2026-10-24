<?php
/**
 * WindowThrottle
 *
 * A per-address counter in fixed windows, kept in a transient. It is the
 * throttle of the public endpoints that answer before any form is filled in
 * — the ALTCHA challenge and the device test's ping (#2064) — where spending
 * the submission budget `RateLimiter` counts in its tables would throttle
 * people who have not yet sent anything.
 *
 * Fixed windows, not a sliding one: the key carries the window number, so a
 * steady caller crosses into a fresh bucket instead of holding one open.
 * Re-setting a single transient on every hit would renew its expiry each time
 * and never reset the count. `RateLimitChecker::check_global_limit()` buckets
 * the same way.
 *
 * The address is hashed and never stored raw: an option name is readable by
 * anything that can list options, and a visitor IP is personal data. The
 * window length is part of the bucket number, so changing it in the settings
 * moves callers into a fresh bucket rather than reinterpreting counts taken
 * under the old length.
 *
 * @package FreeFormCertificate\Core
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixed-window counter per address.
 */
final class WindowThrottle {

	/**
	 * Count one hit for the caller and say whether it is within the cap.
	 *
	 * A cap of 0 means no cap: nothing is read or written, so "off" costs
	 * nothing. A request with no resolvable address is let through, because
	 * there is nothing to count it against.
	 *
	 * @param string $prefix Transient prefix, `ffc_…` with no leading
	 *                       underscore so the uninstall sweep matches it.
	 * @param int    $cap    Hits allowed per window; 0 disables the counter.
	 * @param int    $window Window length in seconds, at least 1.
	 * @return bool True when the hit is allowed.
	 */
	public static function take( string $prefix, int $cap, int $window ): bool {
		if ( $cap <= 0 ) {
			return true;
		}

		$ip = RequestInput::get_user_ip();
		if ( '' === $ip ) {
			return true;
		}

		$window = max( 1, $window );
		$key    = $prefix . substr( hash( 'sha256', $ip ), 0, 32 ) . '_' . (int) floor( time() / $window );
		$count  = get_transient( $key );
		$count  = is_numeric( $count ) ? (int) $count : 0;

		if ( $count >= $cap ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * Seconds until the current window ends, which is when a refused caller
	 * may try again.
	 *
	 * @param int $window Window length in seconds.
	 * @return int
	 */
	public static function seconds_left( int $window ): int {
		$window = max( 1, $window );

		return $window - ( time() % $window );
	}
}
