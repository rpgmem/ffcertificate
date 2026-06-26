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

use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Security\RateLimiter;
use FreeFormCertificate\Security\RateLimitChecker;
use FreeFormCertificate\Security\RateLimitLogger;

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
	 * Resolution order matches the existing plugin convention:
	 *   1. `settings['read']['respect_whitelist']` — when on AND the
	 *      caller IP is in `settings['whitelist']['ips']`, bypass.
	 *   2. `settings['read']['bypass_logged_in']` — when on AND the
	 *      caller is `is_user_logged_in()`, bypass.
	 *   3. `RateLimiter::check_read_limit()` — check counters.
	 *      Block → log via `RateLimitLogger`, return 429.
	 *      Allow → `record_read_attempt()`, return null.
	 *
	 * @since 6.6.2
	 * @param string $endpoint_key Endpoint identifier matching the
	 *                             `settings['read']['endpoints'][$key]`
	 *                             slot (e.g. `calendar_slots`).
	 * @return \WP_Error|null
	 */
	protected function guard_read( string $endpoint_key ): ?\WP_Error {
		$ip       = RequestInput::get_user_ip();
		$settings = RateLimiter::get_settings();
		$read     = is_array( $settings['read'] ?? null ) ? $settings['read'] : array();

		// Bypass 1 — IP whitelist.
		if ( ! empty( $read['respect_whitelist'] ) && RateLimitChecker::is_ip_whitelisted( $ip ) ) {
			return null;
		}

		// Bypass 2 — logged-in users (staff / kiosks signed in to WP).
		if ( ! empty( $read['bypass_logged_in'] ) && is_user_logged_in() ) {
			return null;
		}

		$result = RateLimiter::check_read_limit( $ip, $endpoint_key );

		if ( ! $result['allowed'] ) {
			$identifier = $ip . '|' . $endpoint_key;
			$reason     = isset( $result['reason'] ) ? (string) $result['reason'] : 'read_limit';

			RateLimitLogger::log_attempt( 'read', $identifier, 'blocked', $reason, null );

			$wait    = isset( $result['wait_seconds'] ) ? (int) $result['wait_seconds'] : 60;
			$message = isset( $result['message'] ) ? (string) $result['message'] : __( 'Too many requests. Please slow down.', 'ffcertificate' );

			return new \WP_Error(
				'rate_limit_exceeded',
				$message,
				array(
					'status'  => 429,
					'headers' => array(
						'Retry-After' => max( 1, $wait ),
					),
				)
			);
		}

		RateLimiter::record_read_attempt( $ip, $endpoint_key );
		return null;
	}
}
