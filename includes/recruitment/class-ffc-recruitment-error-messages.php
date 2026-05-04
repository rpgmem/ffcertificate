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

			// Notice state machine.
			'recruitment_invalid_state_for_preview_import' => __( 'CSV imports for the preliminary list are only allowed while the notice is in `draft` or `preliminary`.', 'ffcertificate' ),
			'recruitment_definitive_to_preliminary_blocked_by_calls' => __( 'Cannot move back to preliminary: at least one call has already been issued. Cancel pending calls before reverting.', 'ffcertificate' ),
			'recruitment_state_locked'                     => __( 'This action is not allowed in the notice\'s current state.', 'ffcertificate' ),
			'recruitment_state_terminal_hired'             => __( 'This candidate has already been hired and cannot be modified.', 'ffcertificate' ),
			'recruitment_transition_race_lost'             => __( 'Another operator changed this notice\'s status moments ago. Reload to see the current state.', 'ffcertificate' ),
			'recruitment_transition_reason_required'       => __( 'A reason is required for this status transition.', 'ffcertificate' ),
			'recruitment_reopen_freeze_active'             => __( 'This notice is in a post-reopen freeze and the affected fields are temporarily locked.', 'ffcertificate' ),

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
			'recruitment_candidate_delete_failed'          => __( 'Could not delete the candidate. Please try again.', 'ffcertificate' ),
			'recruitment_candidate_has_classifications'    => __( 'This candidate has classifications attached and cannot be deleted directly. Remove the classifications first.', 'ffcertificate' ),
			'recruitment_candidate_list_requires_filter'   => __( 'A filter (notice / adjutancy / search) is required to list candidates.', 'ffcertificate' ),

			// Classifications.
			'recruitment_classification_not_found'         => __( 'Classification entry not found.', 'ffcertificate' ),
			'recruitment_classification_insert_failed'     => __( 'Could not save the classification entry. Please try again.', 'ffcertificate' ),
			'recruitment_classification_delete_failed'     => __( 'Could not remove the classification entry. Please try again.', 'ffcertificate' ),
			'recruitment_classification_delete_requires_draft_or_preliminary' => __( 'Classifications can only be removed while the notice is in draft or preliminary status.', 'ffcertificate' ),
			'recruitment_classification_not_empty'         => __( 'Cannot complete the action: classifications already exist for this notice.', 'ffcertificate' ),
			'recruitment_classification_not_empty_for_delete' => __( 'Cannot delete this notice: it still has classifications attached.', 'ffcertificate' ),

			// Calls.
			'recruitment_call_not_found'                   => __( 'Call entry not found.', 'ffcertificate' ),
			'recruitment_call_insert_failed'               => __( 'Could not register the call. Please try again.', 'ffcertificate' ),
			'recruitment_call_already_cancelled'           => __( 'This call has already been cancelled.', 'ffcertificate' ),
			'recruitment_call_cancel_race_lost'            => __( 'Another operator updated this call moments ago. Reload to see the current state.', 'ffcertificate' ),
			'recruitment_cancel_only_from_called_or_accepted' => __( 'Only calls in `called` or `accepted` state can be cancelled.', 'ffcertificate' ),
			'recruitment_cancel_reason_required'           => __( 'A reason is required to cancel this call.', 'ffcertificate' ),
			'recruitment_out_of_order_requires_reason'     => __( 'Calling out of rank order requires a reason.', 'ffcertificate' ),
			'recruitment_bulk_call_empty_id_list'          => __( 'Select at least one classification to call in bulk.', 'ffcertificate' ),

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
			'recruitment_csv_file_missing'                 => __( 'No CSV file was uploaded.', 'ffcertificate' ),
			'recruitment_csv_file_unreadable'              => __( 'Could not read the uploaded CSV file.', 'ffcertificate' ),
			'recruitment_csv_empty'                        => __( 'The CSV file is empty.', 'ffcertificate' ),
			'recruitment_csv_unparseable'                  => __( 'The CSV file could not be parsed. Make sure it is UTF-8 with comma or semicolon delimiters.', 'ffcertificate' ),
			'recruitment_csv_missing_headers'              => __( 'The CSV is missing required header columns.', 'ffcertificate' ),

			// CSV import — per-row validation.
			'recruitment_csv_missing_cpf_or_rf'            => __( 'At least one of CPF or RF is required.', 'ffcertificate' ),
			'recruitment_csv_cpf_must_be_digits_only'      => __( 'CPF must contain digits only.', 'ffcertificate' ),
			'recruitment_csv_rf_must_be_digits_only'       => __( 'RF must contain digits only.', 'ffcertificate' ),
			'recruitment_csv_missing_score'                => __( 'Score is required.', 'ffcertificate' ),
			'recruitment_csv_score_uses_comma_decimal'     => __( 'Score uses a comma decimal — replace with a dot (e.g. 12.5).', 'ffcertificate' ),
			'recruitment_csv_score_invalid_format'         => __( 'Score format is invalid (numeric value expected).', 'ffcertificate' ),
			'recruitment_csv_rank_invalid'                 => __( 'Rank must be a positive integer.', 'ffcertificate' ),
			'recruitment_csv_time_points_uses_comma_decimal' => __( 'Time points uses a comma decimal — replace with a dot.', 'ffcertificate' ),
			'recruitment_csv_time_points_invalid_format'   => __( 'Time points format is invalid (numeric value expected).', 'ffcertificate' ),
			'recruitment_csv_missing_adjutancy'            => __( 'Adjutancy is required.', 'ffcertificate' ),
			'recruitment_csv_adjutancy_not_in_notice'      => __( 'Adjutancy is not linked to this notice.', 'ffcertificate' ),
		);
	}
}
