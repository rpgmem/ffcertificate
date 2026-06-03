<?php
/**
 * Recruitment Activity Logger
 *
 * Thin façade over `\FreeFormCertificate\Core\ActivityLog::log()` with one
 * stable action code per §13 event in the implementation plan. Centralizes
 * the action-name vocabulary (so callers can't drift) and pins the payload
 * shape for each event so consumers (PRs reviewing recruitment changes,
 * audit dashboards, future LGPD reports) get a consistent stream.
 *
 * Sensitive-payload protection is INHERITED automatically from the core
 * ActivityLog: it inspects every payload via
 * {@see \FreeFormCertificate\Core\SensitiveFieldRegistry::contains_sensitive}
 * and encrypts the row's `context_encrypted` column when the payload contains
 * any registered sensitive key (`cpf`, `rf`, `email`, …). Sprint 2 added
 * `CONTEXT_RECRUITMENT_CANDIDATE` to that registry, so any recruitment log
 * call carrying those keys is automatically protected without per-call code.
 *
 * Recruitment event vocabulary:
 *
 *   - recruitment_csv_imported          { notice_id, list_type, inserted_count }
 *   - recruitment_csv_import_failed     { notice_id, list_type, error_count }
 *   - recruitment_notice_status_changed { notice_id, from, to, reason? }
 *   - recruitment_notice_promoted       { notice_id, mode, copied }
 *   - recruitment_classification_status_changed
 *                                       { classification_id, from, to, reason? }
 *   - recruitment_call_created          { call_id, classification_id, out_of_order, ... }
 *   - recruitment_bulk_call_created     { classification_ids, date_to_assume, time_to_assume, count }
 *   - recruitment_call_cancelled        { call_id, classification_id, reason }
 *   - recruitment_candidate_promoted    { candidate_id, user_id }
 *   - recruitment_candidate_deleted     { candidate_id, reason? }
 *   - recruitment_classification_deleted { classification_id, reason? }
 *   - recruitment_adjutancy_deleted     { adjutancy_id }
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\ActivityLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless logger façade. All methods are best-effort: they swallow
 * ActivityLog return values because instrumentation must never disrupt the
 * caller's success path.
 */
final class RecruitmentActivityLogger {

	/**
	 * CSV import succeeded.
	 *
	 * @param int    $notice_id Target notice.
	 * @param string $list_type `preview` or `definitive`.
	 * @param int    $inserted_count Rows committed.
	 * @return void
	 */
	public static function csv_imported( int $notice_id, string $list_type, int $inserted_count ): void {
		ActivityLog::log(
			'recruitment_csv_imported',
			ActivityLog::LEVEL_INFO,
			array(
				'notice_id'      => $notice_id,
				'list_type'      => $list_type,
				'inserted_count' => $inserted_count,
			),
			get_current_user_id()
		);
	}

	/**
	 * CSV import failed (validation error or DB failure).
	 *
	 * Caller passes the count of errors rather than the messages themselves
	 * — line-numbered messages can name candidates indirectly (e.g. "line 7"),
	 * but the messages themselves don't carry sensitive plaintext, so a
	 * count keeps the audit trail compact.
	 *
	 * @param int    $notice_id Target notice.
	 * @param string $list_type `preview` or `definitive`.
	 * @param int    $error_count Number of validation/DB errors.
	 * @return void
	 */
	public static function csv_import_failed( int $notice_id, string $list_type, int $error_count ): void {
		ActivityLog::log(
			'recruitment_csv_import_failed',
			ActivityLog::LEVEL_WARNING,
			array(
				'notice_id'   => $notice_id,
				'list_type'   => $list_type,
				'error_count' => $error_count,
			),
			get_current_user_id()
		);
	}

	/**
	 * Notice status transition succeeded.
	 *
	 * @param int         $notice_id Notice ID.
	 * @param string      $from From status.
	 * @param string      $to   To status.
	 * @param string|null $reason Reason (when the transition required one, e.g. closed → active).
	 * @return void
	 */
	public static function notice_status_changed( int $notice_id, string $from, string $to, ?string $reason = null ): void {
		$context = array(
			'notice_id' => $notice_id,
			'from'      => $from,
			'to'        => $to,
		);
		if ( null !== $reason && '' !== $reason ) {
			$context['reason'] = $reason;
		}

		ActivityLog::log(
			'recruitment_notice_status_changed',
			ActivityLog::LEVEL_INFO,
			$context,
			get_current_user_id()
		);
	}

	/**
	 * Notice promotion succeeded (snapshot mode).
	 *
	 * @param int    $notice_id Notice ID.
	 * @param string $mode `snapshot` or `definitive_import`.
	 * @param int    $copied Rows copied to definitive (0 for definitive_import).
	 * @return void
	 */
	public static function notice_promoted( int $notice_id, string $mode, int $copied ): void {
		ActivityLog::log(
			'recruitment_notice_promoted',
			ActivityLog::LEVEL_INFO,
			array(
				'notice_id' => $notice_id,
				'mode'      => $mode,
				'copied'    => $copied,
			),
			get_current_user_id()
		);
	}

	/**
	 * Classification status transition succeeded.
	 *
	 * @param int         $classification_id Classification ID.
	 * @param string      $from From status.
	 * @param string      $to   To status.
	 * @param string|null $reason Reason (when required).
	 * @return void
	 */
	public static function classification_status_changed( int $classification_id, string $from, string $to, ?string $reason = null ): void {
		$context = array(
			'classification_id' => $classification_id,
			'from'              => $from,
			'to'                => $to,
		);
		if ( null !== $reason && '' !== $reason ) {
			$context['reason'] = $reason;
		}

		ActivityLog::log(
			'recruitment_classification_status_changed',
			ActivityLog::LEVEL_INFO,
			$context,
			get_current_user_id()
		);
	}

	/**
	 * Admin override forced a *stuck* classification back to `empty`.
	 *
	 * Logged as a distinct, `WARNING`-level event (not the routine
	 * `recruitment_classification_status_changed`) so the audit trail flags
	 * privileged overrides that bypassed the terminal guard / reopen-freeze.
	 * The reason is always present — {@see
	 * RecruitmentClassificationStateMachine::admin_override_to_empty} gates
	 * on it before reaching this point.
	 *
	 * @param int    $classification_id Classification ID.
	 * @param string $from   Overridden status (`hired|withdrew|not_shown`).
	 * @param string $reason Justification supplied by the operator.
	 * @return void
	 */
	public static function classification_override_to_empty( int $classification_id, string $from, string $reason ): void {
		ActivityLog::log(
			'recruitment_classification_override_to_empty',
			ActivityLog::LEVEL_WARNING,
			array(
				'classification_id' => $classification_id,
				'from'              => $from,
				'to'                => 'empty',
				'reason'            => $reason,
				'override'          => 1,
			),
			get_current_user_id()
		);
	}

	/**
	 * Single convocation created.
	 *
	 * @param int  $call_id Call ID.
	 * @param int  $classification_id Classification ID.
	 * @param bool $out_of_order Whether the call bypassed the in-order rule.
	 * @return void
	 */
	public static function call_created( int $call_id, int $classification_id, bool $out_of_order ): void {
		ActivityLog::log(
			'recruitment_call_created',
			ActivityLog::LEVEL_INFO,
			array(
				'call_id'           => $call_id,
				'classification_id' => $classification_id,
				'out_of_order'      => $out_of_order ? 1 : 0,
			),
			get_current_user_id()
		);
	}

	/**
	 * Bulk convocation created (one aggregated event for N call rows).
	 *
	 * @param array<int> $classification_ids Classifications convocated.
	 * @param array<int> $call_ids Newly created call IDs (parallel to classification_ids).
	 * @param string     $date_to_assume Shared date.
	 * @param string     $time_to_assume Shared time.
	 * @return void
	 */
	public static function bulk_call_created( array $classification_ids, array $call_ids, string $date_to_assume, string $time_to_assume ): void {
		ActivityLog::log(
			'recruitment_bulk_call_created',
			ActivityLog::LEVEL_INFO,
			array(
				'classification_ids' => array_values( array_map( 'intval', $classification_ids ) ),
				'call_ids'           => array_values( array_map( 'intval', $call_ids ) ),
				'date_to_assume'     => $date_to_assume,
				'time_to_assume'     => $time_to_assume,
				'count'              => count( $classification_ids ),
			),
			get_current_user_id()
		);
	}

	/**
	 * Call cancelled.
	 *
	 * @param int    $call_id Call ID.
	 * @param int    $classification_id Classification ID.
	 * @param string $reason Cancellation reason.
	 * @return void
	 */
	public static function call_cancelled( int $call_id, int $classification_id, string $reason ): void {
		ActivityLog::log(
			'recruitment_call_cancelled',
			ActivityLog::LEVEL_INFO,
			array(
				'call_id'           => $call_id,
				'classification_id' => $classification_id,
				'reason'            => $reason,
			),
			get_current_user_id()
		);
	}

	/**
	 * Candidate promoted to a `wp_user`.
	 *
	 * @param int $candidate_id Candidate row ID.
	 * @param int $user_id Linked WP user ID.
	 * @return void
	 */
	public static function candidate_promoted( int $candidate_id, int $user_id ): void {
		ActivityLog::log(
			'recruitment_candidate_promoted',
			ActivityLog::LEVEL_INFO,
			array(
				'candidate_id' => $candidate_id,
				'user_id'      => $user_id,
			),
			$user_id
		);
	}

	/**
	 * Candidate "General" fields edited (name / phone / notes / email)
	 * via the admin edit page — issue #331 "Edit estendido" frontend.
	 *
	 * `$changes` carries only the keys whose value actually changed;
	 * the caller computes the diff against the pre-update row. Each
	 * entry is `['old' => mixed, 'new' => mixed]`. For the `email`
	 * field the values are the SHA-256 hash digests of the addresses
	 * (not the plaintext), so the audit row is safe to display even
	 * if the operator viewing it doesn't hold the PII-reveal tier;
	 * the other three (name / phone / notes) live unencrypted in the
	 * candidate table by design (§12) and are logged as-is.
	 *
	 * Returns false (no-op) when `$changes` is empty — the save
	 * handler still calls this method, the no-diff branch just short-
	 * circuits so we don't write `{}` rows when the operator hits
	 * "Save" without touching anything.
	 *
	 * @since 6.6.2
	 * @param int                                                      $candidate_id Candidate row ID.
	 * @param array<string, array{old: scalar|null, new: scalar|null}> $changes      Per-field old/new pairs.
	 * @return bool True if a log entry was written.
	 */
	public static function candidate_fields_edited( int $candidate_id, array $changes ): bool {
		if ( empty( $changes ) ) {
			return false;
		}
		ActivityLog::log(
			'recruitment_candidate_fields_edited',
			ActivityLog::LEVEL_INFO,
			array(
				'candidate_id' => $candidate_id,
				'changes'      => $changes,
			),
			get_current_user_id()
		);
		return true;
	}

	/**
	 * Classification's `adjutancy_id` swapped via the candidate edit
	 * page (issue #331). Validation that the new adjutancy is attached
	 * to the classification's notice happens in the REST controller —
	 * by the time this fires the swap has already committed.
	 *
	 * @since 6.6.2
	 * @param int $classification_id Classification ID.
	 * @param int $from              Previous adjutancy_id.
	 * @param int $to                New adjutancy_id.
	 * @return void
	 */
	public static function classification_adjutancy_changed( int $classification_id, int $from, int $to ): void {
		ActivityLog::log(
			'recruitment_classification_adjutancy_changed',
			ActivityLog::LEVEL_INFO,
			array(
				'classification_id' => $classification_id,
				'from'              => $from,
				'to'                => $to,
			),
			get_current_user_id()
		);
	}

	/**
	 * Candidate hard-deleted (gate already checked by RecruitmentDeleteService).
	 *
	 * @param int         $candidate_id Candidate ID (the row no longer exists at log time).
	 * @param string|null $reason       Reason (optional).
	 * @return void
	 */
	public static function candidate_deleted( int $candidate_id, ?string $reason = null ): void {
		$context = array( 'candidate_id' => $candidate_id );
		if ( null !== $reason && '' !== $reason ) {
			$context['reason'] = $reason;
		}
		ActivityLog::log(
			'recruitment_candidate_deleted',
			ActivityLog::LEVEL_WARNING,
			$context,
			get_current_user_id()
		);
	}

	/**
	 * Classification individual delete.
	 *
	 * @param int         $classification_id Classification ID (row no longer exists at log time).
	 * @param string|null $reason            Reason (optional).
	 * @return void
	 */
	public static function classification_deleted( int $classification_id, ?string $reason = null ): void {
		$context = array( 'classification_id' => $classification_id );
		if ( null !== $reason && '' !== $reason ) {
			$context['reason'] = $reason;
		}
		ActivityLog::log(
			'recruitment_classification_deleted',
			ActivityLog::LEVEL_WARNING,
			$context,
			get_current_user_id()
		);
	}

	/**
	 * Adjutancy deletion.
	 *
	 * @param int $adjutancy_id Adjutancy ID (row no longer exists at log time).
	 * @return void
	 */
	public static function adjutancy_deleted( int $adjutancy_id ): void {
		ActivityLog::log(
			'recruitment_adjutancy_deleted',
			ActivityLog::LEVEL_WARNING,
			array( 'adjutancy_id' => $adjutancy_id ),
			get_current_user_id()
		);
	}

	/**
	 * Sensitive PII revealed on the candidate detail screen (#330).
	 *
	 * Dedup: a transient keyed on (user_id, candidate_id, field_key) suppresses
	 * repeated logs within {@see self::PII_REVEAL_DEDUP_SECONDS}. The intent is
	 * audit-grade trail for investigations — collapsing accidental double-clicks
	 * and rapid re-reveals of the same field by the same operator keeps the log
	 * readable without losing signal.
	 *
	 * Honors the `audit_pii_reveals` recruitment setting: when the operator
	 * disables the toggle, the reveal still works but no log row is written.
	 * Defaults to true (audit on) so production behavior is conservative.
	 *
	 * @param int    $candidate_id Candidate row ID.
	 * @param string $field_key    Logical field key (`cpf`, `rf`, `email`).
	 * @return bool True if a log entry was written; false on dedup hit or setting off.
	 */
	public static function pii_revealed( int $candidate_id, string $field_key ): bool {
		// Read the option directly so we don't pull in the
		// RecruitmentSettings::defaults() path (which calls translation
		// helpers) for what is just a boolean check. Defaults to true
		// when the key is missing — first save after upgrade keeps audit
		// on by default.
		$opts = get_option( 'ffc_recruitment_settings', array() );
		if ( is_array( $opts ) && array_key_exists( 'audit_pii_reveals', $opts ) && ! $opts['audit_pii_reveals'] ) {
			return false;
		}

		$user_id = get_current_user_id();

		// Transient key — short enough to keep readable, distinct per
		// (user, candidate, field) tuple. wp_using_ext_object_cache() handles
		// the multi-frontend case automatically (transient API falls back to
		// the options table when no object cache is wired up).
		$dedup_key = sprintf(
			'ffc_pii_reveal_%d_%d_%s',
			$user_id,
			$candidate_id,
			preg_replace( '/[^a-z0-9_]+/', '', $field_key )
		);

		if ( false !== get_transient( $dedup_key ) ) {
			return false;
		}
		set_transient( $dedup_key, 1, self::PII_REVEAL_DEDUP_SECONDS );

		ActivityLog::log(
			'recruitment_pii_revealed',
			ActivityLog::LEVEL_INFO,
			array(
				'candidate_id' => $candidate_id,
				'field_key'    => $field_key,
			),
			$user_id
		);

		return true;
	}

	/**
	 * Dedup window for pii_revealed() in seconds. 60s collapses the obvious
	 * UX cases (operator clicks "Reveal", masks it, clicks again) while still
	 * splitting genuinely separate review sessions across rows.
	 */
	private const PII_REVEAL_DEDUP_SECONDS = 60;
}
