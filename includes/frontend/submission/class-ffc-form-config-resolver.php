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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified upstream by NonceGuard.
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		if ( ! $form_id ) {
			throw new SubmissionRejected( array( 'message' => __( 'Invalid Form ID.', 'ffcertificate' ) ) );
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
