<?php
/**
 * SecurityFieldsGuard — pipeline stages 4–5 (#563 Sprint 1).
 *
 * Emits the CAPTCHA debug trace, then validates the security fields
 * (math CAPTCHA + honeypot) via SecurityService. On failure it mints a
 * fresh CAPTCHA so the client can retry inline.
 *
 * Runs after NonceGuard, so the $_POST read here is nonce-verified.
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
		// ===== DEBUG CAPTCHA =====.
		\FreeFormCertificate\Core\Debug::log_form( '===== CAPTCHA DEBUG =====' );
		\FreeFormCertificate\Core\Debug::log_form( 'Answer received', RequestInput::get_post_string( 'ffc_captcha_ans', 'NOT SET' ) );
		\FreeFormCertificate\Core\Debug::log_form( 'Hash received', RequestInput::get_post_string( 'ffc_captcha_hash', 'NOT SET' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() check only; values read via sanitized accessor below.
		if ( isset( $_POST['ffc_captcha_ans'] ) && isset( $_POST['ffc_captcha_hash'] ) ) {
			$test_answer    = trim( RequestInput::get_post_string( 'ffc_captcha_ans' ) );
			$received_hash  = RequestInput::get_post_string( 'ffc_captcha_hash' );
			$generated_hash = wp_hash( $test_answer . 'ffc_math_salt' );

			\FreeFormCertificate\Core\Debug::log_form( 'Trimmed answer', $test_answer );
			\FreeFormCertificate\Core\Debug::log_form( 'Generated hash from answer', $generated_hash );
			\FreeFormCertificate\Core\Debug::log_form( 'Hashes match', $generated_hash === $received_hash ? 'YES' : 'NO' );

			// Test with different variations.
			\FreeFormCertificate\Core\Debug::log_form( 'Test with (int)', wp_hash( (int) $test_answer . 'ffc_math_salt' ) );
			\FreeFormCertificate\Core\Debug::log_form( 'Test with (string)', wp_hash( (string) $test_answer . 'ffc_math_salt' ) );
		}
		\FreeFormCertificate\Core\Debug::log_form( '===== END CAPTCHA DEBUG =====' );
		// ===== END DEBUG =====.

		// Validate security fields (CAPTCHA + honeypot).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by NonceGuard; SecurityService sanitizes internally.
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
