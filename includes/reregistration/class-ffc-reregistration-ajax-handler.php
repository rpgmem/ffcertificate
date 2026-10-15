<?php
/**
 * Reregistration AJAX Handler
 *
 * Hosts the wp_ajax callbacks for the reregistration admin:
 * - ffc_generate_record            — PDF record generation (the old
 *   `ffc_generate_ficha` action was removed in 6.28.0; only the NONCE action
 *   string still carries that name, deliberately — see ReregistrationAdmin)
 * - ffc_view_submission_details    — submission details modal HTML
 * - ffc_rereg_count_members        — affected user count for an audience set
 * - ffc_rereg_send_invitations     — invite whoever is still awaiting one (#1190)
 *
 * Hook registration still happens in ReregistrationAdmin::init() against an
 * instance of this class stored on the facade.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.12.14  Extracted from ReregistrationAdmin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\RequestInput;

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
	 * PII reveal capability (#1815). Mirrored by the `reregistration` entry
	 * of `AdminAjax::pii_reveal_configs()` and by the submissions list.
	 */
	private const PII_CAPABILITY = 'ffc_view_reregistration_pii';

	/**
	 * Role granted the unmasked tier. The domain has no `_admin` role of its
	 * own, so its admin tier is the global FFC administrator.
	 */
	private const PII_UNMASKED_ROLE = 'ffc_administrator';

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
	 * AJAX: Generate record PDF data for a submission.
	 *
	 * @return void
	 */
	public function ajax_generate_record(): void {
		check_ajax_referer( 'ffc_generate_ficha', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
		}

		$submission_id = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
		if ( ! $submission_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid submission.', 'ffcertificate' ) ) );
		}

		$record_data = RecordGenerator::generate_record_data( $submission_id );
		if ( ! $record_data ) {
			wp_send_json_error( array( 'message' => __( 'Could not generate record.', 'ffcertificate' ) ) );
		}

		wp_send_json_success( array( 'pdf_data' => $record_data ) );
	}

	/**
	 * AJAX: return HTML with the full submission detail grouped by fieldset.
	 *
	 * Used by the "View Details" modal on the submissions list. Decrypts
	 * sensitive values (CPF/RF/RG) via RecordGenerator helpers and renders
	 * them grouped by field_group with labels from wp_ffc_custom_fields.
	 *
	 * Gated by the PII policy rather than the manage cap (#1815): a viewer of
	 * the list may open it only when the policy lets them reveal, the masked
	 * tier gets a 403, and a `reveal`-tier open writes an audit row — the
	 * same rule as every other reveal of the module's PII.
	 *
	 * @return void
	 */
	public function ajax_view_submission_details(): void {
		check_ajax_referer( 'ffc_view_submission_details', 'nonce' );

		// Surface gate: the details belong to the submissions list.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_reregistration' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ), 403 );
		}

		$submission_id = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
		if ( ! $submission_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid submission.', 'ffcertificate' ) ) );
		}

		$submission = ReregistrationSubmissionReader::get_by_id( $submission_id );
		if ( ! $submission ) {
			wp_send_json_error( array( 'message' => __( 'Submission not found.', 'ffcertificate' ) ) );
		}

		$owner_id = isset( $submission->user_id ) ? (int) $submission->user_id : 0;
		$owner    = $owner_id > 0 ? $owner_id : null;
		$tier     = \FreeFormCertificate\Core\PiiAccessPolicy::resolve( self::PII_CAPABILITY, self::PII_UNMASKED_ROLE, $owner );
		if ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED === $tier ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this submission\'s personal data.', 'ffcertificate' ) ), 403 );
		}

		$rereg = ReregistrationRepository::get_by_id( (int) $submission->reregistration_id );
		if ( ! $rereg ) {
			wp_send_json_error( array( 'message' => __( 'Reregistration not found.', 'ffcertificate' ) ) );
		}

		// Unified dynamic shape: { fields: { field_key => value } }.
		$sub_data   = $submission->data ? json_decode( $submission->data, true ) : array();
		$raw_values = is_array( $sub_data['fields'] ?? null ) ? $sub_data['fields'] : array();

		$all_fields       = RecordGenerator::get_custom_fields_for_reregistration( $rereg );
		$decrypted_values = RecordGenerator::decrypt_field_values( $all_fields, $raw_values );

		$html = $this->details_renderer->build_submission_details_html( $submission, $all_fields, $decrypted_values );

		if ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_REVEAL === $tier ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'reregistration_details_viewed',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array( 'reregistration_id' => (int) $submission->reregistration_id ),
				get_current_user_id(),
				$submission_id
			);
		}

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

		$raw          = isset( $_POST['audience_ids'] ) ? array_map( 'absint', (array) $_POST['audience_ids'] ) : array();
		$audience_ids = array_filter( $raw );

		if ( empty( $audience_ids ) ) {
			wp_send_json_success( array( 'count' => 0 ) );
		}

		$user_ids = ReregistrationRepository::get_user_ids_for_audiences( $audience_ids );
		wp_send_json_success( array( 'count' => count( $user_ids ) ) );
	}
	/**
	 * AJAX: send the invitation to whoever is still awaiting one.
	 *
	 * Idempotent, which is what makes it safe as a button an operator can press
	 * twice (#1190): the work of deciding who is owed an email lives in
	 * {@see ReregistrationSubmissionReader::get_awaiting_invitation()}, and the
	 * send stamps `invited_at`, so a second press right after the first sends
	 * nothing. Pressing it after the deadline is extended reaches whoever has
	 * not finished, and only them.
	 *
	 * @return void
	 */
	public function ajax_send_invitations(): void {
		check_ajax_referer( 'ffc_reregistration_nonce', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
		}

		$reregistration_id = RequestInput::get_post_int( 'reregistration_id' );
		if ( $reregistration_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid campaign.', 'ffcertificate' ) ) );
		}

		$rereg = ReregistrationRepository::get_by_id( $reregistration_id );
		if ( ! $rereg ) {
			wp_send_json_error( array( 'message' => __( 'Campaign not found.', 'ffcertificate' ) ) );
		}

		if ( empty( $rereg->email_invitation_enabled ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invitation emails are disabled for this campaign.', 'ffcertificate' ) )
			);
		}

		$sent = ReregistrationEmailHandler::send_invitations( $reregistration_id );

		wp_send_json_success(
			array(
				'sent'    => $sent,
				'message' => 0 === $sent
					? __( 'Nobody was awaiting an invitation.', 'ffcertificate' )
					: sprintf(
						/* translators: %d: number of invitations sent. */
						_n( '%d invitation sent.', '%d invitations sent.', $sent, 'ffcertificate' ),
						$sent
					),
			)
		);
	}
}
