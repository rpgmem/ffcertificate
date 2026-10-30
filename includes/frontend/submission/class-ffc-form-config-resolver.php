<?php
/**
 * FormConfigResolver — pipeline stage 6 (#563 Sprint 1).
 *
 * Resolves the posted form id and loads its `_ffc_form_config` /
 * `_ffc_form_fields` post meta onto the context. Rejects on a missing form
 * id or absent field config (always an admin-side state — deleted form /
 * unsaved config — never the user's fault, so the message points them at
 * the organizer).
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form id + config resolution gate.
 */
class FormConfigResolver {

	/**
	 * Resolve form id + config onto the context.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected When the form id is invalid or config is absent.
	 */
	public function apply( SubmissionContext $ctx ): void {
		$form_id = SubmissionInput::int( $ctx, 'form_id' );
		if ( ! $form_id ) {
			throw new SubmissionRejected( array( 'message' => __( 'Invalid Form ID.', 'ffcertificate' ) ) );
		}

		// Only a published form takes submissions: a draft, private or trashed
		// one keeps its fields and was accepted here, while REST refused it (#2241).
		$form = get_post( $form_id );
		if ( ! $form || 'ffc_form' !== $form->post_type || 'publish' !== $form->post_status ) {
			throw new SubmissionRejected( array( 'message' => __( 'This form is not available right now. Please contact the organizer.', 'ffcertificate' ) ) );
		}

		$form_config = get_post_meta( $form_id, '_ffc_form_config', true );
		if ( ! is_array( $form_config ) ) {
			$form_config = array();
		}

		$fields_config = get_post_meta( $form_id, '_ffc_form_fields', true );
		if ( ! $fields_config ) {
			throw new SubmissionRejected( array( 'message' => __( 'This form is not available right now. Please contact the organizer.', 'ffcertificate' ) ) );
		}

		$ctx->form_id       = $form_id;
		$ctx->form_config   = $form_config;
		$ctx->fields_config = is_array( $fields_config ) ? $fields_config : array();
	}
}
