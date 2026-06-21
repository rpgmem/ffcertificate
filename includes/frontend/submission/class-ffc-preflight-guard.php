<?php
/**
 * PreflightGuard — pipeline stage 7 (#563 Sprint 1).
 *
 * Cheap O(1) presence checks BEFORE the field-validation loop: LGPD consent
 * + email presence. Running them up front lets the user get both errors in a
 * single response instead of fixing CPF first, resubmitting, then seeing the
 * LGPD error. The combined `errors` array mirrors the legacy refresh_captcha
 * shape so the client needs no new code path.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

use FreeFormCertificate\Core\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LGPD consent + email-presence preflight gate.
 */
class PreflightGuard {

	/**
	 * Reject the request when consent or email presence is missing.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected With every preflight error at once.
	 */
	public function apply( SubmissionContext $ctx ): void {
		$preflight_errors = array();

		// LGPD: trivial string compare, no field loop dependency.
		if ( Utils::get_post_string( 'ffc_lgpd_consent' ) !== '1' ) {
			$preflight_errors[] = __( 'You must agree to the Privacy Policy to continue.', 'ffcertificate' );
		}

		// Email presence: peek at the admin-configured 'email' fields.
		$email_field_names = array();
		foreach ( $ctx->fields_config as $field ) {
			if ( isset( $field['type'], $field['name'] ) && 'email' === $field['type'] ) {
				$email_field_names[] = $field['name'];
			}
		}
		if ( ! empty( $email_field_names ) ) {
			$email_missing = false;
			foreach ( $email_field_names as $email_field ) {
				$raw_email = Utils::get_post_string( $email_field );
				if ( '' === trim( $raw_email ) ) {
					$email_missing = true;
					break;
				}
			}
			if ( $email_missing ) {
				$preflight_errors[] = __( 'Email address is required.', 'ffcertificate' );
			}
		}

		if ( ! empty( $preflight_errors ) ) {
			throw new SubmissionRejected(
				array(
					// Backward-compatible: legacy single-error consumers read
					// `message` (first error). New consumers read the full
					// `errors` array to surface every missing field at once.
					'message' => $preflight_errors[0],
					'errors'  => $preflight_errors,
				)
			);
		}
	}
}
