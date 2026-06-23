<?php
/**
 * Call Reader
 *
 * Read-side of the call repository split (#563 backlog, B3). Holds every
 * SELECT / lookup query for `ffc_recruitment_call`. Writes live in
 * {@see RecruitmentCallWriter}. Callers depend on this reader (reads) and the
 * writer (writes) directly; the delegating façade was retired in #563 B3-A.
 *
 * "Active call for classification" = the most recent row for the
 * classification with `cancelled_at IS NULL`. The composite index
 * `(classification_id, cancelled_at)` covers this lookup directly.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read queries for `ffc_recruitment_call` rows.
 *
 * @since 6.11.3
 *
 * @phpstan-type CallRow \stdClass&object{id: numeric-string, classification_id: numeric-string, called_at: numeric-string|int, date_to_assume: string, time_to_assume: string, out_of_order: numeric-string, out_of_order_reason: string|null, cancellation_reason: string|null, cancelled_at: numeric-string|int|null, cancelled_by: numeric-string|null, notes: string|null, created_by: numeric-string, created_at: string, updated_at: string}
 */
class RecruitmentCallReader {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see RecruitmentCallWriter::cache_group()} so writes
	 * invalidate the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_recruitment_call';
	}

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_recruitment_call';
	}

	/**
	 * Get a call row by ID.
	 *
	 * @param int $id Call ID.
	 * @return CallRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		$cached = static::cache_get( "id_{$id}" );
		if ( false !== $cached ) {
			/**
			 * Object-cache return cast.
			 *
			 * @var CallRow|null $cached
			 */
			return $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var CallRow|null $result
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
	 * Get the active (non-cancelled) call for a classification, if any.
	 *
	 * Returns the most recent row where `cancelled_at IS NULL`. There SHOULD
	 * be at most one such row at any time (state machine enforces: a new
	 * call is only issued when classification is `empty`, which only
	 * happens after the previous call was cancelled and stamped). The
	 * `LIMIT 1` is defensive.
	 *
	 * @param int $classification_id Classification ID.
	 * @return CallRow|null
	 */
	public static function get_active_for_classification( int $classification_id ): ?object {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var CallRow|null $result
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Covered by INDEX (classification_id, cancelled_at).
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE classification_id = %d AND cancelled_at IS NULL ORDER BY called_at DESC LIMIT 1',
				$table,
				$classification_id
			)
		);

		return $result ? $result : null;
	}

	/**
	 * List all calls for a classification (history view, including cancelled).
	 *
	 * Sort is `called_at DESC` (most recent first) — matches the candidate
	 * dashboard's "Histórico de convocações" sort.
	 *
	 * @param int $classification_id Classification ID.
	 * @return list<CallRow>
	 */
	public static function get_history_for_classification( int $classification_id ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb results to typed shape.
		 *
		 * @var list<CallRow>|null $results
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Covered by INDEX on classification_id.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE classification_id = %d ORDER BY called_at DESC',
				$table,
				$classification_id
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get all calls (history) for a list of classification IDs.
	 *
	 * Used by the candidate-self dashboard ({@see GET /me/recruitment}) to
	 * batch-load the call history for all the user's classifications in a
	 * single query.
	 *
	 * @param array<int> $classification_ids Classification IDs.
	 * @return list<CallRow>
	 */
	public static function get_history_for_classifications( array $classification_ids ): array {
		if ( empty( $classification_ids ) ) {
			return array();
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		$ids          = array_map( 'intval', $classification_ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$sql = "SELECT * FROM %i WHERE classification_id IN ({$placeholders}) ORDER BY called_at DESC";

		$prepare_args = array_merge( array( $table ), $ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN() built from %d placeholders only.
		$prepared = $wpdb->prepare( $sql, $prepare_args );
		if ( ! is_string( $prepared ) ) {
			return array();
		}

		/**
		 * Cast wpdb results to typed shape.
		 *
		 * @var list<CallRow>|null $results
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $prepared );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count all calls for a classification, including cancelled.
	 *
	 * @param int $classification_id Classification ID.
	 * @return int
	 */
	public static function count_for_classification( int $classification_id ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Index range count.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE classification_id = %d', $table, $classification_id )
		);

		return $count;
	}
}
