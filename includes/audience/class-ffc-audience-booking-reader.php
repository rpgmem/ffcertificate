<?php
/**
 * Audience Booking Reader
 *
 * Read-side of the audience-booking repository split (#563 backlog, A6). Holds
 * every SELECT / lookup / conflict-query and the read-only aggregation helpers.
 * Writes live in {@see AudienceBookingWriter}; {@see AudienceBookingRepository}
 * remains the public façade that delegates to both.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
/**
 * Read queries for audience booking records.
 *
 * @since 6.11.3
 *
 * @phpstan-import-type BookingRow from AudienceBookingRepository
 */
class AudienceBookingReader {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see AudienceBookingWriter::cache_group()} so writes invalidate
	 * the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_audience_bookings';
	}

	/**
	 * Get bookings table name
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_audience_bookings';
	}

	/**
	 * Get booking audiences table name
	 *
	 * @return string
	 */
	public static function get_booking_audiences_table_name(): string {
		return self::db()->prefix . 'ffc_audience_booking_audiences';
	}

	/**
	 * Get booking users table name
	 *
	 * @return string
	 */
	public static function get_booking_users_table_name(): string {
		return self::db()->prefix . 'ffc_audience_booking_users';
	}

	/**
	 * Get all bookings
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return list<BookingRow>
	 */
	public static function get_all( array $args = array() ): array {
		$wpdb      = self::db();
		$table     = self::get_table_name();
		$env_table = AudienceEnvironmentRepository::get_table_name();

		$defaults = array(
			'environment_id' => null,
			'schedule_id'    => null,
			'booking_date'   => null,
			'start_date'     => null,
			'end_date'       => null,
			'status'         => null,
			'booking_type'   => null,
			'created_by'     => null,
			'orderby'        => 'booking_date',
			'order'          => 'ASC',
			'limit'          => 0,
			'offset'         => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array();
		$values = array();

		if ( $args['environment_id'] ) {
			$where[]  = 'b.environment_id = %d';
			$values[] = $args['environment_id'];
		}

		if ( $args['schedule_id'] ) {
			$where[]  = 'e.schedule_id = %d';
			$values[] = $args['schedule_id'];
		}

		if ( $args['booking_date'] ) {
			$where[]  = 'b.booking_date = %s';
			$values[] = $args['booking_date'];
		}

		if ( $args['start_date'] ) {
			$where[]  = 'b.booking_date >= %s';
			$values[] = $args['start_date'];
		}

		if ( $args['end_date'] ) {
			$where[]  = 'b.booking_date <= %s';
			$values[] = $args['end_date'];
		}

		if ( $args['status'] ) {
			$where[]  = 'b.status = %s';
			$values[] = $args['status'];
		}

		if ( $args['booking_type'] ) {
			$where[]  = 'b.booking_type = %s';
			$values[] = $args['booking_type'];
		}

		if ( $args['created_by'] ) {
			$where[]  = 'b.created_by = %d';
			$values[] = $args['created_by'];
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$orderby_sanitized = sanitize_sql_orderby( 'b.' . $args['orderby'] . ' ' . $args['order'] );
		$orderby           = $orderby_sanitized ? $orderby_sanitized : 'b.booking_date ASC';
		$limit_clause      = $args['limit'] > 0 ? sprintf( 'LIMIT %d OFFSET %d', $args['limit'], $args['offset'] ) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT b.*, e.name as environment_name, e.schedule_id
                FROM %i b
                INNER JOIN %i e ON b.environment_id = e.id
                {$where_clause}
                ORDER BY {$orderby}, b.start_time ASC
                {$limit_clause}";

		$prepare_args = array_merge( array( $table, $env_table ), $values );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		/**
		 * Description.
		 *
		 * @phpstan-ignore-next-line argument.type
		 */
		$sql = $wpdb->prepare( $sql, $prepare_args );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $sql );
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<BookingRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get booking by ID
	 *
	 * Get by id.
	 *
	 * Get by id.
	 *
	 * Get by id.
	 *
	 * Get by id.
	 *
	 * Get by id.
	 *
	 * @param int $id Booking ID.
	 * @return BookingRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		$cached = static::cache_get( "id_{$id}" );
		if ( false !== $cached ) {
			return $cached;
		}

		$wpdb      = self::db();
		$table     = self::get_table_name();
		$env_table = AudienceEnvironmentRepository::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var BookingRow|null $booking
		 */
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT b.*, e.name as environment_name, e.schedule_id
                FROM %i b
                INNER JOIN %i e ON b.environment_id = e.id
                WHERE b.id = %d',
				$table,
				$env_table,
				$id
			)
		);

		if ( $booking ) {
			// Load related audiences and users.
			$booking->audiences = self::get_booking_audiences( $id );
			$booking->users     = self::get_booking_users( $id );
			static::cache_set( "id_{$id}", $booking );
		}

		return $booking;
	}

	/**
	 * Get bookings for a specific date and environment
	 *
	 * @param int         $environment_id Environment ID.
	 * @param string      $date Date (Y-m-d).
	 * @param string|null $status Optional status filter.
	 * @return list<BookingRow>
	 */
	public static function get_by_date( int $environment_id, string $date, ?string $status = null ): array {
		return self::get_all(
			array(
				'environment_id' => $environment_id,
				'booking_date'   => $date,
				'status'         => $status,
			)
		);
	}

	/**
	 * Get bookings for a date range
	 *
	 * @param int         $environment_id Environment ID.
	 * @param string      $start_date Start date (Y-m-d).
	 * @param string      $end_date End date (Y-m-d).
	 * @param string|null $status Optional status filter.
	 * @return list<BookingRow>
	 */
	public static function get_by_date_range( int $environment_id, string $start_date, string $end_date, ?string $status = null ): array {
		return self::get_all(
			array(
				'environment_id' => $environment_id,
				'start_date'     => $start_date,
				'end_date'       => $end_date,
				'status'         => $status,
			)
		);
	}

	/**
	 * Get bookings created by a user
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $args Additional query arguments.
	 * @return list<BookingRow>
	 */
	public static function get_by_creator( int $user_id, array $args = array() ): array {
		$args['created_by'] = $user_id;
		return self::get_all( $args );
	}

	/**
	 * Get bookings for a user (as participant, not creator)
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $args Additional query arguments.
	 * @return list<BookingRow>
	 */
	public static function get_by_participant( int $user_id, array $args = array() ): array {
		$wpdb            = self::db();
		$table           = self::get_table_name();
		$users_table     = self::get_booking_users_table_name();
		$audiences_table = self::get_booking_audiences_table_name();
		$members_table   = AudienceRepository::get_members_table_name();
		$env_table       = AudienceEnvironmentRepository::get_table_name();

		$defaults = array(
			'start_date' => null,
			'end_date'   => null,
			'status'     => null,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array();
		$values = array( $user_id, $user_id );

		if ( $args['start_date'] ) {
			$where[]  = 'b.booking_date >= %s';
			$values[] = $args['start_date'];
		}

		if ( $args['end_date'] ) {
			$where[]  = 'b.booking_date <= %s';
			$values[] = $args['end_date'];
		}

		if ( $args['status'] ) {
			$where[]  = 'b.status = %s';
			$values[] = $args['status'];
		}

		$where_clause = ! empty( $where ) ? 'AND ' . implode( ' AND ', $where ) : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT b.*, e.name as environment_name, e.schedule_id
                FROM %i b
                INNER JOIN %i e ON b.environment_id = e.id
                LEFT JOIN %i bu ON b.id = bu.booking_id
                LEFT JOIN %i ba ON b.id = ba.booking_id
                LEFT JOIN %i am ON ba.audience_id = am.audience_id
                WHERE (bu.user_id = %d OR am.user_id = %d)
                {$where_clause}
                ORDER BY b.booking_date ASC, b.start_time ASC",
				array_merge( array( $table, $env_table, $users_table, $audiences_table, $members_table ), $values )
			)
		);
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<BookingRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get audiences for a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @return list<\stdClass>
	 */
	public static function get_booking_audiences( int $booking_id ): array {
		$wpdb            = self::db();
		$table           = self::get_booking_audiences_table_name();
		$audiences_table = AudienceRepository::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.* FROM %i a
                INNER JOIN %i ba ON a.id = ba.audience_id
                WHERE ba.booking_id = %d
                ORDER BY a.name ASC',
				$audiences_table,
				$table,
				$booking_id
			)
		);
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get users for a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<int> User IDs
	 */
	public static function get_booking_users( int $booking_id ): array {
		$wpdb  = self::db();
		$table = self::get_booking_users_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT user_id FROM %i WHERE booking_id = %d',
				$table,
				$booking_id
			)
		);

		return array_map( 'intval', $results );
	}

	/**
	 * Get all affected users for a booking (from audiences + individual users)
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<int> Unique user IDs
	 */
	public static function get_all_affected_users( int $booking_id ): array {
		$users = array();

		// Get directly added users.
		$direct_users = self::get_booking_users( $booking_id );
		$users        = array_merge( $users, $direct_users );

		// Get users from audiences.
		$audiences = self::get_booking_audiences( $booking_id );
		foreach ( $audiences as $audience ) {
			$audience_users = AudienceRepository::get_members( (int) $audience->id, true );
			$users          = array_merge( $users, $audience_users );
		}

		// Return unique user IDs.
		return array_unique( $users );
	}

	/**
	 * Check for time conflicts
	 *
	 * @param int      $environment_id Environment ID.
	 * @param string   $date Date (Y-m-d).
	 * @param string   $start_time Start time (H:i).
	 * @param string   $end_time End time (H:i).
	 * @param int|null $exclude_booking_id Booking ID to exclude (for updates).
	 * @return list<BookingRow> Conflicting bookings
	 */
	public static function get_conflicts( int $environment_id, string $date, string $start_time, string $end_time, ?int $exclude_booking_id = null ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$exclude_clause = $exclude_booking_id ? $wpdb->prepare( 'AND id != %d', $exclude_booking_id ) : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				/**
				 * Description.
				 *
				 * @phpstan-ignore-next-line argument.type
				 */
				"SELECT * FROM %i
                WHERE environment_id = %d
                AND booking_date = %s
                AND status = 'active'
                AND (
                    (start_time < %s AND end_time > %s) OR
                    (start_time >= %s AND start_time < %s) OR
                    (end_time > %s AND end_time <= %s)
                )
                {$exclude_clause}
                ORDER BY start_time ASC",
				$table,
				$environment_id,
				$date,
				$end_time,
				$start_time,
				$start_time,
				$end_time,
				$start_time,
				$end_time
			)
		);
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<BookingRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Check for user conflicts across environments
	 *
	 * Returns bookings that would affect the same users at the same time.
	 * When $scope_schedule_id is provided, only bookings in that schedule
	 * are considered (isolated-calendar mode).
	 *
	 * Get user conflicts.
	 *
	 * Get user conflicts.
	 *
	 * Get user conflicts.
	 *
	 * Get user conflicts.
	 *
	 * Get user conflicts.
	 *
	 * @param string     $date Date (Y-m-d).
	 * @param string     $start_time Start time (H:i).
	 * @param string     $end_time End time (H:i).
	 * @param array<int> $audience_ids Audience IDs to check.
	 * @param array<int> $user_ids Individual user IDs to check.
	 * @param int|null   $exclude_booking_id Booking ID to exclude.
	 * @param int|null   $scope_schedule_id When set, restrict to this schedule only.
	 * @return array{bookings: list<BookingRow>, affected_users: array<int>}
	 */
	public static function get_user_conflicts(
		string $date,
		string $start_time,
		string $end_time,
		array $audience_ids,
		array $user_ids,
		?int $exclude_booking_id = null,
		?int $scope_schedule_id = null
	): array {
		$wpdb          = self::db();
		$table         = self::get_table_name();
		$ba_table      = self::get_booking_audiences_table_name();
		$bu_table      = self::get_booking_users_table_name();
		$members_table = AudienceRepository::get_members_table_name();

		// Get all users that would be affected by this booking.
		$all_user_ids = $user_ids;
		foreach ( $audience_ids as $audience_id ) {
			$audience_users = AudienceRepository::get_members( (int) $audience_id, true );
			$all_user_ids   = array_merge( $all_user_ids, $audience_users );
		}
		$all_user_ids = array_unique( $all_user_ids );

		if ( empty( $all_user_ids ) ) {
			return array(
				'bookings'       => array(),
				'affected_users' => array(),
			);
		}

		$placeholders   = implode( ',', array_fill( 0, count( $all_user_ids ), '%d' ) );
		$exclude_clause = $exclude_booking_id ? $wpdb->prepare( 'AND b.id != %d', $exclude_booking_id ) : '';

		// Isolated schedule: JOIN environments and restrict to schedule.
		$env_join         = '';
		$env_where        = '';
		$env_join_tables  = array(); // %i table name for JOIN
		$env_where_values = array(); // %d schedule_id for WHERE
		if ( $scope_schedule_id ) {
			$env_table        = AudienceEnvironmentRepository::get_table_name();
			$env_join         = 'INNER JOIN %i env ON b.environment_id = env.id';
			$env_where        = 'AND env.schedule_id = %d';
			$env_join_tables  = array( $env_table );
			$env_where_values = array( $scope_schedule_id );
		}

		$values = array( $date, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time );
		$values = array_merge( $values, $all_user_ids, $all_user_ids );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conflicting_bookings_raw = $wpdb->get_results(
			$wpdb->prepare(
				/**
				 * Description.
				 *
				 * @phpstan-ignore-next-line argument.type
				 */
				"SELECT DISTINCT b.* FROM %i b
                LEFT JOIN %i ba ON b.id = ba.booking_id
                LEFT JOIN %i am ON ba.audience_id = am.audience_id
                LEFT JOIN %i bu ON b.id = bu.booking_id
                {$env_join}
                WHERE b.booking_date = %s
                AND b.status = 'active'
                AND (
                    (b.start_time < %s AND b.end_time > %s) OR
                    (b.start_time >= %s AND b.start_time < %s) OR
                    (b.end_time > %s AND b.end_time <= %s)
                )
                AND (am.user_id IN ({$placeholders}) OR bu.user_id IN ({$placeholders}))
                {$env_where}
                {$exclude_clause}
                ORDER BY b.start_time ASC",
				array_merge( array( $table, $ba_table, $members_table, $bu_table ), $env_join_tables, $values, $env_where_values )
			)
		);
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<BookingRow> $conflicting_bookings
		 */
		$conflicting_bookings = is_array( $conflicting_bookings_raw ) ? $conflicting_bookings_raw : array();

		// Find which specific users have conflicts.
		$affected_users = array();
		foreach ( $conflicting_bookings as $booking ) {
			$booking_users  = self::get_all_affected_users( (int) $booking->id );
			$conflicting    = array_intersect( $all_user_ids, $booking_users );
			$affected_users = array_merge( $affected_users, $conflicting );
		}
		$affected_users = array_unique( $affected_users );

		return array(
			'bookings'       => $conflicting_bookings,
			'affected_users' => $affected_users,
		);
	}

	/**
	 * Find bookings on the same date that include any of the given audience groups
	 *
	 * This is a "soft conflict" check — same audience group booked multiple times
	 * on the same day (regardless of time overlap).
	 * When $scope_schedule_id is provided, only bookings in that schedule
	 * are considered (isolated-calendar mode).
	 *
	 * Get audience same day bookings.
	 *
	 * Get audience same day bookings.
	 *
	 * Get audience same day bookings.
	 *
	 * Get audience same day bookings.
	 *
	 * Get audience same day bookings.
	 *
	 * @param string     $date Date (Y-m-d).
	 * @param array<int> $audience_ids Audience IDs to check.
	 * @param int|null   $exclude_booking_id Booking ID to exclude (for updates).
	 * @param int|null   $scope_schedule_id When set, restrict to this schedule only.
	 * @return list<BookingRow> Bookings with matched audience info
	 */
	public static function get_audience_same_day_bookings(
		string $date,
		array $audience_ids,
		?int $exclude_booking_id = null,
		?int $scope_schedule_id = null
	): array {
		$wpdb            = self::db();
		$table           = self::get_table_name();
		$ba_table        = self::get_booking_audiences_table_name();
		$audiences_table = AudienceRepository::get_table_name();

		if ( empty( $audience_ids ) ) {
			return array();
		}

		$placeholders   = implode( ',', array_fill( 0, count( $audience_ids ), '%d' ) );
		$exclude_clause = $exclude_booking_id ? $wpdb->prepare( 'AND b.id != %d', $exclude_booking_id ) : '';

		// Isolated schedule: JOIN environments and restrict to schedule.
		$env_join         = '';
		$env_where        = '';
		$env_join_tables  = array(); // %i table name for JOIN
		$env_where_values = array(); // %d schedule_id for WHERE
		if ( $scope_schedule_id ) {
			$env_table        = AudienceEnvironmentRepository::get_table_name();
			$env_join         = 'INNER JOIN %i env ON b.environment_id = env.id';
			$env_where        = 'AND env.schedule_id = %d';
			$env_join_tables  = array( $env_table );
			$env_where_values = array( $scope_schedule_id );
		}

		$values = array( $date );
		$values = array_merge( $values, $audience_ids );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				/**
				 * Description.
				 *
				 * @phpstan-ignore-next-line argument.type
				 */
				"SELECT b.id, b.start_time, b.end_time, b.description, a.name AS audience_name, ba.audience_id
                FROM %i b
                INNER JOIN %i ba ON b.id = ba.booking_id
                INNER JOIN %i a ON ba.audience_id = a.id
                {$env_join}
                WHERE b.booking_date = %s
                AND b.status = 'active'
                AND ba.audience_id IN ({$placeholders})
                {$env_where}
                {$exclude_clause}
                ORDER BY a.name ASC, b.start_time ASC",
				array_merge( array( $table, $ba_table, $audiences_table ), $env_join_tables, $values, $env_where_values )
			)
		);
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<BookingRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count bookings
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return int
	 */
	public static function count( array $args = array() ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$where  = array();
		$values = array();

		if ( isset( $args['environment_id'] ) ) {
			$where[]  = 'environment_id = %d';
			$values[] = $args['environment_id'];
		}

		if ( isset( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( isset( $args['booking_date'] ) ) {
			$where[]  = 'booking_date = %s';
			$values[] = $args['booking_date'];
		}

		// `start_date` and `end_date` mirror `get_all()`'s window filter so
		// callers (e.g. AudienceAdminDashboard's "upcoming bookings" stat)
		// can ask for "bookings on or after today" without scanning every row.
		if ( isset( $args['start_date'] ) ) {
			$where[]  = 'booking_date >= %s';
			$values[] = $args['start_date'];
		}

		if ( isset( $args['end_date'] ) ) {
			$where[]  = 'booking_date <= %s';
			$values[] = $args['end_date'];
		}

		if ( isset( $args['created_by'] ) ) {
			$where[]  = 'created_by = %d';
			$values[] = $args['created_by'];
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM %i {$where_clause}";

		$prepare_args = array_merge( array( $table ), $values );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, $prepare_args );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}
}
