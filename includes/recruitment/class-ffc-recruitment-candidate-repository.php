<?php
/**
 * Candidate Repository
 *
 * CRUD for `ffc_recruitment_candidate` rows. Candidates start standalone
 * (no `wp_users` link) and are promoted via `UserCreator::get_or_create_user()`
 * — promotion logic lives in the service layer (sprint 4 for CSV import,
 * sprint 9.1 for manual edits via REST). This repository exposes only:
 *
 * - CRUD primitives ({@see self::create()}, {@see self::update()},
 *   {@see self::delete()}).
 * - Hash-based lookups ({@see self::get_by_cpf_hash()},
 *   {@see self::get_by_rf_hash()}, {@see self::get_by_email_hash()}).
 * - The `user_id` setter for promotion ({@see self::set_user_id()}).
 *
 * `cpf_hash` and `rf_hash` carry separate UNIQUE constraints (DB-level): a
 * second insert with a colliding hash returns `false`. The REST controller
 * is responsible for surfacing this as a 409 with `existing_candidate_id`
 * (sprint 9.1).
 *
 * `pcd_hash` is NOT NULL with both candidate domains
 * (`HMAC(salt, ("1"|"0") || candidate_id)`). Computation lives in the
 * service layer (sprint 4) — the repository accepts whatever string the
 * caller supplies.
 *
 * Since the #563 phase-2 read/write split (Sprint D1) this class is a thin
 * façade: reads live in {@see RecruitmentCandidateReader}, writes in
 * {@see RecruitmentCandidateWriter}. It is kept as the public entry point so
 * existing call sites and the typed shape below need no change.
 *
 * Tech-debt (#563 B3): migrate call sites to depend on RecruitmentCandidateReader /
 * RecruitmentCandidateWriter directly, then retire this delegating façade.
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
 * Public façade over {@see RecruitmentCandidateReader} + {@see RecruitmentCandidateWriter}.
 *
 * Encrypted columns (`*_encrypted`) and their corresponding hash columns
 * (`*_hash`) follow the existing plugin convention (cf. `Activator::add_columns`):
 * `*_encrypted` is TEXT, `*_hash` is VARCHAR(64). Encryption / hashing is
 * delegated to {@see \FreeFormCertificate\Core\Encryption} at the service
 * layer; this repository never touches plaintext values.
 *
 * @since 6.0.0
 *
 * @phpstan-type CandidateRow \stdClass&object{id: numeric-string, user_id: numeric-string|null, name: string, cpf_encrypted: string|null, cpf_hash: string|null, rf_encrypted: string|null, rf_hash: string|null, email_encrypted: string|null, email_hash: string|null, phone: string|null, notes: string|null, pcd_hash: string, created_at: string, updated_at: string}
 */
class RecruitmentCandidateRepository {

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return RecruitmentCandidateReader::get_table_name();
	}

	// ─────────────────────────────────────────────.
	// Reads — delegate to RecruitmentCandidateReader.
	// ─────────────────────────────────────────────.

	/**
	 * Get a candidate by ID.
	 *
	 * @param int $id Candidate ID.
	 * @return CandidateRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		return RecruitmentCandidateReader::get_by_id( $id );
	}

	/**
	 * Batch-fetch candidate rows by ID.
	 *
	 * @param array<int, int> $ids Candidate IDs.
	 * @return array<int, CandidateRow>
	 */
	public static function get_by_ids( array $ids ): array {
		return RecruitmentCandidateReader::get_by_ids( $ids );
	}

	/**
	 * Look up a candidate by CPF hash.
	 *
	 * @param string $cpf_hash Hash produced by `Encryption::hash()`.
	 * @return CandidateRow|null
	 */
	public static function get_by_cpf_hash( string $cpf_hash ): ?object {
		return RecruitmentCandidateReader::get_by_cpf_hash( $cpf_hash );
	}

	/**
	 * Look up a candidate by RF hash.
	 *
	 * @param string $rf_hash Hash produced by `Encryption::hash()`.
	 * @return CandidateRow|null
	 */
	public static function get_by_rf_hash( string $rf_hash ): ?object {
		return RecruitmentCandidateReader::get_by_rf_hash( $rf_hash );
	}

	/**
	 * Look up the first candidate matching a given email hash.
	 *
	 * @param string $email_hash Hash produced by `Encryption::hash()`.
	 * @return CandidateRow|null
	 */
	public static function get_by_email_hash( string $email_hash ): ?object {
		return RecruitmentCandidateReader::get_by_email_hash( $email_hash );
	}

	/**
	 * Get the candidate rows for a logged-in WP user.
	 *
	 * @param int $user_id WP user ID.
	 * @return list<CandidateRow>
	 */
	public static function get_by_user_id( int $user_id ): array {
		return RecruitmentCandidateReader::get_by_user_id( $user_id );
	}

	/**
	 * Paginated list for the admin Candidates list table.
	 *
	 * @param string $name_search Optional substring filter on name (empty = no filter).
	 * @param int    $limit       Maximum rows (1-200).
	 * @param int    $offset      Offset for pagination.
	 * @return list<CandidateRow>
	 */
	public static function get_paginated( string $name_search, int $limit, int $offset ): array {
		return RecruitmentCandidateReader::get_paginated( $name_search, $limit, $offset );
	}

	/**
	 * Page of candidates that have at least one classification in the
	 * supplied adjutancy.
	 *
	 * @param string $name_search Optional substring filter on name.
	 * @param int    $adjutancy_id Adjutancy id (must be > 0).
	 * @param int    $limit       Page size.
	 * @param int    $offset      0-indexed offset.
	 * @return list<CandidateRow>
	 */
	public static function get_paginated_for_adjutancy( string $name_search, int $adjutancy_id, int $limit, int $offset ): array {
		return RecruitmentCandidateReader::get_paginated_for_adjutancy( $name_search, $adjutancy_id, $limit, $offset );
	}

	/**
	 * Companion count for {@see self::get_paginated_for_adjutancy()}.
	 *
	 * @param string $name_search  Optional substring filter on name.
	 * @param int    $adjutancy_id Adjutancy id.
	 * @return int
	 */
	public static function count_paginated_for_adjutancy( string $name_search, int $adjutancy_id ): int {
		return RecruitmentCandidateReader::count_paginated_for_adjutancy( $name_search, $adjutancy_id );
	}

	/**
	 * Total candidate count, optionally filtered by `name` substring.
	 *
	 * @param string $name_search Optional substring filter on name.
	 * @return int
	 */
	public static function count_paginated( string $name_search ): int {
		return RecruitmentCandidateReader::count_paginated( $name_search );
	}

	/**
	 * Return every candidate ID whose `email_hash` matches the given digest.
	 *
	 * @since 6.6.2
	 * @param string $email_hash Hash produced by `Encryption::hash()`.
	 * @return list<int> Candidate IDs matching the hash (empty array on no match).
	 */
	public static function get_ids_by_email_hash( string $email_hash ): array {
		return RecruitmentCandidateReader::get_ids_by_email_hash( $email_hash );
	}

	/**
	 * Paginated candidates with combinable filters (issue #331 search frontend).
	 *
	 * @since 6.6.2
	 * @param string         $name_search   Optional substring filter on name.
	 * @param list<int>|null $id_constraint Optional candidate-id constraint set.
	 * @param int            $adjutancy_id  Optional adjutancy id; 0 = no filter.
	 * @param string         $status        Optional classification status; '' = no filter.
	 * @param int            $limit         Page size (capped at 200).
	 * @param int            $offset        Page offset.
	 * @return list<CandidateRow>
	 */
	public static function get_paginated_filtered(
		string $name_search,
		?array $id_constraint,
		int $adjutancy_id,
		string $status,
		int $limit,
		int $offset
	): array {
		return RecruitmentCandidateReader::get_paginated_filtered( $name_search, $id_constraint, $adjutancy_id, $status, $limit, $offset );
	}

	/**
	 * Companion count for {@see self::get_paginated_filtered()}.
	 *
	 * @since 6.6.2
	 * @param string         $name_search   Optional substring filter on name.
	 * @param list<int>|null $id_constraint Optional candidate-id constraint set.
	 * @param int            $adjutancy_id  Optional adjutancy id; 0 = no filter.
	 * @param string         $status        Optional classification status; '' = no filter.
	 * @return int
	 */
	public static function count_paginated_filtered(
		string $name_search,
		?array $id_constraint,
		int $adjutancy_id,
		string $status
	): int {
		return RecruitmentCandidateReader::count_paginated_filtered( $name_search, $id_constraint, $adjutancy_id, $status );
	}

	// ─────────────────────────────────────────────.
	// Writes — delegate to RecruitmentCandidateWriter.
	// ─────────────────────────────────────────────.

	/**
	 * Insert a new candidate row.
	 *
	 * @param array<string, mixed> $data Candidate payload (see allowed keys above).
	 * @return int|false New candidate ID or false on failure.
	 */
	public static function create( array $data ) {
		return RecruitmentCandidateWriter::create( $data );
	}

	/**
	 * Update mutable candidate fields.
	 *
	 * @param int                  $id   Candidate ID.
	 * @param array<string, mixed> $data Update payload.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		return RecruitmentCandidateWriter::update( $id, $data );
	}

	/**
	 * Set or clear the linked `wp_users.ID` (promotion / un-link).
	 *
	 * @param int      $id Candidate ID.
	 * @param int|null $user_id WP user ID, or null to clear.
	 * @return bool
	 */
	public static function set_user_id( int $id, ?int $user_id ): bool {
		return RecruitmentCandidateWriter::set_user_id( $id, $user_id );
	}

	/**
	 * Hard-delete a candidate row unconditionally.
	 *
	 * @param int $id Candidate ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return RecruitmentCandidateWriter::delete( $id );
	}
}
