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
 * anything that can list options, and a visitor IP is personal data. It is
 * counted by `IpAddress::rate_key()`, the plugin's one rule for which
 * requests are the same caller: an IPv4 address whole, an IPv6 address by
 * its /64 (#2106). The
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
	 * nothing. A caller whose address the resolver could not read arrives as
	 * `ClientIpResolver::UNKNOWN`, never as an empty string, and what happens
	 * to it is the administrator's choice per endpoint (#2106): let through
	 * uncounted, or counted together in one shared bucket. On an intranet of
	 * private addresses every visitor is unresolved, so the shared bucket
	 * caps the whole network at once.
	 *
	 * @param string $prefix          Transient prefix, `ffc_…` with no leading
	 *                                underscore so the uninstall sweep matches it.
	 * @param int    $cap             Hits allowed per window; 0 disables the counter.
	 * @param int    $window          Window length in seconds, at least 1.
	 * @param bool   $count_unresolved True to count unresolved callers together;
	 *                                false lets them through uncounted.
	 * @return bool True when the hit is allowed.
	 */
	public static function take( string $prefix, int $cap, int $window, bool $count_unresolved = false ): bool {
		if ( $cap <= 0 ) {
			return true;
		}

		$caller = IpAddress::rate_key( RequestInput::get_user_ip() );
		if ( null === $caller ) {
			if ( ! $count_unresolved ) {
				return true;
			}
			$caller = 'unresolved';
		}

		$window = max( 1, $window );
		$key    = $prefix . substr( hash( 'sha256', $caller ), 0, 32 ) . '_' . (int) floor( time() / $window );
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
