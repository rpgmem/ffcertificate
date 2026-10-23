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

		$ctx->user_email = \FreeFormCertificate\Core\DataSanitizer::normalize_email( $invite['email'] );
		$ctx->invite     = $invite;
	}
}
