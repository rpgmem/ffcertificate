<?php
/**
 * Classification Repository
 *
 * CRUD for `ffc_recruitment_classification` rows — a candidate's standing per
 * (adjutancy, notice, list_type). Atomic state-machine transitions are
 * exposed via {@see self::set_status()} for use by the state machine and
 * convocation service (sprints 5 / 6) — those rely on conditional
 * `UPDATE … WHERE status = '<expected>'` to win/lose races without losing
 * data integrity.
 *
 * The hot path "lowest-rank empty for this adjutancy/notice/list_type" used
 * by the in-order convocation check is served by the composite index
 * `(notice_id, adjutancy_id, list_type, status, rank)` declared in the
 * activator.
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
 * Database repository for `ffc_recruitment_classification` rows.
 *
 * Status enum values: `empty`, `called`, `accepted`, `not_shown`, `hired`.
 * `list_type` enum values: `preview`, `definitive`. Per the §5.2 invariant,
 * rows with `list_type='preview'` are always `status='empty'` (convocation
 * acts only on `definitive`); the repository does NOT enforce this — the
 * service layer (sprint 4 importer + sprint 6 convocation) is responsible.
 *
 * @phpstan-type ClassificationRow \stdClass&object{id: numeric-string, candidate_id: numeric-string, adjutancy_id: numeric-string, notice_id: numeric-string, list_type: string, rank: numeric-string, score: string, status: string, created_at: string, updated_at: string}
 */
class ClassificationRepository {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_recruitment_classification';
	}

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_recruitment_classification';
	}

	/**
	 * Get a classification row by ID.
	 *
	 * @param int $id Classification ID.
	 * @return ClassificationRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		$cached = static::cache_get( "id_{$id}" );
		if ( false !== $cached ) {
			/**
			 * Object-cache return cast.
			 *
			 * @var ClassificationRow|null $cached
			 */
			return $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ClassificationRow|null $result
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Object-cached.
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id )
		);

		if ( $result ) {
			static::cache_set( "id_{$id}", $result );
		}

		return $result;
	}

	/**
	 * List classifications for a notice, optionally filtered by list_type / adjutancy.
	 *
	 * Sort is `(rank ASC, candidate_id ASC)` — matches the public shortcode's
	 * tie-break rule (§3 Naming & Conventions of the implementation plan).
	 *
	 * @param int         $notice_id Notice ID.
	 * @param string|null $list_type Optional `preview` or `definitive`.
	 * @param int|null    $adjutancy_id Optional adjutancy filter.
	 * @return list<ClassificationRow>
	 */
	public static function get_for_notice( int $notice_id, ?string $list_type = null, ?int $adjutancy_id = null ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$where  = array( 'notice_id = %d' );
		$values = array( $notice_id );

		if ( null !== $list_type ) {
			$where[]  = 'list_type = %s';
			$values[] = $list_type;
		}

		if ( null !== $adjutancy_id ) {
			$where[]  = 'adjutancy_id = %d';
			$values[] = $adjutancy_id;
		}

		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT * FROM %i WHERE {$where_sql} ORDER BY `rank` ASC, candidate_id ASC";

		$prepare_args = array_merge( array( $table ), $values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql is built from internal placeholders only.
		$prepared = $wpdb->prepare( $sql, $prepare_args );
		if ( ! is_string( $prepared ) ) {
			return array();
		}

		/**
		 * Cast wpdb results to typed shape.
		 *
		 * @var list<ClassificationRow>|null $results
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $prepared );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get all classifications for a single candidate, across notices.
	 *
	 * Powers the candidate hard-delete gate (sprint 7: deletion requires
	 * zero rows) and the candidate-self dashboard query (sprint 12).
	 *
	 * @param int $candidate_id Candidate ID.
	 * @return list<ClassificationRow>
	 */
	public static function get_for_candidate( int $candidate_id ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb results to typed shape.
		 *
		 * @var list<ClassificationRow>|null $results
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed by candidate_id.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE candidate_id = %d ORDER BY notice_id ASC, adjutancy_id ASC',
				$table,
				$candidate_id
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count classifications for a candidate. Powers the hard-delete gate.
	 *
	 * @param int $candidate_id Candidate ID.
	 * @return int
	 */
	public static function count_for_candidate( int $candidate_id ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Index-only count.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE candidate_id = %d', $table, $candidate_id )
		);

		return $count;
	}

	/**
	 * Count classifications referencing a given adjutancy. Used by the
	 * adjutancy deletion gate (sprint 7).
	 *
	 * @param int $adjutancy_id Adjutancy ID.
	 * @return int
	 */
	public static function count_for_adjutancy( int $adjutancy_id ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Index range scan covered by composite (notice, adjutancy, list_type, status, rank).
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE adjutancy_id = %d', $table, $adjutancy_id )
		);

		return $count;
	}

	/**
	 * Find the lowest-rank `empty` classification for a notice/adjutancy/list_type.
	 *
	 * Powers the in-order convocation check (sprint 6): if the candidate the
	 * admin is calling does NOT match this row's id, the call is "out of
	 * order" and requires a reason.
	 *
	 * Tie-break is `(rank ASC, candidate_id ASC)` — same as the public sort.
	 *
	 * @param int    $notice_id Notice ID.
	 * @param int    $adjutancy_id Adjutancy ID.
	 * @param string $list_type `preview` or `definitive` (typically `definitive`).
	 * @return ClassificationRow|null
	 */
	public static function find_lowest_rank_empty( int $notice_id, int $adjutancy_id, string $list_type ): ?object {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ClassificationRow|null $result
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Hot path; covered by composite index.
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i
                  WHERE notice_id = %d AND adjutancy_id = %d AND list_type = %s AND status = %s
                  ORDER BY `rank` ASC, candidate_id ASC LIMIT 1',
				$table,
				$notice_id,
				$adjutancy_id,
				$list_type,
				'empty'
			)
		);

		return $result ? $result : null;
	}

	/**
	 * Insert a new classification row.
	 *
	 * Required keys: `candidate_id`, `adjutancy_id`, `notice_id`, `list_type`,
	 * `rank`, `score`. Optional `status` (defaults to `empty`).
	 *
	 * Returns `false` on UNIQUE collision
	 * `(candidate_id, adjutancy_id, notice_id, list_type)`.
	 *
	 * @param array{candidate_id: int, adjutancy_id: int, notice_id: int, list_type: string, rank: int, score: string|float, status?: string} $data Classification payload.
	 * @return int|false New classification ID or false on failure.
	 */
	public static function create( array $data ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper.
		$result = $wpdb->insert(
			$table,
			array(
				'candidate_id' => $data['candidate_id'],
				'adjutancy_id' => $data['adjutancy_id'],
				'notice_id'    => $data['notice_id'],
				'list_type'    => $data['list_type'],
				'rank'         => $data['rank'],
				'score'        => (string) $data['score'],
				'status'       => $data['status'] ?? 'empty',
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Atomic conditional status transition.
	 *
	 * Performs `UPDATE … SET status=$new WHERE id=$id AND status=$expected`.
	 * Returns the affected-row count (0 or 1) so callers can detect a lost
	 * race. The state machine wraps this with transition validity / reason
	 * checks (sprint 5) and the convocation service uses it for atomic call
	 * creation (sprint 6).
	 *
	 * @param int    $id Classification ID.
	 * @param string $expected_current Expected current status.
	 * @param string $new_status Target status.
	 * @return int Number of rows affected.
	 */
	public static function set_status( int $id, string $expected_current, string $new_status ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$prepared = $wpdb->prepare(
			'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status = %s',
			$table,
			$new_status,
			current_time( 'mysql' ),
			$id,
			$expected_current
		);

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic state transition; $prepared came from $wpdb->prepare() on the line above.
		$affected = $wpdb->query( $prepared );

		static::cache_delete( "id_{$id}" );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Bulk-delete all classifications for a notice + list_type pair.
	 *
	 * Used by the CSV importer's atomic wipe-and-reinsert (sprint 4) and by
	 * the promote-preview snapshot path (sprint 5). The caller is expected
	 * to wrap this and the subsequent inserts in an InnoDB transaction so
	 * the previous list is preserved on validation failure (see §6 of the
	 * implementation plan).
	 *
	 * @param int    $notice_id Notice ID.
	 * @param string $list_type `preview` or `definitive`.
	 * @return int Number of rows deleted.
	 */
	public static function delete_all_for_notice_list( int $notice_id, string $list_type ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk delete; bounded by the notice's classification count.
		$result = $wpdb->delete(
			$table,
			array(
				'notice_id' => $notice_id,
				'list_type' => $list_type,
			),
			array( '%d', '%s' )
		);

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Hard-delete a single classification row.
	 *
	 * Deletion gating (`status='empty'` + notice in `draft`/`preliminary`)
	 * lives at the service layer (sprint 7); this method is a pure CRUD
	 * primitive.
	 *
	 * @param int $id Classification ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete via wpdb helper.
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		static::cache_delete( "id_{$id}" );

		return false !== $result;
	}

	/**
	 * Count calls in the entire history of a notice (across all classifications).
	 *
	 * Powers the `active → preliminary` gate (§5.1): the transition is only
	 * allowed when zero calls have ever been issued on this notice
	 * (including cancelled ones). This is implemented as a JOIN through
	 * the call table because there's no direct notice_id column on calls.
	 *
	 * @param int $notice_id Notice ID.
	 * @return int Total call rows for any classification of this notice.
	 */
	public static function count_calls_for_notice( int $notice_id ): int {
		$wpdb        = self::db();
		$table       = self::get_table_name();
		$calls_table = self::db()->prefix . 'ffc_recruitment_call';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-table count for state-machine gate.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i c
                  INNER JOIN %i cl ON cl.id = c.classification_id
                  WHERE cl.notice_id = %d',
				$calls_table,
				$table,
				$notice_id
			)
		);

		return $count;
	}
}
