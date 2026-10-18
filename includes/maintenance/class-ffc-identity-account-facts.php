<?php
/**
 * IdentityAccountFacts
 *
 * What is true of each account a finding names: whether it exists, how many
 * rows it owns per store, and when it was last active (#1354, #1346).
 *
 * The evidence an operator chooses a survivor by, read by the resolution
 * screen and by the audit export through this one class so the two cannot
 * disagree about an account (#1925).
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-account facts for identity findings.
 */
class IdentityAccountFacts {

	/**
	 * Where each store records that something happened.
	 *
	 * A map and not a probe, unlike {@see IdentityStores::all()}, because nothing in
	 * a schema says which of several timestamps means "was used" -- the
	 * appointment table alone carries seven. The column each entry names is
	 * still probed before it is read, so a store that loses it degrades to
	 * "no activity here" instead of failing the whole audit.
	 *
	 * @var array<string, string>
	 */
	private const ACTIVITY_COLUMNS = array(
		'ffc_submissions'                  => 'submission_date',
		'ffc_self_scheduling_appointments' => 'created_at',
		'ffc_recruitment_candidate'        => 'created_at',
		'ffc_user_profiles'                => 'updated_at',
	);

	/**
	 * The stores whose activity column is a unix instant rather than a DATETIME.
	 *
	 * **This split is the whole reason the comparison is not done in SQL.**
	 * `submission_date` is a Category A instant -- unix UTC seconds, written
	 * by `time()`. The other three are the housekeeping DATETIMEs that
	 * `CLAUDE.md`'s date convention carves out, written through
	 * `current_time( 'mysql' )` and therefore already in the SITE's timezone.
	 *
	 * `UNIX_TIMESTAMP()` would reinterpret those DATETIMEs in the MySQL
	 * server's timezone, which is not necessarily WordPress's, so a single
	 * `MAX()` across the union would silently skew three stores against one.
	 * Both categories render to the same site-local calendar date, so the
	 * comparison happens on `Y-m-d` strings instead: chronological, because
	 * that format sorts lexicographically, and with no timezone arithmetic
	 * anywhere.
	 *
	 * A list and not a map: the only thing an entry carries is membership, so
	 * a `=> true` beside each name would be a value that says nothing -- and
	 * PHPStan is right to call the `$is_unix &&` it invites a condition that
	 * is always true.
	 *
	 * @var array<int, string>
	 */
	private const ACTIVITY_UNIX_STORES = array( 'ffc_submissions' );

	/**
	 * What is true of each account a finding names: does it exist, and how
	 * much data hangs off it.
	 *
	 * ONE STATEMENT PER QUESTION, NOT FIVE PER ACCOUNT
	 *
	 * The obvious shape -- ask `wp_users` once per id, then `COUNT(*)` once
	 * per id per store -- is 5 statements an account, or 360 for the 72 the
	 * first production export named. Both questions are set questions, so
	 * both are asked once: existence through `get_users()` bounded by
	 * `include`, and the counts as one `UNION ALL` grouping by account and
	 * store. That second one is the same union the rest of this class already
	 * builds, widened to count rows instead of pairing identifiers.
	 *
	 * EXISTENCE GOES THROUGH THE WP API, AND `blog_id` IS WHY IT IS NOT SQL
	 *
	 * A hand-rolled `SELECT ID FROM wp_users` is what `PhpcsSuppressionTest`
	 * refuses in a file carrying a `DirectDatabaseQuery` disable, and it is
	 * right to: the sniff has something real to say here, because core owns
	 * that table. `get_users()` answers the same question -- and `blog_id => 0`
	 * is load-bearing rather than tidy, since the default scopes the query to
	 * users holding a role on the CURRENT site, which on multisite would
	 * report a live account as deleted.
	 *
	 * The counts are of EVERY row the account owns in a store, not only the
	 * rows carrying the identifier the finding is about. The question they
	 * answer is "what would a merge move, or a deletion destroy", and that is
	 * all of it.
	 *
	 * An account is reported `missing` until `wp_users` says otherwise, so a
	 * query that returns nothing reads as "no account found" rather than
	 * silently leaving every status blank -- an empty result must never read
	 * as clean.
	 *
	 * @param array<int, mixed> $user_ids Accounts a finding names; non-positive values are dropped.
	 * @return array<int, array{status: string, rows: array<string, int>, activity: string}> Account id -> facts.
	 */
	public function of( array $user_ids ): array {
		global $wpdb;

		$wanted = array();
		foreach ( $user_ids as $candidate ) {
			$id = is_numeric( $candidate ) ? (int) $candidate : 0;
			if ( $id > 0 ) {
				$wanted[ $id ] = true;
			}
		}

		$ids = array_keys( $wanted );
		if ( array() === $ids ) {
			return array();
		}

		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array(
				'status'   => IdentityConflictQuery::STATUS_MISSING,
				'rows'     => array(),
				'activity' => '',
			);
		}

		$found = get_users(
			array(
				'include'     => $ids,
				'fields'      => 'ID',
				'blog_id'     => 0,
				'number'      => count( $ids ),
				'count_total' => false,
			)
		);

		foreach ( (array) $found as $id ) {
			$id = is_numeric( $id ) ? (int) $id : 0;
			if ( isset( $out[ $id ] ) ) {
				$out[ $id ]['status'] = IdentityConflictQuery::STATUS_EXISTS;
			}
		}

		$stores = IdentityStores::all();

		if ( array() === $stores ) {
			return $out;
		}

		foreach ( $this->activity_per_account( $ids ) as $id => $date ) {
			if ( isset( $out[ $id ] ) ) {
				$out[ $id ]['activity'] = $date;
			}
		}

		foreach ( array_chunk( $ids, IdentityStores::USERS_PER_STATEMENT ) as $chunk ) {
			$slots = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			$parts  = array();
			$values = array();

			foreach ( $stores as $table ) {
				$parts[]  = "SELECT user_id, %s AS src, COUNT(*) AS n FROM %i WHERE user_id IN ({$slots}) GROUP BY user_id";
				$values[] = IdentityStores::label( $table );
				$values[] = $table;
				foreach ( $chunk as $user_id ) {
					$values[] = (int) $user_id;
				}
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The fragment is this class's own literal, repeated once per table it resolved itself through `IdentityStores::all()`; `$slots` is `%d` placeholders counted off `$chunk`. These are the plugin's own `ffc_*` tables, for which WordPress exposes no API, and an audit read must reflect the live rows.
			$counts = $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $parts ), ...$values ), ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $counts as $row ) {
				$row   = (array) $row;
				$id    = isset( $row['user_id'] ) && is_numeric( $row['user_id'] ) ? (int) $row['user_id'] : 0;
				$src   = isset( $row['src'] ) && is_string( $row['src'] ) ? $row['src'] : '';
				$total = isset( $row['n'] ) && is_numeric( $row['n'] ) ? (int) $row['n'] : 0;

				if ( isset( $out[ $id ] ) && '' !== $src && $total > 0 ) {
					$out[ $id ]['rows'][ $src ] = $total;
				}
			}
		}

		return $out;
	}

	/**
	 * The most recent calendar date each account was active on, anywhere.
	 *
	 * One `MAX()` per store, then the latest of those per account. The
	 * comparison is on `Y-m-d` strings rather than on timestamps, for the
	 * reason {@see self::ACTIVITY_UNIX_STORES} records: the stores disagree
	 * about how a moment is stored, and only the rendered site-local date is
	 * a like-for-like value across all four.
	 *
	 * A store missing its mapped column is skipped rather than fatal, and an
	 * account with nothing anywhere is simply absent from the result -- which
	 * {@see self::of()} leaves as the empty string it seeded.
	 *
	 * @param array<int, int> $user_ids Accounts to ask about.
	 * @return array<int, string> Account id -> `Y-m-d`.
	 */
	private function activity_per_account( array $user_ids ): array {
		global $wpdb;

		$out    = array();
		$stores = $this->stores_with_activity();

		if ( array() === $stores || array() === $user_ids ) {
			return $out;
		}

		foreach ( array_chunk( $user_ids, IdentityStores::USERS_PER_STATEMENT ) as $chunk ) {
			$slots  = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$parts  = array();
			$values = array();

			foreach ( $stores as $table => $column ) {
				$parts[]  = "SELECT user_id, %s AS src, MAX(%i) AS last_seen FROM %i WHERE user_id IN ({$slots}) GROUP BY user_id";
				$values[] = (string) $table;
				$values[] = $column;
				$values[] = (string) $table;
				foreach ( $chunk as $user_id ) {
					$values[] = (int) $user_id;
				}
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The fragment is this class's own literal, repeated once per table it resolved itself; `$slots` is `%d` placeholders counted off `$chunk`, never request data. These are the plugin's own `ffc_*` tables, and an audit read must reflect the live rows.
			$rows = $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $parts ), ...$values ), ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$row = (array) $row;
				$id  = isset( $row['user_id'] ) && is_numeric( $row['user_id'] ) ? (int) $row['user_id'] : 0;
				$src = isset( $row['src'] ) && is_string( $row['src'] ) ? $row['src'] : '';
				// Two arms where its siblings need one, and the reason is the
				// union itself: `MAX()` over a bigint branch and a DATETIME branch
				// can come back as either, depending on the driver. What must not
				// happen is casting the `mixed` a row offset yields without
				// establishing its type first -- which is what the level-9 gate
				// caught here, and what every other read in this class already does.
				$last = ( isset( $row['last_seen'] ) && ( is_string( $row['last_seen'] ) || is_int( $row['last_seen'] ) ) )
					? (string) $row['last_seen']
					: '';
				$date = self::activity_date( $src, $last );

				// `Y-m-d` sorts lexicographically, so the later string is the
				// later date and no parsing is needed to compare them.
				if ( $id > 0 && '' !== $date && ( ! isset( $out[ $id ] ) || $date > $out[ $id ] ) ) {
					$out[ $id ] = $date;
				}
			}
		}

		return $out;
	}

	/**
	 * The stores whose activity column this install actually has.
	 *
	 * @return array<string, string> Prefixed table -> column.
	 */
	private function stores_with_activity(): array {
		global $wpdb;

		$out = array();

		foreach ( IdentityStores::all() as $table ) {
			$column = '';

			foreach ( self::ACTIVITY_COLUMNS as $suffix => $candidate ) {
				if ( substr( $table, -strlen( $suffix ) ) === $suffix ) {
					$column = $candidate;
					break;
				}
			}

			if ( '' === $column ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, as in `IdentityStores::all()`.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );

			if ( $column === $found ) {
				$out[ $table ] = $column;
			}
		}

		return $out;
	}

	/**
	 * One store's stored value as the site-local calendar date it means.
	 *
	 * The two shapes are not interchangeable and the difference is not
	 * cosmetic: a unix instant has to be rendered through the site timezone
	 * to become a date, while a housekeeping DATETIME was written in that
	 * timezone already and only needs its date half.
	 *
	 * `wp_date()` rather than `DateFormatter::format_date()` on purpose. This
	 * is a machine column -- it is compared, sorted and filtered in a CSV --
	 * so it needs the stable `Y-m-d` that the site's display format is not,
	 * the same documented-reason carve-out `CLAUDE.md` grants an iCal
	 * `DTSTAMP`. The screen shows the identical string, which is what keeps
	 * the file and the table corroborating each other.
	 *
	 * @param string $table Prefixed table the value came from.
	 * @param string $value Stored value, as the driver returned it.
	 * @return string `Y-m-d`, or '' when there is nothing to report.
	 */
	private static function activity_date( string $table, string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		foreach ( self::ACTIVITY_UNIX_STORES as $suffix ) {
			if ( substr( $table, -strlen( $suffix ) ) === $suffix ) {
				$stamp = (int) $value;

				return ( $stamp > 0 && function_exists( 'wp_date' ) )
					? (string) wp_date( 'Y-m-d', $stamp )
					: '';
			}
		}

		// A DATETIME the site's own timezone already wrote. Anything that is
		// not one -- a zero date, a driver oddity -- reports nothing rather
		// than a date nobody can trace back to a row.
		return ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $match ) && '0000-00-00' !== $match[1] )
			? $match[1]
			: '';
	}

	/**
	 * One account's per-store counts as the string the report carries.
	 *
	 * `submissions:12,user_profiles:1`, or an empty string when the account
	 * owns no row in any store -- which is itself a finding, since an account
	 * named by this audit is named BECAUSE rows point at it. An empty value
	 * there means every such row sits in a store {@see IdentityStores::all()} does
	 * not resolve on this install.
	 *
	 * @param array<string, int> $rows Store label -> row count.
	 * @return string
	 */
	public static function format_account_rows( array $rows ): string {
		$parts = array();

		foreach ( $rows as $store => $total ) {
			$parts[] = $store . IdentityConflictQuery::ACCOUNT_ROWS_COUNT . (string) (int) $total;
		}

		return implode( IdentityConflictQuery::ACCOUNT_ROWS_SEPARATOR, $parts );
	}
}
