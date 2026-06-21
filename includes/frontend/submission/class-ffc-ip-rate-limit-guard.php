<?php
/**
 * IpRateLimitGuard — pipeline stage 2 (#563 Sprint 1).
 *
 * Rate limit by IP, run BEFORE nonce/CAPTCHA to prevent brute-force and
 * DoS attacks from consuming server resources on expensive checks.
 *
 * Skipped on the exception path: operators handing tablets at a venue
 * routinely concentrate submissions on one outbound IP, and the signed
 * token already binds the bypass to a 30-minute operator-issued window.
 * Per-CPF rate-limits (in RateLimiter::check_all() later) still apply so a
 * single participant can't replay the token across submissions.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-IP throttle gate.
 */
class IpRateLimitGuard {

	/**
	 * Reject the request when the visitor's IP is over the limit.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected When the IP is rate-limited.
	 */
	public function apply( SubmissionContext $ctx ): void {
		if ( ! $ctx->has_exception && class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			$user_ip    = \FreeFormCertificate\Core\RequestInput::get_user_ip();
			$rate_check = \FreeFormCertificate\Security\RateLimiter::check_ip_limit( $user_ip );
			if ( ! $rate_check['allowed'] ) {
				throw new SubmissionRejected(
					array(
						'message'      => $rate_check['message'] ?? __( 'Too many requests. Please wait.', 'ffcertificate' ),
						'rate_limit'   => true,
						'wait_seconds' => $rate_check['wait_seconds'] ?? 0,
					)
				);
			}
		}
	}
}
