<?php
/**
 * AudienceQueryService
 *
 * Cross-table read aggregator for the user-facing audience surface.
 * Concentrates the multi-table JOINs that lived inline in
 * `Api\UserAudienceRestController` + `Api\UserSummaryRestController`
 * (#343 group B).
 *
 * Rationale: each REST endpoint composes data from 4–7 audience tables
 * to build a view-model. Pushing those joins into any single audience
 * repository would violate single-table ownership; this service is the
 * right layer — read-only, scoped to "what does this user see in the
 * audience UI?", easy to test against a mocked wpdb.
 *
 * Design notes (#343 Option C):
 *   - Service returns rich denormalized rows; REST controllers own the
 *     final view-model assembly (badge nesting, parent-child tree, etc.).
 *   - All methods are stateless static — matches `UserService` and
 *     `UserIdentifiersQueryService` already in this codebase.
 *   - No caching at this layer initially; profiling will tell whether
 *     per-request memoization helps. Repository-level caches still
 *     apply where the service delegates back to a repository.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.6.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$id_list} is the absint()-mapped booking ids joined with commas; table names go through %i and every other value is bound through prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Stateless service. Public API mirrors what REST controllers used to
 * spell inline; the JOIN shape is the same — just centralized.
 *
 * Row shapes below are what `$wpdb` actually hands back, not what the
 * columns mean: every column arrives as a string because WordPress uses
 * mysqli without native types, and only a real SQL NULL stays null. They are
 * derived from the `CREATE TABLE` statements in `AudienceActivator`, column
 * by column — a shape that says `id: int` would satisfy the analyser and keep
 * the defect it exists to catch (#1060).
 *
 * A column declared without `NOT NULL` is nullable in MySQL even when it
 * carries a `DEFAULT`, so `color`, `status` and the timestamps are
 * `string|null` here. `allow_self_join` and `is_all_day` are optional because
 * they arrive by migration rather than from the original `CREATE TABLE`.
 *
 * @phpstan-type JoinableAudienceRow array{
 *     id: numeric-string,
 *     name: string,
 *     color: string|null,
 *     parent_id: numeric-string|null,
 *     allow_self_join: numeric-string|null,
 *     is_member: numeric-string
 * }
 * @phpstan-type UserBookingRow array{
 *     id: numeric-string,
 *     environment_id: numeric-string,
 *     booking_date: string,
 *     start_time: string,
 *     end_time: string,
 *     booking_type: string,
 *     description: string,
 *     status: string|null,
 *     created_by: numeric-string,
 *     created_at: string|null,
 *     cancelled_by: numeric-string|null,
 *     cancelled_at: string|null,
 *     cancellation_reason: string|null,
 *     environment_name: string|null,
 *     schedule_name: string|null,
 *     is_all_day?: numeric-string|null
 * }
 * `UserBookingWithAudiences` repeats every key of `UserBookingRow` instead of
 * intersecting with it: PHPStan resolves an intersection of two sealed array
 * shapes to *NEVER*, so `UserBookingRow&array{audiences: …}` type-checks as a
 * value that cannot exist. The duplication is the cost of saying what the
 * method actually returns.
 *
 * @phpstan-type UserBookingWithAudiences array{
 *     id: numeric-string,
 *     environment_id: numeric-string,
 *     booking_date: string,
 *     start_time: string,
 *     end_time: string,
 *     booking_type: string,
 *     description: string,
 *     status: string|null,
 *     created_by: numeric-string,
 *     created_at: string|null,
 *     cancelled_by: numeric-string|null,
 *     cancelled_at: string|null,
 *     cancellation_reason: string|null,
 *     environment_name: string|null,
 *     schedule_name: string|null,
 *     is_all_day?: numeric-string|null,
 *     audiences: list<array{name: string, color: string|null}>
 * }
 * @phpstan-type BookingAudienceBadgeRow array{
 *     booking_id: numeric-string,
 *     name: string,
 *     color: string|null
 * }
 */
final class AudienceQueryService {

	/**
	 * Count the user's active self-join audience memberships.
	 *
	 * A "self-join membership" is a row in `ffc_audience_members` whose
	 * audience has `allow_self_join = 1` — joinability is per-node (any
	 * self-join audience, child or top-level, counts), matching the gate
	 * `UserAudienceRestController::join_audience_group` uses to enforce
	 * the `MAX_SELF_JOIN_GROUPS` cap (#791).
	 *
	 * @since 6.6.2
	 * @param int $user_id WordPress user ID.
	 * @return int
	 */
	public static function count_user_self_join_memberships( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		$audiences_table = $wpdb->prefix . 'ffc_audiences';
		$members_table   = $wpdb->prefix . 'ffc_audience_members';

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i m
				INNER JOIN %i a ON a.id = m.audience_id
				WHERE m.user_id = %d AND a.allow_self_join = 1',
				$members_table,
				$audiences_table,
				$user_id
			)
		);
		return null === $count ? 0 : (int) $count;
	}

	/**
	 * List every **active** audience with a per-row `allow_self_join`
	 * flag (whether the audience itself offers a self-join button) and
	 * an `is_member` flag (whether the supplied user already belongs).
	 * Returned flat (no parent-child nesting) — the REST controller
	 * assembles the tree because that's a presentation concern.
	 *
	 * Returns the **full active set**, not only self-join rows: the
	 * dashboard tree must still render a non-self-join parent as a
	 * header when it has joinable descendants (per-node model, #792).
	 * The controller applies the appears/button rules; this layer just
	 * supplies the raw rows + flags.
	 *
	 * Each row carries: `id` (int), `name` (string), `color` (string),
	 * `parent_id` (?int — null for root audiences),
	 * `allow_self_join` (bool), `is_member` (bool).
	 *
	 * Issue #343 group B; broadened for the per-node self-join list (#792).
	 *
	 * @since 6.6.2
	 * @param int $user_id WordPress user ID.
	 * @return list<array{id: int, name: string, color: string, parent_id: ?int, allow_self_join: bool, is_member: bool}>
	 */
	public static function find_user_joinable_audiences( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		global $wpdb;
		$audiences_table = $wpdb->prefix . 'ffc_audiences';
		$members_table   = $wpdb->prefix . 'ffc_audience_members';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.name, a.color, a.parent_id, a.allow_self_join,
					CASE WHEN m.id IS NOT NULL THEN 1 ELSE 0 END AS is_member
				FROM %i a
				LEFT JOIN %i m ON m.audience_id = a.id AND m.user_id = %d
				WHERE a.status = 'active'
				ORDER BY a.name ASC",
				$audiences_table,
				$members_table,
				$user_id
			),
			ARRAY_A
		);

		/**
		 * Rows as `$wpdb` hands them back, checked against the SELECT above.
		 *
		 * @var list<JoinableAudienceRow>|null $rows
		 */
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'              => (int) $row['id'],
				'name'            => $row['name'],
				'color'           => (string) ( $row['color'] ?? '' ),
				'parent_id'       => empty( $row['parent_id'] ) ? null : (int) $row['parent_id'],
				'allow_self_join' => 1 === (int) ( $row['allow_self_join'] ?? 0 ),
				'is_member'       => 1 === (int) $row['is_member'],
			);
		}
		return $out;
	}

	/**
	 * Find every audience booking the user participates in — either
	 * as a direct participant (`ffc_audience_booking_users`) or as a
	 * member of an audience tied to the booking (via
	 * `ffc_audience_booking_audiences` ⨝ `ffc_audience_members`).
	 *
	 * Each row already carries `environment_name` and `schedule_name`
	 * (LEFT JOIN with environments + schedules) plus an `audiences`
	 * list of `{name, color}` badges that ARE the audiences attached
	 * to that booking. Batch-loaded after the main query to avoid the
	 * N+1 pattern the previous inline implementation also avoided.
	 *
	 * Filter shape — all keys optional:
	 *   - `start_date` (string `Y-m-d`) — `booking_date >= %s`
	 *   - `end_date`   (string `Y-m-d`) — `booking_date <= %s`
	 *   - `exclude_status` (string)     — `status != %s`
	 *
	 * Issue #343 group B.
	 *
	 * @since 6.6.2
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $filter  Optional filter (see shape above).
	 * @return list<UserBookingWithAudiences>
	 */
	public static function find_user_bookings( int $user_id, array $filter = array() ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		[ $where_extra, $where_args ] = self::booking_filter_clauses( $filter );

		global $wpdb;
		$bookings_table          = $wpdb->prefix . 'ffc_audience_bookings';
		$users_table             = $wpdb->prefix . 'ffc_audience_booking_users';
		$booking_audiences_table = $wpdb->prefix . 'ffc_audience_booking_audiences';
		$members_table           = $wpdb->prefix . 'ffc_audience_members';
		$audience_names_table    = $wpdb->prefix . 'ffc_audiences';
		$environments_table      = $wpdb->prefix . 'ffc_audience_environments';
		$schedules_table         = $wpdb->prefix . 'ffc_audience_schedules';

		$sql = 'SELECT DISTINCT b.*, e.name as environment_name, s.name as schedule_name
			FROM %i b
			LEFT JOIN %i bu ON b.id = bu.booking_id
			LEFT JOIN %i ba ON b.id = ba.booking_id
			LEFT JOIN %i am ON ba.audience_id = am.audience_id
			LEFT JOIN %i e ON b.environment_id = e.id
			LEFT JOIN %i s ON e.schedule_id = s.id
			WHERE (bu.user_id = %d OR am.user_id = %d)'
			. $where_extra
			. ' ORDER BY b.booking_date DESC, b.start_time DESC';

		$args = array_merge(
			array(
				$bookings_table,
				$users_table,
				$booking_audiences_table,
				$members_table,
				$environments_table,
				$schedules_table,
				$user_id,
				$user_id,
			),
			$where_args
		);

		/**
		 * Rows as `$wpdb` hands them back, checked against the SELECT above.
		 *
		 * @var list<UserBookingRow>|null $bookings
		 */
		$bookings = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		if ( ! is_array( $bookings ) || empty( $bookings ) ) {
			return array();
		}

		$booking_ids = array();
		foreach ( $bookings as $b ) {
			$id = (int) $b['id'];
			if ( $id > 0 ) {
				$booking_ids[] = $id;
			}
		}
		$audiences_map = self::batch_load_audiences_for_bookings( $booking_ids, $booking_audiences_table, $audience_names_table );

		$out = array();
		foreach ( $bookings as $booking ) {
			$booking_id           = (int) $booking['id'];
			$booking['audiences'] = $audiences_map[ $booking_id ] ?? array();
			$out[]                = $booking;
		}
		return $out;
	}

	/**
	 * Companion to {@see self::find_user_bookings()} — same JOIN shape,
	 * same `filter` semantics, but returns `COUNT(DISTINCT b.id)` and
	 * skips the audience batch-load. Issue #343 group B.
	 *
	 * @since 6.6.2
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $filter  Optional filter (same shape as find_user_bookings).
	 * @return int
	 */
	public static function count_user_bookings( int $user_id, array $filter = array() ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		[ $where_extra, $where_args ] = self::booking_filter_clauses( $filter );

		global $wpdb;
		$bookings_table          = $wpdb->prefix . 'ffc_audience_bookings';
		$users_table             = $wpdb->prefix . 'ffc_audience_booking_users';
		$booking_audiences_table = $wpdb->prefix . 'ffc_audience_booking_audiences';
		$members_table           = $wpdb->prefix . 'ffc_audience_members';

		$sql = 'SELECT COUNT(DISTINCT b.id)
			FROM %i b
			LEFT JOIN %i bu ON b.id = bu.booking_id
			LEFT JOIN %i ba ON b.id = ba.booking_id
			LEFT JOIN %i am ON ba.audience_id = am.audience_id
			WHERE (bu.user_id = %d OR am.user_id = %d)'
			. $where_extra;

		$args = array_merge(
			array(
				$bookings_table,
				$users_table,
				$booking_audiences_table,
				$members_table,
				$user_id,
				$user_id,
			),
			$where_args
		);

		$count = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		return null === $count ? 0 : (int) $count;
	}

	/**
	 * Translate the public `find_user_bookings` / `count_user_bookings`
	 * filter array into `[where_sql_fragment, where_args]`. Returns an
	 * empty pair when no filter keys are set — the SQL fragment is
	 * concatenated directly into the outer query.
	 *
	 * @param array<string, mixed> $filter Filter dict.
	 * @return array{0: string, 1: list<scalar>}
	 */
	private static function booking_filter_clauses( array $filter ): array {
		$sql  = '';
		$args = array();

		$start_date     = self::filter_string( $filter, 'start_date' );
		$end_date       = self::filter_string( $filter, 'end_date' );
		$exclude_status = self::filter_string( $filter, 'exclude_status' );

		if ( '' !== $start_date ) {
			$sql   .= ' AND b.booking_date >= %s';
			$args[] = $start_date;
		}
		if ( '' !== $end_date ) {
			$sql   .= ' AND b.booking_date <= %s';
			$args[] = $end_date;
		}
		if ( '' !== $exclude_status ) {
			$sql   .= ' AND b.status != %s';
			$args[] = $exclude_status;
		}
		return array( $sql, $args );
	}

	/**
	 * Read one filter key as a string, or `''` when it is not usable.
	 *
	 * The filter dict is caller-supplied, so a value is only known to be
	 * `mixed`. Casting it straight to string turned an array into the literal
	 * `'Array'` and bound that into the query — a filter that silently matches
	 * nothing rather than failing.
	 *
	 * @param array<string, mixed> $filter Filter dict.
	 * @param string               $key    Key to read.
	 * @return string
	 */
	private static function filter_string( array $filter, string $key ): string {
		$value = $filter[ $key ] ?? null;

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Batch-load the audience badges (name + color) tied to every
	 * booking in `$booking_ids`, keyed by booking_id. One query.
	 * Avoids the N+1 pattern of looping the bookings and fetching
	 * badges per-row.
	 *
	 * @param array  $booking_ids             Booking IDs to look up.
	 * @phpstan-param list<int> $booking_ids
	 * @param string $booking_audiences_table Junction table name.
	 * @param string $audiences_table         Audiences table name.
	 * @return array<int, list<array{name: string, color: string}>>
	 */
	private static function batch_load_audiences_for_bookings( array $booking_ids, string $booking_audiences_table, string $audiences_table ): array {
		if ( empty( $booking_ids ) ) {
			return array();
		}

		global $wpdb;
		$safe_ids = array_map( 'absint', $booking_ids );
		$id_list  = implode( ',', $safe_ids );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ba.booking_id, a.name, a.color
				FROM %i ba
				INNER JOIN %i a ON ba.audience_id = a.id
				WHERE ba.booking_id IN ({$id_list})",
				$booking_audiences_table,
				$audiences_table
			),
			ARRAY_A
		);

		/**
		 * Rows as `$wpdb` hands them back, checked against the SELECT above.
		 *
		 * @var list<BookingAudienceBadgeRow>|null $rows
		 */
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $aud ) {
				$out[ (int) $aud['booking_id'] ][] = array(
					'name'  => $aud['name'],
					'color' => (string) ( $aud['color'] ?? '#2271b1' ),
				);
			}
		}
		return $out;
	}
}
