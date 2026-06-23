<?php
/**
 * Adjutancy Repository
 *
 * Public façade for the global Adjutancy ("matéria") catalog. Adjutancies are
 * reusable across notices via the `ffc_recruitment_notice_adjutancy` junction.
 *
 * Since the #563 backlog read/write split (B3) this class is a thin façade:
 * reads live in {@see RecruitmentAdjutancyReader}, writes in
 * {@see RecruitmentAdjutancyWriter}. It is kept as the public entry point so the
 * existing call sites and the public constant below need no change.
 *
 * Schema-level invariants enforced by the writer:
 *
 * - `slug` is UNIQUE (DB constraint). Insert/update operations rely on the
 *   constraint to surface duplicates as a `false` return.
 * - Deletion gating (zero references in `notice_adjutancy` and zero references
 *   in `classification`) is enforced by the service layer, not here — the
 *   writer's `delete()` is unconditional. The deletion gate lives in the
 *   REST controller / service so the gate can return a typed 409 with
 *   reference counts instead of a silent failure.
 *
 * Tech-debt (#563 B3): migrate call sites to depend on RecruitmentAdjutancyReader /
 * RecruitmentAdjutancyWriter directly, then retire this delegating façade.
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
 * Public façade over {@see RecruitmentAdjutancyReader} + {@see RecruitmentAdjutancyWriter}.
 *
 * @phpstan-type AdjutancyRow \stdClass&object{id: numeric-string, slug: string, name: string, color: string, created_at: string, updated_at: string}
 */
class RecruitmentAdjutancyRepository {

	/** Default badge color used when admins haven't picked one yet. */
	public const DEFAULT_COLOR = '#e9ecef';

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return RecruitmentAdjutancyReader::get_table_name();
	}

	// ─────────────────────────────────────────────.
	// Reads — delegate to RecruitmentAdjutancyReader.
	// ─────────────────────────────────────────────.

	/**
	 * Get an adjutancy row by ID.
	 *
	 * @param int $id Adjutancy ID.
	 * @return AdjutancyRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		return RecruitmentAdjutancyReader::get_by_id( $id );
	}

	/**
	 * Get an adjutancy row by slug.
	 *
	 * @param string $slug Adjutancy slug (lowercase, unique).
	 * @return AdjutancyRow|null
	 */
	public static function get_by_slug( string $slug ): ?object {
		return RecruitmentAdjutancyReader::get_by_slug( $slug );
	}

	/**
	 * List all adjutancies, ordered by name ASC.
	 *
	 * @return list<AdjutancyRow>
	 */
	public static function get_all(): array {
		return RecruitmentAdjutancyReader::get_all();
	}

	// ─────────────────────────────────────────────.
	// Writes — delegate to RecruitmentAdjutancyWriter.
	// ─────────────────────────────────────────────.

	/**
	 * Create a new adjutancy row.
	 *
	 * @param string $slug  Unique slug.
	 * @param string $name  Display name.
	 * @param string $color Optional badge background color (#RGB / #RRGGBB / #RRGGBBAA).
	 * @return int|false New adjutancy ID or false on failure.
	 */
	public static function create( string $slug, string $name, string $color = '' ) {
		return RecruitmentAdjutancyWriter::create( $slug, $name, $color );
	}

	/**
	 * Normalize a color string into the canonical lowercase hex form.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_color( string $value ): string {
		return RecruitmentAdjutancyWriter::normalize_color( $value );
	}

	/**
	 * Update name and/or slug on an existing adjutancy.
	 *
	 * @param int                  $id   Adjutancy ID.
	 * @param array<string, mixed> $data Subset of {slug, name, color}.
	 * @return bool True on successful update (zero or more rows affected).
	 */
	public static function update( int $id, array $data ): bool {
		return RecruitmentAdjutancyWriter::update( $id, $data );
	}

	/**
	 * Delete an adjutancy row unconditionally.
	 *
	 * @param int $id Adjutancy ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return RecruitmentAdjutancyWriter::delete( $id );
	}
}
