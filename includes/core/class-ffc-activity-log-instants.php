<?php
/**
 * ActivityLogInstants
 *
 * Moves `ffc_activity_log.created_at` from the site's wall clock to UTC
 * seconds (#2340, Category A).
 *
 * The column was written with `current_time( 'mysql' )`, a `DATETIME` in
 * the site's timezone, and then read as if it were UTC: the screen showed
 * every entry shifted by the site's offset, and the cleanup and the stats
 * compared it with UTC cutoffs. An entry's time is an instant, so it is
 * stored as one.
 *
 * WHY THE BACKFILL IS ONE STATEMENT PER OFFSET, NOT ONE PER ROW.
 *
 * This table is the largest the plugin keeps: its record cap goes up to a
 * million rows, and the shared per-row helper issues one `UPDATE` per row
 * inside a web request. Within one stretch of the site timezone where the
 * offset does not change, every row converts the same way -- its wall clock
 * read as UTC, minus that offset -- so each stretch is one bounded `UPDATE`
 * computed by the server. `TIMESTAMPDIFF()` from the epoch reads the value
 * without the database session's own timezone, which WordPress does not
 * pin. The stretches come from the timezone's own transitions, so a
 * daylight-saving change between two rows moves exactly the rows after it.
 *
 * A wall-clock time that does not exist in the timezone -- the hour skipped
 * when the clocks go forward -- falls between two stretches, and is read
 * with the offset before the change. A zero date falls in no stretch and
 * keeps the instant 0. Then the filled column replaces the old one.
 *
 * ONE REQUEST AT A TIME, AND NEVER ON THE FRONT END. The column swap drops
 * the old column; two requests swapping at once could drop the new one. So
 * the work runs under an option lock, in wp-admin, a cron run or WP-CLI.
 *
 * @package FreeFormCertificate\Core
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- A schema migration of the plugin's own `ffc_activity_log` table: WordPress has no API for it and a migration must never read from a cache.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts the activity log's creation times to instants.
 */
final class ActivityLogInstants {

	use DatabaseHelperTrait;

	/**
	 * Option holding the lock while a request converts the column.
	 */
	public const LOCK_OPTION = 'ffc_activity_log_instants_lock';

	/**
	 * Seconds after which a lock left by a request that died is taken over.
	 */
	private const LOCK_TTL = 600;

	/**
	 * Rows one statement converts at most.
	 */
	public const BATCH = 20000;

	/**
	 * Margin, in seconds, added on both sides of the stored range when the
	 * timezone's transitions are read, so the first and last rows always
	 * fall inside a stretch.
	 */
	private const MARGIN = 2 * DAY_IN_SECONDS;

	/**
	 * The activity log table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_activity_log';
	}

	/**
	 * Convert the column, or say it is already converted.
	 *
	 * @return bool Whether `created_at` now stores instants, or the table does not exist yet.
	 */
	public static function migrate(): bool {
		$table = self::table();
		if ( ! self::table_exists( $table ) ) {
			return true;
		}
		if ( self::is_converted( $table ) ) {
			return true;
		}
		if ( ! self::may_run() || ! self::take_lock() ) {
			return false;
		}

		try {
			self::add_column_if_missing( $table, 'created_at_ts', 'BIGINT UNSIGNED NOT NULL DEFAULT 0', 'created_at' );
			if ( self::column_exists( $table, 'created_at_ts' ) ) {
				if ( self::column_exists( $table, 'created_at' ) ) {
					self::backfill( $table, wp_timezone() );
					self::swap();
				} else {
					// A request that died between the two statements of the swap.
					self::rename();
				}
			}
		} finally {
			delete_option( self::LOCK_OPTION );
		}

		return self::is_converted( $table );
	}

	/**
	 * Whether `created_at` is a BIGINT and no staging column is left.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function is_converted( string $table ): bool {
		$type = strtolower( (string) self::column_type( $table, 'created_at' ) );
		return 0 === strpos( $type, 'bigint' ) && ! self::column_exists( $table, 'created_at_ts' );
	}

	/**
	 * Replace `created_at` with the filled `created_at_ts`.
	 *
	 * Dropping the old column drops its index; the index is added back on
	 * the new one.
	 *
	 * @return void
	 */
	private static function swap(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table, 'created_at' ) );
		self::rename();
	}

	/**
	 * Give the filled column its final name and index.
	 *
	 * @return void
	 */
	private static function rename(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE %i %i BIGINT UNSIGNED NOT NULL DEFAULT 0', $table, 'created_at_ts', 'created_at' ) );
		self::add_index_if_missing( $table, 'created_at', '(created_at)' );
	}

	/**
	 * Fill `created_at_ts` from `created_at`, one statement per offset.
	 *
	 * @param string        $table Table name.
	 * @param \DateTimeZone $tz    Timezone the wall clock was written in.
	 * @return int Rows converted.
	 */
	public static function backfill( string $table, \DateTimeZone $tz ): int {
		global $wpdb;

		$range = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT MIN(created_at) AS first_at, MAX(created_at) AS last_at FROM %i WHERE created_at_ts = 0 AND created_at > %s',
				$table,
				'1970-01-02 00:00:00'
			),
			ARRAY_A
		);
		if ( ! is_array( $range ) ) {
			return 0;
		}
		$first = self::utc_seconds( $range['first_at'] ?? null );
		$last  = self::utc_seconds( $range['last_at'] ?? null );
		if ( null === $first || null === $last ) {
			return 0;
		}

		$stretches = self::stretches( $tz, $first - self::MARGIN, $last + self::MARGIN );
		$ranges    = $stretches;
		$count     = count( $stretches );
		for ( $i = 0; $i + 1 < $count; $i++ ) {
			// The hour the clocks skip: no stretch covers it.
			if ( $stretches[ $i ]['to'] < $stretches[ $i + 1 ]['from'] ) {
				$ranges[] = array(
					'offset' => $stretches[ $i ]['offset'],
					'from'   => $stretches[ $i ]['to'],
					'to'     => $stretches[ $i + 1 ]['from'],
				);
			}
		}

		$changed = 0;
		foreach ( $ranges as $range ) {
			$changed += self::convert_range( $table, $range['offset'], $range['from'], $range['to'] );
		}

		return $changed;
	}

	/**
	 * Convert the rows of one wall-clock range, in bounded statements.
	 *
	 * The loop ends on a statement that converts fewer rows than its limit,
	 * which a statement that converts nothing always does.
	 *
	 * @param string $table  Table name.
	 * @param int    $offset Offset of the range, seconds east of UTC.
	 * @param string $from   First wall-clock value, inclusive.
	 * @param string $to     Last wall-clock value, exclusive.
	 * @return int Rows converted.
	 */
	private static function convert_range( string $table, int $offset, string $from, string $to ): int {
		global $wpdb;
		$changed = 0;
		do {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET created_at_ts = TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', created_at) - %d WHERE created_at_ts = 0 AND created_at >= %s AND created_at < %s LIMIT %d",
					$table,
					$offset,
					$from,
					$to,
					self::BATCH
				)
			);
			$affected = is_int( $affected ) ? $affected : 0;
			$changed += $affected;
		} while ( self::BATCH === $affected );

		return $changed;
	}

	/**
	 * The stretches of a timezone between two instants, as wall-clock ranges.
	 *
	 * Each stretch has one offset; its range is where that offset applies,
	 * written as the wall clock the site would have shown. Ranges touch
	 * end to start; where the clocks go back they overlap, and the earlier
	 * stretch takes the repeated hour.
	 *
	 * @param \DateTimeZone $tz    Timezone.
	 * @param int           $begin First instant, UTC seconds.
	 * @param int           $end   Last instant, UTC seconds.
	 * @return list<array{offset: int, from: string, to: string}>
	 */
	public static function stretches( \DateTimeZone $tz, int $begin, int $end ): array {
		// A fixed offset (`-03:00`) has no transitions, and PHP answers false
		// although its stubs say a list.
		$transitions = $tz->getTransitions( $begin, $end );
		if ( false === (bool) $transitions ) {
			$transitions = array(
				array(
					'ts'     => $begin,
					'offset' => $tz->getOffset( new \DateTimeImmutable( '@' . $begin ) ),
				),
			);
		}

		$out   = array();
		$count = count( $transitions );
		for ( $i = 0; $i < $count; $i++ ) {
			$offset = (int) $transitions[ $i ]['offset'];
			$from   = max( $begin, (int) $transitions[ $i ]['ts'] );
			$to     = $i + 1 < $count ? (int) $transitions[ $i + 1 ]['ts'] : $end;
			if ( $to <= $from ) {
				continue;
			}
			$out[] = array(
				'offset' => $offset,
				'from'   => gmdate( 'Y-m-d H:i:s', $from + $offset ),
				'to'     => gmdate( 'Y-m-d H:i:s', $to + $offset ),
			);
		}

		return $out;
	}

	/**
	 * A stored wall-clock value read as UTC, or null when it is no date.
	 *
	 * @param mixed $value Stored value.
	 * @return int|null
	 */
	private static function utc_seconds( $value ): ?int {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( \Exception $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * Whether this request may convert the column: wp-admin, a cron run or
	 * WP-CLI, never the front end.
	 *
	 * @return bool
	 */
	private static function may_run(): bool {
		return ( function_exists( 'is_admin' ) && is_admin() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Take the lock, or say another request holds it.
	 *
	 * `add_option()` is atomic on its unique key, so of two requests that
	 * arrive together only one creates the row. A lock older than its TTL
	 * belongs to a request that died and is taken over.
	 *
	 * @return bool
	 */
	private static function take_lock(): bool {
		$now = time();
		if ( add_option( self::LOCK_OPTION, (string) $now, '', false ) ) {
			return true;
		}
		$stored = get_option( self::LOCK_OPTION, 0 );
		$held   = is_numeric( $stored ) ? (int) $stored : 0;
		if ( $held > 0 && $now - $held < self::LOCK_TTL ) {
			return false;
		}
		update_option( self::LOCK_OPTION, (string) $now, false );
		return true;
	}
}
