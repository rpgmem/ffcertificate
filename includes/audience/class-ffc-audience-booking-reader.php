<?php
/**
 * Audience Booking Reader
 *
 * Read-side of the audience-booking repository split (#563 backlog, A6). Holds
 * every SELECT / lookup / conflict-query and the read-only aggregation helpers.
 * Writes live in {@see AudienceBookingWriter}. Callers depend on this reader
 * (reads) and the writer (writes) directly; the delegating façade was retired
 * in #563 B3-A.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$where_clause}/{$exclude_clause}/{$env_join}/{$env_where}/{$limit_clause} are fragments assembled here and {$placeholders} is %d repeated to match the bound array — prepare() takes them as one argument, which is what ReplacementsWrongNumber trips on. {$orderby} comes from sanitize_sql_orderby(), {$tie_direction} from a two-way ternary and {$id} from an int cast.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Read queries for audience booking records.
 *
 * @since 6.12.0
 *
 * @phpstan-type BookingRow \stdClass&object{id: numeric-string, environment_id: numeric-string, booking_date: string, start_time: string, end_time: string, booking_type: string, description: string, status: string, created_by: numeric-string, created_at: numeric-string, cancelled_by: numeric-string|null, cancelled_at: numeric-string|null, cancellation_reason: string|null, series_id?: numeric-string|null, is_all_day?: numeric-string, end_date?: string|null, time_mode?: string|null, skipped_dates?: string|null, attendance_mode?: string|null, attendance_min_percent?: numeric-string|null, post_event_custom?: numeric-string|null, post_event_form_id?: numeric-string|null, post_event_window_days?: numeric-string|null, post_event_attendees_only?: numeric-string|null, environment_name?: string|null, schedule_id?: numeric-string|null, audience_name?: string, audience_id?: numeric-string, title?: string, audiences?: array<int, mixed>, users?: array<int, int>}
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
			'schedule_ids'   => null,
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

		list( $where, $values ) = self::list_filters( $args );

		// A list of calendars the caller may read (#2243): filtered here, so
		// a month read never fetches the rows of a calendar it then drops.
		// An empty list matches nothing.
		if ( is_array( $args['schedule_ids'] ) ) {
			$schedule_ids = array();
			foreach ( $args['schedule_ids'] as $schedule_id ) {
				if ( is_numeric( $schedule_id ) ) {
					$schedule_ids[ (int) $schedule_id ] = (int) $schedule_id;
				}
			}
			$schedule_ids = array_values( $schedule_ids );
			if ( empty( $schedule_ids ) ) {
				return array();
			}
			$where[] = 'e.schedule_id IN (' . implode( ', ', array_fill( 0, count( $schedule_ids ), '%d' ) ) . ')';
			$values  = array_merge( $values, $schedule_ids );
		}

		if ( $args['booking_date'] ) {
			list( $day_sql, $day_args ) = AudienceBookingRules::occupies_day_clause( (string) $args['booking_date'], 'b.' );
			$where[]                    = $day_sql;
			$values                     = array_merge( $values, $day_args );
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
		// The time and the id break ties in the direction asked for, so a list
		// read newest first stays newest first inside a day (#2288).
		$tie_direction = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
		$limit_clause  = $args['limit'] > 0 ? sprintf( 'LIMIT %d OFFSET %d', $args['limit'], $args['offset'] ) : '';

		$sql = "SELECT b.*, e.name as environment_name, e.schedule_id
                FROM %i b
                INNER JOIN %i e ON b.environment_id = e.id
                {$where_clause}
                ORDER BY {$orderby}, b.start_time {$tie_direction}, b.id {$tie_direction}
                {$limit_clause}";

		$prepare_args = array_merge( array( $table, $env_table ), $values );
		/**
		 * Same shape as the other audience list readers: `{$where_clause}`,
		 * `{$orderby}` and `{$limit_clause}` are assembled at runtime, so the query is
		 * not `literal-string`. `$orderby` is a `sanitize_sql_orderby()` result — a
		 * runtime value by construction, and the check that makes it safe — the WHERE
		 * fragments are literals carrying placeholders, and both table names are bound
		 * with `%i`.
		 *
		 * @phpstan-ignore argument.type
		 */
		$sql = $wpdb->prepare( $sql, $prepare_args );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The argument is the string $wpdb->prepare() returned above; the sniff cannot follow a prepared string across an assignment.
		$results = $wpdb->get_results( $sql );
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<BookingRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count bookings under the filters {@see self::list_filters()} reads: the
	 * total of the bookings list's pages, of the batched CSV export, and of a
	 * calendar's bookings. It JOINs the environment like {@see self::get_all()},
	 * so a `schedule_id` filter counts what the list shows; the shared
	 * {@see self::count()} queries the bookings table alone and cannot.
	 *
	 * @since 6.17.0
	 * @param array<string, mixed> $args Filters ({@see self::list_filters()}).
	 * @return int
	 */
	public static function count_filtered( array $args = array() ): int {
		$wpdb      = self::db();
		$table     = self::get_table_name();
		$env_table = AudienceEnvironmentRepository::get_table_name();

		list( $where, $values ) = self::list_filters( $args );

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$sql = "SELECT COUNT(*)
                FROM %i b
                INNER JOIN %i e ON b.environment_id = e.id
                {$where_clause}";

		$prepare_args = array_merge( array( $table, $env_table ), $values );
		$sql          = $wpdb->prepare( $sql, $prepare_args );

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Keyset page for the batched CSV export: rows with `b.id < $cursor`, newest
	 * first (`b.id DESC`), limited to `$size`. Keyset (not LIMIT/OFFSET) so paging
	 * stays stable across concurrent inserts during a long export. Filters +
	 * environment JOIN mirror {@see self::get_all()}.
	 *
	 * @since 6.17.0
	 * @param array<string, mixed> $args   Filters ({@see self::list_filters()}).
	 * @param int                  $cursor Exclusive upper-bound id (PHP_INT_MAX on the first page).
	 * @param int                  $size   Page size.
	 * @return list<BookingRow>
	 */
	public static function find_by_cursor( array $args, int $cursor, int $size ): array {
		$wpdb      = self::db();
		$table     = self::get_table_name();
		$env_table = AudienceEnvironmentRepository::get_table_name();

		list( $where, $values ) = self::list_filters( $args );
		array_unshift( $where, 'b.id < %d' );
		array_unshift( $values, $cursor );

		$where_clause = 'WHERE ' . implode( ' AND ', $where );

		$sql = "SELECT b.*, e.name as environment_name, e.schedule_id
                FROM %i b
                INNER JOIN %i e ON b.environment_id = e.id
                {$where_clause}
                ORDER BY b.id DESC
                LIMIT %d";

		$prepare_args = array_merge( array( $table, $env_table ), $values, array( $size ) );
		$sql          = $wpdb->prepare( $sql, $prepare_args );

		$results = $wpdb->get_results( $sql );
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<BookingRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * The filters the bookings list, its CSV export and its count share
	 * (#2243), so the three can never disagree about which rows match.
	 *
	 * `audience_id` keeps the bookings made for that audience; `search`
	 * matches the description, and a number matches the booking id too.
	 *
	 * @param array<string, mixed> $args environment_id / schedule_id / status / start_date / end_date / audience_id / search.
	 * @return array{0: list<literal-string>, 1: list<int|string>} The WHERE fragments (literals with placeholders) and their values, in order.
	 */
	private static function list_filters( array $args ): array {
		$where  = array();
		$values = array();

		if ( ! empty( $args['environment_id'] ) ) {
			$where[]  = 'b.environment_id = %d';
			$values[] = \FreeFormCertificate\Core\ArrayValue::int( $args, 'environment_id' );
		}
		if ( ! empty( $args['schedule_id'] ) ) {
			$where[]  = 'e.schedule_id = %d';
			$values[] = \FreeFormCertificate\Core\ArrayValue::int( $args, 'schedule_id' );
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'b.status = %s';
			$values[] = \FreeFormCertificate\Core\ArrayValue::string( $args, 'status' );
		}
		if ( ! empty( $args['start_date'] ) ) {
			$where[]  = 'COALESCE(b.end_date, b.booking_date) >= %s';
			$values[] = \FreeFormCertificate\Core\ArrayValue::string( $args, 'start_date' );
		}
		if ( ! empty( $args['end_date'] ) ) {
			$where[]  = 'b.booking_date <= %s';
			$values[] = \FreeFormCertificate\Core\ArrayValue::string( $args, 'end_date' );
		}
		if ( ! empty( $args['audience_id'] ) ) {
			$where[]  = 'EXISTS (SELECT 1 FROM %i ba WHERE ba.booking_id = b.id AND ba.audience_id = %d)';
			$values[] = self::get_booking_audiences_table_name();
			$values[] = \FreeFormCertificate\Core\ArrayValue::int( $args, 'audience_id' );
		}
		$search = trim( \FreeFormCertificate\Core\ArrayValue::string( $args, 'search' ) );
		if ( '' !== $search ) {
			$like = '%' . self::db()->esc_like( $search ) . '%';
			if ( ctype_digit( $search ) ) {
				$where[]  = '(b.id = %d OR b.description LIKE %s)';
				$values[] = (int) $search;
			} else {
				$where[] = 'b.description LIKE %s';
			}
			$values[] = $like;
		}

		return array( $where, $values );
	}

	/**
	 * Get booking by ID
	 *
	 * @param int $id Booking ID.
	 * @return BookingRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		/**
		 * The object cache is untyped — `wp_cache_get()` returns mixed — and
		 * this key is the one written a few lines below, so the assertion is
		 * checkable against the `cache_set()` in the same method. It is stated
		 * per key rather than on the trait's `cache_get()` because the cache
		 * is heterogeneous: this class also stores counts and lists under
		 * other keys, and one type on the accessor would be a lie for those.
		 *
		 * @var BookingRow|false $cached
		 */
		$cached = static::cache_get( "id_{$id}" );
		if ( false !== $cached ) {
			return $cached;
		}

		$wpdb      = self::db();
		$table     = self::get_table_name();
		$env_table = AudienceEnvironmentRepository::get_table_name();

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
	 * The active occurrences of a weekly series, first date first (#2243).
	 *
	 * @param int $series_id The id of the series' first booking.
	 * @return list<object{id: numeric-string, booking_date: string, start_time: string, end_time: string}>
	 */
	public static function get_series( int $series_id ): array {
		if ( $series_id <= 0 ) {
			return array();
		}

		$wpdb = self::db();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<object{id: numeric-string, booking_date: string, start_time: string, end_time: string}>|null $rows
		 */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, booking_date, start_time, end_time FROM %i WHERE series_id = %d AND status = 'active' ORDER BY booking_date ASC, id ASC",
				self::get_table_name(),
				$series_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get bookings for a specific date and environment
	 *
	 * @param int         $environment_id Environment ID.
	 * @param string      $date Date (Y-m-d).
	 * @param string|null $status Optional status filter.
	 * @return list<BookingRow>
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function get_by_date( int $environment_id, string $date, ?string $status = null ): array {
		_deprecated_function( __METHOD__, '6.43.0' );

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
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function get_by_date_range( int $environment_id, string $start_date, string $end_date, ?string $status = null ): array {
		_deprecated_function( __METHOD__, '6.43.0' );

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
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function get_by_creator( int $user_id, array $args = array() ): array {
		_deprecated_function( __METHOD__, '6.43.0' );

		$args['created_by'] = $user_id;
		return self::get_all( $args );
	}

	/**
	 * Get bookings for a user (as participant, not creator)
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $args Additional query arguments.
	 * @return list<BookingRow>
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function get_by_participant( int $user_id, array $args = array() ): array {
		_deprecated_function( __METHOD__, '6.43.0' );

		$wpdb            = self::db();
		$table           = self::get_table_name();
		$users_table     = self::get_booking_users_table_name();
		$audiences_table = self::get_booking_audiences_table_name();
		$members_table   = AudienceReader::get_members_table_name();
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
			$where[]  = 'COALESCE(b.end_date, b.booking_date) >= %s';
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
		$audiences_table = AudienceReader::get_table_name();

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
	 * The audiences of several bookings, read in one query (#2243).
	 *
	 * The month read used to run one query per booking.
	 *
	 * @param array<int> $booking_ids Booking IDs.
	 * @return array<int, list<\stdClass>> Audiences keyed by booking ID; a booking with none is absent.
	 */
	public static function get_audiences_for_bookings( array $booking_ids ): array {
		$booking_ids = array_values( array_unique( array_map( 'intval', $booking_ids ) ) );
		if ( empty( $booking_ids ) ) {
			return array();
		}

		$wpdb         = self::db();
		$placeholders = implode( ', ', array_fill( 0, count( $booking_ids ), '%d' ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, ba.booking_id AS ffc_booking_id FROM %i a
                INNER JOIN %i ba ON a.id = ba.audience_id
                WHERE ba.booking_id IN ({$placeholders})
                ORDER BY a.name ASC",
				...array_merge( array( AudienceReader::get_table_name(), self::get_booking_audiences_table_name() ), $booking_ids )
			)
		);

		$by_booking = array();
		foreach ( is_array( $results ) ? $results : array() as $row ) {
			$booking_id = (int) ( $row->ffc_booking_id ?? 0 );
			unset( $row->ffc_booking_id );
			$by_booking[ $booking_id ][] = $row;
		}

		return $by_booking;
	}

	/**
	 * How many bookings, of any status or date, name any of these audiences (#2243).
	 *
	 * @param array<int> $audience_ids Audience IDs.
	 * @return int
	 */
	public static function count_for_audiences( array $audience_ids ): int {
		$audience_ids = array_values( array_unique( array_map( 'intval', $audience_ids ) ) );
		if ( empty( $audience_ids ) ) {
			return 0;
		}

		$wpdb         = self::db();
		$placeholders = implode( ', ', array_fill( 0, count( $audience_ids ), '%d' ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT booking_id) FROM %i WHERE audience_id IN ({$placeholders})",
				...array_merge( array( self::get_booking_audiences_table_name() ), $audience_ids )
			)
		);
	}

	/**
	 * How many bookings name an audience or one of its groups (#2243): what
	 * deleting it would take from, since a delete takes the groups too.
	 *
	 * @param int $audience_id Audience ID.
	 * @return int
	 */
	public static function count_for_audience_tree( int $audience_id ): int {
		return self::count_for_audiences( array_merge( array( $audience_id ), AudienceReader::get_descendant_ids( $audience_id ) ) );
	}

	/**
	 * Whether a booking exists and is cancelled.
	 *
	 * Read after a cancel that changed no row, to tell a request that lost
	 * the race to another one from a write the database refused (#2243).
	 *
	 * @param int $id Booking ID.
	 * @return bool
	 */
	public static function is_cancelled( int $id ): bool {
		$booking = self::get_by_id( $id );

		return null !== $booking && 'cancelled' === ( $booking->status ?? '' );
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
	 * Whether an account is a custom user of any booking, whatever its status.
	 *
	 * The booking details list those people, so the account-email reveal
	 * serves them too (#2243).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_participant_of_any( int $user_id ): bool {
		$wpdb  = self::db();
		$table = self::get_booking_users_table_name();

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE user_id = %d LIMIT 1',
				$table,
				$user_id
			)
		);

		return null !== $found;
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
			$audience_users = AudienceReader::get_members( (int) $audience->id, true );
			$users          = array_merge( $users, $audience_users );
		}

		// Return unique user IDs.
		return array_unique( $users );
	}

	/**
	 * The active bookings whose last day falls in a range (#1948).
	 *
	 * The post-event invitation scan reads it: a booking's form opens when its
	 * last day ends, so only bookings that ended recently can owe invitations.
	 *
	 * The last day is `end_date` for a range and `booking_date` for one day.
	 * It is read as those two halves rather than `COALESCE()`, which no index
	 * can serve: `(end_date, booking_date)` takes the range of `end_date`, and
	 * a NULL `end_date` with the range of `booking_date` (#2243).
	 *
	 * @param string $from First last-day to include (Y-m-d).
	 * @param string $to   Last last-day to include (Y-m-d).
	 * @return list<int> Booking IDs, oldest end first.
	 */
	public static function ended_between( string $from, string $to ): array {
		$wpdb = self::db();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE status = 'active' AND ((end_date BETWEEN %s AND %s) OR (end_date IS NULL AND booking_date BETWEEN %s AND %s)) ORDER BY COALESCE(end_date, booking_date) ASC, id ASC",
				self::get_table_name(),
				$from,
				$to,
				$from,
				$to
			)
		);

		return array_map( 'intval', $ids );
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

		$exclude_clause             = $exclude_booking_id ? $wpdb->prepare( 'AND id != %d', $exclude_booking_id ) : '';
		list( $day_sql, $day_args ) = AudienceBookingRules::occupies_day_clause( $date );
		$args                       = array_merge( array( $table, $environment_id ), $day_args );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				/**
				 * The SQL is a literal except for `{$day_sql}`, a fixed predicate from
				 * `AudienceBookingRules::occupies_day_clause()`, and `{$exclude_clause}`, which is
				 * either '' or the output of `prepare( 'AND id != %d', … )`. Interpolating those
				 * prepared fragments is safe because they carry no unbound `%` left for the outer
				 * `prepare()` to re-process. The table name is bound with `%i` and every value is
				 * a placeholder.
				 *
				 * @phpstan-ignore argument.type
				 */
				"SELECT * FROM %i
                WHERE environment_id = %d
                AND {$day_sql}
                AND status = 'active'
                {$exclude_clause}
                ORDER BY start_time ASC",
				...$args
			)
		);
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<BookingRow>
		 */
		$rows = is_array( $results ) ? $results : array();

		// The hours are compared here, not in SQL: a span row's stored times
		// are not the hours it takes on a middle day.
		return AudienceBookingRules::overlapping_on_day( $rows, $date, $start_time, $end_time );
	}

	/**
	 * The JOIN and WHERE fragments that restrict a booking query by schedule.
	 *
	 * Both fragments are literals chosen here; the table name and the ids
	 * travel as placeholder values.
	 *
	 * @param int|null   $scope_schedule_id     Restrict to this schedule.
	 * @param int[]|null $readable_schedule_ids Restrict to these schedules (non-empty when set).
	 * @return array{0: string, 1: string, 2: list<string>, 3: list<int>} JOIN, WHERE, JOIN values, WHERE values.
	 */
	private static function schedule_scope_clause( ?int $scope_schedule_id, ?array $readable_schedule_ids ): array {
		$where        = '';
		$where_values = array();
		if ( $scope_schedule_id ) {
			$where        = 'AND env.schedule_id = %d';
			$where_values = array( $scope_schedule_id );
		}
		if ( null !== $readable_schedule_ids && array() !== $readable_schedule_ids ) {
			$ids          = array_values( array_map( 'intval', $readable_schedule_ids ) );
			$where       .= ' AND env.schedule_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$where_values = array_merge( $where_values, $ids );
		}
		if ( '' === $where ) {
			return array( '', '', array(), array() );
		}
		return array(
			'INNER JOIN %i env ON b.environment_id = env.id',
			$where,
			array( AudienceEnvironmentRepository::get_table_name() ),
			$where_values,
		);
	}

	/**
	 * Check for user conflicts across environments
	 *
	 * @param string     $date Date (Y-m-d).
	 * @param string     $start_time Start time (H:i).
	 * @param string     $end_time End time (H:i).
	 * @param array<int> $audience_ids Audience IDs to check.
	 * @param array<int> $user_ids Individual user IDs to check.
	 * @param int|null   $exclude_booking_id Booking ID to exclude.
	 * @param int|null   $scope_schedule_id When set, restrict to this schedule only.
	 * @param int[]|null $readable_schedule_ids When set, restrict to bookings in these schedules (#1861).
	 * @return array{bookings: list<BookingRow>, affected_users: array<int>}
	 */
	public static function get_user_conflicts(
		string $date,
		string $start_time,
		string $end_time,
		array $audience_ids,
		array $user_ids,
		?int $exclude_booking_id = null,
		?int $scope_schedule_id = null,
		?array $readable_schedule_ids = null
	): array {
		$wpdb          = self::db();
		$table         = self::get_table_name();
		$ba_table      = self::get_booking_audiences_table_name();
		$bu_table      = self::get_booking_users_table_name();
		$members_table = AudienceReader::get_members_table_name();

		// Get all users that would be affected by this booking.
		$all_user_ids = $user_ids;
		foreach ( $audience_ids as $audience_id ) {
			$audience_users = AudienceReader::get_members( (int) $audience_id, true );
			$all_user_ids   = array_merge( $all_user_ids, $audience_users );
		}
		$all_user_ids = array_unique( $all_user_ids );

		if ( empty( $all_user_ids ) || array() === $readable_schedule_ids ) {
			return array(
				'bookings'       => array(),
				'affected_users' => array(),
			);
		}

		$placeholders   = implode( ',', array_fill( 0, count( $all_user_ids ), '%d' ) );
		$exclude_clause = $exclude_booking_id ? $wpdb->prepare( 'AND b.id != %d', $exclude_booking_id ) : '';

		// Isolated schedule, or a caller who may read only some schedules:
		// JOIN environments and restrict by schedule.
		list( $env_join, $env_where, $env_join_tables, $env_where_values ) = self::schedule_scope_clause( $scope_schedule_id, $readable_schedule_ids );

		list( $day_sql, $day_args ) = AudienceBookingRules::occupies_day_clause( $date, 'b.' );
		$values                     = array_merge( $day_args, $all_user_ids, $all_user_ids );

		$conflicting_bookings_raw = $wpdb->get_results(
			$wpdb->prepare(
				/**
				 * The query interpolates `{$env_join}` / `{$env_where}` (empty, or literals
				 * selected by whether a schedule scope was given) plus the `%d` placeholder
				 * lists built with `implode` + `array_fill`, so it is a runtime string rather
				 * than `literal-string`. Every interpolated fragment is code-chosen — no
				 * request value reaches the SQL text; the ids behind the placeholders travel
				 * in `$values` and each table name is bound with `%i`.
				 *
				 * @phpstan-ignore argument.type
				 */
				"SELECT DISTINCT b.* FROM %i b
                LEFT JOIN %i ba ON b.id = ba.booking_id
                LEFT JOIN %i am ON ba.audience_id = am.audience_id
                LEFT JOIN %i bu ON b.id = bu.booking_id
                {$env_join}
                WHERE {$day_sql}
                AND b.status = 'active'
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

		// Hours compared per day, as in get_conflicts().
		$conflicting_bookings = AudienceBookingRules::overlapping_on_day( $conflicting_bookings, $date, $start_time, $end_time );

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
	 * @param string     $date Date (Y-m-d).
	 * @param array<int> $audience_ids Audience IDs to check.
	 * @param int|null   $exclude_booking_id Booking ID to exclude (for updates).
	 * @param int|null   $scope_schedule_id When set, restrict to this schedule only.
	 * @param int[]|null $readable_schedule_ids When set, restrict to bookings in these schedules (#1861).
	 * @return list<BookingRow> Bookings with matched audience info
	 */
	public static function get_audience_same_day_bookings(
		string $date,
		array $audience_ids,
		?int $exclude_booking_id = null,
		?int $scope_schedule_id = null,
		?array $readable_schedule_ids = null
	): array {
		$wpdb            = self::db();
		$table           = self::get_table_name();
		$ba_table        = self::get_booking_audiences_table_name();
		$audiences_table = AudienceReader::get_table_name();

		if ( empty( $audience_ids ) || array() === $readable_schedule_ids ) {
			return array();
		}

		$placeholders   = implode( ',', array_fill( 0, count( $audience_ids ), '%d' ) );
		$exclude_clause = $exclude_booking_id ? $wpdb->prepare( 'AND b.id != %d', $exclude_booking_id ) : '';

		// Isolated schedule, or a caller who may read only some schedules:
		// JOIN environments and restrict by schedule.
		list( $env_join, $env_where, $env_join_tables, $env_where_values ) = self::schedule_scope_clause( $scope_schedule_id, $readable_schedule_ids );

		list( $day_sql, $day_args ) = AudienceBookingRules::occupies_day_clause( $date, 'b.' );
		$values                     = array_merge( $day_args, $audience_ids );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				/**
				 * The query interpolates `{$env_join}` / `{$env_where}` (empty, or literals
				 * selected by whether a schedule scope was given) plus the `%d` placeholder
				 * lists built with `implode` + `array_fill`, so it is a runtime string rather
				 * than `literal-string`. Every interpolated fragment is code-chosen — no
				 * request value reaches the SQL text; the ids behind the placeholders travel
				 * in `$values` and each table name is bound with `%i`.
				 *
				 * @phpstan-ignore argument.type
				 */
				"SELECT b.id, b.start_time, b.end_time, b.description, a.name AS audience_name, ba.audience_id
                FROM %i b
                INNER JOIN %i ba ON b.id = ba.booking_id
                INNER JOIN %i a ON ba.audience_id = a.id
                {$env_join}
                WHERE {$day_sql}
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

		if ( isset( $args['booking_date'] ) && is_string( $args['booking_date'] ) ) {
			list( $day_sql, $day_args ) = AudienceBookingRules::occupies_day_clause( $args['booking_date'] );
			$where[]                    = $day_sql;
			$values                     = array_merge( $values, $day_args );
		}

		// `start_date` and `end_date` mirror `get_all()`'s window filter so
		// callers (e.g. AudienceAdminDashboard's "upcoming bookings" stat)
		// can ask for "bookings on or after today" without scanning every row.
		if ( isset( $args['start_date'] ) ) {
			$where[]  = 'COALESCE(end_date, booking_date) >= %s';
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

		$sql = "SELECT COUNT(*) FROM %i {$where_clause}";

		$prepare_args = array_merge( array( $table ), $values );
		/**
		 * The WHERE pieces are literal fragments; the day predicate comes from AudienceBookingRules::occupies_day_clause().
		 *
		 * @phpstan-ignore argument.type
		 */
		$sql = $wpdb->prepare( $sql, $prepare_args );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The argument is the string $wpdb->prepare() returned above; the sniff cannot follow a prepared string across an assignment.
		return (int) $wpdb->get_var( $sql );
	}
}
