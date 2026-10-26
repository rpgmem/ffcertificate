<?php
/**
 * FieldSanitizer — pipeline stages 8–9 (#563 Sprint 1).
 *
 * Processes + sanitizes every submitted field into `submission_data`,
 * resolves the submitter email, validates CPF/RF, stamps the LGPD consent,
 * and captures the restriction inputs (password / ticket / cpf) onto the
 * context. Rejects on an invalid CPF/RF or an empty (post-sanitize) email.
 *
 * Runs after NonceGuard on the web form, so the $_POST reads (through
 * SubmissionInput) are nonce-verified; the REST route reads its JSON body.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

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
		$email_typed     = false;

		// Name fields that should be normalized (capitalized with lowercase
		// connectives) -- the key list is `SubmitterName`'s (#1480).
		$name_fields = \FreeFormCertificate\Core\SubmitterName::CANDIDATE_KEYS;

		foreach ( $ctx->fields_config as $field ) {
			// Skip display-only field types (no user input).
			if ( isset( $field['type'] ) && in_array( $field['type'], array( 'info', 'embed' ), true ) ) {
				continue;
			}

			$name = $field['name'];

			// A hidden field carries the value the form defines, never the
			// one the request carries: it has no visible control, so a
			// different value can only come from an edited page (#1574).
			if ( isset( $field['type'] ) && 'hidden' === $field['type'] ) {
				$submission_data[ $name ] = sanitize_text_field( (string) ( $field['default_value'] ?? '' ) );
				continue;
			}

			// Only the fields the form defines are read: any other key in the
			// request is ignored, on the web form and on the REST route alike.
			$raw = SubmissionInput::raw( $ctx, (string) $name );
			if ( null !== $raw ) {
				$value = \FreeFormCertificate\Core\DataSanitizer::recursive_sanitize( $raw );

				// Normalize name fields (proper capitalization with lowercase connectives).
				if ( in_array( $name, $name_fields, true ) && is_string( $value ) && ! empty( $value ) ) {
					$value = \FreeFormCertificate\Core\DataSanitizer::normalize_brazilian_name( $value );
				}

				// Special validation for CPF/RF.
				if ( 'cpf_rf' === $name ) {
					$value = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $value );

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
					$email_typed = $email_typed || ( is_string( $value ) && '' !== trim( $value ) );
					// Normalize email to lowercase for consistent storage and lookups.
					$candidate  = \FreeFormCertificate\Core\DataSanitizer::normalize_email( sanitize_email( is_string( $value ) ? $value : '' ) );
					$user_email = is_email( $candidate ) ? $candidate : '';
				}
			}
		}

		// Defensive: after the field loop ran, the email field may have
		// failed sanitize_email() despite passing the RequiredPresenceGuard presence
		// check (e.g. user typed "not an email"). Keep this gate as a
		// fallback so $user_email is never empty downstream.
		if ( empty( $user_email ) ) {
			// Something was typed and `sanitize_email()` refused it: that is
			// an invalid address, not a missing one (#1734).
			throw new SubmissionRejected(
				array(
					'message' => $email_typed
						? __( 'Enter a valid email address.', 'ffcertificate' )
						: __( 'Email address is required.', 'ffcertificate' ),
				)
			);
		}

		// LGPD already validated up-front (RequiredPresenceGuard); just stamp the
		// consent on the submission data.
		$submission_data['ffc_lgpd_consent'] = '1';

		// Capture restriction fields (password/ticket) from POST.
		$val_password = trim( SubmissionInput::string( $ctx, 'ffc_password' ) );
		$val_ticket   = strtoupper( trim( SubmissionInput::string( $ctx, 'ffc_ticket' ) ) );
		$val_cpf      = isset( $submission_data['cpf_rf'] ) ? trim( (string) $submission_data['cpf_rf'] ) : '';

		$ctx->submission_data = $submission_data;
		$ctx->user_email      = $user_email;
		$ctx->val_password    = $val_password;
		$ctx->val_ticket      = $val_ticket;
		$ctx->val_cpf         = $val_cpf;
	}
}
