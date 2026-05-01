<?php
/**
 * Call Repository
 *
 * CRUD-ish access to `ffc_recruitment_call` rows. Calls are append-only
 * history: cancellation does NOT delete the row — it stamps
 * `cancellation_reason` / `cancelled_at` / `cancelled_by` on the existing
 * row. A subsequent re-call for the same classification creates a new row.
 *
 * "Active call for classification" = the most recent row for the
 * classification with `cancelled_at IS NULL`. The composite index
 * `(classification_id, cancelled_at)` covers this lookup directly.
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
 * Database repository for `ffc_recruitment_call` rows.
 *
 * @phpstan-type CallRow \stdClass&object{id: numeric-string, classification_id: numeric-string, called_at: string, date_to_assume: string, time_to_assume: string, out_of_order: numeric-string, out_of_order_reason: string|null, cancellation_reason: string|null, cancelled_at: string|null, cancelled_by: numeric-string|null, notes: string|null, created_by: numeric-string, created_at: string, updated_at: string}
 */
class RecruitmentCallRepository {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
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
	 * Insert a new call row.
	 *
	 * Required keys: `classification_id`, `date_to_assume`, `time_to_assume`,
	 * `created_by`. Optional keys: `out_of_order` (defaults to 0),
	 * `out_of_order_reason`, `notes`, `called_at` (defaults to now).
	 *
	 * Invariant (enforced here, also at the service layer): when
	 * `out_of_order = 1`, `out_of_order_reason` must be a non-empty string.
	 * Returns `false` if the invariant is violated.
	 *
	 * @param array{classification_id: int, date_to_assume: string, time_to_assume: string, created_by: int, out_of_order?: int, out_of_order_reason?: string|null, notes?: string|null, called_at?: string} $data Call payload.
	 * @return int|false New call ID or false on failure.
	 */
	public static function create( array $data ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$out_of_order        = isset( $data['out_of_order'] ) ? (int) $data['out_of_order'] : 0;
		$out_of_order_reason = $data['out_of_order_reason'] ?? null;

		// Repository-level guard for the §3.6 invariant.
		if ( 1 === $out_of_order && ( ! is_string( $out_of_order_reason ) || '' === trim( $out_of_order_reason ) ) ) {
			return false;
		}

		$now = current_time( 'mysql' );

		$insert = array(
			'classification_id'   => $data['classification_id'],
			'called_at'           => $data['called_at'] ?? $now,
			'date_to_assume'      => $data['date_to_assume'],
			'time_to_assume'      => $data['time_to_assume'],
			'out_of_order'        => $out_of_order,
			'out_of_order_reason' => $out_of_order_reason,
			'notes'               => $data['notes'] ?? null,
			'created_by'          => $data['created_by'],
			'created_at'          => $now,
			'updated_at'          => $now,
		);
		$format = array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper.
		$result = $wpdb->insert( $table, $insert, $format );

		if ( ! $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Stamp cancellation columns on an existing call row.
	 *
	 * Idempotent within a single cancellation: the WHERE clause requires
	 * `cancelled_at IS NULL`, so a second cancel attempt on an already
	 * cancelled row returns 0. The state machine (sprint 5) calls this
	 * AFTER {@see RecruitmentClassificationRepository::set_status()} has already moved
	 * the classification back to `empty` atomically, so the two operations
	 * together preserve the audit trail.
	 *
	 * @param int    $id Call ID.
	 * @param string $reason Cancellation reason (mandatory; §5.2).
	 * @param int    $cancelled_by WP user ID who performed the cancel.
	 * @return int Number of rows affected (1 on first cancel, 0 if already cancelled).
	 */
	public static function mark_cancelled( int $id, string $reason, int $cancelled_by ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$now = current_time( 'mysql' );

		$prepared = $wpdb->prepare(
			'UPDATE %i SET cancellation_reason = %s, cancelled_at = %s, cancelled_by = %d, updated_at = %s
              WHERE id = %d AND cancelled_at IS NULL',
			$table,
			$reason,
			$now,
			$cancelled_by,
			$now,
			$id
		);

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Conditional UPDATE via wpdb->query for affected-rows return; $prepared came from $wpdb->prepare() on the line above.
		$affected = $wpdb->query( $prepared );

		static::cache_delete( "id_{$id}" );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Update mutable, non-history fields on a call row.
	 *
	 * Only `notes` is writable post-creation; cancellation columns go
	 * through {@see self::mark_cancelled()}, and audit columns
	 * (`called_at`, `created_by`, `out_of_order*`) are immutable per the
	 * append-only-history contract.
	 *
	 * @param int                  $id Call ID.
	 * @param array<string, mixed> $data Update payload (only `notes` honored).
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$update = array();
		$format = array();

		if ( array_key_exists( 'notes', $data ) ) {
			$update['notes'] = $data['notes'];
			$format[]        = '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[]             = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update via wpdb helper.
		$result = $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );

		static::cache_delete( "id_{$id}" );

		return false !== $result;
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
