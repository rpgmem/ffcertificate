<?php
/**
 * SecurityFieldsGuard — pipeline stages 4–5 (#563 Sprint 1).
 *
 * Validates the security fields (math CAPTCHA + honeypot) via
 * SecurityService. On failure it mints a fresh CAPTCHA so the client can
 * retry inline; since 6.23.0 a redeemed challenge is spent, so every later
 * rejection is given one too — see FormProcessor's catch.
 *
 * Runs after NonceGuard, so the $_POST read here is nonce-verified.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CAPTCHA + honeypot validation gate.
 */
class SecurityFieldsGuard {

	/**
	 * Reject the request when the security fields fail validation.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected When CAPTCHA / honeypot validation fails.
	 */
	public function apply( SubmissionContext $ctx ): void {
		\FreeFormCertificate\Core\Debug::log_form(
			'Captcha submitted',
			array(
				'answer_present' => '' !== RequestInput::get_post_string( 'ffc_captcha_ans' ) ? 'yes' : 'no',
				'token_present'  => '' !== RequestInput::get_post_string( 'ffc_captcha_hash' ) ? 'yes' : 'no',
			)
		);

		// Validate security fields (CAPTCHA + honeypot).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by NonceGuard; SecurityService sanitizes internally.
		$security_check = \FreeFormCertificate\Core\SecurityService::validate_security_fields( $_POST );
		if ( true !== $security_check ) {
			// Generate new captcha for retry.
			$new_captcha = \FreeFormCertificate\Core\SecurityService::generate_simple_captcha();
			throw new SubmissionRejected(
				array(
					'message'         => $security_check,
					'refresh_captcha' => true,
					'new_label'       => $new_captcha['label'],
					'new_hash'        => $new_captcha['hash'],
				)
			);
		}
	}
}
