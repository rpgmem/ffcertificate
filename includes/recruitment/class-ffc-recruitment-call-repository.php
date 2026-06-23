<?php
/**
 * Call Repository
 *
 * Public façade for `ffc_recruitment_call` rows. Calls are append-only
 * history: cancellation does NOT delete the row — it stamps
 * `cancellation_reason` / `cancelled_at` / `cancelled_by` on the existing
 * row. A subsequent re-call for the same classification creates a new row.
 *
 * Since the #563 backlog read/write split (B3) this class is a thin façade:
 * reads live in {@see RecruitmentCallReader}, writes in
 * {@see RecruitmentCallWriter}. It is kept as the public entry point so the
 * existing call sites need no change.
 *
 * "Active call for classification" = the most recent row for the
 * classification with `cancelled_at IS NULL`. The composite index
 * `(classification_id, cancelled_at)` covers this lookup directly.
 *
 * Tech-debt (#563 B3): migrate call sites to depend on RecruitmentCallReader /
 * RecruitmentCallWriter directly, then retire this delegating façade.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public façade over {@see RecruitmentCallReader} + {@see RecruitmentCallWriter}.
 *
 * @phpstan-type CallRow \stdClass&object{id: numeric-string, classification_id: numeric-string, called_at: numeric-string|int, date_to_assume: string, time_to_assume: string, out_of_order: numeric-string, out_of_order_reason: string|null, cancellation_reason: string|null, cancelled_at: numeric-string|int|null, cancelled_by: numeric-string|null, notes: string|null, created_by: numeric-string, created_at: string, updated_at: string}
 */
class RecruitmentCallRepository {

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return RecruitmentCallReader::get_table_name();
	}

	// ─────────────────────────────────────────────.
	// Reads — delegate to RecruitmentCallReader.
	// ─────────────────────────────────────────────.

	/**
	 * Get a call row by ID.
	 *
	 * @param int $id Call ID.
	 * @return CallRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		return RecruitmentCallReader::get_by_id( $id );
	}

	/**
	 * Get the active (non-cancelled) call for a classification, if any.
	 *
	 * @param int $classification_id Classification ID.
	 * @return CallRow|null
	 */
	public static function get_active_for_classification( int $classification_id ): ?object {
		return RecruitmentCallReader::get_active_for_classification( $classification_id );
	}

	/**
	 * List all calls for a classification (history view, including cancelled).
	 *
	 * @param int $classification_id Classification ID.
	 * @return list<CallRow>
	 */
	public static function get_history_for_classification( int $classification_id ): array {
		return RecruitmentCallReader::get_history_for_classification( $classification_id );
	}

	/**
	 * Get all calls (history) for a list of classification IDs.
	 *
	 * @param array<int> $classification_ids Classification IDs.
	 * @return list<CallRow>
	 */
	public static function get_history_for_classifications( array $classification_ids ): array {
		return RecruitmentCallReader::get_history_for_classifications( $classification_ids );
	}

	/**
	 * Count all calls for a classification, including cancelled.
	 *
	 * @param int $classification_id Classification ID.
	 * @return int
	 */
	public static function count_for_classification( int $classification_id ): int {
		return RecruitmentCallReader::count_for_classification( $classification_id );
	}

	// ─────────────────────────────────────────────.
	// Writes — delegate to RecruitmentCallWriter.
	// ─────────────────────────────────────────────.

	/**
	 * Insert a new call row.
	 *
	 * @param array{classification_id: int, date_to_assume: string, time_to_assume: string, created_by: int, out_of_order?: int, out_of_order_reason?: string|null, notes?: string|null, called_at?: int|string} $data Call payload.
	 * @return int|false New call ID or false on failure.
	 */
	public static function create( array $data ) {
		return RecruitmentCallWriter::create( $data );
	}

	/**
	 * Stamp cancellation columns on an existing call row.
	 *
	 * @param int    $id Call ID.
	 * @param string $reason Cancellation reason (mandatory; §5.2).
	 * @param int    $cancelled_by WP user ID who performed the cancel.
	 * @return int Number of rows affected (1 on first cancel, 0 if already cancelled).
	 */
	public static function mark_cancelled( int $id, string $reason, int $cancelled_by ): int {
		return RecruitmentCallWriter::mark_cancelled( $id, $reason, $cancelled_by );
	}

	/**
	 * Update mutable, non-history fields on a call row.
	 *
	 * @param int                  $id Call ID.
	 * @param array<string, mixed> $data Update payload (only `notes` honored).
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		return RecruitmentCallWriter::update( $id, $data );
	}
}
