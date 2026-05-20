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

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
/**
 * Stateless service. Public API mirrors what REST controllers used to
 * spell inline; the JOIN shape is the same — just centralized.
 */
final class AudienceQueryService {

	/**
	 * Count the user's active self-join audience memberships.
	 *
	 * A "self-join membership" is a row in `ffc_audience_members` whose
	 * audience meets BOTH `allow_self_join = 1` AND `parent_id IS NOT NULL`
	 * (only child audiences are joinable per the §self-join rules; this
	 * matches the gate `UserAudienceRestController::join_audience_group`
	 * uses to enforce the `MAX_SELF_JOIN_GROUPS` cap).
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence-style count gating a user-initiated POST; instantaneous decision.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i m
				INNER JOIN %i a ON a.id = m.audience_id
				WHERE m.user_id = %d AND a.allow_self_join = 1 AND a.parent_id IS NOT NULL',
				$members_table,
				$audiences_table,
				$user_id
			)
		);
		return null === $count ? 0 : (int) $count;
	}

	/**
	 * List every self-joinable, active audience with a per-row
	 * `is_member` boolean flag indicating whether the supplied user
	 * already belongs. Returned flat (no parent-child nesting) — the
	 * REST controller assembles the tree because that's a presentation
	 * concern.
	 *
	 * Each row carries: `id` (int), `name` (string), `color` (string),
	 * `parent_id` (?int — null for root audiences), `is_member` (bool).
	 *
	 * Issue #343 group B.
	 *
	 * @since 6.6.2
	 * @param int $user_id WordPress user ID.
	 * @return list<array{id: int, name: string, color: string, parent_id: ?int, is_member: bool}>
	 */
	public static function find_user_joinable_audiences( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		global $wpdb;
		$audiences_table = $wpdb->prefix . 'ffc_audiences';
		$members_table   = $wpdb->prefix . 'ffc_audience_members';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LEFT JOIN per-user; bounded by active+joinable audiences (small set).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.name, a.color, a.parent_id,
					CASE WHEN m.id IS NOT NULL THEN 1 ELSE 0 END AS is_member
				FROM %i a
				LEFT JOIN %i m ON m.audience_id = a.id AND m.user_id = %d
				WHERE a.allow_self_join = 1 AND a.status = 'active'
				ORDER BY a.name ASC",
				$audiences_table,
				$members_table,
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'        => (int) ( $row['id'] ?? 0 ),
				'name'      => (string) ( $row['name'] ?? '' ),
				'color'     => (string) ( $row['color'] ?? '' ),
				'parent_id' => empty( $row['parent_id'] ) ? null : (int) $row['parent_id'],
				'is_member' => 1 === (int) ( $row['is_member'] ?? 0 ),
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
	 * @return list<array<string, mixed>>
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Multi-table JOIN bounded by per-user filter on both sides.
		$bookings = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		if ( ! is_array( $bookings ) || empty( $bookings ) ) {
			return array();
		}

		$booking_ids = array();
		foreach ( $bookings as $b ) {
			$id = (int) ( $b['id'] ?? 0 );
			if ( $id > 0 ) {
				$booking_ids[] = $id;
			}
		}
		$audiences_map = self::batch_load_audiences_for_bookings( $booking_ids, $booking_audiences_table, $audience_names_table );

		$out = array();
		foreach ( $bookings as $booking ) {
			$booking_id           = (int) ( $booking['id'] ?? 0 );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded count over the same JOIN find_user_bookings uses.
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

		if ( ! empty( $filter['start_date'] ) ) {
			$sql   .= ' AND b.booking_date >= %s';
			$args[] = (string) $filter['start_date'];
		}
		if ( ! empty( $filter['end_date'] ) ) {
			$sql   .= ' AND b.booking_date <= %s';
			$args[] = (string) $filter['end_date'];
		}
		if ( ! empty( $filter['exclude_status'] ) ) {
			$sql   .= ' AND b.status != %s';
			$args[] = (string) $filter['exclude_status'];
		}
		return array( $sql, $args );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $id_list built from absint() values; identifiers bound via %i.
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

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $aud ) {
				$out[ (int) $aud['booking_id'] ][] = array(
					'name'  => (string) ( $aud['name'] ?? '' ),
					'color' => (string) ( $aud['color'] ?? '#2271b1' ),
				);
			}
		}
		return $out;
	}
}
