<?php
/**
 * Reason Repository
 *
 * Public façade for the global "Reason" catalog — operator-defined labels
 * attached to a preliminary-list classification's `preview_status`
 * (e.g. "appeal granted because …", "denied because …"). Like
 * adjutancies in shape but reusable across every notice without an
 * attach junction.
 *
 * Since the #563 backlog read/write split (B3) this class is a thin façade:
 * reads live in {@see RecruitmentReasonReader}, writes in
 * {@see RecruitmentReasonWriter}. It is kept as the public entry point so the
 * existing call sites and the public constants below need no change.
 *
 * Schema-level invariants enforced by the writer:
 *
 * - `slug` is UNIQUE (DB constraint). Insert/update operations rely on
 *   the constraint to surface duplicates as a `false` return.
 * - Deletion gating (zero references in `classification.preview_reason_id`)
 *   is enforced by the service layer, not here — the writer's `delete()`
 *   is unconditional.
 *
 * `applies_to` is an empty-or-CSV list of preview-status enum values
 * (`denied,granted,appeal_denied,appeal_granted`). Empty string means
 * "applies to every preview status"; a non-empty list narrows the
 * dropdown when the admin picks a status.
 *
 * Tech-debt (#563 B3): migrate call sites to depend on RecruitmentReasonReader /
 * RecruitmentReasonWriter directly, then retire this delegating façade.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public façade over {@see RecruitmentReasonReader} + {@see RecruitmentReasonWriter}.
 *
 * @phpstan-type ReasonRow \stdClass&object{id: numeric-string, slug: string, label: string, color: string, applies_to: string, created_at: string, updated_at: string}
 */
class RecruitmentReasonRepository {

	/** Default badge color used when admins haven't picked one yet. */
	public const DEFAULT_COLOR = '#e9ecef';

	/** Preview-status enum values that a reason can be tagged with. */
	public const APPLIES_TO_VALUES = array( 'denied', 'granted', 'appeal_denied', 'appeal_granted' );

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return RecruitmentReasonReader::get_table_name();
	}

	// ─────────────────────────────────────────────.
	// Reads — delegate to RecruitmentReasonReader.
	// ─────────────────────────────────────────────.

	/**
	 * Get a reason row by ID.
	 *
	 * @param int $id Reason ID.
	 * @return ReasonRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		return RecruitmentReasonReader::get_by_id( $id );
	}

	/**
	 * List all reasons, ordered by label ASC.
	 *
	 * @return list<ReasonRow>
	 */
	public static function get_all(): array {
		return RecruitmentReasonReader::get_all();
	}

	/**
	 * Decode a stored applies_to CSV back into a list.
	 *
	 * @param string $stored CSV value from the row.
	 * @return list<string>
	 */
	public static function decode_applies_to( string $stored ): array {
		return RecruitmentReasonReader::decode_applies_to( $stored );
	}

	/**
	 * Count how many classification rows currently reference this reason.
	 *
	 * @param int $id Reason ID.
	 * @return int
	 */
	public static function count_references( int $id ): int {
		return RecruitmentReasonReader::count_references( $id );
	}

	// ─────────────────────────────────────────────.
	// Writes — delegate to RecruitmentReasonWriter.
	// ─────────────────────────────────────────────.

	/**
	 * Create a new reason row.
	 *
	 * @param string            $slug       Unique slug.
	 * @param string            $label      Display label.
	 * @param string            $color      Optional badge color (#RGB / #RRGGBB / #RRGGBBAA).
	 * @param array<int,string> $applies_to Subset of {@see self::APPLIES_TO_VALUES}.
	 *                                  Empty array = applies to every preview status.
	 * @return int|false New reason ID or false on failure.
	 */
	public static function create( string $slug, string $label, string $color = '', array $applies_to = array() ) {
		return RecruitmentReasonWriter::create( $slug, $label, $color, $applies_to );
	}

	/**
	 * Update slug / label / color / applies_to on an existing reason.
	 *
	 * @param int                  $id   Reason ID.
	 * @param array<string, mixed> $data Subset of {slug, label, color, applies_to (array)}.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		return RecruitmentReasonWriter::update( $id, $data );
	}

	/**
	 * Delete a reason row unconditionally.
	 *
	 * @param int $id Reason ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return RecruitmentReasonWriter::delete( $id );
	}

	/**
	 * Normalize a color string into the canonical lowercase hex form.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_color( string $value ): string {
		return RecruitmentReasonWriter::normalize_color( $value );
	}

	/**
	 * Normalize an applies_to selection into the canonical CSV form.
	 *
	 * @param array<int, mixed> $value Raw selection.
	 * @return string
	 */
	public static function normalize_applies_to( array $value ): string {
		return RecruitmentReasonWriter::normalize_applies_to( $value );
	}
}
