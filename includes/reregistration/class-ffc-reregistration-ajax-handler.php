<?php
/**
 * Reregistration AJAX Handler
 *
 * Hosts the wp_ajax callbacks for the reregistration admin:
 * - ffc_generate_ficha             — PDF ficha generation
 * - ffc_view_submission_details    — submission details modal HTML
 * - ffc_rereg_count_members        — affected user count for an audience set
 *
 * Hook registration still happens in ReregistrationAdmin::init() against an
 * instance of this class stored on the facade.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.12.14  Extracted from ReregistrationAdmin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration AJAX Handler.
 */
final class ReregistrationAjaxHandler {

	/**
	 * Required capability.
	 */
	private const CAPABILITY = 'ffc_manage_reregistration';

	/**
	 * Submission details renderer (for the View Details modal).
	 *
	 * @var ReregistrationSubmissionDetailsRenderer
	 */
	private ReregistrationSubmissionDetailsRenderer $details_renderer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->details_renderer = new ReregistrationSubmissionDetailsRenderer();
	}

	/**
	 * AJAX: Generate ficha PDF data for a submission.
	 *
	 * @return void
	 */
	public function ajax_generate_ficha(): void {
		check_ajax_referer( 'ffc_generate_ficha', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
		$submission_id = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
		if ( ! $submission_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid submission.', 'ffcertificate' ) ) );
		}

		$ficha_data = FichaGenerator::generate_ficha_data( $submission_id );
		if ( ! $ficha_data ) {
			wp_send_json_error( array( 'message' => __( 'Could not generate ficha.', 'ffcertificate' ) ) );
		}

		wp_send_json_success( array( 'pdf_data' => $ficha_data ) );
	}

	/**
	 * AJAX: return HTML with the full submission detail grouped by fieldset.
	 *
	 * Used by the "View Details" modal on the submissions list. Decrypts
	 * sensitive values (CPF/RF/RG) via FichaGenerator helpers and renders
	 * them grouped by field_group with labels from wp_ffc_custom_fields.
	 *
	 * @return void
	 */
	public function ajax_view_submission_details(): void {
		check_ajax_referer( 'ffc_view_submission_details', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
		$submission_id = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
		if ( ! $submission_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid submission.', 'ffcertificate' ) ) );
		}

		$submission = ReregistrationSubmissionReader::get_by_id( $submission_id );
		if ( ! $submission ) {
			wp_send_json_error( array( 'message' => __( 'Submission not found.', 'ffcertificate' ) ) );
		}

		$rereg = ReregistrationRepository::get_by_id( (int) $submission->reregistration_id );
		if ( ! $rereg ) {
			wp_send_json_error( array( 'message' => __( 'Reregistration not found.', 'ffcertificate' ) ) );
		}

		// Unified dynamic shape: { fields: { field_key => value } }.
		$sub_data   = $submission->data ? json_decode( $submission->data, true ) : array();
		$raw_values = is_array( $sub_data['fields'] ?? null ) ? $sub_data['fields'] : array();

		$all_fields       = FichaGenerator::get_custom_fields_for_reregistration( $rereg );
		$decrypted_values = FichaGenerator::decrypt_field_values( $all_fields, $raw_values );

		$html = $this->details_renderer->build_submission_details_html( $submission, $all_fields, $decrypted_values );

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * AJAX: Count members for a set of audience IDs.
	 *
	 * @return void
	 */
	public function ajax_count_members(): void {
		check_ajax_referer( 'ffc_reregistration_nonce', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$raw          = isset( $_POST['audience_ids'] ) ? array_map( 'absint', (array) $_POST['audience_ids'] ) : array();
		$audience_ids = array_filter( $raw );

		if ( empty( $audience_ids ) ) {
			wp_send_json_success( array( 'count' => 0 ) );
		}

		$user_ids = ReregistrationRepository::get_user_ids_for_audiences( $audience_ids );
		wp_send_json_success( array( 'count' => count( $user_ids ) ) );
	}
}
