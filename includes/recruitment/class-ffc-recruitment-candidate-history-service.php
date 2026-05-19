<?php
/**
 * Recruitment Candidate History Service
 *
 * Per-candidate audit-trail aggregator. Cross-references the recruitment
 * action vocabulary (see {@see RecruitmentActivityLogger}) against the
 * candidate's classifications so the operator sees every activity-log
 * entry that touches "this candidate" on one screen — status changes,
 * calls (issued / cancelled), PII reveals, field edits, adjutancy swaps,
 * classification deletions, promotion to WP user.
 *
 * Filter strategy:
 *
 *   1. SELECT activity log rows where `action IN (...)` for the recruitment
 *      action codes that can reference a candidate (directly or via a
 *      classification). Bounded by a generous LIMIT so the per-candidate
 *      view stays performant even on a system with millions of total
 *      activity rows.
 *
 *   2. Resolve `context` via `ActivityLogQuery::resolve_context()` so
 *      encrypted rows (those carrying sensitive keys per
 *      `SensitiveFieldRegistry`) are decoded transparently.
 *
 *   3. Filter in PHP: keep rows whose context references this candidate
 *      directly (`candidate_id`), via a classification this candidate
 *      currently owns (`classification_id`), or via the bulk-call array
 *      (`classification_ids`). Classifications that were hard-deleted
 *      before this fetch are lost — acceptable tradeoff for v1; the
 *      delete event itself still lands via the direct-id path when it
 *      carried a `candidate_id`.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.6.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\ActivityLogQuery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless service. Issue #331 "History" frontend.
 */
final class RecruitmentCandidateHistoryService {

	/**
	 * Activity log action codes that may reference a candidate. Kept
	 * in sync with the §13 vocabulary in {@see RecruitmentActivityLogger}.
	 * Codes that never name a candidate (notice / adjutancy / CSV
	 * pipeline level) are deliberately omitted — they would just
	 * inflate the SELECT before the PHP-side filter rejects them.
	 *
	 * @var list<string>
	 */
	private const RELEVANT_ACTIONS = array(
		'recruitment_candidate_promoted',
		'recruitment_candidate_fields_edited',
		'recruitment_pii_revealed',
		'recruitment_classification_status_changed',
		'recruitment_classification_adjutancy_changed',
		'recruitment_classification_deleted',
		'recruitment_call_created',
		'recruitment_bulk_call_created',
		'recruitment_call_cancelled',
	);

	/**
	 * Pre-filter SELECT cap. Over-fetched so the PHP-side filter has
	 * room to drop unrelated entries and still surface a useful page.
	 */
	private const SELECT_CEILING = 400;

	/**
	 * Return every activity-log entry that references the candidate,
	 * sorted by `created_at DESC` (most recent first).
	 *
	 * Each entry mirrors the shape `ActivityLogQuery::get_activities()`
	 * returns: `id`, `action`, `level`, `context` (resolved/decoded),
	 * `user_id`, `user_ip`, `created_at`.
	 *
	 * @param int $candidate_id Candidate row ID.
	 * @param int $limit        Page size (max 200).
	 * @return list<array<string, mixed>>
	 */
	public static function get_for_candidate( int $candidate_id, int $limit = 50 ): array {
		if ( $candidate_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 200, $limit ) );

		$classifications = RecruitmentClassificationRepository::get_for_candidate( $candidate_id );
		$cls_ids         = array_map( static fn( $c ) => (int) $c->id, $classifications );

		// Delegate the SELECT to ActivityLogQuery so the read path stays
		// centralized (context resolution + decrypt + json_decode happen
		// inside `get_activities()` once, for every consumer). Over-fetch
		// vs $limit because we still need to filter in PHP — the action
		// codes themselves don't carry candidate identity, only their
		// context does.
		$rows = ActivityLogQuery::get_activities(
			array(
				'action_in' => self::RELEVANT_ACTIONS,
				'orderby'   => 'created_at',
				'order'     => 'DESC',
				'limit'     => self::SELECT_CEILING,
			)
		);

		$out = array();
		foreach ( $rows as $row ) {
			if ( self::matches_candidate( $row, $candidate_id, $cls_ids ) ) {
				$out[] = $row;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Decide whether a resolved log row references the candidate.
	 *
	 * Accepts:
	 *   - direct `candidate_id` match (candidate_promoted, fields_edited,
	 *     pii_revealed)
	 *   - `classification_id` ∈ candidate's current classifications
	 *   - any of `classification_ids[]` ∈ candidate's current classifications
	 *     (covers `recruitment_bulk_call_created`)
	 *
	 * @param array<string, mixed> $row          Resolved activity log row.
	 * @param int                  $candidate_id Candidate ID.
	 * @param array                $cls_ids      Candidate's classification IDs.
	 * @phpstan-param list<int>    $cls_ids
	 * @return bool
	 */
	public static function matches_candidate( array $row, int $candidate_id, array $cls_ids ): bool {
		$ctx = $row['context'] ?? array();
		if ( ! is_array( $ctx ) ) {
			return false;
		}

		if ( isset( $ctx['candidate_id'] ) && (int) $ctx['candidate_id'] === $candidate_id ) {
			return true;
		}

		if ( isset( $ctx['classification_id'] ) && in_array( (int) $ctx['classification_id'], $cls_ids, true ) ) {
			return true;
		}

		if ( isset( $ctx['classification_ids'] ) && is_array( $ctx['classification_ids'] ) ) {
			foreach ( $ctx['classification_ids'] as $cid ) {
				if ( in_array( (int) $cid, $cls_ids, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Render-side summary string for an event row. Returns a short
	 * single-line, already-escaped description suitable for direct
	 * `echo` from the candidate-edit history table.
	 *
	 * Unknown action codes fall back to the action code itself — keeps
	 * the panel forward-compatible when new recruitment events ship
	 * before this method learns about them.
	 *
	 * @param string               $action  Action code.
	 * @param array<string, mixed> $context Resolved context.
	 * @return string Escaped HTML.
	 */
	public static function summarize_event( string $action, array $context ): string {
		switch ( $action ) {
			case 'recruitment_candidate_promoted':
				return esc_html(
					sprintf(
						/* translators: %d — WP user ID */
						__( 'Promoted to WordPress user #%d.', 'ffcertificate' ),
						(int) ( $context['user_id'] ?? 0 )
					)
				);

			case 'recruitment_candidate_fields_edited':
				$changes = isset( $context['changes'] ) && is_array( $context['changes'] ) ? array_keys( $context['changes'] ) : array();
				return esc_html(
					sprintf(
						/* translators: %s — comma-separated list of field names */
						__( 'Fields edited: %s.', 'ffcertificate' ),
						implode( ', ', $changes )
					)
				);

			case 'recruitment_pii_revealed':
				return esc_html(
					sprintf(
						/* translators: %s — PII field key (cpf / rf / email) */
						__( 'PII revealed: %s.', 'ffcertificate' ),
						(string) ( $context['field_key'] ?? '?' )
					)
				);

			case 'recruitment_classification_status_changed':
				$base = sprintf(
					/* translators: 1 — classification ID, 2 — previous status, 3 — new status */
					__( 'Classification #%1$d status: %2$s → %3$s.', 'ffcertificate' ),
					(int) ( $context['classification_id'] ?? 0 ),
					(string) ( $context['from'] ?? '?' ),
					(string) ( $context['to'] ?? '?' )
				);
				return esc_html( self::append_reason( $base, $context ) );

			case 'recruitment_classification_adjutancy_changed':
				return esc_html(
					sprintf(
						/* translators: 1 — classification ID, 2 — previous adjutancy id, 3 — new adjutancy id */
						__( 'Classification #%1$d adjutancy: #%2$d → #%3$d.', 'ffcertificate' ),
						(int) ( $context['classification_id'] ?? 0 ),
						(int) ( $context['from'] ?? 0 ),
						(int) ( $context['to'] ?? 0 )
					)
				);

			case 'recruitment_classification_deleted':
				$base = sprintf(
					/* translators: %d — classification id */
					__( 'Classification #%d deleted.', 'ffcertificate' ),
					(int) ( $context['classification_id'] ?? 0 )
				);
				return esc_html( self::append_reason( $base, $context ) );

			case 'recruitment_call_created':
				$ooo_flag = ( isset( $context['out_of_order'] ) && 1 === (int) $context['out_of_order'] ) ? ' (out-of-order)' : '';
				return esc_html(
					sprintf(
						/* translators: 1 — call ID, 2 — classification ID, 3 — out-of-order flag suffix */
						__( 'Call #%1$d issued on classification #%2$d%3$s.', 'ffcertificate' ),
						(int) ( $context['call_id'] ?? 0 ),
						(int) ( $context['classification_id'] ?? 0 ),
						$ooo_flag
					)
				);

			case 'recruitment_bulk_call_created':
				return esc_html(
					sprintf(
						/* translators: %d — total count of classifications convocated in the bulk call */
						__( 'Bulk call issued for %d classifications.', 'ffcertificate' ),
						(int) ( $context['count'] ?? 0 )
					)
				);

			case 'recruitment_call_cancelled':
				$base = sprintf(
					/* translators: 1 — call ID, 2 — classification ID */
					__( 'Call #%1$d cancelled on classification #%2$d.', 'ffcertificate' ),
					(int) ( $context['call_id'] ?? 0 ),
					(int) ( $context['classification_id'] ?? 0 )
				);
				return esc_html( self::append_reason( $base, $context ) );

			default:
				return esc_html( $action );
		}
	}

	/**
	 * Append the `reason` clause to an event summary when the context
	 * carries one. Pure formatting helper extracted so the `__()` calls
	 * with placeholders sit next to their `translators:` annotation per
	 * the WPCS I18n rule.
	 *
	 * @param string               $base    Already-formatted base summary.
	 * @param array<string, mixed> $context Resolved log context.
	 * @return string Plain text (caller wraps in esc_html).
	 */
	private static function append_reason( string $base, array $context ): string {
		$reason = (string) ( $context['reason'] ?? '' );
		if ( '' === $reason ) {
			return $base;
		}
		return $base . ' ' . sprintf(
			/* translators: %s — operator-supplied reason text */
			__( '(reason: %s)', 'ffcertificate' ),
			$reason
		);
	}
}
