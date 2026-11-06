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

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$where_clause}/{$placeholders} are fragments assembled here, {$orderby}/{$order} come from an in_array() allowlist and {$days} is an int-typed parameter. The table name goes through %i.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Activity Log Query.
 *
 * The row shape is what `$wpdb` hands back, derived from the `CREATE TABLE`
 * in `ActivityLog` — every column arrives as a string, and only a real SQL
 * NULL stays null (#1060). `context_encrypted` is optional because it is
 * added by migration, which is also why the code guards it with
 * `column_exists()` before using it.
 *
 * @phpstan-type ActivityLogRow array{
 *     id: numeric-string,
 *     action: string,
 *     level: string,
 *     context: string|null,
 *     user_id: numeric-string|null,
 *     user_ip: string|null,
 *     created_at: numeric-string,
 *     context_encrypted?: string|null,
 *     user_ip_encrypted?: string|null
 * }
 */
class ActivityLogQuery {

	/**
	 * Transiente do vocabulario de acoes (#1234).
	 *
	 * @var string
	 */
	private const ACTIONS_CACHE_KEY = 'ffc_activity_log_distinct_actions';

	/**
	 * Lifetime of the transient above.
	 *
	 * One HOUR, and not the 5 minutes `countByStatus` uses, because what is
	 * cached here changes by a different nature: the `action` values are defined
	 * in the CODE, not in the data. A new value only appears when a new feature
	 * starts recording it -- that is, on a deploy -- so the staleness window is
	 * not "how long until the number changes" but "how long after a deploy until
	 * the filter offers the new action".
	 *
	 * @var int
	 */
	private const ACTIONS_CACHE_TTL = HOUR_IN_SECONDS;

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
			'date_from' => null,
			'date_to'   => null,
			'search'    => null,
			'orderby'   => 'created_at',
			'order'     => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );

		if ( $args['level'] ) {
			$where[] = $wpdb->prepare( 'level = %s', sanitize_key( ArrayValue::string( $args, 'level' ) ) );
		}
		if ( $args['action'] ) {
			$where[] = $wpdb->prepare( 'action = %s', sanitize_text_field( ArrayValue::string( $args, 'action' ) ) );
		}
		if ( is_array( $args['action_in'] ) && ! empty( $args['action_in'] ) ) {
			// Sanitized + bounded — each value passes through
			// sanitize_text_field and the SET is built from a fixed-count
			// `%s` placeholder run that matches $sanitized one-for-one.
			$sanitized    = array_values(
				array_unique(
					array_map(
						static fn( $action ): string => sanitize_text_field( is_scalar( $action ) ? (string) $action : '' ),
						$args['action_in']
					)
				)
			);
			$placeholders = implode( ',', array_fill( 0, count( $sanitized ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a compile-time `%s,%s,...` string whose count matches $sanitized one-for-one; values are bound by wpdb->prepare.
			$where[] = $wpdb->prepare( "action IN ({$placeholders})", $sanitized );
		}
		if ( $args['user_id'] ) {
			$where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
		}
		$date_from = self::instant( $args['date_from'] );
		if ( null !== $date_from ) {
			$where[] = $wpdb->prepare( 'created_at >= %d', $date_from );
		}
		$date_to = self::instant( $args['date_to'], true );
		if ( null !== $date_to ) {
			$where[] = $wpdb->prepare( 'created_at <= %d', $date_to );
		}
		if ( $args['search'] ) {
			$search  = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
		}

		$where_clause = implode( ' AND ', $where );

		$allowed_orderby = array( 'id', 'action', 'level', 'user_id', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$offset          = absint( $args['offset'] );
		$limit           = absint( $args['limit'] );

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
			$result['user_ip'] = self::resolve_ip( $result );
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
			$where[] = $wpdb->prepare( 'level = %s', sanitize_key( ArrayValue::string( $filters, 'level' ) ) );
		}
		if ( ! empty( $filters['action'] ) ) {
			$where[] = $wpdb->prepare( 'action = %s', sanitize_text_field( ArrayValue::string( $filters, 'action' ) ) );
		}
		if ( ! empty( $filters['search'] ) ) {
			$search  = '%' . $wpdb->esc_like( sanitize_text_field( ArrayValue::string( $filters, 'search' ) ) ) . '%';
			$where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
		}

		$where_clause = implode( ' AND ', $where );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$where_clause} AND id < %d ORDER BY id DESC LIMIT %d",
				$table_name,
				$cursor,
				$size
			),
			ARRAY_A
		);

		/**
		 * Rows as `$wpdb` hands them back, checked against the SELECT above.
		 *
		 * @var list<ActivityLogRow>|null $results
		 */
		if ( ! is_array( $results ) ) {
			return array();
		}

		foreach ( $results as &$result ) {
			$result['context'] = self::resolve_context( $result );
			$result['user_ip'] = self::resolve_ip( $result );
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
	 * CACHED IN A TRANSIENT (#1234). The caller is the Activity Log screen's
	 * filter `<select>`, redrawn on every render, and this is the fastest
	 * growing table in the plugin. The `DISTINCT` uses the `KEY action` index,
	 * so it is not a TABLE scan -- but it still walks the whole index to produce
	 * a few dozen values.
	 *
	 * No invalidation on write, on purpose: the table is append-only and the set
	 * changes by deploy, not by use. See {@see self::ACTIONS_CACHE_TTL}.
	 *
	 * @since 6.6.2
	 * @return list<string>
	 */
	public static function distinct_actions(): array {
		/**
		 * Um transiente e `mixed` por construcao -- `is_array()` estreita o
		 * recipiente, nunca os valores.
		 *
		 * @var list<string>|false $cached
		 */
		$cached = \get_transient( self::ACTIONS_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_activity_log';

		$actions = $wpdb->get_col(
			$wpdb->prepare( 'SELECT DISTINCT action FROM %i ORDER BY action ASC', $table_name )
		);
		if ( ! is_array( $actions ) ) {
			return array();
		}

		$list = array_values(
			array_map(
				static fn( $action ): string => is_scalar( $action ) ? (string) $action : '',
				$actions
			)
		);

		// A query that failed returns something that is not an array and leaves
		// above without writing; arriving here with an EMPTY list is a
		// legitimate answer (a log with no rows yet) and is worth caching like
		// any other.
		\set_transient( self::ACTIONS_CACHE_KEY, $list, self::ACTIONS_CACHE_TTL );

		return $list;
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
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $exists !== $table_name ) {
			return 0;
		}

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
	 * Erasure-request helper (#2343): what {@see self::redact_user_id()} does,
	 * plus the person's own data on those rows -- the client address, in both
	 * the encrypted column and the legacy plain one, and the encrypted context.
	 *
	 * The line itself stays: it is the audit record of what happened, and the
	 * plain `context` is the operator-facing summary the log screen shows.
	 * Account deletion keeps calling `redact_user_id()`, because deleting an
	 * account is not an erasure request (CLAUDE.md "Security & PII
	 * conventions").
	 *
	 * @param int $user_id WP user ID whose rows are erased.
	 * @return int Number of log rows rewritten.
	 */
	public static function erase_user_data( int $user_id ): int {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return 0;
		}
		$table_name = $wpdb->prefix . 'ffc_activity_log';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $exists !== $table_name ) {
			return 0;
		}

		$rows = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET user_id = NULL, user_ip = NULL, user_ip_encrypted = NULL, context_encrypted = NULL WHERE user_id = %d',
				$table_name,
				$user_id
			)
		);
		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * The row's client IP, readable.
	 *
	 * The address is stored only in `user_ip_encrypted` (#1574). A row written
	 * before that still carries it in the legacy `user_ip` column until the
	 * "Activity Log: Encrypt Client IPs" migration runs, so that value is the
	 * fallback. An address that cannot be decrypted reads as null rather than
	 * as the ciphertext.
	 *
	 * @param array<string, mixed> $row Activity log row (raw from DB).
	 * @return string|null
	 */
	private static function resolve_ip( array $row ): ?string {
		$encrypted = $row['user_ip_encrypted'] ?? null;
		if ( is_string( $encrypted ) && '' !== $encrypted ) {
			$plain = \FreeFormCertificate\Core\Encryption::is_configured()
				? \FreeFormCertificate\Core\Encryption::decrypt( $encrypted )
				: null;
			return ( null !== $plain && '' !== $plain ) ? $plain : null;
		}

		$legacy = $row['user_ip'] ?? null;
		return is_string( $legacy ) && '' !== $legacy ? $legacy : null;
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
			$decrypted = \FreeFormCertificate\Core\Encryption::decrypt( ArrayValue::string( $row, 'context_encrypted' ) );
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
		$date_from = self::instant( $args['date_from'] );
		if ( null !== $date_from ) {
			$where[] = $wpdb->prepare( 'created_at >= %d', $date_from );
		}
		$date_to = self::instant( $args['date_to'], true );
		if ( null !== $date_to ) {
			$where[] = $wpdb->prepare( 'created_at <= %d', $date_to );
		}
		if ( $args['search'] ) {
			$search  = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
		}

		$where_clause = implode( ' AND ', $where );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE {$where_clause}",
				$table_name
			)
		);
	}

	/**
	 * A date filter as UTC seconds (#2340), or null when there is none.
	 *
	 * `created_at` stores instants, so a bound is one too. Seconds are taken
	 * as they are; a date or date-time string is read in the site's timezone,
	 * which is what an operator typing a date means. A bare date that ends a
	 * range takes in the whole of that day.
	 *
	 * @param mixed $value      Seconds, or a site-local `Y-m-d[ H:i:s]`.
	 * @param bool  $end_of_day Whether a bare date stands for its last second.
	 * @return int|null
	 */
	public static function instant( $value, bool $end_of_day = false ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( ctype_digit( $value ) ) {
			return (int) $value > 0 ? (int) $value : null;
		}
		try {
			$moment = new \DateTimeImmutable( sanitize_text_field( $value ), wp_timezone() );
			if ( $end_of_day && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$moment = $moment->setTime( 23, 59, 59 );
			}
			return $moment->getTimestamp();
		} catch ( \Exception $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * Get statistics
	 *
	 * @param int $days Number of days to analyze (default: 30).
	 * @return array<string, mixed> Statistics
	 */
	public static function get_stats( int $days = 30 ): array {
		$cache_key = 'ffc_activity_stats_' . $days;

		/**
		 * The same method writes this transient at the end, so the shape is
		 * checkable against the `set_transient()` a few lines below.
		 *
		 * @var array<string, mixed>|false $cached
		 */
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_activity_log';
		$date_from  = time() - max( 0, $days ) * DAY_IN_SECONDS;

		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %d',
				$table_name,
				$date_from
			)
		);

		$by_level = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT level, COUNT(*) as count FROM %i WHERE created_at >= %d GROUP BY level',
				$table_name,
				$date_from
			),
			ARRAY_A
		);

		$top_actions = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT action, COUNT(*) as count FROM %i WHERE created_at >= %d GROUP BY action ORDER BY count DESC LIMIT 10',
				$table_name,
				$date_from
			),
			ARRAY_A
		);

		$top_users = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, COUNT(*) as count FROM %i WHERE created_at >= %d AND user_id > 0 GROUP BY user_id ORDER BY count DESC LIMIT 10',
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
			unset( $log );
		}

		foreach ( $logs as &$log ) {
			$log['user_ip'] = self::resolve_ip( $log );
		}
		unset( $log );

		return $logs;
	}

	/**
	 * The client address of one entry, decrypted, for an audited Reveal
	 * (#2109). The screen shows it masked; this is the only path to the
	 * clear value.
	 *
	 * @param int $id Activity log entry ID.
	 * @return string The address, or '' when the entry or its address is missing.
	 */
	public static function ip_of( int $id ): string {
		$entry = self::entry_of( $id );
		return null === $entry ? '' : $entry['user_ip'];
	}

	/**
	 * One entry's decrypted address and details, read once, for the audited
	 * Reveal (#2340).
	 *
	 * @param int $id Log row id.
	 * @return array{user_ip: string, context: array<string, mixed>}|null Null when the row is missing.
	 */
	public static function entry_of( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}

		/**
		 * The row, keyed by column.
		 *
		 * @var array<string, mixed>|null $row
		 */
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$wpdb->prefix . 'ffc_activity_log',
				$id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'user_ip' => (string) self::resolve_ip( $row ),
			'context' => self::resolve_context( $row ),
		);
	}

	/**
	 * Rows one retention statement deletes at most (#2340).
	 */
	public const CLEANUP_BATCH = 5000;

	/**
	 * Statements one cleanup run may issue, across both limits (#2340).
	 *
	 * 20 x 5,000 rows a run. A log larger than that past its limits is
	 * finished by a follow-up run a minute later, so no request deletes an
	 * unbounded number of rows.
	 */
	public const CLEANUP_BATCHES_PER_RUN = 20;

	/**
	 * Single event that carries on a cleanup the budget cut short (#2340).
	 */
	public const CLEANUP_CONTINUE_HOOK = 'ffc_activity_log_cleanup_continue';

	/**
	 * Delete the entries older than a number of days, in bounded statements.
	 *
	 * @param int $days    Keep the entries of the last N days.
	 * @param int $batches Statements this call may issue.
	 * @return array{deleted: int, batches: int, more: bool} Rows deleted, statements issued, and whether rows past the limit remain.
	 */
	public static function cleanup( int $days = 90, int $batches = self::CLEANUP_BATCHES_PER_RUN ): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_activity_log';
		$cutoff     = time() - max( 0, $days ) * DAY_IN_SECONDS;

		return self::delete_in_batches(
			(string) $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %d ORDER BY id LIMIT %d', $table_name, $cutoff, self::CLEANUP_BATCH ),
			$batches
		);
	}

	/**
	 * Run the cleanup: the retention window, then the record cap.
	 *
	 * Called by the daily cron and by its follow-up event, never by a page
	 * load. When the budget of one run is spent with rows still past a
	 * limit, a follow-up run is scheduled.
	 *
	 * @since 4.6.9
	 * @return int Number of deleted rows
	 */
	public static function run_cleanup(): int {
		$retention_days = \FreeFormCertificate\Settings\SettingsReader::activity_log_retention_days();
		$budget         = self::CLEANUP_BATCHES_PER_RUN;

		$by_age = $retention_days > 0 ? self::cleanup( $retention_days, $budget ) : array(
			'deleted' => 0,
			'batches' => 0,
			'more'    => false,
		);
		$by_cap = $by_age['more']
			? array(
				'deleted' => 0,
				'batches' => 0,
				'more'    => true,
			)
			: self::trim_to( \FreeFormCertificate\Settings\SettingsReader::activity_log_max_records(), $budget - $by_age['batches'] );

		$deleted = $by_age['deleted'] + $by_cap['deleted'];
		if ( $deleted > 0 ) {
			self::forget_stats();
		}
		if ( ( $by_age['more'] || $by_cap['more'] ) && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CLEANUP_CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CLEANUP_CONTINUE_HOOK );
		}

		return $deleted;
	}

	/**
	 * Delete the oldest rows beyond a record cap (#2338), in bounded statements.
	 *
	 * @param int $max_records Rows to keep.
	 * @param int $batches     Statements this call may issue.
	 * @return array{deleted: int, batches: int, more: bool} Rows deleted, statements issued, and whether rows past the cap remain.
	 */
	public static function trim_to( int $max_records, int $batches = self::CLEANUP_BATCHES_PER_RUN ): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_activity_log';
		$none       = array(
			'deleted' => 0,
			'batches' => 0,
			'more'    => false,
		);

		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );
		if ( $max_records < 1 || $count <= $max_records ) {
			return $none;
		}

		// The id of the newest row that falls outside the cap; it and every
		// older row go.
		$cutoff_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d',
				$table_name,
				$max_records
			)
		);
		if ( $cutoff_id < 1 ) {
			return $none;
		}

		return self::delete_in_batches(
			(string) $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d ORDER BY id LIMIT %d', $table_name, $cutoff_id, self::CLEANUP_BATCH ),
			$batches
		);
	}

	/**
	 * Run one DELETE statement until it deletes less than its limit or the
	 * budget is spent.
	 *
	 * @param string $statement Prepared DELETE statement carrying its LIMIT.
	 * @param int    $batches   Statements allowed.
	 * @return array{deleted: int, batches: int, more: bool}
	 */
	private static function delete_in_batches( string $statement, int $batches ): array {
		global $wpdb;
		$deleted = 0;
		$issued  = 0;
		$more    = false;
		while ( $issued < $batches ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $statement is the output of $wpdb->prepare() built by the two callers above.
			$affected = $wpdb->query( $statement );
			++$issued;
			$affected = is_int( $affected ) ? $affected : 0;
			$deleted += $affected;
			$more     = self::CLEANUP_BATCH === $affected;
			if ( ! $more ) {
				break;
			}
		}

		return array(
			'deleted' => $deleted,
			'batches' => $issued,
			'more'    => $more,
		);
	}

	/**
	 * Drop the cached stats after rows left.
	 *
	 * @return void
	 */
	private static function forget_stats(): void {
		delete_transient( 'ffc_activity_stats_7' );
		delete_transient( 'ffc_activity_stats_30' );
		delete_transient( 'ffc_activity_stats_90' );
	}
}
