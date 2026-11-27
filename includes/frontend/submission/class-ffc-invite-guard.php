<?php
/**
 * InviteGuard — the invitation stage of the submission pipeline (#1948).
 *
 * Runs after FieldSanitizer, so the typed CPF/RF is already normalised and
 * the posted name and e-mail already read: this stage replaces those two with
 * the invited person's, which a request cannot change.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

use FreeFormCertificate\Frontend\FormInvite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admits an invited person, or refuses a form that admits only them.
 */
class InviteGuard {

	/**
	 * Resolve the invitation, if any, and write its identity into the context.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected When the invitation is refused, or missing on an invite-only form.
	 */
	public function apply( SubmissionContext $ctx ): void {
		$code = trim( SubmissionInput::string( $ctx, FormInvite::QUERY_VAR ) );

		if ( '' === $code ) {
			if ( FormInvite::invite_only( $ctx->form_config ) ) {
				throw new SubmissionRejected( array( 'message' => __( 'This form is open by invitation only. Use the link in the e-mail you received.', 'ffcertificate' ) ) );
			}
			return;
		}

		$invite = FormInvite::resolve( $ctx->form_id, $code, '' !== $ctx->val_cpf ? $ctx->val_cpf : null );
		if ( $invite instanceof \WP_Error ) {
			throw new SubmissionRejected( array( 'message' => $invite->get_error_message() ) );
		}

		foreach ( $ctx->fields_config as $field ) {
			$name = (string) ( $field['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			if ( FormInvite::is_name_field( $field ) && '' !== $invite['name'] ) {
				$ctx->submission_data[ $name ] = \FreeFormCertificate\Core\DataSanitizer::normalize_brazilian_name( $invite['name'] );
			} elseif ( FormInvite::is_email_field( $field ) ) {
				$ctx->submission_data[ $name ] = $invite['email'];
			}
		}

		// A completion link's CPF/RF is the profile's (#2944): the form draws
		// it masked and posts nothing, and a value the request carries is
		// replaced like the name and the e-mail.
		if ( '' !== $invite['cpf_rf'] && self::asks_identifier( $ctx->fields_config ) ) {
			$ctx->submission_data['cpf_rf'] = $invite['cpf_rf'];
			$ctx->val_cpf                   = $invite['cpf_rf'];
		}
		// The fields the profile answers are drawn locked, and the profile is
		// what is kept: an edited page cannot change them either.
		foreach ( FormInvite::profile_values( $ctx->fields_config, $invite ) as $name => $value ) {
			$ctx->submission_data[ $name ] = $value;
		}

		$ctx->user_email = \FreeFormCertificate\Core\DataSanitizer::normalize_email( $invite['email'] );
		$ctx->invite     = $invite;
	}

	/**
	 * Whether the form asks for a CPF/RF.
	 *
	 * @param array<int, array<string, mixed>> $fields Stored fields.
	 * @return bool
	 */
	private static function asks_identifier( array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( 'cpf_rf' === ( $field['name'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
}
