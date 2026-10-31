<?php
/**
 * Recruitment error-code → user-facing message translator.
 *
 * The recruitment service layer returns failure envelopes whose `errors` arrays
 * contain stable machine-readable codes (e.g. `recruitment_notice_has_no_adjutancies`,
 * `recruitment_csv_missing_cpf_or_rf`). Those codes are stable identifiers used
 * by REST clients, tests, and activity logs — but they're not suitable as user-
 * visible strings.
 *
 * This class maps each known code to a translated, user-friendly message. The
 * REST controller's `wp_error_from_envelope()` calls {@see self::translate()}
 * so the API response carries a readable message in the `message` slot while
 * the original code stays in the `code` slot + `errors[]` data array for
 * tooling and tests.
 *
 * @package FreeFormCertificate\Recruitment
 * @since 6.2.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recruitment error-code translator.
 */
final class RecruitmentErrorMessages {

	/**
	 * Translate a single error code (or a `line=N: code` / `code: suffix`
	 * variant emitted by the CSV importer) into a user-facing message.
	 *
	 * The line-prefix format is preserved so operators can locate the
	 * offending row; the code segment is replaced with its translation
	 * when one is registered.
	 *
	 * Unknown codes pass through unchanged so we never silently swallow
	 * a new error category.
	 *
	 * @param string $raw Error code or composite (e.g. "line=3: recruitment_csv_missing_score").
	 * @return string Translated message.
	 */
	public static function translate( string $raw ): string {
		$line   = 0;
		$rest   = $raw;
		$suffix = '';

		// Pull out a leading `line=N: ` so we can re-prefix the human form.
		if ( 1 === preg_match( '/^line=(\d+):\s*(.+)$/', $rest, $m ) ) {
			$line = (int) $m[1];
			$rest = (string) $m[2];
		}

		// Pull out a trailing `: suffix` (e.g. adjutancy slug, missing-headers list).
		$colon = strpos( $rest, ': ' );
		if ( false !== $colon ) {
			$suffix = (string) substr( $rest, $colon + 2 );
			$rest   = (string) substr( $rest, 0, $colon );
		}

		$map     = self::map();
		$message = $map[ $rest ] ?? $rest;

		// `from->to` names two statuses; say them in words (#2245).
		if ( 'recruitment_invalid_transition' === $rest && 1 === preg_match( '/^([a-z_]+)->([a-z_]+)$/', $suffix, $t ) ) {
			$message = sprintf(
				/* translators: 1: current status, 2: requested status */
				__( 'This status change is not allowed: from "%1$s" to "%2$s".', 'ffcertificate' ),
				self::status_name( $t[1] ),
				self::status_name( $t[2] )
			);
			$suffix = '';
		}

		if ( '' !== $suffix && isset( $map[ $rest ] ) ) {
			$message .= ' (' . $suffix . ')';
		} elseif ( '' !== $suffix ) {
			// Unknown code — keep the raw form so debugging info isn't lost.
			$message = $rest . ': ' . $suffix;
		}

		if ( $line > 0 ) {
			/* translators: 1: 1-based line number, 2: error message. */
			$message = sprintf( __( 'Line %1$d: %2$s', 'ffcertificate' ), $line, $message );
		}

		return $message;
	}

	/**
	 * Translate a list of codes, preserving order + duplicates.
	 *
	 * @param array<int, string> $codes Error codes / composites.
	 * @return array<int, string>
	 */
	public static function translate_all( array $codes ): array {
		return array_map( array( __CLASS__, 'translate' ), $codes );
	}

	/**
	 * A notice or classification status in words, for the transition
	 * message. An unknown value is returned as it came.
	 *
	 * @param string $status Status code.
	 * @return string
	 */
	private static function status_name( string $status ): string {
		$names = array(
			'draft'       => __( 'Draft', 'ffcertificate' ),
			'preliminary' => __( 'Preliminary', 'ffcertificate' ),
			'definitive'  => __( 'Definitive', 'ffcertificate' ),
			'closed'      => __( 'Closed', 'ffcertificate' ),
			'empty'       => __( 'Waiting', 'ffcertificate' ),
			'called'      => __( 'Called', 'ffcertificate' ),
			'accepted'    => __( 'Accepted', 'ffcertificate' ),
			'not_shown'   => __( 'Did not show up', 'ffcertificate' ),
			'hired'       => __( 'Hired', 'ffcertificate' ),
			'withdrew'    => __( 'Withdrew', 'ffcertificate' ),
		);
		return $names[ $status ] ?? $status;
	}

	/**
	 * Static map of stable error codes to translated user-facing strings.
	 *
	 * Keep this catalog grouped by subsystem so it's easy to extend when
	 * a new code lands. Codes not listed here pass through verbatim — that
	 * makes "missing message" obvious in the UI rather than silently
	 * masking it as a generic failure.
	 *
	 * @return array<string, string>
	 */
	private static function map(): array {
		return array(
			// Generic.
			'recruitment_error'                            => __( 'An unexpected error occurred. Please try again.', 'ffcertificate' ),

			// Notices.
			'recruitment_notice_not_found'                 => __( 'Notice not found.', 'ffcertificate' ),
			'recruitment_notice_create_failed'             => __( 'Could not create the notice. Please try again.', 'ffcertificate' ),
			'recruitment_notice_update_failed'             => __( 'Could not update the notice. Please try again.', 'ffcertificate' ),
			'recruitment_notice_has_no_adjutancies'        => __( 'This notice has no adjutancies linked. Add at least one adjutancy in the Adjutancies tab before importing a CSV.', 'ffcertificate' ),
			'recruitment_notice_adjutancy_attach_failed'   => __( 'Could not link the adjutancy to the notice. Please try again.', 'ffcertificate' ),
			'recruitment_notice_status_changed'            => __( 'Notice status changed.', 'ffcertificate' ),
			'recruitment_notice_delete_requires_draft'     => __( 'Only a draft notice can be deleted. A published notice can only be closed.', 'ffcertificate' ),
			'recruitment_notice_delete_failed'             => __( 'Could not delete the notice. Nothing was removed.', 'ffcertificate' ),

			// Notice state machine.
			'recruitment_invalid_state_for_preview_import' => __( 'CSV imports for the preliminary list are only allowed while the notice is in `draft` or `preliminary`.', 'ffcertificate' ),
			'recruitment_definitive_to_preliminary_blocked_by_calls' => __( 'Cannot move back to preliminary: at least one call has already been issued. Cancel pending calls before reverting.', 'ffcertificate' ),
			'recruitment_definitive_import_requires_preliminary' => __( 'The definitive list can only be imported while the notice is in `preliminary`. Nothing was imported.', 'ffcertificate' ),
			'recruitment_definitive_import_requires_preliminary_list' => __( 'Import the preliminary list first: the definitive list is imported only on a notice that has one. Nothing was imported.', 'ffcertificate' ),
			'recruitment_definitive_import_blocked_by_calls' => __( 'The definitive list cannot be replaced: this notice already has calls. Nothing was imported.', 'ffcertificate' ),
			'recruitment_state_locked'                     => __( 'Someone else changed this row a moment ago, so nothing was done. Reload the page and try again.', 'ffcertificate' ),
			'recruitment_state_terminal_hired'             => __( 'This candidate has already been hired and cannot be modified.', 'ffcertificate' ),
			'recruitment_state_terminal_withdrew'          => __( 'This candidate has withdrawn and cannot be modified.', 'ffcertificate' ),
			'recruitment_transition_race_lost'             => __( 'Another operator changed this notice\'s status moments ago. Reload to see the current state.', 'ffcertificate' ),
			'recruitment_transition_reason_required'       => __( 'A reason is required for this status transition.', 'ffcertificate' ),
			'recruitment_reopen_freeze_active'             => __( 'This notice is in a post-reopen freeze and the affected fields are temporarily locked.', 'ffcertificate' ),
			'recruitment_override_not_overridable'         => __( 'Only candidates that are hired, withdrawn, or marked as no-show can be reset to waiting.', 'ffcertificate' ),

			// Adjutancies.
			'recruitment_adjutancy_not_found'              => __( 'Adjutancy not found.', 'ffcertificate' ),
			'recruitment_adjutancy_create_failed'          => __( 'Could not create the adjutancy. Please try again.', 'ffcertificate' ),
			'recruitment_adjutancy_update_failed'          => __( 'Could not update the adjutancy. Please try again.', 'ffcertificate' ),
			'recruitment_adjutancy_delete_failed'          => __( 'Could not delete the adjutancy. Please try again.', 'ffcertificate' ),
			'recruitment_adjutancy_in_use'                 => __( 'This adjutancy is in use by one or more notices and cannot be deleted.', 'ffcertificate' ),

			// Candidates.
			'recruitment_candidate_not_found'              => __( 'Candidate not found.', 'ffcertificate' ),
			'recruitment_candidate_upsert_failed'          => __( 'Could not save candidate data. Please try again.', 'ffcertificate' ),
			'recruitment_candidate_update_failed'          => __( 'Could not update the candidate. Please try again.', 'ffcertificate' ),
			'recruitment_candidate_update_no_writable_fields' => __( 'No writable fields were supplied for this candidate update.', 'ffcertificate' ),
			'recruitment_candidate_identifier_not_editable' => __( 'A candidate\'s CPF and RF cannot be edited. They identify the candidate in every notice.', 'ffcertificate' ),
			'recruitment_candidate_email_not_editable'     => __( 'You may not change this candidate\'s e-mail without the capability to reveal it.', 'ffcertificate' ),
			'recruitment_candidate_delete_failed'          => __( 'Could not delete the candidate. Please try again.', 'ffcertificate' ),
			'recruitment_candidate_has_classifications'    => __( 'This candidate has classifications attached and cannot be deleted directly. Remove the classifications first.', 'ffcertificate' ),
			'recruitment_candidate_list_requires_filter'   => __( 'A filter (notice / adjutancy / search) is required to list candidates.', 'ffcertificate' ),

			// Classifications.
			'recruitment_classification_not_found'         => __( 'Classification entry not found.', 'ffcertificate' ),
			'recruitment_classification_insert_failed'     => __( 'Could not save the classification entry. Please try again.', 'ffcertificate' ),
			'recruitment_classification_delete_failed'     => __( 'Could not remove the classification entry. Please try again.', 'ffcertificate' ),
			'recruitment_classification_delete_requires_draft_or_preliminary' => __( 'Classifications can only be removed while the notice is in draft or preliminary status.', 'ffcertificate' ),
			'recruitment_classification_not_empty'         => __( 'This candidate is no longer waiting to be called: the row was called or changed in the meantime. Reload the page.', 'ffcertificate' ),
			'recruitment_classification_not_empty_for_delete' => __( 'Cannot delete this notice: it still has classifications attached.', 'ffcertificate' ),

			// Calls.
			'recruitment_call_not_found'                   => __( 'Call entry not found.', 'ffcertificate' ),
			'recruitment_call_insert_failed'               => __( 'Could not register the call. Please try again.', 'ffcertificate' ),
			'recruitment_call_already_cancelled'           => __( 'This call has already been cancelled.', 'ffcertificate' ),
			'recruitment_call_cancel_race_lost'            => __( 'Another operator updated this call moments ago. Reload to see the current state.', 'ffcertificate' ),
			'recruitment_cancel_only_from_called_or_accepted' => __( 'Only a call that is called, accepted or not shown can be cancelled.', 'ffcertificate' ),
			'recruitment_call_not_in_classification'       => __( 'This call does not belong to that candidate row. Reload the page and try again.', 'ffcertificate' ),
			'recruitment_call_not_yours'                   => __( 'Only the operator who made this call, or a manager, can cancel it.', 'ffcertificate' ),
			'recruitment_status_called_requires_call'      => __( 'A candidate is called through Call, which records the call and its date.', 'ffcertificate' ),
			'recruitment_status_empty_requires_cancel'     => __( 'A call is undone through Cancel call or Reopen, which end the call itself.', 'ffcertificate' ),
			'recruitment_call_requires_definitive_list'    => __( 'Calls and outcomes are recorded on the definitive list only.', 'ffcertificate' ),
			'recruitment_call_requires_definitive_notice'  => __( 'Calls and outcomes are recorded only while the notice is definitive. A draft, preliminary or closed notice takes none.', 'ffcertificate' ),
			'recruitment_cancel_reason_required'           => __( 'A reason is required to cancel this call.', 'ffcertificate' ),
			'recruitment_out_of_order_requires_reason'     => __( 'Calling out of rank order requires a reason.', 'ffcertificate' ),
			'recruitment_bulk_call_empty_id_list'          => __( 'Select at least one classification to call in bulk.', 'ffcertificate' ),
			'recruitment_bulk_call_failed_at_classification' => __( 'Nobody was called: one of the selected rows was refused, and a bulk call is all or nothing.', 'ffcertificate' ),
			'recruitment_call_invalid_date'                => __( 'The date to assume is not a valid date.', 'ffcertificate' ),
			'recruitment_call_invalid_time'                => __( 'The time to assume is not a valid time.', 'ffcertificate' ),
			'recruitment_invalid_transition'               => __( 'This status change is not allowed.', 'ffcertificate' ),

			// Notice-status locks (#2245).
			'recruitment_notice_adjutancies_locked'        => __( 'Adjutancies are attached or detached only while the notice is in draft or preliminary.', 'ffcertificate' ),
			'recruitment_row_adjutancy_locked'             => __( 'A candidate moves to another adjutancy only while the notice is in draft or preliminary.', 'ffcertificate' ),
			'recruitment_preview_status_locked'            => __( 'Preliminary statuses and reasons are set only while the notice is preliminary.', 'ffcertificate' ),

			// Preview status / reasons.
			'recruitment_preview_status_invalid'           => __( 'Invalid preliminary status.', 'ffcertificate' ),
			'recruitment_preview_status_only_on_preview_list' => __( 'Preliminary status can only be set on the preliminary list.', 'ffcertificate' ),
			'recruitment_preview_status_update_failed'     => __( 'Could not update the preliminary status. Please try again.', 'ffcertificate' ),
			'recruitment_preview_reason_required'          => __( 'A reason is required for this preliminary status.', 'ffcertificate' ),
			'recruitment_preview_reason_not_found'         => __( 'Selected preliminary reason no longer exists.', 'ffcertificate' ),
			'recruitment_preview_reason_status_mismatch'   => __( 'The selected reason does not apply to this preliminary status.', 'ffcertificate' ),

			// Reasons catalog.
			'recruitment_reason_create_failed'             => __( 'Could not create the reason. Please try again.', 'ffcertificate' ),
			'recruitment_reason_update_failed'             => __( 'Could not update the reason. Please try again.', 'ffcertificate' ),
			'recruitment_reason_delete_failed'             => __( 'Could not delete the reason. Please try again.', 'ffcertificate' ),
			'recruitment_reason_in_use'                    => __( 'This reason is referenced by existing classifications and cannot be deleted.', 'ffcertificate' ),

			// Promotion (preview → definitive).
			'recruitment_promotion_requires_preliminary_state' => __( 'Promotion to definitive is only allowed while the notice is in `preliminary` status.', 'ffcertificate' ),
			'recruitment_promotion_no_preview_rows'        => __( 'There are no preliminary rows to promote.', 'ffcertificate' ),
			'recruitment_promotion_copy_failed'            => __( 'Could not copy preliminary rows into the definitive list. Please try again.', 'ffcertificate' ),

			// CSV import — file-level.
			'recruitment_csv_empty'                        => __( 'The CSV file is empty.', 'ffcertificate' ),
			'recruitment_csv_missing_headers'              => __( 'The CSV is missing required header columns.', 'ffcertificate' ),

			// CSV import — per-row validation.
			'recruitment_csv_missing_cpf_or_rf'            => __( 'At least one of CPF or RF is required.', 'ffcertificate' ),
			// The two `*_must_be_digits_only` entries that sat here were dead:
			// nothing in the tree emitted either, while the two codes the
			// validator DOES emit for an over-length identifier had no label at
			// all, so an operator read `recruitment_csv_cpf_too_long` verbatim.
			// The lists had drifted in both directions at once (#1489), which is
			// what `RecruitmentErrorCodeCoverageTest` now refuses.
			'recruitment_csv_cpf_too_long'                 => __( 'CPF has more digits than a CPF can have — check the column for a stray value.', 'ffcertificate' ),
			'recruitment_csv_rf_too_long'                  => __( 'RF has more digits than an RF can have — check the column for a stray value.', 'ffcertificate' ),
			// A CPF is padded with leading zeros when a spreadsheet dropped them,
			// so this failing means the padded value is not a real CPF: the source
			// was truncated or mistyped, and no correction here can recover the
			// digits the file never carried.
			'recruitment_csv_cpf_invalid'                  => __( 'CPF is not valid — its check digits do not match. Confirm the number in the source spreadsheet.', 'ffcertificate' ),
			'recruitment_csv_rf_invalid'                   => __( 'RF is not valid — its check digit does not match, and the RF check digit setting is on. Confirm the number in the source spreadsheet.', 'ffcertificate' ),
			'recruitment_csv_missing_score'                => __( 'Score is required.', 'ffcertificate' ),
			'recruitment_csv_score_uses_comma_decimal'     => __( 'Score uses a comma decimal — replace with a dot (e.g. 12.5).', 'ffcertificate' ),
			'recruitment_csv_score_invalid_format'         => __( 'Score format is invalid (numeric value expected).', 'ffcertificate' ),
			'recruitment_csv_rank_invalid'                 => __( 'Rank must be a positive integer.', 'ffcertificate' ),
			'recruitment_csv_not_utf8'                     => __( 'The file is not UTF-8. Excel often saves CSV as Windows-1252: in Excel choose "CSV UTF-8", or re-save the file as UTF-8 in your editor, and import it again.', 'ffcertificate' ),
			'recruitment_csv_time_points_uses_comma_decimal' => __( 'Time points uses a comma decimal — replace with a dot.', 'ffcertificate' ),
			'recruitment_csv_time_points_invalid_format'   => __( 'Time points format is invalid (numeric value expected).', 'ffcertificate' ),
			'recruitment_csv_missing_adjutancy'            => __( 'Adjutancy is required.', 'ffcertificate' ),
			'recruitment_csv_adjutancy_not_in_notice'      => __( 'Adjutancy is not linked to this notice.', 'ffcertificate' ),
			// Candidate identity (#2245).
			'recruitment_csv_identity_conflict'            => __( 'The CPF and the RF on this line belong to two different candidates. Correct the line and import again.', 'ffcertificate' ),
			'recruitment_import_identity_mismatch'         => __( 'Some lines match a stored candidate whose data differs. Review them and confirm to import; the stored data is kept.', 'ffcertificate' ),
			'recruitment_reimport_needs_confirmation'      => __( 'The file replaces a list that already has rows. Review what changes and confirm to import; nothing was written yet.', 'ffcertificate' ),
			'recruitment_promotion_requires_promote_flow'  => __( 'Promote a preliminary notice by copying the preliminary list or importing a definitive one, so the definitive list is always the current one.', 'ffcertificate' ),
		);
	}
}
