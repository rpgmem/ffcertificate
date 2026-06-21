<?php
/**
 * NonceGuard — pipeline stage 3 (#563 Sprint 1).
 *
 * Verifies the WP nonce. On failure, hands back a fresh nonce keyed to the
 * visitor's current session cookie so the client (FFC.request) can
 * transparently retry once. This works around cached HTML carrying another
 * visitor's nonce on shared hosts, iOS Safari ITP / iCloud Private Relay
 * rotating the session cookie between render and submit, and
 * ffc-dynamic-fragments silently failing on some networks.
 *
 * Safety: a stale-nonce auto-refresh is not a CSRF weakening. The fresh
 * nonce is bound to the cookie of the request that asks for it; an attacker
 * who can't present a valid cookie can't use the returned nonce. Callers
 * are guarded against retry loops (options._ffcNonceRetried in ffc-core.js).
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP nonce verification gate.
 */
class NonceGuard {

	/**
	 * Reject the request with a fresh nonce when verification fails.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected When the nonce is invalid.
	 */
	public function apply( SubmissionContext $ctx ): void {
		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'nonce' ), 'ffc_frontend_nonce' ) ) {
			throw new SubmissionRejected(
				array(
					'message'       => __( 'Security check failed. Please refresh the page.', 'ffcertificate' ),
					'refresh_nonce' => true,
					'new_nonce'     => wp_create_nonce( 'ffc_frontend_nonce' ),
				)
			);
		}
	}
}
