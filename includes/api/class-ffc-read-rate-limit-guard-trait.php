<?php
/**
 * Read Rate-Limit Guard Trait
 *
 * Reusable guard for public GET endpoints that need their own
 * rate-limit pool — see issue #259 for the threat model (scrapers
 * that never submit forms bypass the IP pool's circuit breaker
 * because that pool is only fed by submit/verify activity).
 *
 * Consumers attach the trait and call `guard_read( $endpoint_key )`
 * at the top of every public read handler. Returns `null` on green
 * light (caller continues) or a `WP_Error` 429 on block (caller
 * just returns the error — the trait already shaped the response
 * to match what the rest of the plugin does for rate-limit blocks).
 *
 * Endpoint keys are arbitrary strings the admin configures via the
 * Rate Limit settings tab; the trait validates the supplied key
 * exists in the settings and short-circuits to "allowed" when the
 * specific endpoint is disabled (so adoption stays opt-in per
 * endpoint).
 *
 * @package FreeFormCertificate\API
 * @since   6.6.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared guard for read-only public REST endpoints.
 */
trait ReadRateLimitGuardTrait {

	/**
	 * Gate the current request against the per-endpoint rate-limit
	 * pool. Returns null when the caller is clear (and increments
	 * the counter); returns a `WP_Error` 429 with `Retry-After`
	 * header data otherwise.
	 *
	 * The resolution (whitelist, logged-in bypass, check, log, record) is
	 * {@see \FreeFormCertificate\Security\ReadRateGuard::check()}, which
	 * the public CSV endpoints call directly (#2341).
	 *
	 * @since 6.6.2
	 * @param string $endpoint_key    Endpoint identifier matching the
	 *                                `settings['read']['endpoints'][$key]`
	 *                                slot (e.g. `calendar_slots`).
	 * @param bool   $count_logged_in Count logged-in callers too.
	 * @return \WP_Error|null
	 */
	protected function guard_read( string $endpoint_key, bool $count_logged_in = false ): ?\WP_Error {
		return \FreeFormCertificate\Security\ReadRateGuard::check( $endpoint_key, $count_logged_in );
	}
}
