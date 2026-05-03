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
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database repository for `ffc_recruitment_candidate` rows.
 *
 * Encrypted columns (`*_encrypted`) and their corresponding hash columns
 * (`*_hash`) follow the existing plugin convention (cf. `Activator::add_columns`):
 * `*_encrypted` is TEXT, `*_hash` is VARCHAR(64). Encryption / hashing is
 * delegated to {@see \FreeFormCertificate\Core\Encryption} at the service
 * layer; this repository never touches plaintext values.
 *
 * @phpstan-type CandidateRow \stdClass&object{id: numeric-string, user_id: numeric-string|null, name: string, cpf_encrypted: string|null, cpf_hash: string|null, rf_encrypted: string|null, rf_hash: string|null, email_encrypted: string|null, email_hash: string|null, phone: string|null, notes: string|null, pcd_hash: string, created_at: string, updated_at: string}
 */
class RecruitmentCandidateRepository {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_recruitment_candidate';
	}

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_recruitment_candidate';
	}

	/**
	 * Get a candidate by ID.
	 *
	 * @param int $id Candidate ID.
	 * @return CandidateRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		$cached = static::cache_get( "id_{$id}" );
		if ( false !== $cached ) {
			/**
			 * Object-cache return cast.
			 *
			 * @var CandidateRow|null $cached
			 */
			return $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var CandidateRow|null $result
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Object-cached; %i for table identifier.
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id )
		);

		if ( $result ) {
			static::cache_set( "id_{$id}", $result );
		}

		return $result;
	}

	/**
	 * Batch-fetch candidate rows by ID.
	 *
	 * Returns an `id => row` map for the supplied id list, single
	 * `WHERE id IN (...)` query. Object cache is warmed for every
	 * fetched row so subsequent {@see self::get_by_id()} lookups in
	 * the same request hit the cache without a second SELECT — that's
	 * the primary call pattern from the public shortcode's
	 * `render_section()`, which still loops `get_by_id()` per row
	 * inside `render_row()` for each cell that needs a name / cpf /
	 * email lookup.
	 *
	 * Empty input returns an empty array. Duplicate ids in the input
	 * are silently deduplicated.
	 *
	 * @param array<int, int> $ids Candidate IDs.
	 * @return array<int, CandidateRow>
	 */
	public static function get_by_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $i ): bool => $i > 0 ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		// `%d` placeholders generated dynamically for the IN clause —
		// $ids is already coerced to a list<int> above so the join is
		// safe; the wpdb->prepare() call still binds each id per the
		// placeholder count.
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT * FROM %i WHERE id IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are %i + N×%d, all generated literals; $ids items are intval-coerced above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $table ), $ids ) ) );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		/**
		 * Cast wpdb's mixed return into the typed shape.
		 *
		 * @var array<int, CandidateRow> $out
		 */
		$out = array();
		foreach ( $rows as $row ) {
			$row_id = (int) ( $row->id ?? 0 );
			if ( $row_id <= 0 ) {
				continue;
			}
			static::cache_set( "id_{$row_id}", $row );
			/** @var CandidateRow $row */
			$out[ $row_id ] = $row;
		}
		return $out;
	}

	/**
	 * Look up a candidate by CPF hash.
	 *
	 * Used by the CSV importer to detect cross-CSV / cross-notice reuse: a
	 * matching `cpf_hash` reuses the existing candidate row (with new
	 * classifications added) instead of creating a duplicate.
	 *
	 * @param string $cpf_hash Hash produced by `Encryption::hash()`.
	 * @return CandidateRow|null
	 */
	public static function get_by_cpf_hash( string $cpf_hash ): ?object {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var CandidateRow|null $result
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed by UNIQUE constraint.
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE cpf_hash = %s LIMIT 1', $table, $cpf_hash )
		);

		return $result ? $result : null;
	}

	/**
	 * Look up a candidate by RF hash.
	 *
	 * @param string $rf_hash Hash produced by `Encryption::hash()`.
	 * @return CandidateRow|null
	 */
	public static function get_by_rf_hash( string $rf_hash ): ?object {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var CandidateRow|null $result
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed by UNIQUE constraint (RF).
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE rf_hash = %s LIMIT 1', $table, $rf_hash )
		);

		return $result ? $result : null;
	}

	/**
	 * Look up the first candidate matching a given email hash.
	 *
	 * `email_hash` is NOT enforced UNIQUE (see schema rationale in §3.4 of
	 * the implementation plan: candidates may share an email address —
	 * e.g. family members — so the unique key is CPF/RF). When multiple
	 * candidates share the same email, the FIRST inserted is returned;
	 * callers needing all matches should use a different query.
	 *
	 * @param string $email_hash Hash produced by `Encryption::hash()`.
	 * @return CandidateRow|null
	 */
	public static function get_by_email_hash( string $email_hash ): ?object {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var CandidateRow|null $result
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed (non-unique) lookup.
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE email_hash = %s ORDER BY id ASC LIMIT 1', $table, $email_hash )
		);

		return $result ? $result : null;
	}

	/**
	 * Get the candidate row for a logged-in WP user.
	 *
	 * Powers the candidate-self dashboard section ({@see GET /me/recruitment}).
	 * A candidate may be linked to at most one `wp_users.ID` per row, but a
	 * single user could in principle have multiple candidate rows (across
	 * notices) — although in practice the unique CPF/RF constraints keep
	 * cardinality low. This method returns ALL candidate rows for a user.
	 *
	 * @param int $user_id WP user ID.
	 * @return list<CandidateRow>
	 */
	public static function get_by_user_id( int $user_id ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb results to typed shape.
		 *
		 * @var list<CandidateRow>|null $results
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed by user_id.
		$results = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', $table, $user_id )
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Paginated list for the admin Candidates list table.
	 *
	 * `$name_search` filters case-insensitively against `name` (CPF/RF
	 * lookups go through the dedicated hash methods because the column
	 * is encrypted and the operator-typed value has to be hashed before
	 * matching). Returns rows ordered by `created_at DESC` so the most
	 * recently imported candidates surface first.
	 *
	 * @param string $name_search Optional substring filter on name (empty = no filter).
	 * @param int    $limit       Maximum rows (1-200).
	 * @param int    $offset      Offset for pagination.
	 * @return list<CandidateRow>
	 */
	public static function get_paginated( string $name_search, int $limit, int $offset ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = '' !== $name_search
			? $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE name LIKE %s ORDER BY created_at DESC LIMIT %d OFFSET %d',
					$table,
					'%' . $wpdb->esc_like( $name_search ) . '%',
					$limit,
					$offset
				)
			)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			: $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d',
					$table,
					$limit,
					$offset
				)
			);

		/**
		 * Cast wpdb's mixed return into the typed shape.
		 *
		 * @var list<CandidateRow>|null $results
		 */
		$results = $results;
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Page of candidates that have at least one classification in the
	 * supplied adjutancy. Mirrors {@see self::get_paginated()} but
	 * inner-joins the classification table so the result set is scoped
	 * to candidates active in that adjutancy. Used by the admin
	 * Candidates tab's adjutancy filter.
	 *
	 * @param string $name_search Optional substring filter on name.
	 * @param int    $adjutancy_id Adjutancy id (must be > 0).
	 * @param int    $limit       Page size.
	 * @param int    $offset      0-indexed offset.
	 * @return list<CandidateRow>
	 */
	public static function get_paginated_for_adjutancy( string $name_search, int $adjutancy_id, int $limit, int $offset ): array {
		$wpdb      = self::db();
		$table     = self::get_table_name();
		$cls_table = $wpdb->prefix . 'ffc_recruitment_classification';

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		// DISTINCT because a candidate can hold several classifications
		// for the same adjutancy (across notices / list_types) and we
		// want a single list-table row per candidate.
		$sql = '' !== $name_search
			? 'SELECT DISTINCT c.* FROM %i c INNER JOIN %i cls ON cls.candidate_id = c.id WHERE cls.adjutancy_id = %d AND c.name LIKE %s ORDER BY c.created_at DESC LIMIT %d OFFSET %d'
			: 'SELECT DISTINCT c.* FROM %i c INNER JOIN %i cls ON cls.candidate_id = c.id WHERE cls.adjutancy_id = %d ORDER BY c.created_at DESC LIMIT %d OFFSET %d';

		$args = '' !== $name_search
			? array( $table, $cls_table, $adjutancy_id, '%' . $wpdb->esc_like( $name_search ) . '%', $limit, $offset )
			: array( $table, $cls_table, $adjutancy_id, $limit, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is one of two literals selected immediately above; both placeholders match $args.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		/**
		 * Cast wpdb's mixed return into the typed shape.
		 *
		 * @var list<CandidateRow>|null $results
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Companion count for {@see self::get_paginated_for_adjutancy()}.
	 *
	 * @param string $name_search  Optional substring filter on name.
	 * @param int    $adjutancy_id Adjutancy id.
	 * @return int
	 */
	public static function count_paginated_for_adjutancy( string $name_search, int $adjutancy_id ): int {
		$wpdb      = self::db();
		$table     = self::get_table_name();
		$cls_table = $wpdb->prefix . 'ffc_recruitment_classification';

		$sql = '' !== $name_search
			? 'SELECT COUNT(DISTINCT c.id) FROM %i c INNER JOIN %i cls ON cls.candidate_id = c.id WHERE cls.adjutancy_id = %d AND c.name LIKE %s'
			: 'SELECT COUNT(DISTINCT c.id) FROM %i c INNER JOIN %i cls ON cls.candidate_id = c.id WHERE cls.adjutancy_id = %d';

		$args = '' !== $name_search
			? array( $table, $cls_table, $adjutancy_id, '%' . $wpdb->esc_like( $name_search ) . '%' )
			: array( $table, $cls_table, $adjutancy_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is one of two literals selected immediately above; both placeholders match $args.
		$total = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );

		return null === $total ? 0 : (int) $total;
	}

	/**
	 * Total candidate count, optionally filtered by `name` substring.
	 *
	 * Pairs with {@see self::get_paginated()} to drive the list table
	 * pagination headers.
	 *
	 * @param string $name_search Optional substring filter on name.
	 * @return int
	 */
	public static function count_paginated( string $name_search ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		if ( '' !== $name_search ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE name LIKE %s',
					$table,
					'%' . $wpdb->esc_like( $name_search ) . '%'
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
			);
		}

		return null === $total ? 0 : (int) $total;
	}

	/**
	 * Insert a new candidate row.
	 *
	 * Required keys: `name`, `pcd_hash`. At least one of `cpf_hash` or
	 * `rf_hash` must be present (caller's responsibility; the schema does
	 * NOT enforce this — both columns allow NULL because UNIQUE indexes
	 * permit multiple NULLs in MySQL).
	 *
	 * Optional keys: `user_id`, `cpf_encrypted`, `cpf_hash`, `rf_encrypted`,
	 * `rf_hash`, `email_encrypted`, `email_hash`, `phone`, `notes`.
	 *
	 * Returns `false` on UNIQUE collision (`cpf_hash` or `rf_hash` already
	 * present on another row) or other DB failure.
	 *
	 * @param array<string, mixed> $data Candidate payload (see allowed keys above).
	 * @return int|false New candidate ID or false on failure.
	 */
	public static function create( array $data ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		if ( ! isset( $data['name'], $data['pcd_hash'] ) || ! is_string( $data['name'] ) || ! is_string( $data['pcd_hash'] ) ) {
			return false;
		}

		$now = current_time( 'mysql' );

		$insert = array(
			'name'       => $data['name'],
			'pcd_hash'   => $data['pcd_hash'],
			'created_at' => $now,
			'updated_at' => $now,
		);
		$format = array( '%s', '%s', '%s', '%s' );

		$optional_columns = array(
			'user_id'         => '%d',
			'cpf_encrypted'   => '%s',
			'cpf_hash'        => '%s',
			'rf_encrypted'    => '%s',
			'rf_hash'         => '%s',
			'email_encrypted' => '%s',
			'email_hash'      => '%s',
			'phone'           => '%s',
			'notes'           => '%s',
		);

		foreach ( $optional_columns as $column => $column_format ) {
			if ( array_key_exists( $column, $data ) && null !== $data[ $column ] ) {
				$insert[ $column ] = $data[ $column ];
				$format[]          = $column_format;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper.
		$result = $wpdb->insert( $table, $insert, $format );

		if ( ! $result ) {
			return false;
		}

		do_action( 'ffc_recruitment_public_cache_dirty' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update mutable candidate fields.
	 *
	 * Accepted keys: `name`, `phone`, `notes`, `cpf_encrypted` + `cpf_hash`
	 * (must be supplied together), `rf_encrypted` + `rf_hash` (together),
	 * `email_encrypted` + `email_hash` (together).
	 *
	 * `user_id` is NOT writable here — use {@see self::set_user_id()} for
	 * promotion. `pcd_hash` is NOT writable — PCD value is set on creation
	 * only (sprint 4 enforces "PCD is CSV-only" per §12).
	 *
	 * @param int                  $id   Candidate ID.
	 * @param array<string, mixed> $data Update payload.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$update = array();
		$format = array();

		$writable = array(
			'name'            => '%s',
			'phone'           => '%s',
			'notes'           => '%s',
			'cpf_encrypted'   => '%s',
			'cpf_hash'        => '%s',
			'rf_encrypted'    => '%s',
			'rf_hash'         => '%s',
			'email_encrypted' => '%s',
			'email_hash'      => '%s',
		);

		foreach ( $writable as $column => $column_format ) {
			if ( array_key_exists( $column, $data ) ) {
				$update[ $column ] = $data[ $column ];
				$format[]          = $column_format;
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[]             = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update via wpdb helper.
		$result = $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );

		static::cache_delete( "id_{$id}" );

		if ( false !== $result ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

		return false !== $result;
	}

	/**
	 * Set or clear the linked `wp_users.ID` (promotion / un-link).
	 *
	 * Called by the service layer after `UserCreator::get_or_create_user()`
	 * resolves a `wp_user` ID. Pass `null` to detach (rare; mostly for tests).
	 *
	 * @param int      $id Candidate ID.
	 * @param int|null $user_id WP user ID, or null to clear.
	 * @return bool
	 */
	public static function set_user_id( int $id, ?int $user_id ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update via wpdb helper.
		$result = $wpdb->update(
			$table,
			array(
				'user_id'    => $user_id,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		static::cache_delete( "id_{$id}" );

		return false !== $result;
	}

	/**
	 * Hard-delete a candidate row unconditionally.
	 *
	 * Deletion gating (zero classifications) lives in the REST controller
	 * (sprint 7); this method is a pure CRUD primitive and assumes the
	 * caller has already verified the gate. The linked `wp_user` (if any)
	 * is preserved — the recruitment module never deletes WP users.
	 *
	 * @param int $id Candidate ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete via wpdb helper.
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		static::cache_delete( "id_{$id}" );

		if ( false !== $result ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

		return false !== $result;
	}
}
