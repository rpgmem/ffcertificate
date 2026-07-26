<?php
/**
 * ActivityLogQuery
 *
 * Handles querying, statistics, and cleanup for the activity log.
 * Extracted from ActivityLog (v4.12.2) for single-responsibility.
 *
 * @package FreeFormCertificate\Core
 * @since 4.12.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
/**
 * Activity Log Query.
 */
class ActivityLogQuery {

	/**
	 * Get recent activities with filters
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<int, array<string, mixed>> Activities
	 */
	public static function get_activities( array $args = array() ): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_activity_log';

		$defaults = array(
			'limit'     => 50,
			'offset'    => 0,
			'level'     => null,
			'action'    => null,
			'action_in' => null,
			'user_id'   => null,
			'user_ip'   => null,
			'date_from' => null,
			'date_to'   => null,
			'search'    => null,
			'orderby'   => 'created_at',
			'order'     => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );

		if ( $args['level'] ) {
			$where[] = $wpdb->prepare( 'level = %s', sanitize_key( $args['level'] ) );
		}
		if ( $args['action'] ) {
			$where[] = $wpdb->prepare( 'action = %s', sanitize_text_field( $args['action'] ) );
		}
		if ( is_array( $args['action_in'] ) && ! empty( $args['action_in'] ) ) {
			// Sanitized + bounded — each value passes through
			// sanitize_text_field and the SET is built from a fixed-count
			// `%s` placeholder run that matches $sanitized one-for-one.
			$sanitized    = array_values( array_unique( array_map( 'sanitize_text_field', $args['action_in'] ) ) );
			$placeholders = implode( ',', array_fill( 0, count( $sanitized ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a compile-time `%s,%s,...` string whose count matches $sanitized one-for-one; values are bound by wpdb->prepare.
			$where[] = $wpdb->prepare( "action IN ({$placeholders})", $sanitized );
		}
		if ( $args['user_id'] ) {
			$where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
		}
		if ( $args['user_ip'] ) {
			$where[] = $wpdb->prepare( 'user_ip = %s', sanitize_text_field( $args['user_ip'] ) );
		}
		if ( $args['date_from'] ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', sanitize_text_field( $args['date_from'] ) );
		}
		if ( $args['date_to'] ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', sanitize_text_field( $args['date_to'] ) );
		}
		if ( $args['search'] ) {
			$search  = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
		}

		$where_clause = implode( ' AND ', $where );

		$allowed_orderby = array( 'id', 'action', 'level', 'user_id', 'user_ip', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$offset          = absint( $args['offset'] );
		$limit           = absint( $args['limit'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_clause, $orderby, $order are pre-validated above.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d, %d",
				$table_name,
				$offset,
				$limit
			),
			ARRAY_A
		);

		foreach ( $results as &$result ) {
			$result['context'] = self::resolve_context( $result );
		}

		return $results;
	}

	/**
	 * Keyset page for the batched CSV export: rows with `id < $cursor`, newest
	 * first (`id DESC`), limited to `$size`, with `context` decrypted per row.
	 * Keyset (not LIMIT/OFFSET) so paging stays stable across concurrent inserts
	 * during a long export. Filters mirror {@see self::count_activities()}
	 * (level / action / search).
	 *
	 * @since 6.17.0
	 * @param array<string, mixed> $filters Level / action / search.
	 * @param int                  $cursor  Exclusive upper-bound id (PHP_INT_MAX on the first page).
	 * @param int                  $size    Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public static function find_by_cursor( array $filters, int $cursor, int $size ): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_activity_log';

		$where = array( '1=1' );

		if ( ! empty( $filters['level'] ) ) {
			$where[] = $wpdb->prepare( 'level = %s', sanitize_key( (string) $filters['level'] ) );
		}
		if ( ! empty( $filters['action'] ) ) {
			$where[] = $wpdb->prepare( 'action = %s', sanitize_text_field( (string) $filters['action'] ) );
		}
		if ( ! empty( $filters['search'] ) ) {
			$search  = '%' . $wpdb->esc_like( sanitize_text_field( (string) $filters['search'] ) ) . '%';
			$where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
		}

		$where_clause = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_clause is built from prepared fragments above.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$where_clause} AND id < %d ORDER BY id DESC LIMIT %d",
				$table_name,
				$cursor,
				$size
			),
			ARRAY_A
		);

		if ( ! is_array( $results ) ) {
			return array();
		}

		foreach ( $results as &$result ) {
			$result['context'] = self::resolve_context( $result );
		}
		unset( $result );

		return $results;
	}

	/**
	 * List every distinct `action` ever written to the activity log,
	 * ordered alphabetically. Used by the admin-side filter dropdown
	 * — and by any caller that needs to enumerate the action vocabulary
	 * present in this installation. Replaces a raw `SELECT DISTINCT
	 * action` query that previously sat in the admin page (issue #331
	 * "candidate history service" cleanup).
	 *
	 * @since 6.6.2
	 * @return list<string>
	 */
	public static function distinct_actions(): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_activity_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Catalog lookup; %i used for the table identifier.
		$actions = $wpdb->get_col(
			$wpdb->prepare( 'SELECT DISTINCT action FROM %i ORDER BY action ASC', $table_name )
		);
		if ( ! is_array( $actions ) ) {
			return array();
		}
		return array_values( array_map( 'strval', $actions ) );
	}

	/**
	 * Privacy / user-cleanup helper — `SET user_id = NULL` on every log
	 * row that referenced the supplied wp_user, so the audit trail
	 * survives a user deletion without leaking the orphaned numeric ID
	 * via foreign-key joins. Returns the row count that was rewritten,
	 * or `0` when the table doesn't exist (legacy installs that never
	 * ran the activator).
	 *
	 * Centralizes a query that was duplicated in
	 * {@see \FreeFormCertificate\Privacy\PrivacyHandler} and
	 * {@see \FreeFormCertificate\UserDashboard\UserCleanup} — both
	 * callsites now route through here.
	 *
	 * @since 6.6.2
	 * @param int $user_id WP user ID to redact.
	 * @return int Number of log rows whose `user_id` was nulled.
	 */
	public static function redact_user_id( int $user_id ): int {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return 0;
		}
		$table_name = $wpdb->prefix . 'ffc_activity_log';

		// Mirror the existing callers' table-existence guard so legacy
		// pre-activator installs degrade quietly. Follows the same
		// shape as DatabaseHelperTrait::table_exists() (SHOW TABLES
		// LIKE %s; %s already handles LIKE-escape — the table name is
		// internal, never operator-supplied).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $exists !== $table_name ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy redaction; surface area kept narrow (single WHERE on user_id).
		$rows = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET user_id = NULL WHERE user_id = %d',
				$table_name,
				$user_id
			)
		);
		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * Resolve a row's context into a decoded array.
	 *
	 * Sensitive rows have a NULL plaintext column and the JSON in
	 * `context_encrypted` instead. Decrypt on demand, then json_decode.
	 *
	 * @param array<string, mixed> $row Activity log row (raw from DB).
	 * @return array<string, mixed> Decoded context, or empty array.
	 */
	private static function resolve_context( array $row ): array {
		$raw = $row['context'] ?? null;

		if ( ( null === $raw || '' === $raw )
			&& ! empty( $row['context_encrypted'] )
			&& class_exists( '\\FreeFormCertificate\\Core\\Encryption' )
			&& \FreeFormCertificate\Core\Encryption::is_configured()
		) {
			$decrypted = \FreeFormCertificate\Core\Encryption::decrypt( $row['context_encrypted'] );
			if ( null !== $decrypted && '' !== $decrypted ) {
				$raw = $decrypted;
			}
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get activity count with filters
	 *
	 * @param array<string, mixed> $args Same as get_activities().
	 * @return int Count
	 */
	public static function count_activities( array $args = array() ): int {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_activity_log';

		$defaults = array(
			'level'     => null,
			'action'    => null,
			'user_id'   => null,
			'user_ip'   => null,
			'date_from' => null,
			'date_to'   => null,
			'search'    => null,
		);

		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );

		if ( $args['level'] ) {
			$where[] = $wpdb->prepare( 'level = %s', sanitize_key( $args['level'] ) );
		}
		if ( $args['action'] ) {
			$where[] = $wpdb->prepare( 'action = %s', sanitize_text_field( $args['action'] ) );
		}
		if ( $args['user_id'] ) {
			$where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
		}
		if ( $args['user_ip'] ) {
			$where[] = $wpdb->prepare( 'user_ip = %s', sanitize_text_field( $args['user_ip'] ) );
		}
		if ( $args['date_from'] ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', sanitize_text_field( $args['date_from'] ) );
		}
		if ( $args['date_to'] ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', sanitize_text_field( $args['date_to'] ) );
		}
		if ( $args['search'] ) {
			$search  = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
		}

		$where_clause = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE {$where_clause}",
				$table_name
			)
		);
	}

	/**
	 * Get statistics
	 *
	 * @param int $days Number of days to analyze (default: 30).
	 * @return array<string, mixed> Statistics
	 */
	public static function get_stats( int $days = 30 ): array {
		$cache_key = 'ffc_activity_stats_' . $days;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table_name   = $wpdb->prefix . 'ffc_activity_log';
		$date_from_ts = strtotime( "-{$days} days" );
		$date_from    = gmdate( 'Y-m-d H:i:s', $date_from_ts ? $date_from_ts : time() );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$table_name,
				$date_from
			)
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_level = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT level, COUNT(*) as count FROM %i WHERE created_at >= %s GROUP BY level',
				$table_name,
				$date_from
			),
			ARRAY_A
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$top_actions = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT action, COUNT(*) as count FROM %i WHERE created_at >= %s GROUP BY action ORDER BY count DESC LIMIT 10',
				$table_name,
				$date_from
			),
			ARRAY_A
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$top_users = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, COUNT(*) as count FROM %i WHERE created_at >= %s AND user_id > 0 GROUP BY user_id ORDER BY count DESC LIMIT 10',
				$table_name,
				$date_from
			),
			ARRAY_A
		);

		$stats = array(
			'total'       => (int) $total,
			'by_level'    => $by_level,
			'top_actions' => $top_actions,
			'top_users'   => $top_users,
			'period_days' => $days,
		);

		set_transient( $cache_key, $stats, HOUR_IN_SECONDS );

		return $stats;
	}

	/**
	 * Get logs for specific submission (LGPD audit trail)
	 *
	 * @param int $submission_id Submission ID.
	 * @param int $limit         Maximum number of logs.
	 * @return array<int, array<string, mixed>> Logs
	 */
	public static function get_submission_logs( int $submission_id, int $limit = 100 ): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_activity_log';

		$columns = ActivityLog::get_table_columns_cached( $table_name );
		if ( ! in_array( 'submission_id', $columns, true ) ) {
			return array();
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE submission_id = %d ORDER BY created_at DESC LIMIT %d',
				$table_name,
				$submission_id,
				$limit
			),
			ARRAY_A
		);

		if ( class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
			foreach ( $logs as &$log ) {
				if ( ! empty( $log['context_encrypted'] ) ) {
					$decrypted = \FreeFormCertificate\Core\Encryption::decrypt( $log['context_encrypted'] );
					if ( null !== $decrypted ) {
						// Populate the canonical column too so consumers
						// that only inspect `context` see the JSON.
						if ( empty( $log['context'] ) ) {
							$log['context'] = $decrypted;
						}
						// Backward-compat: keep the sibling that older
						// callers may still inspect.
						$log['context_decrypted'] = $decrypted;
					}
				}
			}
		}

		return $logs;
	}

	/**
	 * Clean old logs
	 *
	 * @param int $days Keep logs from last N days (default: 90).
	 * @return int Number of deleted rows
	 */
	public static function cleanup( int $days = 90 ): int {
		global $wpdb;
		$table_name     = $wpdb->prefix . 'ffc_activity_log';
		$cutoff_date_ts = strtotime( "-{$days} days" );
		$cutoff_date    = gmdate( 'Y-m-d H:i:s', $cutoff_date_ts ? $cutoff_date_ts : time() );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				$table_name,
				$cutoff_date
			)
		);

		delete_transient( 'ffc_activity_stats_7' );
		delete_transient( 'ffc_activity_stats_30' );
		delete_transient( 'ffc_activity_stats_90' );

		return (int) $deleted;
	}

	/**
	 * Run automatic log cleanup (called by daily cron)
	 *
	 * @since 4.6.9
	 * @return int Number of deleted rows
	 */
	public static function run_cleanup(): int {
		$retention_days = \FreeFormCertificate\Settings\SettingsReader::activity_log_retention_days();

		if ( $retention_days <= 0 ) {
			return 0;
		}

		return self::cleanup( $retention_days );
	}
}
