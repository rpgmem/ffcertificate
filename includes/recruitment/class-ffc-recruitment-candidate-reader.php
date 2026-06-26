<?php
/**
 * Candidate Reader
 *
 * Read-side of the candidate repository split (#563 phase-2, Sprint D1). Holds
 * every SELECT / lookup / count query for `ffc_recruitment_candidate` rows.
 * Writes live in {@see RecruitmentCandidateWriter}. Callers depend on this
 * reader (reads) and the writer (writes) directly; the delegating façade was
 * retired in #563 B3-A.
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
 * Read queries for `ffc_recruitment_candidate` rows.
 *
 * @since 6.0.0
 *
 * @phpstan-type CandidateRow \stdClass&object{id: numeric-string, user_id: numeric-string|null, name: string, cpf_encrypted: string|null, cpf_hash: string|null, rf_encrypted: string|null, rf_hash: string|null, email_encrypted: string|null, email_hash: string|null, phone: string|null, notes: string|null, pcd_hash: string, created_at: string, updated_at: string}
 */
class RecruitmentCandidateReader {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see RecruitmentCandidateWriter::cache_group()} so writes
	 * invalidate the entries reads populate.
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

		/**
		 * Cast wpdb's mixed return into the typed shape.
		 *
		 * @var list<CandidateRow>|null $rows
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are %i + N×%d, all generated literals; $ids items are intval-coerced above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $table ), $ids ) ) );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$row_id = (int) ( $row->id ?? 0 );
			if ( $row_id <= 0 ) {
				continue;
			}
			static::cache_set( "id_{$row_id}", $row );
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
	 * Return every candidate row whose `email_hash` matches the given
	 * digest. Used by the list-table when the operator types an email
	 * into the search box — the column is non-unique so multiple
	 * candidates can legitimately share it (family members, etc.).
	 *
	 * @since 6.6.2
	 * @param string $email_hash Hash produced by `Encryption::hash()`.
	 * @return list<int> Candidate IDs matching the hash (empty array on no match).
	 */
	public static function get_ids_by_email_hash( string $email_hash ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed (non-unique) lookup.
		$rows = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE email_hash = %s', $table, $email_hash )
		);

		return array_values( array_map( 'intval', $rows ) );
	}

	/**
	 * Paginated candidates with combinable filters (issue #331 search frontend).
	 *
	 * All filters are AND-ed:
	 *   - `$name_search`     — LIKE on the `name` column when non-empty.
	 *   - `$id_constraint`   — array of candidate IDs the result is
	 *     limited to (used by CPF / RF / email matches resolved upfront).
	 *     `null` means "no constraint"; an empty array means "no
	 *     candidates match" and the method short-circuits to `[]`.
	 *   - `$adjutancy_id`    — `>0` joins on the classifications table
	 *     and limits to candidates with at least one classification in
	 *     that adjutancy.
	 *   - `$status`          — one of `empty|called|accepted|not_shown|hired|withdrew`
	 *     joins on the classifications table (list_type='definitive')
	 *     and limits to candidates whose at-least-one definitive
	 *     classification is in that status.
	 *
	 * When both `$adjutancy_id` and `$status` are set, the JOIN is
	 * combined (same row must match both) — matches the operator's
	 * mental model of "candidates currently called in adjutancy X".
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
		if ( is_array( $id_constraint ) && empty( $id_constraint ) ) {
			return array();
		}

		$wpdb      = self::db();
		$table     = self::get_table_name();
		$cls_table = $wpdb->prefix . 'ffc_recruitment_classification';

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		$where          = array();
		$where_a        = array();
		$needs_cls_join = $adjutancy_id > 0 || '' !== $status;

		if ( '' !== $name_search ) {
			$where[]   = 'c.name LIKE %s';
			$where_a[] = '%' . $wpdb->esc_like( $name_search ) . '%';
		}
		if ( is_array( $id_constraint ) ) {
			$ids     = array_values( array_unique( array_map( 'intval', $id_constraint ) ) );
			$where[] = 'c.id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$where_a = array_merge( $where_a, $ids );
		}
		if ( $adjutancy_id > 0 ) {
			$where[]   = 'cls.adjutancy_id = %d';
			$where_a[] = $adjutancy_id;
		}
		if ( '' !== $status ) {
			$where[]   = 'cls.status = %s';
			$where_a[] = $status;
			$where[]   = "cls.list_type = 'definitive'";
		}

		$sql_args = array( $table );
		$select   = 'SELECT DISTINCT c.* FROM %i c';
		if ( $needs_cls_join ) {
			$select    .= ' INNER JOIN %i cls ON cls.candidate_id = c.id';
			$sql_args[] = $cls_table;
		}
		if ( ! empty( $where ) ) {
			$select  .= ' WHERE ' . implode( ' AND ', $where );
			$sql_args = array_merge( $sql_args, $where_a );
		}
		$select    .= ' ORDER BY c.created_at DESC LIMIT %d OFFSET %d';
		$sql_args[] = $limit;
		$sql_args[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $select is assembled from compile-time fragments with %s/%d placeholders matching $sql_args one-to-one.
		$results = $wpdb->get_results( $wpdb->prepare( $select, $sql_args ) );

		/**
		 * Typed shape cast.
		 *
		 * @var list<CandidateRow>|null $results
		 */
		return is_array( $results ) ? $results : array();
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
		if ( is_array( $id_constraint ) && empty( $id_constraint ) ) {
			return 0;
		}

		$wpdb      = self::db();
		$table     = self::get_table_name();
		$cls_table = $wpdb->prefix . 'ffc_recruitment_classification';

		$where          = array();
		$where_a        = array();
		$needs_cls_join = $adjutancy_id > 0 || '' !== $status;

		if ( '' !== $name_search ) {
			$where[]   = 'c.name LIKE %s';
			$where_a[] = '%' . $wpdb->esc_like( $name_search ) . '%';
		}
		if ( is_array( $id_constraint ) ) {
			$ids     = array_values( array_unique( array_map( 'intval', $id_constraint ) ) );
			$where[] = 'c.id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$where_a = array_merge( $where_a, $ids );
		}
		if ( $adjutancy_id > 0 ) {
			$where[]   = 'cls.adjutancy_id = %d';
			$where_a[] = $adjutancy_id;
		}
		if ( '' !== $status ) {
			$where[]   = 'cls.status = %s';
			$where_a[] = $status;
			$where[]   = "cls.list_type = 'definitive'";
		}

		$sql_args = array( $table );
		$select   = 'SELECT COUNT(DISTINCT c.id) FROM %i c';
		if ( $needs_cls_join ) {
			$select    .= ' INNER JOIN %i cls ON cls.candidate_id = c.id';
			$sql_args[] = $cls_table;
		}
		if ( ! empty( $where ) ) {
			$select  .= ' WHERE ' . implode( ' AND ', $where );
			$sql_args = array_merge( $sql_args, $where_a );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $select assembled from compile-time fragments; placeholders match $sql_args.
		$total = $wpdb->get_var( $wpdb->prepare( $select, $sql_args ) );

		return null === $total ? 0 : (int) $total;
	}
}
