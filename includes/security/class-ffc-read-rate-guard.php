<?php
/**
 * Read Rate Guard
 *
 * The per-endpoint read pool (#259), as one static entry point: checks the
 * caller against an endpoint of `settings['read']['endpoints']`, logs a
 * refusal and records an allowed request. `ReadRateLimitGuardTrait` (the REST
 * and AJAX readers) and the public CSV endpoints (#2341) both call it, so the
 * bypasses, the log line and the recording cannot drift between them.
 *
 * @package FreeFormCertificate\Security
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gate for a request counted in an endpoint's read pool.
 */
final class ReadRateGuard {

	/**
	 * Check the current request against an endpoint's pool, and count it.
	 *
	 * Resolution order:
	 *   1. `settings['read']['respect_whitelist']` — when on AND the caller
	 *      IP is whitelisted, bypass.
	 *   2. `settings['read']['bypass_logged_in']` — when on AND the caller is
	 *      logged in, bypass; unless `$count_logged_in`, which counts a
	 *      logged-in caller by account instead (#2243).
	 *   3. `RateLimiter::check_read_limit()`. Refused: logged through
	 *      `RateLimitLogger`, a 429 `WP_Error` with `Retry-After`. Allowed:
	 *      `record_read_attempt()`, then null.
	 *
	 * @param string $endpoint_key    Endpoint key of `READ_ENDPOINT_DEFAULTS`.
	 * @param bool   $count_logged_in Count logged-in callers, by account.
	 * @return \WP_Error|null Null when the caller may go on.
	 */
	public static function check( string $endpoint_key, bool $count_logged_in = false ): ?\WP_Error {
		$ip       = RequestInput::get_user_ip();
		$settings = RateLimiter::get_settings();
		$read     = is_array( $settings['read'] ?? null ) ? $settings['read'] : array();

		if ( ! empty( $read['respect_whitelist'] ) && RateLimitChecker::is_ip_whitelisted( $ip ) ) {
			return null;
		}

		$user_id = 0;
		if ( is_user_logged_in() ) {
			if ( ! $count_logged_in ) {
				if ( ! empty( $read['bypass_logged_in'] ) ) {
					return null;
				}
			} else {
				$user_id = (int) get_current_user_id();
			}
		}

		$result = RateLimiter::check_read_limit( $ip, $endpoint_key, $user_id );

		if ( ! $result['allowed'] ) {
			// The address enters the log as its keyed fingerprint (#2342): a
			// bare sha256 of an IPv4 address is reversed by a table of all 2^32.
			$identifier = \FreeFormCertificate\Core\IpAddress::fingerprint( $ip ) . '|' . $endpoint_key;
			$reason     = isset( $result['reason'] ) ? (string) $result['reason'] : 'read_limit';

			RateLimitLogger::log_attempt( 'read', $identifier, 'blocked', $reason, null );

			$wait = isset( $result['wait_seconds'] ) ? (int) $result['wait_seconds'] : 60;

			return new \WP_Error(
				'rate_limit_exceeded',
				$result['message'],
				array(
					'status'  => 429,
					'headers' => array(
						'Retry-After' => max( 1, $wait ),
					),
				)
			);
		}

		RateLimiter::record_read_attempt( $ip, $endpoint_key, $user_id );
		return null;
	}
}
