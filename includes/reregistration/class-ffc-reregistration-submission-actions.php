<?php
/**
 * Reregistration Submission Actions
 *
 * Handles admin actions on reregistration submissions:
 * approve, reject or return to draft with a reason, and bulk operations.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.12.13  Extracted from ReregistrationAdmin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration Submission Actions.
 */
class ReregistrationSubmissionActions {

	/**
	 * Handle approve single submission.
	 *
	 * @return void
	 */
	public static function handle_approve(): void {
		if ( ! isset( $_GET['action'] ) || 'approve' !== $_GET['action'] || ! isset( $_GET['sub_id'] ) ) {
			return;
		}

		$sub_id   = absint( $_GET['sub_id'] );
		$rereg_id = RequestInput::get_get_int( 'id' );

		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_get_string( '_wpnonce' ), 'approve_submission_' . $sub_id ) ) {
			return;
		}

		ReregistrationSubmissionWriter::approve( $sub_id, get_current_user_id() );
		wp_safe_redirect( admin_url( 'admin.php?page=' . ReregistrationAdmin::MENU_SLUG . '&view=submissions&id=' . $rereg_id . '&message=approved' ) );
		exit;
	}

	/**
	 * The decisions the review modal posts, each with the redirect message it
	 * reports (#2242).
	 *
	 * @var array<string, string>
	 */
	public const REVIEW_DECISIONS = array(
		'reject'          => 'rejected',
		'return_to_draft' => 'returned_to_draft',
	);

	/**
	 * Handle a reject or a return to draft, for one row or a selection.
	 *
	 * Both decisions send the submission back to the member, so both carry a
	 * reason the member reads on the dashboard and in an e-mail (#2242). The
	 * modal that posts here is also the confirmation step; without a reason
	 * nothing changes. A selected row in a status the decision does not
	 * accept, or from another campaign, is skipped and counted.
	 *
	 * @return void
	 */
	public static function handle_review_decision(): void {
		if ( 'review_decision' !== RequestInput::get_post_string( 'ffc_action' ) ) {
			return;
		}

		$rereg_id = RequestInput::get_post_int( 'reregistration_id' );
		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'ffc_review_nonce' ), 'review_decision_' . $rereg_id ) ) {
			return;
		}

		$decision = RequestInput::get_post_string( 'decision' );
		if ( ! isset( self::REVIEW_DECISIONS[ $decision ] ) ) {
			return;
		}

		$base = admin_url( 'admin.php?page=' . ReregistrationAdmin::MENU_SLUG . '&view=submissions&id=' . $rereg_id );

		// A textarea keeps its line breaks; `get_post_string()` would fold them.
		$reason = isset( $_POST['review_reason'] ) ? trim( sanitize_textarea_field( wp_unslash( (string) $_POST['review_reason'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_textarea_field() on the unslashed value.
		if ( ! ReregistrationSubmissionWriter::is_valid_reason( $reason ) ) {
			wp_safe_redirect( $base . '&message=reason_required' );
			exit;
		}

		$ids  = array_values( array_unique( array_filter( array_map( 'absint', RequestInput::get_post_array( 'submission_ids' ) ) ) ) );
		$done = 0;
		foreach ( $ids as $sub_id ) {
			if ( self::apply_review_decision( $sub_id, $rereg_id, $decision, $reason ) ) {
				++$done;
			}
		}

		wp_safe_redirect( $base . '&message=' . self::REVIEW_DECISIONS[ $decision ] . '&count=' . $done . '&skipped=' . ( count( $ids ) - $done ) );
		exit;
	}

	/**
	 * Apply one decision to one submission, then tell the member and log it.
	 *
	 * @param int    $sub_id   Submission ID.
	 * @param int    $rereg_id The campaign the screen shows.
	 * @param string $decision `reject` or `return_to_draft`.
	 * @param string $reason   Reason, already validated.
	 * @return bool Whether the submission changed.
	 */
	private static function apply_review_decision( int $sub_id, int $rereg_id, string $decision, string $reason ): bool {
		$sub = ReregistrationSubmissionReader::get_by_id( $sub_id );
		if ( ! $sub || (int) $sub->reregistration_id !== $rereg_id ) {
			return false;
		}

		$changed = 'reject' === $decision
			? ReregistrationSubmissionWriter::reject( $sub_id, get_current_user_id(), $reason )
			: ReregistrationSubmissionWriter::return_to_draft( $sub_id, $reason );
		if ( ! $changed ) {
			return false;
		}

		ReregistrationEmailHandler::send_review_decision( $sub_id, $decision, $reason );

		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			// Two literal calls, so the activity-log label guard sees both actions.
			$data = array(
				'reregistration_id' => $rereg_id,
				'submission_id'     => $sub_id,
			);
			if ( 'reject' === $decision ) {
				\FreeFormCertificate\Core\ActivityLog::log( 'reregistration_submission_rejected', \FreeFormCertificate\Core\ActivityLog::LEVEL_INFO, $data, get_current_user_id() );
			} else {
				\FreeFormCertificate\Core\ActivityLog::log( 'reregistration_submission_returned', \FreeFormCertificate\Core\ActivityLog::LEVEL_INFO, $data, get_current_user_id() );
			}
		}

		return true;
	}

	/**
	 * Handle bulk actions on submissions.
	 *
	 * @return void
	 */
	public static function handle_bulk(): void {
		if ( ! isset( $_POST['ffc_action'] ) || 'bulk_submissions' !== $_POST['ffc_action'] ) {
			return;
		}

		$rereg_id = isset( $_POST['reregistration_id'] ) ? absint( $_POST['reregistration_id'] ) : 0;
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_bulk_nonce' ), 'bulk_submissions_' . $rereg_id ) ) {
			return;
		}

		$action = \FreeFormCertificate\Core\RequestInput::get_post_string( 'bulk_action' );
		$ids    = isset( $_POST['submission_ids'] ) ? array_map( 'absint', (array) $_POST['submission_ids'] ) : array();

		if ( empty( $ids ) || empty( $action ) ) {
			return;
		}

		if ( 'approve' === $action ) {
			// Only rows awaiting review are approved (#2242); the rest of the
			// selection is reported, never silently changed.
			$approved = ReregistrationSubmissionWriter::bulk_approve( $ids, get_current_user_id() );
			$skipped  = count( $ids ) - $approved;
			wp_safe_redirect( admin_url( 'admin.php?page=' . ReregistrationAdmin::MENU_SLUG . '&view=submissions&id=' . $rereg_id . '&message=bulk_approved&count=' . $approved . '&skipped=' . $skipped ) );
			exit;
		}

		if ( 'send_reminder' === $action ) {
			// Collect user IDs from submission IDs.
			$user_ids = array();
			foreach ( $ids as $sub_id ) {
				$sub = ReregistrationSubmissionReader::get_by_id( $sub_id );
				if ( $sub ) {
					$user_ids[] = (int) $sub->user_id;
				}
			}
			$sent = ReregistrationEmailHandler::send_reminders( $rereg_id, $user_ids );
			wp_safe_redirect( admin_url( 'admin.php?page=' . ReregistrationAdmin::MENU_SLUG . '&view=submissions&id=' . $rereg_id . '&message=reminders_sent&count=' . $sent ) );
			exit;
		}
	}
}
