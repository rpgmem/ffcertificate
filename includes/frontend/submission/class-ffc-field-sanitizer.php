<?php
/**
 * FieldSanitizer — pipeline stages 8–9 (#563 Sprint 1).
 *
 * Processes + sanitizes every submitted field into `submission_data`,
 * resolves the submitter email, validates CPF/RF, stamps the LGPD consent,
 * and captures the restriction inputs (password / ticket / cpf) onto the
 * context. Rejects on an invalid CPF/RF or an empty (post-sanitize) email.
 *
 * Runs after NonceGuard, so the $_POST reads here are nonce-verified.
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
 * Field sanitization + CPF/RF validation gate.
 */
class FieldSanitizer {

	/**
	 * Build sanitized submission data + restriction inputs on the context.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected When CPF/RF is invalid or email is empty.
	 */
	public function apply( SubmissionContext $ctx ): void {
		$submission_data = array();
		$user_email      = '';

		// Name fields that should be normalized (capitalized with lowercase connectives).
		$name_fields = array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome', 'participante' );

		foreach ( $ctx->fields_config as $field ) {
			// Skip display-only field types (no user input).
			if ( isset( $field['type'] ) && in_array( $field['type'], array( 'info', 'embed' ), true ) ) {
				continue;
			}

			$name = $field['name'];
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified upstream; value unslashed and sanitized below.
			if ( isset( $_POST[ $name ] ) ) {
				$value = \FreeFormCertificate\Core\DataSanitizer::recursive_sanitize( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via recursive_sanitize().

				// Normalize name fields (proper capitalization with lowercase connectives).
				if ( in_array( $name, $name_fields, true ) && is_string( $value ) && ! empty( $value ) ) {
					$value = \FreeFormCertificate\Core\DataSanitizer::normalize_brazilian_name( $value );
				}

				// Special validation for CPF/RF.
				if ( 'cpf_rf' === $name ) {
					$value = preg_replace( '/\D/', '', $value );

					// Validate length.
					if ( strlen( $value ) !== 7 && strlen( $value ) !== 11 ) {
						throw new SubmissionRejected( array( 'message' => __( 'CPF/RF must be exactly 7 or 11 digits.', 'ffcertificate' ) ) );
					}

					// Validate CPF (11 digits) using official algorithm.
					if ( strlen( $value ) === 11 ) {
						if ( ! \FreeFormCertificate\Core\DocumentFormatter::validate_cpf( $value ) ) {
							throw new SubmissionRejected( array( 'message' => __( 'Invalid CPF. Please check the number and try again.', 'ffcertificate' ) ) );
						}
					}

					// Validate RF (7 digits) - must be numeric.
					if ( strlen( $value ) === 7 ) {
						if ( ! \FreeFormCertificate\Core\DocumentFormatter::validate_rf( $value ) ) {
							throw new SubmissionRejected( array( 'message' => __( 'Invalid RF. Must contain only numbers.', 'ffcertificate' ) ) );
						}
					}
				}

				$submission_data[ $name ] = $value;

				if ( isset( $field['type'] ) && 'email' === $field['type'] ) {
					// Normalize email to lowercase for consistent storage and lookups.
					$user_email = strtolower( sanitize_email( $value ) );
				}
			}
		}

		// Defensive: after the field loop ran, the email field may have
		// failed sanitize_email() despite passing the preflight presence
		// check (e.g. user typed "not an email"). Keep this gate as a
		// fallback so $user_email is never empty downstream.
		if ( empty( $user_email ) ) {
			throw new SubmissionRejected( array( 'message' => __( 'Email address is required.', 'ffcertificate' ) ) );
		}

		// LGPD already validated up-front (PreflightGuard); just stamp the
		// consent on the submission data.
		$submission_data['ffc_lgpd_consent'] = '1';

		// Capture restriction fields (password/ticket) from POST.
		$val_password = trim( RequestInput::get_post_string( 'ffc_password' ) );
		$val_ticket   = strtoupper( trim( RequestInput::get_post_string( 'ffc_ticket' ) ) );
		$val_cpf      = isset( $submission_data['cpf_rf'] ) ? trim( (string) $submission_data['cpf_rf'] ) : '';

		$ctx->submission_data = $submission_data;
		$ctx->user_email      = $user_email;
		$ctx->val_password    = $val_password;
		$ctx->val_ticket      = $val_ticket;
		$ctx->val_cpf         = $val_cpf;
	}
}
