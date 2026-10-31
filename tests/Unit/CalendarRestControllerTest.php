<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\CalendarRestController;
use FreeFormCertificate\Core\IpAddress;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Security\RateLimitChecker;

/**
 * Tests for CalendarRestController: route registration, get_calendars,
 * get_calendar, and get_calendar_slots endpoints.
 *
 * Everything below the controller is real: CalendarRepository,
 * AppointmentHandler (with its appointment and blocked-date repositories),
 * the read rate-limit guard down to RateLimitChecker, RateCounter and
 * RateLimitLogger, and Debug. Storage is a `$wpdb` double that holds the
 * calendar rows and answers the SQL by table, plus transient stubs for the
 * rate counters. The controller catches every \Exception, so a query the
 * double does not expect is recorded and failed after the test rather than
 * thrown where the controller would swallow it.
 *
 * A URL route parameter arrives as a string, so the controller casts the
 * `id` before handing it to the `int`-typed repository and handler methods;
 * under strict types an uncast "3" raises a TypeError, an \Error the
 * controller's catch does not reach. The `*_with_a_string_id` tests send it
 * that way (#2146); the rest pass integers.
 */
class CalendarRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const CALENDARS = 'wp_ffc_self_scheduling_calendars';

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether REMOTE_ADDR was set before this test. */
	private bool $had_remote_addr = false;

	/** @var mixed REMOTE_ADDR as an earlier test left it. */
	private $remote_addr_backup;

	/** @var array<int, array<string, string>> Calendar rows the database holds, by id. */
	private array $calendars = array();

	/** @var array<string, mixed> The stored `ffc_rate_limit_settings` option. */
	private array $rate_settings = array();

	/** @var array<string, mixed> Transients, by key. */
	private array $transients = array();

	/** @var bool Whether a read of the calendars table throws. */
	private bool $calendar_read_throws = false;

	/** @var list<string> Every SQL string the double ran, in order. */
	private array $queries = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> Every insert, as [table, data]. */
	private array $inserts = array();

	/** @var list<string> Queries the double was not written to answer. */
	private array $unexpected = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered_routes    = [];
		$this->calendars            = array();
		$this->transients           = array();
		$this->calendar_read_throws = false;
		$this->queries              = array();
		$this->inserts              = array();
		$this->unexpected           = array();

		// Whitelist empty, logged-in callers bypass the read pool, and no
		// endpoint is configured: every read is allowed and counted.
		$this->rate_settings = array(
			'whitelist' => array( 'ips' => array(), 'emails' => array(), 'email_domains' => array(), 'cpfs' => array() ),
			'read'      => array(
				'respect_whitelist' => true,
				'bypass_logged_in'  => true,
				'message'           => 'Slow down, wait {time}.',
				'endpoints'         => array(),
			),
		);

		// Capture register_rest_route calls
		Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
			$this->registered_routes[] = array(
				'namespace' => $namespace,
				'route'     => $route,
				'args'      => $args,
			);
		});

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
		Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				if ( 'ffc_rate_limit_settings' === $name ) {
					return $this->rate_settings;
				}
				// `ffc_settings` (Debug areas, DateFormatter) and the global
				// holidays: nothing stored.
				return $default_value;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static fn ( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
		);
		Functions\when( 'get_transient' )->alias( fn ( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-04-01 12:00:00' );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $ts, $tz = null ) {
				$dt = new \DateTime( '@' . (int) $ts );
				$dt->setTimezone( $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' ) );
				return $dt->format( (string) $format );
			}
		);

		// RateLimitChecker memoises the settings it read first.
		$ref = new \ReflectionProperty( RateLimitChecker::class, 'settings_cache' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		$this->had_remote_addr    = array_key_exists( 'REMOTE_ADDR', $_SERVER );
		$this->remote_addr_backup = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']   = '8.8.4.4';

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->make_wpdb();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		if ( $this->had_remote_addr ) {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr_backup;
		} else {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
		$ref = new \ReflectionProperty( RateLimitChecker::class, 'settings_cache' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The controller turns any exception into `ffc_internal_error`, so an
	 * unexpected query is checked here, outside its try block.
	 */
	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'Every query the code ran was one the double answers.' );
	}

	/**
	 * A `$wpdb` double that renders prepare() into plain SQL and answers by table.
	 */
	private function make_wpdb(): object {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$i = 0;
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function ( $m ) use ( &$i, $args ) {
						$v = $args[ $i++ ] ?? '';
						if ( '%i' === $m[0] ) {
							return '`' . $v . '`';
						}
						if ( '%d' === $m[0] ) {
							return (string) (int) $v;
						}
						return "'" . addslashes( (string) $v ) . "'";
					},
					(string) $sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( false !== strpos( $sql, '`' . self::CALENDARS . '`' ) ) {
					if ( $this->calendar_read_throws ) {
						throw new \RuntimeException( 'boom' );
					}
					if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
						return $this->calendars[ (int) $m[1] ] ?? null;
					}
				}
				if ( false !== strpos( $sql, '`wp_ffc_rate_limits`' ) ) {
					return null; // No day counter row yet.
				}
				$this->unexpected[] = 'get_row: ' . $sql;
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( false !== strpos( $sql, '`' . self::CALENDARS . '`' ) ) {
					if ( $this->calendar_read_throws ) {
						throw new \RuntimeException( 'boom' );
					}
					return $this->matching_calendars( $sql );
				}
				if ( false !== strpos( $sql, '`wp_ffc_self_scheduling_blocked_dates`' )
					|| false !== strpos( $sql, '`wp_ffc_self_scheduling_appointments`' ) ) {
					return array();
				}
				$this->unexpected[] = 'get_results: ' . $sql;
				return array();
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( false !== strpos( $sql, 'SELECT COUNT(*) FROM `wp_ffc_rate_limit_logs`' ) ) {
					return '0';
				}
				$this->unexpected[] = 'get_var: ' . $sql;
				return null;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( 0 === strpos( $sql, 'DELETE FROM `wp_ffc_rate_limit_logs`' ) ) {
					return 0;
				}
				$this->unexpected[] = 'query: ' . $sql;
				return false;
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				if ( in_array( $table, array( 'wp_ffc_rate_limits', 'wp_ffc_rate_limit_logs' ), true ) ) {
					$this->inserts[] = array( $table, $data );
					return 1;
				}
				$this->unexpected[] = 'insert: ' . $table;
				return false;
			}
		);
		return $wpdb;
	}

	/**
	 * The stored calendars matching every `col = 'value'` the WHERE carries.
	 *
	 * @param string $sql Rendered SQL.
	 * @return list<array<string, string>>
	 */
	private function matching_calendars( string $sql ): array {
		preg_match_all( "/`(\\w+)` = '([^']*)'/", $sql, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $this->calendars as $row ) {
			foreach ( $m as $cond ) {
				if ( ( $row[ $cond[1] ] ?? null ) !== $cond[2] ) {
					continue 2;
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * The rendered queries that read the calendars table.
	 *
	 * @return list<string>
	 */
	private function calendar_queries(): array {
		return array_values(
			array_filter( $this->queries, static fn ( $q ) => false !== strpos( $q, '`' . self::CALENDARS . '`' ) )
		);
	}

	/**
	 * A stored calendar row, as wpdb returns it (string columns).
	 *
	 * @param array<string, mixed> $over Columns to override.
	 * @return array<string, string>
	 */
	private function calendar_row( array $over ): array {
		$row = array_merge(
			array(
				'id'                        => '1',
				'post_id'                   => '',
				'status'                    => 'active',
				'title'                     => 'Calendar',
				'description'               => '',
				'requires_approval'         => '0',
				'visibility'                => 'public',
				'scheduling_visibility'     => 'public',
				'allow_cancellation'        => '1',
				'cancellation_min_hours'    => '24',
				'slot_duration'             => '30',
				'slot_interval'             => '0',
				'max_appointments_per_slot' => '1',
				'advance_booking_min'       => '1',
				'advance_booking_max'       => '30',
				'working_hours'             => '',
				'email_config'              => '',
			),
			$over
		);
		return array_map( 'strval', $row );
	}

	/**
	 * Store a calendar row under its id.
	 *
	 * @param array<string, mixed> $over Columns to override.
	 */
	private function store_calendar( array $over ): void {
		$row                                 = $this->calendar_row( $over );
		$this->calendars[ (int) $row['id'] ] = $row;
	}

	/**
	 * Turn on the read pool for one endpoint, with its hour counter already at the cap.
	 *
	 * @param string $endpoint_key Endpoint identifier.
	 */
	private function saturate_hour_pool( string $endpoint_key ): void {
		$this->rate_settings['read']['endpoints'][ $endpoint_key ] = array(
			'enabled'        => true,
			'max_per_minute' => 0,
			'max_per_hour'   => 1,
		);
		$key                      = 'ffc_rate_read_' . md5( IpAddress::fingerprint( RequestInput::get_user_ip() ) . '|' . $endpoint_key ) . '_hour';
		$this->transients[ $key ] = 1;
	}

	/**
	 * Turn on the read pool for one endpoint, with its minute counter already at the cap.
	 *
	 * @param string $endpoint_key Endpoint identifier.
	 */
	private function saturate_minute_pool( string $endpoint_key ): void {
		$this->rate_settings['read']['endpoints'][ $endpoint_key ] = array(
			'enabled'        => true,
			'max_per_minute' => 1,
			'max_per_hour'   => 0,
		);
		$key                      = 'ffc_rate_read_' . md5( IpAddress::fingerprint( RequestInput::get_user_ip() ) . '|' . $endpoint_key ) . '_minute';
		$this->transients[ $key ] = 1;
	}

	/**
	 * The `ffc_rate_limit_logs` rows written, as data arrays.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rate_limit_logs(): array {
		$out = array();
		foreach ( $this->inserts as $insert ) {
			if ( 'wp_ffc_rate_limit_logs' === $insert[0] ) {
				$out[] = $insert[1];
			}
		}
		return $out;
	}

	/**
	 * Assert the guard logged exactly one blocked read for this endpoint.
	 *
	 * @param string $endpoint_key Endpoint identifier.
	 * @param string $reason       Expected reason.
	 */
	private function assert_blocked_read_logged( string $endpoint_key, string $reason ): void {
		$logs = $this->rate_limit_logs();
		$this->assertCount( 1, $logs );
		$this->assertSame( 'read', $logs[0]['type'] );
		$this->assertSame( 'blocked', $logs[0]['action'] );
		$this->assertSame( $reason, $logs[0]['reason'] );
		$this->assertSame( hash( 'sha256', RequestInput::get_user_ip() . '|' . $endpoint_key ), $logs[0]['identifier'] );
	}

	/**
	 * Helper: create a mock WP_REST_Request with given params.
	 */
	private function make_request( array $params = [], array $json_params = [] ): object {
		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_param' )->andReturnUsing( function( $key ) use ( $params ) {
			return $params[ $key ] ?? null;
		});
		$req->shouldReceive( 'get_json_params' )->andReturn( $json_params );
		return $req;
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	public function test_register_routes_creates_three_endpoints(): void {
		$ctrl = new CalendarRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertCount( 3, $this->registered_routes );
	}

	public function test_calendars_list_route_is_public(): void {
		$ctrl = new CalendarRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$route = $this->registered_routes[0];
		$this->assertSame( '/calendars', $route['route'] );
		$this->assertSame( '__return_true', $route['args']['permission_callback'] );
	}

	public function test_calendar_single_route_is_public(): void {
		$ctrl = new CalendarRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$route = $this->registered_routes[1];
		$this->assertStringContainsString( 'calendars', $route['route'] );
		$this->assertSame( '__return_true', $route['args']['permission_callback'] );
	}

	public function test_calendar_slots_route_is_public(): void {
		$ctrl = new CalendarRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$route = $this->registered_routes[2];
		$this->assertStringContainsString( 'slots', $route['route'] );
		$this->assertSame( '__return_true', $route['args']['permission_callback'] );
	}

	// ------------------------------------------------------------------
	// get_calendars()
	// ------------------------------------------------------------------

	public function test_get_calendars_returns_429_when_rate_limit_blocks(): void {
		$this->saturate_hour_pool( 'calendar_list' );

		$ctrl    = new CalendarRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_calendars( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
		$this->assertSame( 'Slow down, wait 1 hour.', $result->get_error_message() );
		$data = $result->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$this->assertSame( 3600, $data['headers']['Retry-After'] );
		$this->assert_blocked_read_logged( 'calendar_list', 'read_hour_limit' );
		$this->assertSame( array(), $this->calendar_queries(), 'A blocked read never reaches the repository.' );
	}

	public function test_get_calendar_slots_returns_429_when_rate_limit_blocks(): void {
		$this->saturate_minute_pool( 'calendar_slots' );

		$ctrl    = new CalendarRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 1, 'date' => '2026-05-20' ) );
		$result  = $ctrl->get_calendar_slots( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
		$this->assert_blocked_read_logged( 'calendar_slots', 'read_minute_limit' );
	}

	public function test_get_calendars_returns_public_calendars_for_anon(): void {
		$this->store_calendar(
			array(
				'id'          => 1,
				'title'       => 'Public Cal',
				'description' => 'Desc',
			)
		);
		$this->store_calendar(
			array(
				'id'         => 2,
				'title'      => 'Private Cal',
				'visibility' => 'private',
			)
		);

		$ctrl = new CalendarRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result = $ctrl->get_calendars( $request );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'calendars', $result );
		$this->assertCount( 1, $result['calendars'] );
		$this->assertSame( 1, $result['calendars'][0]['id'] );
		$this->assertSame( 1, $result['total'] );

		$reads = $this->calendar_queries();
		$this->assertCount( 1, $reads );
		$this->assertStringContainsString( "`status` = 'active'", $reads[0] );
		$this->assertStringContainsString( "`visibility` = 'public'", $reads[0] );
		// Visitors see the list in the same order logged-in users do (#2147).
		$this->assertStringEndsWith( 'ORDER BY title ASC', $reads[0] );

		// The allowed read was counted against the pool.
		$this->assertNotEmpty( $this->transients );
		$this->assertSame( array(), $this->rate_limit_logs() );
	}

	public function test_get_calendars_returns_all_calendars_for_logged_in_user(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$this->store_calendar(
			array(
				'id'    => 1,
				'title' => 'Cal A',
			)
		);
		$this->store_calendar(
			array(
				'id'                => 2,
				'title'             => 'Cal B',
				'description'       => 'Private',
				'requires_approval' => 1,
				'visibility'        => 'private',
			)
		);
		$this->store_calendar(
			array(
				'id'     => 3,
				'title'  => 'Cal C',
				'status' => 'inactive',
			)
		);

		$ctrl = new CalendarRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result = $ctrl->get_calendars( $request );

		$this->assertCount( 2, $result['calendars'] );
		$this->assertSame( 2, $result['total'] );

		$reads = $this->calendar_queries();
		$this->assertCount( 1, $reads );
		$this->assertStringContainsString( "`status` = 'active'", $reads[0] );
		$this->assertStringNotContainsString( 'visibility', $reads[0] );
		$this->assertStringEndsWith( 'ORDER BY title ASC', $reads[0] );

		// Logged-in callers bypass the read pool entirely.
		$this->assertSame( array(), $this->transients );
	}

	// ------------------------------------------------------------------
	// get_calendar()
	// ------------------------------------------------------------------

	public function test_get_calendar_returns_error_when_not_found(): void {
		$ctrl = new CalendarRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 99 ) );
		$result = $ctrl->get_calendar( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'calendar_not_found', $result->get_error_code() );
		$this->assertStringContainsString( 'WHERE id = 99', $this->calendar_queries()[0] );
	}

	public function test_get_calendar_returns_error_when_inactive(): void {
		$this->store_calendar(
			array(
				'id'     => 5,
				'status' => 'inactive',
				'title'  => 'Disabled',
			)
		);

		$ctrl = new CalendarRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 5 ) );
		$result = $ctrl->get_calendar( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'calendar_inactive', $result->get_error_code() );
	}

	public function test_get_calendar_returns_error_for_private_calendar_anon(): void {
		$this->store_calendar(
			array(
				'id'         => 7,
				'title'      => 'Private Cal',
				'visibility' => 'private',
			)
		);

		$ctrl = new CalendarRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 7 ) );
		$result = $ctrl->get_calendar( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'calendar_private', $result->get_error_code() );
	}

	public function test_get_calendar_returns_data_on_success(): void {
		$this->store_calendar(
			array(
				'id'                        => 3,
				'title'                     => 'Active Calendar',
				'description'               => 'Some description',
				'requires_approval'         => 1,
				'slot_interval'             => 15,
				'max_appointments_per_slot' => 3,
				'advance_booking_max'       => 60,
				'working_hours'             => (string) json_encode( array( 'mon' => '09:00-17:00' ) ),
			)
		);

		$ctrl = new CalendarRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 3 ) );
		$result = $ctrl->get_calendar( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['id'] );
		$this->assertSame( 'Active Calendar', $result['title'] );
		$this->assertTrue( $result['requires_approval'] );
		$this->assertSame( 30, $result['slot_duration'] );
		$this->assertSame( array( 'mon' => '09:00-17:00' ), $result['working_hours'] );
	}

	// ------------------------------------------------------------------
	// get_calendar_slots()
	// ------------------------------------------------------------------

	public function test_get_calendar_slots_returns_slots_on_success(): void {
		// 2032-03-10 is a Wednesday, in the future so no slot has passed (day 3); 09:00-10:00 in 30-minute slots.
		$this->store_calendar(
			array(
				'id'            => 3,
				'working_hours' => (string) json_encode(
					array(
						array( 'day' => 3, 'start' => '09:00', 'end' => '10:00' ),
					)
				),
			)
		);

		$ctrl = new CalendarRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 3, 'date' => '2032-03-10' ) );
		$result = $ctrl->get_calendar_slots( $request );

		$this->assertIsArray( $result );
		$this->assertSame( array( '09:00:00', '09:30:00' ), array_column( $result['slots'], 'time' ) );
		$this->assertSame( array( 1, 1 ), array_column( $result['slots'], 'available' ) );
		$this->assertSame( '2032-03-10', $result['date'] );
		$this->assertSame( 3, $result['calendar_id'] );
	}

	/**
	 * A real request carries the route's `id` as a string (#2146).
	 */
	public function test_get_calendar_answers_with_a_string_id(): void {
		$this->store_calendar( array( 'id' => 3, 'title' => 'Active Calendar' ) );

		$result = ( new CalendarRestController( 'ffc/v1' ) )->get_calendar( $this->make_request( array( 'id' => '3' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['id'] );
		$this->assertSame( 'Active Calendar', $result['title'] );
	}

	/**
	 * The slots endpoint casts the string id too, and answers `calendar_id`
	 * as an integer like the other calendar endpoints (#2146).
	 */
	public function test_get_calendar_slots_answers_with_a_string_id(): void {
		// 2032-03-10 is a Wednesday, in the future so no slot has passed (day 3); 09:00-10:00 in 30-minute slots.
		$this->store_calendar(
			array(
				'id'            => 3,
				'working_hours' => (string) json_encode(
					array(
						array( 'day' => 3, 'start' => '09:00', 'end' => '10:00' ),
					)
				),
			)
		);

		$result = ( new CalendarRestController( 'ffc/v1' ) )->get_calendar_slots(
			$this->make_request( array( 'id' => '3', 'date' => '2032-03-10' ) )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( '09:00:00', '09:30:00' ), array_column( $result['slots'], 'time' ) );
		$this->assertSame( 3, $result['calendar_id'] );
	}

	public function test_get_calendar_slots_propagates_wp_error(): void {
		// No calendar 3 is stored, so the handler answers with a WP_Error.
		$ctrl = new CalendarRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 3, 'date' => '2026-04-01' ) );
		$result = $ctrl->get_calendar_slots( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_calendar', $result->get_error_code() );
	}

	public function test_get_calendar_returns_429_when_rate_limit_blocks(): void {
		$this->saturate_hour_pool( 'calendar_detail' );

		$result = ( new CalendarRestController( 'ffc/v1' ) )->get_calendar( $this->make_request( array( 'id' => 3 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
		$this->assert_blocked_read_logged( 'calendar_detail', 'read_hour_limit' );
	}

	public function test_route_validate_callbacks_accept_and_reject(): void {
		$ctrl = new CalendarRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$detail_id = $this->registered_routes[1]['args']['args']['id']['validate_callback'];
		$this->assertTrue( $detail_id( '5' ) );
		$this->assertFalse( (bool) $detail_id( 'abc' ) );

		$slots = $this->registered_routes[2]['args']['args'];
		$this->assertTrue( $slots['id']['validate_callback']( '7' ) );
		$this->assertTrue( (bool) $slots['date']['validate_callback']( '2026-04-01' ) );
		$this->assertFalse( (bool) $slots['date']['validate_callback']( 'not-a-date' ) );
	}

	// ------------------------------------------------------------------
	// catch blocks → ffc_internal_error (+ log_rest_error)
	// ------------------------------------------------------------------

	public function test_get_calendars_wraps_unexpected_exception(): void {
		$this->calendar_read_throws = true;

		$result = ( new CalendarRestController( 'ffc/v1' ) )->get_calendars( $this->make_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}

	public function test_get_calendar_wraps_unexpected_exception(): void {
		$this->calendar_read_throws = true;

		$result = ( new CalendarRestController( 'ffc/v1' ) )->get_calendar( $this->make_request( array( 'id' => 3 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}

	public function test_get_calendar_slots_wraps_unexpected_exception(): void {
		$this->calendar_read_throws = true;

		$result = ( new CalendarRestController( 'ffc/v1' ) )->get_calendar_slots(
			$this->make_request( array( 'id' => 3, 'date' => '2026-04-01' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}
}
