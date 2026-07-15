<?php
/**
 * CacheVersion
 *
 * Coarse-grained cache invalidation for caches whose keys cannot be
 * enumerated at write time — e.g. `md5( args )` query hashes. A monotonic
 * version integer is stored per domain in `wp_options` and folded into the
 * cache key; a single {@see self::bump()} atomically retires every entry
 * written under the previous version (later lookups miss and recompute; the
 * orphaned entries expire under their own TTL or are LRU-evicted).
 *
 * This is the canonical home for the pattern that previously lived inline in
 * the recruitment public-listing shortcode.
 *
 * @package FreeFormCertificate\Core
 * @since 6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-domain cache version counter.
 */
final class CacheVersion {

	/**
	 * Domain → `wp_options` key.
	 *
	 * New domains follow `ffc_cache_version_<domain>`. `recruitment_public`
	 * keeps its pre-existing option name so upgrading does not cold-start the
	 * recruitment public-listing cache.
	 *
	 * @var array<string, string>
	 */
	private const OPTIONS = array(
		'recruitment_public' => 'ffc_recruitment_public_cache_version',
		'audience'           => 'ffc_cache_version_audience',
	);

	/**
	 * Resolve the `wp_options` key backing a domain's version counter.
	 *
	 * @param string $domain Cache domain (e.g. `audience`).
	 * @return string Option name.
	 */
	private static function option_name( string $domain ): string {
		return self::OPTIONS[ $domain ] ?? 'ffc_cache_version_' . $domain;
	}

	/**
	 * Current version counter for a domain (0 when never bumped).
	 *
	 * @param string $domain Cache domain.
	 * @return int Monotonic version.
	 */
	public static function current( string $domain ): int {
		return (int) get_option( self::option_name( $domain ), 0 );
	}

	/**
	 * Version token to fold into a cache key, e.g. `v7`.
	 *
	 * @param string $domain Cache domain.
	 * @return string Suffix token.
	 */
	public static function suffix( string $domain ): string {
		return 'v' . self::current( $domain );
	}

	/**
	 * Retire every cache entry written under the current version by
	 * incrementing the counter.
	 *
	 * `autoload => false` keeps the option out of `alloptions`. The counter
	 * wraps at `PHP_INT_MAX` — theoretical (~292 billion years at one bump
	 * per second), the modulo only guards against accidental misconfiguration.
	 *
	 * @param string $domain Cache domain.
	 * @return void
	 */
	public static function bump( string $domain ): void {
		$option  = self::option_name( $domain );
		$current = (int) get_option( $option, 0 );
		update_option( $option, ( $current + 1 ) % PHP_INT_MAX, false );
	}
}
