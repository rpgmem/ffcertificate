<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceRestController;
use FreeFormCertificate\Audience\AudienceBookingRules;
use FreeFormCertificate\Tests\Support\CallLog;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;

/**
 * Tests for AudienceRestController: route registration, permission checks,
 * get_bookings, create_booking, cancel_booking, and check_conflicts.
 *
 * The collaborators run for real: AudienceScheduleRepository,
 * AudienceEnvironmentRepository, AudienceBookingReader, AudienceBookingWriter,
 * AudienceBookingRules, AudienceReader, DateBlockingService,
 * WorkingHoursService, DateFormatter, SettingsReader and ActivityLog (read
 * through its write buffer). The `$wpdb` double below keeps the schedules,
 * permissions, environments, holidays and bookings a test stores, answers
 * each query by its SQL text and the values bound to it, and records every
 * query and every write. A query it does not know is recorded and fails the
 * test in assertPostConditions(), because check_conflicts() catches every
 * exception and would otherwise turn it into a 500.
 *
 * @covers \FreeFormCertificate\Audience\AudienceRestController
 */
class AudienceRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/** @var array<int, object> Schedule rows by id. */
	private array $schedules = array();

	/** @var list<object> Schedule permission rows. */
	private array $permissions = array();

	/** @var array<int, object> Environment rows by id. */
	private array $environments = array();

	/** @var list<object> Schedule holiday rows. */
	private array $holidays = array();

	/** @var array<int, object> Booking rows by id. */
	private array $bookings = array();

	/** @var array<int, object> Audience rows by id. */
	private array $audiences = array();

	/** @var array<int, list<int>> The audiences each user belongs to, by user id. */
	private array $members = array();

	/** @var array<int, array{date: string, description?: string}> The `ffc_global_holidays` option. */
	private array $global_holidays = array();

	/** @var list<string> Capabilities the current user holds. */
	private array $caps = array();

	/** @var array<int, list<string>> Capabilities by user id, for user_can(). */
	private array $user_caps = array();

	/** @var int The id the next booking insert receives. */
	private int $next_booking_id = 1;

	/** @var list<array<int, mixed>> Every prepare() call, as its argument list. */
	private array $prepared = array();

	/** @var list<string> The SQL of every read and query() call, in order. */
	private array $ran = array();

	/** @var list<array{0: string, 1: string, 2: mixed}> Every write, in order: kind, table, data. */
	private array $writes = array();

	/** @var bool Whether the database refuses the write to the bookings table. */
	private bool $refuse_booking_update = false;

	/** @var bool Whether another request cancels the booking just before this one's write. */
	private bool $cancelled_by_another_request = false;

	/** @var list<string> Queries the double did not recognise. */
	private array $unexpected = array();

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();

		$this->registered_routes = [];

		Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
			$this->registered_routes[] = array(
				'namespace' => $namespace,
				'route'     => $route,
				'args'      => $args,
			);
		});

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
		Functions\when( 'sanitize_text_field' )->alias( function( $str ) { return trim( strip_tags( (string) $str ) ); } );
		Functions\when( 'sanitize_textarea_field' )->alias( function( $str ) { return trim( strip_tags( (string) $str ) ); } );
		Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'user_can' )->alias( fn( $user, $cap ) => in_array( $cap, $this->user_caps[ (int) $user ] ?? array(), true ) );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					// The activity log is on; DateFormatter reads its default formats.
					return ActivityLogBuffer::ENABLED_SETTINGS;
				}
				if ( 'ffc_global_holidays' === $key ) {
					return $this->global_holidays;
				}
				return $default;
			}
		);

		Functions\when( 'current_time' )->justReturn( '2026-03-01' );
		Functions\when( 'get_userdata' )->alias( static fn( $id ) => (int) $id > 0 ? (object) array( 'ID' => (int) $id ) : false );

		// A booking's dates are rendered for the response (date_label): the
		// formatter reads the site settings and wp_date(), which these tests stub here.
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ): string => gmdate( $format, $ts ) );

		$this->caps            = array();
		$this->user_caps       = array();
		$this->environments    = array();
		$this->holidays        = array();
		$this->bookings        = array();
		$this->global_holidays = array();
		// Audience 1 exists and user 5, the usual booker, belongs to it.
		$this->audiences       = array( 1 => (object) array( 'id' => 1, 'name' => 'Teachers', 'color' => '#3788d8', 'parent_id' => null, 'status' => 'active' ) );
		$this->members         = array( 5 => array( 1 ) );
		$this->next_booking_id = 1;
		$this->prepared        = array();
		$this->ran             = array();
		$this->writes          = array();
		$this->unexpected      = array();

		// An active calendar with no advance limit, unless a test says otherwise,
		// on which user 5 may book.
		$this->schedules   = array(
			20 => (object) array(
				'id'                => 20,
				'name'              => 'Main',
				'status'            => 'active',
				'visibility'        => 'private',
				'future_days_limit' => null,
				'admin_bypass'      => 1,
				'is_isolated'       => 0,
			),
		);
		$this->permissions = array();
		$this->permit( 20, 5 );

		$this->install_wpdb();
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'The $wpdb double received a query it does not know.' );
	}

	/**
	 * The `$wpdb` double over the arrays above.
	 */
	private function install_wpdb(): void {
		$this->wpdb            = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix    = 'wp_';
		$this->wpdb->insert_id = 0;

		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $text ): string => addcslashes( $text, '_%\\' ) );
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn( $sql ) => $this->read_row( (string) $sql ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn( $sql ) => $this->read_var( (string) $sql ) );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn( $sql ) => $this->read_results( (string) $sql ) );
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn( $sql ) => $this->read_col( (string) $sql ) );
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = (string) $sql;
				if ( in_array( $sql, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
					$this->writes[] = array( 'query', '', $sql );
					return true;
				}
				if ( false !== strpos( (string) $sql, "SET status = 'cancelled'" ) ) {
					// AudienceBookingWriter::cancel(): only an active row changes.
					list( , $table, $by, $at, $reason, $id ) = $this->bound( (string) $sql );
					$data           = array(
						'status'              => 'cancelled',
						'cancelled_by'        => $by,
						'cancelled_at'        => $at,
						'cancellation_reason' => $reason,
					);
					$this->writes[] = array( 'update', $table, array( 'where' => array( 'id' => $id ), 'data' => $data ) );
					if ( $this->refuse_booking_update ) {
						return false;
					}
					$booking = $this->bookings[ (int) $id ] ?? null;
					if ( null !== $booking && $this->cancelled_by_another_request ) {
						$booking->status = 'cancelled';
					}
					if ( null === $booking || 'active' !== $booking->status ) {
						return 0;
					}
					foreach ( $data as $key => $value ) {
						$booking->{$key} = $value;
					}
					return 1;
				}
				return $this->unknown( 'query', (string) $sql );
			}
		);
		$this->wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				$this->writes[] = array( 'insert', $table, $data );
				if ( 'wp_ffc_audience_bookings' === $table ) {
					$id                    = $this->next_booking_id++;
					$this->bookings[ $id ] = (object) ( array( 'id' => $id ) + $data );
					$this->wpdb->insert_id = $id;
					return 1;
				}
				if ( in_array( $table, array( 'wp_ffc_audience_booking_audiences', 'wp_ffc_audience_booking_users' ), true ) ) {
					return 1;
				}
				return $this->unknown( 'insert', (string) $table );
			}
		);
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->writes[] = array( 'update', $table, array( 'where' => $where, 'data' => $data ) );
				$id             = (int) ( $where['id'] ?? 0 );
				if ( 'wp_ffc_audience_bookings' === $table && $this->refuse_booking_update ) {
					return false;
				}
				if ( 'wp_ffc_audience_bookings' === $table && isset( $this->bookings[ $id ] ) ) {
					foreach ( $data as $key => $value ) {
						$this->bookings[ $id ]->{$key} = $value;
					}
					return 1;
				}
				return $this->unknown( 'update', (string) $table );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdb;
	}

	/**
	 * Record a query the double does not know and stop the code that ran it.
	 *
	 * @return never
	 */
	private function unknown( string $method, string $sql ) {
		$this->unexpected[] = $method . '(): ' . $sql;
		throw new \LogicException( 'Unexpected ' . $method . '(): ' . $sql );
	}

	/**
	 * The prepare() argument list behind a prepared query string.
	 *
	 * @return array<int, mixed>
	 */
	private function bound( string $sql ): array {
		if ( preg_match( '/^Q(\d+):/', $sql, $match ) ) {
			return $this->prepared[ (int) $match[1] ] ?? array();
		}
		return array( $sql );
	}

	/**
	 * How many reads ran a query containing $needle.
	 */
	private function ranWith( string $needle ): int {
		return count( array_filter( $this->ran, static fn( string $sql ): bool => false !== strpos( $sql, $needle ) ) );
	}

	/**
	 * Every prepare() call whose SQL contains $needle.
	 *
	 * @return list<array<int, mixed>>
	 */
	private function preparedWith( string $needle ): array {
		return array_values( array_filter( $this->prepared, static fn( array $call ): bool => false !== strpos( (string) $call[0], $needle ) ) );
	}

	/**
	 * The `WHERE column op %x` conditions of a query, paired with their bound values.
	 *
	 * @param array<int, mixed> $bound The prepare() argument list.
	 * @return list<array{0: string, 1: string, 2: mixed}>
	 */
	private function conditions( array $bound ): array {
		$sql = (string) $bound[0];
		preg_match_all( '/(COALESCE\(b\.end_date, b\.booking_date\)|[a-z_.]+) (=|>=|<=|IN) (%[sd]|\((?:%d(?:, )?)+\))/', $sql, $found, PREG_SET_ORDER );
		$index = 1 + substr_count( $sql, '%i' );
		$out   = array();
		foreach ( $found as $condition ) {
			if ( 'IN' === $condition[2] ) {
				$count  = substr_count( $condition[3], '%d' );
				$out[]  = array( $condition[1], 'IN', array_slice( $bound, $index, $count ) );
				$index += $count;
				continue;
			}
			$out[] = array( $condition[1], $condition[2], $bound[ $index++ ] ?? null );
		}
		return $out;
	}

	/**
	 * Whether a row satisfies the conditions of a query.
	 *
	 * @param list<array{0: string, 1: string, 2: mixed}> $conditions From conditions().
	 */
	private function row_matches( object $row, array $conditions ): bool {
		foreach ( $conditions as list( $field, $op, $value ) ) {
			if ( 0 === strpos( $field, 'COALESCE' ) ) {
				$actual = (string) ( ( $row->end_date ?? '' ) ?: $row->booking_date );
			} else {
				$column = substr( (string) strrchr( '.' . $field, '.' ), 1 );
				$actual = (string) ( $row->{$column} ?? '' );
			}
			if ( 'IN' === $op ) {
				if ( ! in_array( (int) $actual, array_map( 'intval', (array) $value ), true ) ) {
					return false;
				}
				continue;
			}
			$ok = '=' === $op ? $actual === (string) $value : ( '>=' === $op ? $actual >= (string) $value : $actual <= (string) $value );
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A booking row as the readers' INNER JOIN on the environment returns it.
	 */
	private function joined( object $booking ): ?object {
		$environment = $this->environments[ (int) $booking->environment_id ] ?? null;
		if ( null === $environment ) {
			return null;
		}
		$row                   = clone $booking;
		$row->environment_name = $environment->name;
		$row->schedule_id      = $environment->schedule_id;
		return $row;
	}

	/**
	 * The active bookings of an environment that occupy a day.
	 *
	 * @return list<object>
	 */
	private function occupying( int $environment_id, string $date ): array {
		$rows = array();
		foreach ( $this->bookings as $booking ) {
			$last    = (string) ( ( $booking->end_date ?? '' ) ?: $booking->booking_date );
			$skipped = json_decode( (string) ( $booking->skipped_dates ?? '' ), true );
			if ( (int) $booking->environment_id === $environment_id
				&& 'active' === $booking->status
				&& $booking->booking_date <= $date && $last >= $date
				&& ! in_array( $date, is_array( $skipped ) ? $skipped : array(), true ) ) {
				$rows[] = clone $booking;
			}
		}
		return $rows;
	}

	private function read_row( string $sql ) {
		$this->ran[] = $sql;
		$bound       = $this->bound( $sql );
		if ( false !== strpos( $sql, 'SELECT * FROM %i WHERE id = %d' ) ) {
			$by_table = array(
				'wp_ffc_audience_schedules'    => $this->schedules,
				'wp_ffc_audience_environments' => $this->environments,
				'wp_ffc_audiences'             => $this->audiences,
			);
			if ( isset( $by_table[ $bound[1] ] ) ) {
				$row = $by_table[ $bound[1] ][ (int) $bound[2] ] ?? null;
				return null === $row ? null : clone $row;
			}
		}
		if ( false !== strpos( $sql, 'WHERE schedule_id = %d AND user_id = %d' ) ) {
			foreach ( $this->permissions as $permission ) {
				if ( (int) $permission->schedule_id === (int) $bound[2] && (int) $permission->user_id === (int) $bound[3] ) {
					return clone $permission;
				}
			}
			return null;
		}
		if ( false !== strpos( $sql, 'WHERE b.id = %d' ) ) {
			$booking = $this->bookings[ (int) $bound[3] ] ?? null;
			return null === $booking ? null : $this->joined( $booking );
		}
		return $this->unknown( 'get_row', $sql );
	}

	private function read_var( string $sql ) {
		$this->ran[] = $sql;
		$bound       = $this->bound( $sql );
		if ( false !== strpos( $sql, 'WHERE schedule_id = %d AND holiday_date = %s' ) ) {
			$count = 0;
			foreach ( $this->holidays as $holiday ) {
				if ( (int) $holiday->schedule_id === (int) $bound[2] && $holiday->holiday_date === $bound[3] ) {
					++$count;
				}
			}
			return (string) $count;
		}
		return $this->unknown( 'get_var', $sql );
	}

	private function read_results( string $sql ) {
		$this->ran[] = $sql;
		$bound       = $this->bound( $sql );
		if ( false !== strpos( $sql, 'SELECT DISTINCT s.*' ) ) {
			// AudienceScheduleRepository::get_by_user_access().
			$rows = array();
			foreach ( $this->schedules as $schedule ) {
				$permitted = false;
				foreach ( $this->permissions as $permission ) {
					$permitted = $permitted || ( (int) $permission->schedule_id === (int) $schedule->id && (int) $permission->user_id === (int) $bound[3] );
				}
				if ( 'active' === $schedule->status && ( 'public' === $schedule->visibility || $permitted ) ) {
					$rows[] = clone $schedule;
				}
			}
			usort( $rows, static fn( $a, $b ) => strcmp( $a->name, $b->name ) );
			return $rows;
		}
		if ( false !== strpos( $sql, 'SELECT b.*, e.name as environment_name' ) ) {
			// AudienceBookingReader::get_all().
			$rows = array();
			foreach ( $this->bookings as $booking ) {
				$row = $this->joined( $booking );
				if ( null !== $row && $this->row_matches( $row, $this->conditions( $bound ) ) ) {
					$rows[] = $row;
				}
			}
			return $rows;
		}
		if ( false !== strpos( $sql, 'WHERE m.user_id = %d' ) ) {
			// AudienceReader::get_user_audiences(): the user's own audiences.
			$rows = array();
			foreach ( $this->members[ (int) $bound[3] ] ?? array() as $audience_id ) {
				if ( isset( $this->audiences[ $audience_id ] ) ) {
					$rows[] = clone $this->audiences[ $audience_id ];
				}
			}
			return $rows;
		}
		if ( false !== strpos( $sql, 'WHERE ba.booking_id IN (' ) ) {
			// AudienceBookingReader::get_audiences_for_bookings(): none stored.
			return array();
		}
		if ( false !== strpos( $sql, 'WHERE ba.booking_id = %d' ) ) {
			// No booking here names an audience row of its own.
			return array();
		}
		if ( false !== strpos( $sql, 'FOR UPDATE' ) || false !== strpos( $sql, 'WHERE environment_id = %d' ) ) {
			// The writer's locking read and AudienceBookingReader::get_conflicts():
			// every active row of the environment that occupies the day.
			return $this->occupying( (int) $bound[2], (string) $bound[3] );
		}
		if ( false !== strpos( $sql, 'a.name AS audience_name' ) || false !== strpos( $sql, 'SELECT DISTINCT b.*' ) ) {
			// Same-day audience bookings and member conflicts: none stored.
			return array();
		}
		if ( 0 === strpos( preg_replace( '/^Q\d+:/', '', $sql ), 'SELECT * FROM %i' ) ) {
			$by_table = array(
				'wp_ffc_audience_schedules'    => $this->schedules,
				'wp_ffc_audience_environments' => $this->environments,
				'wp_ffc_audience_holidays'     => $this->holidays,
				// AudienceReader::get_children(): no audience here has children.
				'wp_ffc_audiences'             => array(),
			);
			if ( isset( $by_table[ $bound[1] ] ) ) {
				$conditions = $this->conditions( $bound );
				$rows       = array();
				foreach ( $by_table[ $bound[1] ] as $row ) {
					if ( $this->row_matches( $row, $conditions ) ) {
						$rows[] = clone $row;
					}
				}
				return $rows;
			}
		}
		return $this->unknown( 'get_results', $sql );
	}

	private function read_col( string $sql ) {
		$this->ran[] = $sql;
		if ( false !== strpos( $sql, 'SELECT user_id FROM %i WHERE booking_id = %d' ) ) {
			return array();
		}
		if ( false !== strpos( $sql, 'SELECT DISTINCT user_id FROM %i WHERE audience_id IN' ) ) {
			// AudienceReader::get_members(): the audiences here have no members.
			return array();
		}
		return $this->unknown( 'get_col', $sql );
	}

	/**
	 * Give a user a permission row on a schedule.
	 *
	 * @param array<string, int> $flags can_book, can_cancel_others, can_override_conflicts.
	 */
	private function permit( int $schedule_id, int $user_id, array $flags = array() ): void {
		$this->permissions[] = (object) array_merge(
			array(
				'schedule_id'            => $schedule_id,
				'user_id'                => $user_id,
				'can_book'               => 1,
				'can_cancel_others'      => 0,
				'can_override_conflicts' => 0,
			),
			$flags
		);
	}

	/**
	 * Store an environment.
	 *
	 * @param array<string, mixed>|null $hours Working hours, or null for none.
	 */
	private function environment( int $id, int $schedule_id, ?array $hours = null, string $name = 'Room' ): void {
		$this->environments[ $id ] = (object) array(
			'id'            => $id,
			'schedule_id'   => $schedule_id,
			'name'          => $name,
			'status'        => 'active',
			'working_hours' => null === $hours ? null : json_encode( $hours ),
		);
	}

	/**
	 * Store a booking.
	 *
	 * @param array<string, mixed> $extra Other columns.
	 */
	private function booking( int $id, int $environment_id, string $date, string $start, string $end, array $extra = array() ): void {
		$this->bookings[ $id ] = (object) array_merge(
			array(
				'id'             => $id,
				'environment_id' => $environment_id,
				'booking_date'   => $date,
				'end_date'       => null,
				'time_mode'      => null,
				'skipped_dates'  => null,
				'start_time'     => $start,
				'end_time'       => $end,
				'is_all_day'     => 0,
				'booking_type'   => 'audience',
				'description'    => 'A stored booking',
				'status'         => 'active',
				'created_by'     => 7,
			),
			$extra
		);
	}

	/**
	 * The writes of one kind to one table, as their data.
	 *
	 * @return list<mixed>
	 */
	private function written( string $kind, string $table ): array {
		$out = array();
		foreach ( $this->writes as list( $k, $t, $data ) ) {
			if ( $k === $kind && $t === $table ) {
				$out[] = $data;
			}
		}
		return $out;
	}

	/**
	 * Helper: create a mock WP_REST_Request with given params.
	 */
	private function make_request( array $params = [] ): object {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing( function( $key ) use ( $params ) {
			return $params[ $key ] ?? null;
		});
		return $request;
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	public function test_register_routes_creates_four_endpoints(): void {
		$ctrl = new AudienceRestController();
		$ctrl->register_routes();

		$this->assertCount( 4, $this->registered_routes );
	}

	public function test_bookings_get_route_registered(): void {
		$ctrl = new AudienceRestController();
		$ctrl->register_routes();

		$route = $this->registered_routes[0];
		$this->assertSame( '/audience/bookings', $route['route'] );
		$this->assertSame( array( $ctrl, 'check_read_permission' ), $route['args']['permission_callback'] );
	}

	public function test_bookings_post_route_registered(): void {
		$ctrl = new AudienceRestController();
		$ctrl->register_routes();

		$route = $this->registered_routes[1];
		$this->assertSame( '/audience/bookings', $route['route'] );
		$this->assertSame( array( $ctrl, 'check_write_permission' ), $route['args']['permission_callback'] );
	}

	public function test_bookings_delete_route_registered(): void {
		$ctrl = new AudienceRestController();
		$ctrl->register_routes();

		$route = $this->registered_routes[2];
		$this->assertStringContainsString( 'bookings', $route['route'] );
		$this->assertSame( array( $ctrl, 'check_cancel_permission' ), $route['args']['permission_callback'] );
	}

	public function test_conflicts_route_registered(): void {
		$ctrl = new AudienceRestController();
		$ctrl->register_routes();

		$route = $this->registered_routes[3];
		$this->assertSame( '/audience/conflicts', $route['route'] );
		$this->assertSame( array( $ctrl, 'check_conflicts_permission' ), $route['args']['permission_callback'] );
	}

	public function test_conflicts_permission_refuses_an_anonymous_caller(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->assertFalse( ( new AudienceRestController() )->check_conflicts_permission() );

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->assertTrue( ( new AudienceRestController() )->check_conflicts_permission() );
	}

	// ------------------------------------------------------------------
	// check_read_permission
	// ------------------------------------------------------------------

	public function test_check_read_permission_always_returns_true(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$ctrl = new AudienceRestController();
		$this->assertTrue( $ctrl->check_read_permission() );
	}

	public function test_check_read_permission_returns_true_when_logged_in(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$ctrl = new AudienceRestController();
		$this->assertTrue( $ctrl->check_read_permission() );
	}

	// ------------------------------------------------------------------
	// check_write_permission
	// ------------------------------------------------------------------

	public function test_check_write_permission_returns_false_when_not_logged_in(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$ctrl = new AudienceRestController();
		$this->assertFalse( $ctrl->check_write_permission() );
	}

	public function test_check_write_permission_returns_true_when_bypass(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->caps = array( 'ffc_bypass_audiences' );

		$ctrl = new AudienceRestController();
		$this->assertTrue( $ctrl->check_write_permission() );
	}

	public function test_check_write_permission_returns_true_when_has_capability(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		// The booking capability, without the audience bypass.
		$this->caps = array( 'ffc_view_own_audience_bookings' );

		$ctrl = new AudienceRestController();
		$this->assertTrue( $ctrl->check_write_permission() );
	}

	// ------------------------------------------------------------------
	// check_cancel_permission
	// ------------------------------------------------------------------

	public function test_check_cancel_permission_returns_false_when_not_logged_in(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array( 'id' => 1 ) );
		$this->assertFalse( $ctrl->check_cancel_permission( $request ) );
	}

	public function test_check_cancel_permission_returns_true_when_bypass(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->caps = array( 'ffc_bypass_audiences' );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array( 'id' => 1 ) );
		$this->assertTrue( $ctrl->check_cancel_permission( $request ) );
	}

	public function test_check_cancel_permission_returns_true_when_creator(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$this->environment( 10, 20 );
		$this->booking( 1, 10, '2026-04-01', '09:00', '10:00', array( 'created_by' => 5 ) );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array( 'id' => 1 ) );
		$this->assertTrue( $ctrl->check_cancel_permission( $request ) );
	}

	public function test_check_cancel_permission_returns_false_for_other_user(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 99 );

		$this->environment( 10, 20 );
		$this->booking( 1, 10, '2026-04-01', '09:00', '10:00', array( 'created_by' => 5 ) );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array( 'id' => 1 ) );
		$this->assertFalse( $ctrl->check_cancel_permission( $request ) );

		// The cancel-others permission was asked of the booking's schedule, for this user.
		$asked = $this->preparedWith( 'WHERE schedule_id = %d AND user_id = %d' );
		$this->assertCount( 1, $asked );
		$this->assertSame( array( 'wp_ffc_audience_schedule_permissions', 20, 99 ), array_slice( $asked[0], 1 ) );
	}

	// ------------------------------------------------------------------
	// get_bookings()
	// ------------------------------------------------------------------

	public function test_get_bookings_returns_formatted_bookings_with_holidays(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		// User 5 holds a permission on schedule 20 (the booking's owning schedule).

		$this->environment( 10, 20, null, 'Room A' );
		$this->booking(
			1,
			10,
			'2026-04-01',
			'09:00',
			'10:00',
			array(
				'description' => 'Team meeting',
				'created_by'  => 5,
			)
		);

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'start_date'     => '2026-04-01',
			'end_date'       => '2026-04-30',
			'schedule_id'    => null,
			'environment_id' => null,
		));

		$result = $ctrl->get_bookings( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );

		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertCount( 1, $data['bookings'] );
		$this->assertSame( 1, $data['bookings'][0]['id'] );
		$this->assertSame( 'Room A', $data['bookings'][0]['environment_name'] );
		$this->assertSame( 1, $this->ranWith( 'SELECT b.*, e.name as environment_name' ), 'The bookings are read once.' );
	}

	public function test_get_bookings_filters_out_bookings_from_unreadable_schedules(): void {
		// Anonymous caller: only active public schedules are readable.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		// Public schedule set = {20}; schedule 99 is private and must not leak.
		$this->schedules[20]->visibility = 'public';
		$this->schedules[99]             = (object) array(
			'id'         => 99,
			'name'       => 'Private',
			'status'     => 'active',
			'visibility' => 'private',
		);

		$this->environment( 10, 20, null, 'Public Room' );
		$this->environment( 11, 99, null, 'Private Room' );
		$this->booking( 1, 10, '2026-04-01', '09:00', '10:00', array( 'description' => 'Public' ) );
		$this->booking( 2, 11, '2026-04-02', '11:00', '12:00', array( 'description' => 'Secret', 'created_by' => 8 ) );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'start_date'     => '2026-04-01',
			'end_date'       => '2026-04-30',
			'schedule_id'    => null,
			'environment_id' => null,
		));

		$data = $ctrl->get_bookings( $request )->get_data();

		$this->assertCount( 1, $data['bookings'], 'Private-schedule booking must be filtered out' );
		$this->assertSame( 1, $data['bookings'][0]['id'] );
		$this->assertSame( 1, $this->ranWith( 'SELECT b.*, e.name as environment_name' ), 'The bookings are read once.' );
	}

	public function test_get_bookings_unrestricted_for_bypass_user(): void {
		// Bypass users read everything: readable_schedule_ids() returns null,
		// so no visibility filtering is applied (private schedule included).
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		$this->caps          = array( 'ffc_bypass_audiences' );
		$this->schedules[99] = (object) array(
			'id'         => 99,
			'name'       => 'Private',
			'status'     => 'active',
			'visibility' => 'private',
		);

		$this->environment( 11, 99, null, 'Private Room' );
		$this->booking( 2, 11, '2026-04-02', '11:00', '12:00', array( 'description' => 'Secret', 'created_by' => 8 ) );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'start_date'     => '2026-04-01',
			'end_date'       => '2026-04-30',
			'schedule_id'    => null,
			'environment_id' => null,
		));

		$data = $ctrl->get_bookings( $request )->get_data();

		$this->assertCount( 1, $data['bookings'] );
		$this->assertSame( 2, $data['bookings'][0]['id'] );
		$this->assertTrue( $data['bookings'][0]['can_cancel'], 'A bypass holder may cancel any booking.' );
	}

	public function test_check_conflicts_refuses_an_environment_on_an_unreadable_schedule(): void {
		// A caller probing an environment whose schedule (99) they may not
		// read is refused, exactly as for an environment that does not exist
		// (#1861) — no leak of times/descriptions.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->schedules[20]->visibility = 'public';
		$this->schedules[99]             = (object) array(
			'id'         => 99,
			'name'       => 'Private',
			'status'     => 'active',
			'visibility' => 'private',
		);

		$this->environment( 11, 99 );
		// A real environment conflict exists on the private schedule.
		$this->booking( 5, 11, '2026-04-01', '09:00', '10:00' );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 11,
			'booking_date'   => '2026-04-01',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
			'audience_ids'   => array(),
			'user_ids'       => array(),
		));

		$result = $ctrl->check_conflicts( $request );

		$this->assertSame( 404, $result->get_status() );
		$this->assertFalse( $result->get_data()['success'] );
		$this->assertArrayNotHasKey( 'conflicts', $result->get_data() );
		$this->assertSame( 0, $this->ranWith( 'WHERE environment_id = %d' ), 'The conflict query never runs.' );
	}

	public function test_check_conflicts_refuses_a_missing_environment(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );

		$result = ( new AudienceRestController() )->check_conflicts(
			$this->make_request(
				array(
					'environment_id' => 404,
					'booking_date'   => '2026-04-01',
					'start_time'     => '09:00',
					'end_time'       => '10:00',
					'audience_ids'   => array( 1 ),
					'user_ids'       => array( 2 ),
				)
			)
		);

		$this->assertSame( 404, $result->get_status() );
		$this->assertSame( 0, $this->ranWith( 'WHERE environment_id = %d' ), 'No environment conflict query runs.' );
		$this->assertSame( 0, $this->ranWith( 'SELECT DISTINCT b.*' ), 'No member conflict query runs.' );
	}

	public function test_check_conflicts_scopes_the_cross_schedule_queries_to_readable_schedules(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		// User 3 reads schedule 20 through a permission and schedule 21 because it is public.
		$this->permit( 20, 3 );
		$this->schedules[21] = (object) array(
			'id'         => 21,
			'name'       => 'Other',
			'status'     => 'active',
			'visibility' => 'public',
		);
		$this->environment( 11, 20 );

		$result = ( new AudienceRestController() )->check_conflicts(
			$this->make_request(
				array(
					'environment_id' => 11,
					'booking_date'   => '2026-04-01',
					'start_time'     => '09:00',
					'end_time'       => '10:00',
					'audience_ids'   => array( 1 ),
					'user_ids'       => array( 2 ),
				)
			)
		);

		$this->assertSame( 200, $result->get_status() );

		$same_day = $this->preparedWith( 'a.name AS audience_name' );
		$this->assertCount( 1, $same_day, 'The same-day audience query runs once.' );
		$this->assertStringContainsString( 'AND env.schedule_id IN (%d,%d)', $same_day[0][0] );
		$this->assertStringNotContainsString( 'AND env.schedule_id = %d', $same_day[0][0], 'The schedule is not isolated.' );
		$this->assertStringNotContainsString( 'b.id != %d', $same_day[0][0], 'No booking is excluded.' );
		$this->assertSame( '2026-04-01', $same_day[0][5] );
		$this->assertSame( array( 1, 20, 21 ), array_slice( $same_day[0], -3 ), 'The audience, then the readable schedules.' );

		$members = $this->preparedWith( 'SELECT DISTINCT b.*' );
		$this->assertCount( 1, $members, 'The member conflict query runs once.' );
		$this->assertStringContainsString( 'AND env.schedule_id IN (%d,%d)', $members[0][0] );
		$this->assertStringNotContainsString( 'b.id != %d', $members[0][0], 'No booking is excluded.' );
		$this->assertSame( array( 2, 2, 20, 21 ), array_slice( $members[0], -4 ), 'The user fills both IN() clauses, then the readable schedules.' );
	}

	// ------------------------------------------------------------------
	// create_booking()
	// ------------------------------------------------------------------

	public function test_create_booking_returns_400_when_environment_not_found(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 999,
			'booking_date'   => '2026-04-01',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
			'booking_type'   => 'audience',
			'description'    => 'A test booking description',
			'is_all_day'     => false,
			'audience_ids'   => array( 1 ),
			'user_ids'       => array(),
		));

		$result = $ctrl->create_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 400, $result->get_status() );
		$this->assertFalse( $result->get_data()['success'] );
	}

	public function test_create_booking_returns_403_when_no_permission(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->permissions = array();

		$this->environment( 10, 20 );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 10,
			'booking_date'   => '2026-04-01',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
			'booking_type'   => 'audience',
			'description'    => 'A test booking description',
			'is_all_day'     => false,
			'audience_ids'   => array( 1 ),
			'user_ids'       => array(),
		));

		$result = $ctrl->create_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 403, $result->get_status() );
		$asked = $this->preparedWith( 'WHERE schedule_id = %d AND user_id = %d' );
		$this->assertSame( array( 'wp_ffc_audience_schedule_permissions', 20, 5 ), array_slice( $asked[0], 1 ), 'The booking permission is asked of schedule 20 for user 5.' );
	}

	public function test_create_booking_returns_400_when_past_date(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_time' )->justReturn( '2026-04-01' );

		$this->environment( 10, 20 );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 10,
			'booking_date'   => '2025-01-01',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
			'booking_type'   => 'audience',
			'description'    => 'A test booking description',
			'is_all_day'     => false,
			'audience_ids'   => array( 1 ),
			'user_ids'       => array(),
		));

		$result = $ctrl->create_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'past', $result->get_data()['message'] );
	}

	public function test_create_booking_returns_400_when_invalid_time(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_time' )->justReturn( '2026-03-01' );

		$this->environment( 10, 20 );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 10,
			'booking_date'   => '2026-04-01',
			'start_time'     => '14:00',
			'end_time'       => '10:00',
			'booking_type'   => 'audience',
			'description'    => 'A test booking description',
			'is_all_day'     => false,
			'audience_ids'   => array( 1 ),
			'user_ids'       => array(),
		));

		$result = $ctrl->create_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'End time', $result->get_data()['message'] );
	}

	public function test_create_booking_returns_400_when_description_too_short(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_time' )->justReturn( '2026-03-01' );

		$this->environment( 10, 20 );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 10,
			'booking_date'   => '2026-04-01',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
			'booking_type'   => 'audience',
			'description'    => 'Short',
			'is_all_day'     => false,
			'audience_ids'   => array( 1 ),
			'user_ids'       => array(),
		));

		$result = $ctrl->create_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'Description', $result->get_data()['message'] );
	}

	public function test_create_booking_returns_400_when_audience_required(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_time' )->justReturn( '2026-03-01' );

		$this->environment( 10, 20 );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 10,
			'booking_date'   => '2026-04-01',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
			'booking_type'   => 'audience',
			'description'    => 'A sufficiently long description for booking',
			'is_all_day'     => false,
			'audience_ids'   => array(),
			'user_ids'       => array(),
		));

		$result = $ctrl->create_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'audience', $result->get_data()['message'] );
	}

	public function test_create_booking_returns_400_when_conflicts_exist(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_time' )->justReturn( '2026-03-01' );

		$this->environment( 10, 20 );
		$this->booking( 99, 10, '2026-04-01', '09:00', '10:00' );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 10,
			'booking_date'   => '2026-04-01',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
			'booking_type'   => 'audience',
			'description'    => 'A sufficiently long description for booking',
			'is_all_day'     => false,
			'audience_ids'   => array( 1 ),
			'user_ids'       => array(),
		));

		$result = $ctrl->create_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'already booked', $result->get_data()['message'] );
		$conflict_query = $this->preparedWith( 'WHERE environment_id = %d' );
		$this->assertSame( array( 'wp_ffc_audience_bookings', 10, '2026-04-01', '2026-04-01' ), array_slice( $conflict_query[0], 1, 4 ) );
		$this->assertSame( array(), $this->writes, 'Nothing is written.' );
	}

	public function test_create_booking_returns_201_on_success(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_time' )->justReturn( '2026-03-01' );

		$this->environment( 10, 20 );
		$this->next_booking_id = 42;

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 10,
			'booking_date'   => '2026-04-01',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
			'booking_type'   => 'audience',
			'description'    => 'A sufficiently long description for booking',
			'is_all_day'     => false,
			'audience_ids'   => array( 1 ),
			'user_ids'       => array(),
		));

		$result = $ctrl->create_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
		$this->assertTrue( $result->get_data()['success'] );
		$this->assertSame( 42, $result->get_data()['booking_id'] );
		$this->assertSame( '2026-04-01', $this->bookings[42]->booking_date );
		$this->assertSame(
			array( array( 'booking_id' => 42, 'audience_id' => 1 ) ),
			$this->written( 'insert', 'wp_ffc_audience_booking_audiences' )
		);
	}

	// ------------------------------------------------------------------
	// cancel_booking()
	// ------------------------------------------------------------------

	public function test_cancel_booking_returns_400_when_reason_too_short(): void {
		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'id'     => 1,
			'reason' => 'Hi',
		));

		$result = $ctrl->cancel_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'reason', strtolower( $result->get_data()['message'] ) );
	}

	public function test_cancel_booking_returns_404_when_not_found(): void {
		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'id'     => 999,
			'reason' => 'Cannot attend the meeting anymore',
		));

		$result = $ctrl->cancel_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 404, $result->get_status() );
		$this->assertSame( 999, $this->preparedWith( 'WHERE b.id = %d' )[0][3] );
	}

	public function test_cancel_booking_returns_400_when_already_cancelled(): void {
		$this->environment( 10, 20 );
		$this->booking( 1, 10, '2026-04-01', '09:00', '10:00', array( 'status' => 'cancelled' ) );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'id'     => 1,
			'reason' => 'Cannot attend the meeting anymore',
		));

		$result = $ctrl->cancel_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'already cancelled', $result->get_data()['message'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written.' );
	}

	public function test_cancel_booking_returns_200_on_success(): void {
		$this->environment( 10, 20 );
		$this->booking( 1, 10, '2026-04-01', '09:00', '10:00' );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'id'     => 1,
			'reason' => 'Cannot attend the meeting anymore',
		));

		$result = $ctrl->cancel_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
		$this->assertTrue( $result->get_data()['success'] );

		$updates = $this->written( 'update', 'wp_ffc_audience_bookings' );
		$this->assertCount( 1, $updates );
		$this->assertSame( array( 'id' => 1 ), $updates[0]['where'] );
		$this->assertSame( 'cancelled', $updates[0]['data']['status'] );
		$this->assertSame( 'Cannot attend the meeting anymore', $updates[0]['data']['cancellation_reason'] );
	}

	/**
	 * A cancel the database refused answers 500 and does not fire the
	 * cancellation hook (#2183).
	 */
	public function test_cancel_booking_returns_500_when_the_database_refuses_the_write(): void {
		$this->environment( 10, 20 );
		$this->booking( 1, 10, '2026-04-01', '09:00', '10:00' );
		$this->refuse_booking_update = true;
		$actions                     = CallLog::record( 'do_action' );

		$result = ( new AudienceRestController() )->cancel_booking(
			$this->make_request(
				array(
					'id'     => 1,
					'reason' => 'Cannot attend the meeting anymore',
				)
			)
		);

		$this->assertSame( 500, $result->get_status() );
		$this->assertFalse( $result->get_data()['success'] );
		$this->assertCount( 1, $this->written( 'update', 'wp_ffc_audience_bookings' ) );
		$this->assertSame( 0, $actions->count() );
	}

	// ------------------------------------------------------------------
	// check_conflicts()
	// ------------------------------------------------------------------

	public function test_check_conflicts_returns_400_when_missing_params(): void {
		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 0,
			'booking_date'   => '',
			'start_time'     => '',
			'end_time'       => '',
		));

		$result = $ctrl->check_conflicts( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 400, $result->get_status() );
		$this->assertFalse( $result->get_data()['success'] );
	}

	public function test_check_conflicts_returns_success_with_no_conflicts(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		$this->permit( 20, 3 );
		$this->environment( 10, 20 );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 10,
			'booking_date'   => '2026-04-01',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
			'audience_ids'   => array(),
			'user_ids'       => array(),
		));

		$result = $ctrl->check_conflicts( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
		$this->assertTrue( $result->get_data()['success'] );
		$this->assertSame( 'none', $result->get_data()['conflicts']['type'] );
	}

	public function test_check_conflicts_checks_every_day_of_a_range_with_each_days_hours(): void {
		// The form's "Check" button used to send the first day only, so a range
		// that clashed on a later day was offered and then refused on submit.
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		$this->permit( 20, 3 );
		// No working hours: a span day between its ends takes 00:00-23:59.
		$this->environment( 10, 20 );

		// A span from 14:00 on day one to 10:00 on day two takes 14:00-23:59,
		// then 00:00-10:00. Only booking 7 falls inside those hours; 5 would
		// clash with 09:00-23:59 on day one and 8 with 00:00-23:59 on day two.
		$this->booking( 5, 10, '2026-04-01', '10:00', '13:00' );
		$this->booking( 7, 10, '2026-04-02', '08:00', '09:00' );
		$this->booking( 8, 10, '2026-04-02', '11:00', '12:00' );

		$result = ( new AudienceRestController() )->check_conflicts(
			$this->make_request(
				array(
					'environment_id' => 10,
					'booking_date'   => '2026-04-01',
					'end_date'       => '2026-04-02',
					'time_mode'      => 'span',
					'start_time'     => '14:00',
					'end_time'       => '10:00',
					'audience_ids'   => array(),
					'user_ids'       => array(),
				)
			)
		);

		$this->assertSame(
			array( '2026-04-01', '2026-04-02' ),
			array_map( static fn( array $call ) => $call[3], $this->preparedWith( 'WHERE environment_id = %d' ) ),
			'Every day of the range is checked.'
		);
		$this->assertSame( 'environment', $result->get_data()['conflicts']['type'] );
		$this->assertSame(
			array( 7 ),
			array_column( $result->get_data()['conflicts']['bookings'], 'id' ),
			'A span is checked from its start on the first day to its end on the last.'
		);
	}

	public function test_check_conflicts_refuses_an_invalid_date(): void {
		$result = ( new AudienceRestController() )->check_conflicts(
			$this->make_request(
				array(
					'environment_id' => 10,
					'booking_date'   => '2026-02-30',
					'start_time'     => '09:00',
					'end_time'       => '10:00',
				)
			)
		);

		$this->assertSame( 400, $result->get_status() );
	}

	public function test_check_conflicts_refuses_a_range_over_the_cap_as_the_create_step_does(): void {
		$result = ( new AudienceRestController() )->check_conflicts(
			$this->make_request(
				array(
					'environment_id' => 10,
					'booking_date'   => '2026-07-01',
					'end_date'       => '2026-07-31',
					'start_time'     => '09:00',
					'end_time'       => '10:00',
				)
			)
		);

		$this->assertSame( 400, $result->get_status() );
		$data = $result->get_data();
		$this->assertSame( 'range_too_long', $data['code'] );
		$this->assertStringContainsString( (string) \FreeFormCertificate\Audience\AudienceBookingRules::DEFAULT_MAX_RANGE_DAYS, $data['message'], 'The refusal names the limit, as the create step does (#2243).' );
	}

	public function test_the_range_cap_is_the_configured_setting(): void {
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( \FreeFormCertificate\Audience\AudienceBookingRules::MAX_RANGE_OPTION === $key ) {
					return '60';
				}
				return 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $default;
			}
		);
		$check = fn( string $end ) => ( new AudienceRestController() )->check_conflicts(
			$this->make_request(
				array(
					'environment_id' => 10,
					'booking_date'   => '2026-07-01',
					'end_date'       => $end,
					'start_time'     => '09:00',
					'end_time'       => '10:00',
				)
			)
		);

		// 60 days pass the cap and reach the environment lookup (absent here).
		$this->assertSame( 404, $check( '2026-08-29' )->get_status() );

		$refused = $check( '2026-08-30' );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'range_too_long', $refused->get_data()['code'] );
		$this->assertStringContainsString( '60', $refused->get_data()['message'] );
	}

	public function test_the_range_cap_defaults_to_30_and_stays_between_1_and_365(): void {
		$rules = \FreeFormCertificate\Audience\AudienceBookingRules::class;

		$this->assertSame( 30, $rules::max_range_days(), 'No setting saved: 30.' );
		$this->assertSame( 1, $rules::clamp_max_range( '0' ) );
		$this->assertSame( 365, $rules::clamp_max_range( 1000 ) );
		$this->assertSame( 30, $rules::clamp_max_range( 'abc' ), 'Not a number is not a limit.' );
		$this->assertSame( 120, $rules::clamp_max_range( '120' ) );
	}

	// ------------------------------------------------------------------
	// Booking rules for every caller (#1859)
	// ------------------------------------------------------------------

	/**
	 * A booking request for environment 10 on schedule 20, overridable.
	 *
	 * @param array<string, mixed> $overrides Params to change.
	 */
	private function booking_request( array $overrides = array() ): object {
		return $this->make_request(
			array_merge(
				array(
					'environment_id'   => 10,
					'booking_date'     => '2026-04-01',
					'start_time'       => '09:00',
					'end_time'         => '10:00',
					'booking_type'     => 'audience',
					'description'      => 'A sufficiently long description for booking',
					'is_all_day'       => false,
					'audience_ids'     => array( 1 ),
					'user_ids'         => array(),
					'confirm_override' => false,
				),
				$overrides
			)
		);
	}

	/**
	 * A caller on environment 10 (schedule 20) whose space opens 08:00-18:00
	 * every weekday and is closed at weekends.
	 */
	private function given_an_open_space(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$open = array( 'start' => '08:00', 'end' => '18:00', 'closed' => false );
		$this->environment(
			10,
			20,
			array(
				'sun' => array( 'start' => '08:00', 'end' => '18:00', 'closed' => true ),
				'mon' => $open,
				'tue' => $open,
				'wed' => $open,
				'thu' => $open,
				'fri' => $open,
				'sat' => array( 'start' => '08:00', 'end' => '18:00', 'closed' => true ),
			)
		);
	}

	/**
	 * User 5 holds the audience bypass capability, read through user_can().
	 */
	private function given_the_bypass_capability_for_user_5(): void {
		$this->user_caps[5] = array( 'ffc_bypass_audiences' );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
	 */
	public function rule_cases(): array {
		return array(
			'closed weekday (a Saturday)' => array( array( 'booking_date' => '2026-04-04' ), 'closed_day', 'closed' ),
			'start before opening'        => array( array( 'start_time' => '07:00', 'end_time' => '09:00' ), 'outside_hours', 'opening hours' ),
			'end after closing'           => array( array( 'start_time' => '17:00', 'end_time' => '19:00' ), 'outside_hours', 'opening hours' ),
			'past date'                   => array( array( 'booking_date' => '2026-02-27' ), 'past_date', 'past' ),
		);
	}

	/**
	 * @dataProvider rule_cases
	 * @param array<string, mixed> $params  Request changes.
	 * @param string               $code    Expected reason code.
	 * @param string               $snippet Part of the message.
	 */
	public function test_a_caller_without_the_bypass_is_refused_on_each_rule( array $params, string $code, string $snippet ): void {
		$this->given_an_open_space();

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( $params ) );

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( $code, $result->get_data()['code'] );
		$this->assertStringContainsString( $snippet, $result->get_data()['message'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written.' );
	}

	public function test_a_holiday_refuses_a_caller_without_the_bypass(): void {
		$this->given_an_open_space();
		$this->holidays[] = (object) array( 'schedule_id' => 20, 'holiday_date' => '2026-04-01', 'description' => 'Local' );

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request() );

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'holiday', $result->get_data()['code'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written.' );
	}

	public function test_a_global_holiday_refuses_a_caller_without_the_bypass(): void {
		$this->given_an_open_space();
		$this->global_holidays = array( array( 'date' => '2026-04-01', 'description' => 'National' ) );

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request() );

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'holiday', $result->get_data()['code'] );
	}

	public function test_the_advance_window_is_counted_from_the_site_date(): void {
		$this->given_an_open_space();
		// The site's date, whatever UTC says: a limit of 1 day from 2026-03-31
		// allows 2026-04-01 and refuses 2026-04-02.
		Functions\when( 'current_time' )->justReturn( '2026-03-31' );
		$this->schedules[20]->future_days_limit = '1';
		$this->schedules[20]->admin_bypass      = '1';

		$ctrl = new AudienceRestController();

		$inside = $ctrl->create_booking( $this->booking_request( array( 'booking_date' => '2026-04-01' ) ) );
		$this->assertSame( 201, $inside->get_status() );

		$beyond = $ctrl->create_booking( $this->booking_request( array( 'booking_date' => '2026-04-02' ) ) );
		$this->assertSame( 400, $beyond->get_status() );
		$this->assertSame( 'beyond_window', $beyond->get_data()['code'] );
		$this->assertCount( 1, $this->written( 'insert', 'wp_ffc_audience_bookings' ), 'Only the booking inside the window is written.' );
	}

	public function test_an_inactive_calendar_refuses_every_caller(): void {
		$this->given_an_open_space();
		$this->caps = array( 'ffc_bypass_audiences' );
		$this->given_the_bypass_capability_for_user_5();
		$this->schedules[20]->status = 'inactive';

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( array( 'confirm_override' => true ) ) );

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'inactive', $result->get_data()['code'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written.' );
	}

	public function test_an_all_day_booking_on_an_open_day_takes_the_opening_window(): void {
		$this->given_an_open_space();
		$this->next_booking_id = 77;

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'is_all_day' => true, 'start_time' => '00:00', 'end_time' => '23:59' ) )
		);

		$this->assertSame( 201, $result->get_status() );
		$created = $this->written( 'insert', 'wp_ffc_audience_bookings' );
		$this->assertCount( 1, $created );
		$this->assertSame( '08:00', $created[0]['start_time'] );
		$this->assertSame( '18:00', $created[0]['end_time'] );
		$this->assertSame( 1, $created[0]['is_all_day'] );
	}

	public function test_a_bypass_holder_without_the_confirmation_gets_every_reason_and_nothing_is_saved(): void {
		$this->given_an_open_space();
		// The bypass on schedule 20 also lets user 5 override a conflict.
		$this->given_the_bypass_capability_for_user_5();
		$this->holidays[] = (object) array( 'schedule_id' => 20, 'holiday_date' => '2026-04-01', 'description' => 'Local' );
		$this->booking( 99, 10, '2026-04-01', '17:00', '18:00', array( 'description' => 'Earlier' ) );

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'start_time' => '17:00', 'end_time' => '19:00' ) )
		);

		$this->assertSame( 409, $result->get_status() );
		$data = $result->get_data();
		$this->assertSame( 'override_confirmation_required', $data['code'] );
		$this->assertSame( array( 'holiday', 'outside_hours', 'conflict' ), array_column( $data['reasons'], 'code' ) );
		$this->assertSame( 99, $data['conflicts'][0]['id'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written, not even a transaction.' );
		$this->assertSame( 'active', $this->bookings[99]->status );
	}

	public function test_a_confirmed_override_is_saved_and_logged(): void {
		$this->given_an_open_space();
		$this->given_the_bypass_capability_for_user_5();
		$this->next_booking_id = 55;

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'booking_date' => '2026-04-04', 'confirm_override' => true ) )
		);

		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( 55, $result->get_data()['booking_id'] );
		$this->assertCount( 1, $this->written( 'insert', 'wp_ffc_audience_bookings' ) );

		$entries = ActivityLogBuffer::entries();
		$this->assertCount( 1, $entries, 'One activity-log line is written.' );
		$this->assertSame( 5, $entries[0]['user_id'] );
		$logged = ActivityLogBuffer::contexts()[0];
		$this->assertSame( 'audience_booking_override', $logged['action'] );
		$this->assertSame( 'warning', $logged['level'] );
		$this->assertSame( 55, $logged['context']['booking_id'] );
		$this->assertSame( array( 'closed_day' ), $logged['context']['reasons'] );
		$this->assertIsInt( $logged['context']['confirmed_at'] );
	}

	public function test_a_bypass_capability_with_the_calendar_toggle_off_gets_every_rule(): void {
		$this->given_an_open_space();
		// The capability is held, but the calendar's Admin bypass is off, so
		// the per-calendar answer is no.
		$this->caps = array( 'ffc_bypass_audiences' );
		$this->given_the_bypass_capability_for_user_5();
		$this->schedules[20]->admin_bypass = '0';

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'booking_date' => '2026-04-04', 'confirm_override' => true ) )
		);

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'closed_day', $result->get_data()['code'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written.' );
	}

	public function test_the_appointments_bypass_alone_does_not_bypass_audience_rules(): void {
		// `ffc_bypass_appointments` is not read by the audience controller at
		// all (#1859): the only bypass it asks about is the audience one, so a
		// holder of the personal-calendar bypass alone gets every rule.
		$this->caps         = array( 'ffc_bypass_appointments' );
		$this->user_caps[5] = array( 'ffc_bypass_appointments' );

		$this->given_an_open_space();
		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'booking_date' => '2026-04-04', 'confirm_override' => true ) )
		);

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'closed_day', $result->get_data()['code'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written.' );
	}

	public function test_a_confirmed_conflict_override_cancels_the_earlier_booking(): void {
		$this->given_an_open_space();
		$this->permissions = array();
		$this->permit( 20, 5, array( 'can_override_conflicts' => 1 ) );
		$this->booking( 99, 10, '2026-04-01', '09:00', '10:00', array( 'description' => 'Earlier' ) );
		$this->next_booking_id = 56;

		$actions = array();
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$actions ) {
				$actions[] = $args;
			}
		);

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request(
				array(
					'confirm_override' => true,
					'override_reason'  => 'The visiting committee needs the room.',
				)
			)
		);

		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( 'The visiting committee needs the room.', $this->bookings[99]->cancellation_reason, 'The operator\'s reason is what the owner reads.' );

		// The writer cancels the overridden booking inside the transaction that
		// inserts this one. Cancelling it after create() never happened,
		// because create() refused on it first.
		$this->assertSame( 'cancelled', $this->bookings[99]->status );
		$this->assertNotSame( '', $this->bookings[99]->cancellation_reason );
		$order = array_map(
			static function ( array $write ): string {
				if ( 'query' === $write[0] ) {
					return (string) $write[2];
				}
				return $write[0] . ' ' . $write[1] . ( 'update' === $write[0] ? ' #' . $write[2]['where']['id'] : '' );
			},
			$this->writes
		);
		$this->assertSame(
			array(
				'START TRANSACTION',
				'update wp_ffc_audience_bookings #99',
				'insert wp_ffc_audience_bookings',
				// The participants are written before the commit (#2243).
				'insert wp_ffc_audience_booking_audiences',
				'COMMIT',
			),
			$order
		);

		$overridden = array_values(
			array_filter(
				$actions,
				static fn( array $a ): bool => 'ffcertificate_audience_booking_overridden' === $a[0]
			)
		);
		$this->assertCount( 1, $overridden );
		$this->assertSame( 99, $overridden[0][1] );
		$this->assertSame( 56, $overridden[0][2] );
		$this->assertSame( array(), $overridden[0][4], 'A one-day booking that clashes is cancelled whole.' );

		$this->assertCount( 1, ActivityLogBuffer::entries(), 'The confirmed override is logged once.' );
	}

	public function test_an_override_asks_for_a_reason_and_refuses_one_too_short(): void {
		// The people who lose days are told why, in the operator's words (#2243).
		$this->given_an_open_space();
		$this->permissions = array();
		$this->permit( 20, 5, array( 'can_override_conflicts' => 1 ) );
		$this->booking( 99, 10, '2026-04-01', '09:00', '10:00' );

		$asked = ( new AudienceRestController() )->create_booking( $this->booking_request() );
		$this->assertSame( 409, $asked->get_status() );
		$this->assertSame(
			array(
				'min' => AudienceBookingRules::OVERRIDE_REASON_MIN,
				'max' => AudienceBookingRules::OVERRIDE_REASON_MAX,
			),
			$asked->get_data()['reason_required']
		);

		$refused = ( new AudienceRestController() )->create_booking(
			$this->booking_request(
				array(
					'confirm_override' => true,
					'override_reason'  => 'Because',
				)
			)
		);
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'override_reason_required', $refused->get_data()['code'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written or cancelled.' );
		$this->assertSame( 'active', $this->bookings[99]->status );
	}

	public function test_an_override_takes_only_the_clashing_day_of_a_range(): void {
		// A five-day course overridden on its third day keeps the other four,
		// with their attendance and invitations (#2243).
		$this->given_an_open_space();
		$this->permissions = array();
		$this->permit( 20, 5, array( 'can_override_conflicts' => 1 ) );
		$this->booking(
			99,
			10,
			'2026-03-30',
			'09:00',
			'10:00',
			array(
				'end_date'  => '2026-04-03',
				'time_mode' => 'daily',
			)
		);
		$this->next_booking_id = 56;

		$actions = array();
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$actions ) {
				$actions[] = $args;
			}
		);

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request(
				array(
					'confirm_override' => true,
					'override_reason'  => 'The visiting committee needs the room.',
				)
			)
		);

		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( 'active', $this->bookings[99]->status, 'The earlier booking stands.' );
		$this->assertSame( '["2026-04-01"]', $this->bookings[99]->skipped_dates );
		$this->assertSame(
			array( '2026-03-30', '2026-03-31', '2026-04-02', '2026-04-03' ),
			AudienceBookingRules::booked_days( '2026-03-30', '2026-04-03', $this->bookings[99]->skipped_dates )
		);

		$overridden = array_values(
			array_filter(
				$actions,
				static fn( array $a ): bool => 'ffcertificate_audience_booking_overridden' === $a[0]
			)
		);
		$this->assertCount( 1, $overridden );
		$this->assertSame( 'The visiting committee needs the room.', $overridden[0][3] );
		$this->assertSame( array( '2026-04-01' ), $overridden[0][4] );

		$logged = ActivityLogBuffer::contexts()[0]['context'];
		$this->assertSame( array(), $logged['cancelled_bookings'] );
		$this->assertSame( array( 99 => array( '2026-04-01' ) ), $logged['trimmed_bookings'] );
	}

	public function test_a_conflict_without_override_permission_is_refused(): void {
		$this->given_an_open_space();
		$this->booking( 99, 10, '2026-04-01', '09:00', '10:00' );

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( array( 'confirm_override' => true ) ) );

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'conflict', $result->get_data()['code'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written or cancelled.' );
		$this->assertSame( 'active', $this->bookings[99]->status );
	}

	public function test_get_bookings_for_a_whole_calendar_reports_each_space_and_the_shared_closures(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$open   = array( 'start' => '08:00', 'end' => '18:00', 'closed' => false );
		$closed = array( 'start' => '08:00', 'end' => '18:00', 'closed' => true );
		// Space 10 is closed at weekends; space 11 opens on Saturday.
		$this->environment( 10, 20, array( 'sun' => $closed, 'mon' => $open, 'tue' => $open, 'wed' => $open, 'thu' => $open, 'fri' => $open, 'sat' => $closed ) );
		$this->environment( 11, 20, array( 'sun' => $closed, 'mon' => $open, 'tue' => $open, 'wed' => $open, 'thu' => $open, 'fri' => $open, 'sat' => $open ) );
		$this->holidays[] = (object) array( 'schedule_id' => 20, 'holiday_date' => '2026-04-21', 'description' => 'Local' );
		// Outside the month: not reported.
		$this->holidays[] = (object) array( 'schedule_id' => 20, 'holiday_date' => '2026-05-01', 'description' => 'Later' );

		$data = ( new AudienceRestController() )->get_bookings(
			$this->make_request(
				array(
					'start_date'     => '2026-04-01',
					'end_date'       => '2026-04-30',
					'schedule_id'    => 20,
					'environment_id' => null,
				)
			)
		)->get_data();

		$this->assertSame( array( 0 ), $data['closed_weekdays'], 'Saturday stays open: one space opens on it.' );
		$this->assertSame( array( 10, 11 ), array_column( $data['environments'], 'id' ) );
		$this->assertSame( array( 0, 6 ), $data['environments'][0]['closed_weekdays'] );
		$this->assertSame( array( 0 ), $data['environments'][1]['closed_weekdays'] );
		$this->assertSame( array( 'start' => '08:00', 'end' => '18:00' ), $data['environments'][1]['hours'][6] );
		$this->assertSame( array( '2026-04-21' ), $data['schedule_holidays']['20'] );
		$this->assertContains( '2026-04-21', array_column( $data['holidays'], 'holiday_date' ) );
		$this->assertNotContains( '2026-05-01', array_column( $data['holidays'], 'holiday_date' ) );
	}

	// ------------------------------------------------------------------
	// Ranges (#1946 AC5): a clash on any day, and the end date's order
	// ------------------------------------------------------------------

	public function test_a_range_clashing_on_its_last_day_is_refused_and_names_that_day(): void {
		$this->given_an_open_space();
		// Wed 1 to Fri 3 April: the first two days are free, the last is taken.
		$this->booking( 99, 10, '2026-04-03', '09:00', '10:00', array( 'description' => 'Earlier' ) );

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'end_date' => '2026-04-03' ) )
		);

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'conflict', $result->get_data()['code'] );
		$this->assertSame( array( '2026-04-03' ), $result->get_data()['colliding_dates'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written.' );
	}

	public function test_a_range_clashing_on_one_day_with_override_permission_asks_to_confirm_and_names_the_day(): void {
		$this->given_an_open_space();
		$this->permissions = array();
		$this->permit( 20, 5, array( 'can_override_conflicts' => 1 ) );
		$this->booking( 99, 10, '2026-04-02', '09:00', '10:00', array( 'description' => 'Earlier' ) );

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'end_date' => '2026-04-03' ) )
		);

		$this->assertSame( 409, $result->get_status() );
		$this->assertSame( 'override_confirmation_required', $result->get_data()['code'] );
		$this->assertSame( array( '2026-04-02' ), $result->get_data()['colliding_dates'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written.' );
	}

	public function test_a_range_that_ends_before_it_starts_is_refused_before_anything_is_checked(): void {
		$this->given_an_open_space();

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request(
				array(
					'booking_date' => '2026-04-03',
					'end_date'     => '2026-04-01',
				)
			)
		);

		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'on or after the start date', $result->get_data()['message'] );
		$this->assertSame( 0, $this->ranWith( 'WHERE environment_id = %d' ), 'No conflict query runs.' );
		$this->assertSame( array(), $this->writes, 'Nothing is written.' );
	}

	public function test_a_range_with_an_unreadable_end_date_is_refused(): void {
		$this->given_an_open_space();

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'end_date' => '2026-02-31' ) )
		);

		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'on or after the start date', $result->get_data()['message'] );
		$this->assertSame( array(), $this->writes, 'Nothing is written.' );
	}

	// ------------------------------------------------------------------
	// Authorization and integrity (#2243)
	// ------------------------------------------------------------------

	public function test_a_member_cannot_book_for_an_audience_they_do_not_belong_to(): void {
		$this->given_an_open_space();
		$this->audiences[2] = (object) array( 'id' => 2, 'name' => 'Other', 'color' => '#000000', 'parent_id' => null, 'status' => 'active' );

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( array( 'audience_ids' => array( 2 ) ) ) );

		$this->assertSame( 403, $result->get_status() );
		$this->assertStringContainsString( 'audiences you belong to', $result->get_data()['message'] );
		$this->assertSame( array(), $this->written( 'insert', 'wp_ffc_audience_bookings' ) );
	}

	public function test_an_audience_that_does_not_exist_is_refused(): void {
		$this->given_an_open_space();

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( array( 'audience_ids' => array( 77 ) ) ) );

		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'does not exist', $result->get_data()['message'] );
	}

	public function test_an_individual_booking_needs_the_right_to_manage_audiences(): void {
		$this->given_an_open_space();
		$request = $this->booking_request( array( 'booking_type' => 'individual', 'audience_ids' => array(), 'user_ids' => array( 8 ) ) );

		$result = ( new AudienceRestController() )->create_booking( $request );

		$this->assertSame( 403, $result->get_status() );
		$this->assertStringContainsString( 'individual users', $result->get_data()['message'] );

		$this->caps = array( 'ffc_manage_audiences' );
		$result     = ( new AudienceRestController() )->create_booking( $request );

		$this->assertSame( 201, $result->get_status() );
	}

	public function test_a_bypass_holder_may_book_any_existing_audience(): void {
		$this->given_an_open_space();
		$this->caps         = array( 'ffc_bypass_audiences' );
		$this->audiences[2] = (object) array( 'id' => 2, 'name' => 'Other', 'color' => '#000000', 'parent_id' => null, 'status' => 'active' );

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( array( 'audience_ids' => array( 2 ) ) ) );

		$this->assertSame( 201, $result->get_status() );
	}

	public function test_a_range_longer_than_thirty_days_is_refused_with_the_limit(): void {
		$this->given_an_open_space();

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( array( 'end_date' => '2026-05-01' ) ) );

		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'at most 30 consecutive days', $result->get_data()['message'] );
	}

	public function test_an_absurd_end_date_is_refused_without_walking_the_days(): void {
		$this->given_an_open_space();

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( array( 'end_date' => '9999-12-31' ) ) );

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( array(), $this->preparedWith( 'FOR UPDATE' ) );
	}

	public function test_a_second_cancel_is_refused_and_fires_nothing(): void {
		$this->environment( 10, 20 );
		$this->booking( 1, 10, '2026-04-01', '09:00', '10:00' );
		$this->cancelled_by_another_request = true;
		$actions                            = CallLog::record( 'do_action' );

		$result = ( new AudienceRestController() )->cancel_booking( $this->make_request( array( 'id' => 1, 'reason' => 'Cannot attend anymore' ) ) );

		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'already cancelled', $result->get_data()['message'] );
		$this->assertSame( 0, $actions->count() );
	}

	public function test_the_month_read_refuses_a_span_above_sixty_two_days(): void {
		$result = ( new AudienceRestController() )->get_bookings(
			$this->make_request( array( 'start_date' => '2026-01-01', 'end_date' => '2026-03-04' ) )
		);

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 0, $this->ranWith( 'SELECT b.*' ) );
	}

	public function test_the_month_read_refuses_an_invalid_date(): void {
		$result = ( new AudienceRestController() )->get_bookings(
			$this->make_request( array( 'start_date' => '2026-02-30', 'end_date' => '2026-03-04' ) )
		);

		$this->assertSame( 400, $result->get_status() );
	}

	public function test_the_month_read_loads_every_booking_audience_in_one_query(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->environment( 10, 20 );
		$this->booking( 1, 10, '2026-04-01', '09:00', '10:00' );
		$this->booking( 2, 10, '2026-04-02', '09:00', '10:00' );
		$this->booking( 3, 10, '2026-04-03', '09:00', '10:00' );

		$result = ( new AudienceRestController() )->get_bookings(
			$this->make_request( array( 'start_date' => '2026-04-01', 'end_date' => '2026-04-30' ) )
		);

		$this->assertCount( 3, $result->get_data()['bookings'] );
		$this->assertSame( 1, $this->ranWith( 'ba.booking_id IN (' ) );
		$this->assertSame( 0, $this->ranWith( 'WHERE ba.booking_id = %d' ) );
		// The readable calendars are filtered in the query.
		$this->assertCount( 1, $this->preparedWith( 'e.schedule_id IN (' ) );
	}
}
