<?php
/**
 * IpLimiter — per-IP rate-limit strategy (#563 Sprint 4, A4).
 *
 * Hour counter (object cache) + day counter (DB) + cooldown window. Extracted
 * verbatim from RateLimitChecker::check_ip_limit().
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-IP submission rate limit.
 */
class IpLimiter implements RateLimitStrategy {

	/**
	 * Shared support (settings + message formatter).
	 *
	 * @var RateLimitSupport
	 */
	private RateLimitSupport $support;

	/**
	 * Constructor.
	 *
	 * @param RateLimitSupport $support Injected shared support.
	 */
	public function __construct( RateLimitSupport $support ) {
		$this->support = $support;
	}

	/**
	 * Evaluate the per-IP limit.
	 *
	 * @param string   $identifier IP address.
	 * @param int|null $form_id    Form ID.
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public function check( string $identifier, ?int $form_id = null ): array {
		$ip = $identifier;
		$s  = $this->support->settings()['ip'];

		// Respect the operator's master toggle. The form-submission DoS gate
		// (FormProcessor entry pipeline) calls this DIRECTLY at the top of the
		// handler — before, and independently of, check_all()'s own gating.
		// Without this guard a form would keep blocking off a stale hour/day
		// counter even after the operator turned IP rate limiting OFF.
		if ( empty( $s['enabled'] ) ) {
			return array( 'allowed' => true );
		}

		$hk = 'ffc_rate_ip_' . md5( $ip . $form_id ) . '_hour';
		// Use Object Cache API (auto Redis/Memcached if available).
		$hc = wp_cache_get( $hk, RateLimiter::CACHE_GROUP );
		$hc = false !== $hc ? $hc : 0;
		if ( $hc >= $s['max_per_hour'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'ip_hour_limit',
				'message'      => $this->support->format_message( $s['message'], array( 'time' => __( '1 hour', 'ffcertificate' ) ) ),
				'wait_seconds' => 3600,
			);
		}

		$dc = RateLimitRepository::get_count_from_db( 'ip', $ip, 'day', $form_id );
		if ( $dc >= $s['max_per_day'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'ip_day_limit',
				'message'      => $this->support->format_message( $s['message'], array( 'time' => __( '24 hours', 'ffcertificate' ) ) ),
				'wait_seconds' => 86400,
			);
		}

		$last = wp_cache_get( 'ffc_rate_ip_' . md5( $ip . $form_id ) . '_last', RateLimiter::CACHE_GROUP );
		if ( $last && ( time() - $last ) < $s['cooldown_seconds'] ) {
			$w = $s['cooldown_seconds'] - ( time() - $last );
			return array(
				'allowed'      => false,
				'reason'       => 'ip_cooldown',
				'message'      => sprintf(
					/* translators: %d: number of seconds to wait */
					__( 'Please wait %d seconds.', 'ffcertificate' ),
					$w
				),
				'wait_seconds' => $w,
			);
		}

		return array( 'allowed' => true );
	}
}
