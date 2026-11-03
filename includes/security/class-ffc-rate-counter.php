<?php
/**
 * RateCounter — the persistent store behind the short-window rate counters.
 *
 * The hour, minute and cooldown counters used to live in `wp_cache_*`
 * alone. Without a persistent object cache (Redis, Memcached) that cache
 * lives for one request, so every counter started at zero on every request
 * and no short-window limit ever tripped (#1861). A transient persists in
 * both cases: WordPress routes it to the object cache when one is
 * installed, and to the options table when not.
 *
 * Keys keep their `ffc_` prefix, so the transient sweep in `uninstall.php`
 * covers them.
 *
 * @package FreeFormCertificate\Security
 * @since 6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent counter over transients.
 */
final class RateCounter {

	/**
	 * Current value of a counter, 0 when absent or expired.
	 *
	 * @param string $key Counter key (`ffc_` prefixed).
	 * @return int
	 */
	public static function get( string $key ): int {
		$value = get_transient( $key );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Add one to a counter and (re)arm its expiry.
	 *
	 * @param string $key Counter key (`ffc_` prefixed).
	 * @param int    $ttl Lifetime in seconds.
	 * @return int The new value.
	 */
	public static function increment( string $key, int $ttl ): int {
		$next = self::get( $key ) + 1;
		set_transient( $key, $next, $ttl );
		return $next;
	}

	/**
	 * A counter key for the current fixed window of `$seconds`.
	 *
	 * The window number is part of the key, the way `WindowThrottle` does
	 * it (#2339). A key without one re-armed its expiry on every hit, so a
	 * caller who kept coming back inside the window was never released.
	 *
	 * @param string $key     Counter key (`ffc_` prefixed), without a window.
	 * @param int    $seconds Window length in seconds.
	 * @return string
	 */
	public static function windowed( string $key, int $seconds ): string {
		return $key . '_' . (int) floor( time() / max( 1, $seconds ) );
	}

	/**
	 * Store a value outright, such as the time of the last attempt.
	 *
	 * @param string $key   Counter key (`ffc_` prefixed).
	 * @param int    $value Value to store.
	 * @param int    $ttl   Lifetime in seconds.
	 */
	public static function put( string $key, int $value, int $ttl ): void {
		set_transient( $key, $value, $ttl );
	}
}
