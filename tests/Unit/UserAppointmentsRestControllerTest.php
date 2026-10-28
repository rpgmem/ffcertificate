<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserAppointmentsRestController;
use FreeFormCertificate\Core\Encryption;

/**
 * Tests for UserAppointmentsRestController: route registration, permission checks,
 * and get_user_appointments success/error paths.
 *
 * AppointmentRepository (through AppointmentReader), CalendarRepository,
 * Encryption (with the bootstrap's key constants), MagicLinkHelper, Debug and
 * DateFormatter are the real classes. The `$wpdb` double records every
 * prepared statement and answers only the user's appointments query and the
 * batched calendar query; any other query is recorded and fails the test.
 */
class UserAppointmentsRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const APPOINTMENTS_TABLE = 'wp_ffc_self_scheduling_appointments';

	private const CALENDARS_TABLE = 'wp_ffc_self_scheduling_calendars';

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/**
	 * Every prepared statement, in order: [ sql, args ].
	 *
	 * @var list<array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = array();

	/** @var list<mixed>|\Exception What the appointments query answers, or throws. */
	private $appointments = array();

	/** @var list<array<string, mixed>> The stored calendar rows. */
	private array $calendars = array();

	/** @var list<int> The user id each appointments query was scoped to. */
	private array $appointment_reads = array();

	/** @var list<list<int>> The ids each calendar query asked for. */
	private array $calendar_reads = array();

	/**
	 * Queries the double did not expect. Recorded rather than failed on the
	 * spot, because the endpoint catches every exception, a failed assertion
	 * included; assertPostConditions() fails on any.
	 *
	 * @var list<string>
	 */
	private array $unexpected = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered_routes = [];
		$this->prepared          = array();
		$this->appointments      = array();
		$this->calendars         = array();
		$this->appointment_reads = array();
		$this->calendar_reads    = array();
		$this->unexpected        = array();

		Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
			$this->registered_routes[] = array(
				'namespace' => $namespace,
				'route'     => $route,
				'args'      => $args,
			);
		});

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
		Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
		Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( 'ffc_settings' === $key ) {
				// What DateFormatter reads; every debug area stays off.
				return array(
					'date_format' => 'F j, Y',
					'time_format' => 'H:i',
				);
			}
			// Everything else, the verification page id included, is unset.
			return $default;
		});
		Functions\when( 'date_i18n' )->alias( function( $format, $timestamp = false ) {
			return date( $format, $timestamp ?: time() );
		});
		Functions\when( 'wp_date' )->alias( function( $format, $timestamp = null, $tz = null ) {
			return date( $format, $timestamp ?? time() );
		});
		Functions\when( 'wp_timezone' )->alias( function() {
			return new \DateTimeZone( 'UTC' );
		});
		Functions\when( 'home_url' )->alias( function( $path = '' ) {
			return 'https://example.com' . $path;
		});
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function( $sql, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$this->prepared[] = array( (string) $sql, array_values( $args ) );
			return 'Q' . ( count( $this->prepared ) - 1 );
		});
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( function( $query, $output = null ) {
			[ $sql, $args ] = $this->statement( $query );
			$table          = $args[0] ?? null;
			if ( ARRAY_A === $output && self::APPOINTMENTS_TABLE === $table && 1 === preg_match( '/WHERE (Q\d+) ORDER BY \w+ DESC$/', $sql, $m ) ) {
				[ $where_sql, $where_args ] = $this->statement( $m[1] );
				if ( '%i = %s' === $where_sql && 'user_id' === $where_args[0] ) {
					$this->appointment_reads[] = $where_args[1];
					if ( $this->appointments instanceof \Exception ) {
						throw $this->appointments;
					}
					return $this->appointments;
				}
			}
			if ( ARRAY_A === $output && self::CALENDARS_TABLE === $table && false !== strpos( $sql, 'WHERE id IN (' ) ) {
				$ids                    = array_slice( $args, 1 );
				$this->calendar_reads[] = $ids;
				return array_values(
					array_filter( $this->calendars, static fn( array $row ) => in_array( (int) $row['id'], $ids, true ) )
				);
			}
			$this->unexpected[] = 'get_results(): ' . $sql;
			return array();
		});

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'The controller ran a query the test did not expect.' );
		parent::assertPostConditions();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The prepared statement behind a token the double handed out.
	 *
	 * @param mixed $query Token returned by prepare().
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( $query ): array {
		$index = (int) substr( (string) $query, 1 );
		return $this->prepared[ $index ] ?? array( (string) $query, array() );
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

	public function test_register_routes_creates_one_endpoint(): void {
		$ctrl = new UserAppointmentsRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertCount( 1, $this->registered_routes );
	}

	public function test_appointments_route_path(): void {
		$ctrl = new UserAppointmentsRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertSame( '/user/appointments', $this->registered_routes[0]['route'] );
	}

	public function test_appointments_route_requires_authentication(): void {
		$ctrl = new UserAppointmentsRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertSame(
			'is_user_logged_in',
			$this->registered_routes[0]['args']['permission_callback']
		);
	}

	// ------------------------------------------------------------------
	// get_user_appointments — error paths
	// ------------------------------------------------------------------

	public function test_get_user_appointments_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserAppointmentsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_appointments( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
		$this->assertSame( array(), $this->appointment_reads, 'Nothing is read for a visitor.' );
	}

	public function test_get_user_appointments_returns_error_when_capability_denied(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );

		$ctrl    = new UserAppointmentsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_appointments( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'capability_denied', $result->get_error_code() );
		$this->assertSame( array(), $this->appointment_reads, 'Nothing is read without the capability.' );
	}

	// ------------------------------------------------------------------
	// get_user_appointments — success paths
	// ------------------------------------------------------------------

	public function test_get_user_appointments_returns_empty_when_no_appointments(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->appointments = array();

		$ctrl    = new UserAppointmentsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_appointments( $request );

		$this->assertSame( array( 5 ), $this->appointment_reads, 'The appointments are read for the current user.' );
		$this->assertSame( array(), $this->calendar_reads, 'No calendar is read when there is no appointment.' );
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total'] );
		$this->assertEmpty( $result['appointments'] );
	}

	public function test_get_user_appointments_returns_formatted_appointment_data(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		// Use a future date to test can_cancel logic
		$future_date = date( 'Y-m-d', strtotime( '+30 days' ) );

		// E-mail and phone are stored encrypted, as the writer stores them.
		$this->appointments = array(
			array(
				'id'                 => '10',
				'calendar_id'        => '2',
				'appointment_date'   => $future_date,
				'start_time'         => '14:00:00',
				'end_time'           => '15:00:00',
				'status'             => 'confirmed',
				'name'               => 'John Doe',
				'email'              => '',
				'email_encrypted'    => Encryption::encrypt( 'john@example.com' ),
				'phone'              => '',
				'phone_encrypted'    => Encryption::encrypt( '(11) 99999-0000' ),
				'user_notes'         => 'Test note',
				'created_at'         => '2025-06-01 10:00:00',
				'confirmation_token' => 'conf_token_123',
			),
		);

		$this->calendars = array(
			array(
				'id'                     => '2',
				'title'                  => 'Main Calendar',
				'allow_cancellation'     => '1',
				'cancellation_min_hours' => '24',
			),
		);

		$ctrl    = new UserAppointmentsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_appointments( $request );

		$this->assertSame( array( 5 ), $this->appointment_reads, 'The appointments are read for the current user.' );
		$this->assertSame( array( array( 2 ) ), $this->calendar_reads, 'The calendars are read in one batch.' );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['appointments'] );

		$apt = $result['appointments'][0];
		$this->assertSame( 10, $apt['id'] );
		$this->assertSame( 2, $apt['calendar_id'] );
		$this->assertSame( 'Main Calendar', $apt['calendar_title'] );
		$this->assertSame( 'confirmed', $apt['status'] );
		$this->assertSame( 'Confirmed', $apt['status_label'] );
		$this->assertSame( 'John Doe', $apt['name'] );
		$this->assertSame( 'john@example.com', $apt['email'] );
		$this->assertSame( '(11) 99999-0000', $apt['phone'] );
		$this->assertSame( 'Test note', $apt['user_notes'] );
		$this->assertNotEmpty( $apt['appointment_date'] );
		$this->assertNotEmpty( $apt['start_time'] );
		$this->assertNotEmpty( $apt['end_time'] );
		// The calendar export consumes the raw wall-clock times; end_time_raw
		// must be the stored TIME verbatim (no formatting / TZ shift).
		$this->assertSame( '14:00:00', $apt['start_time_raw'] );
		$this->assertSame( '15:00:00', $apt['end_time_raw'] );
		$this->assertSame( 'https://example.com/valid/#token=conf_token_123', $apt['receipt_url'], 'The receipt link is built from the stored token, on the fallback verification page.' );
	}

	public function test_get_user_appointments_uses_unknown_calendar_when_not_found(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$future_date = date( 'Y-m-d', strtotime( '+30 days' ) );

		$this->appointments = array(
			array(
				'id'                 => '10',
				'calendar_id'        => '99',
				'appointment_date'   => $future_date,
				'start_time'         => '09:00:00',
				'end_time'           => '',
				'status'             => 'pending',
				'name'               => 'Jane Doe',
				'email'              => 'jane@example.com',
				'phone'              => '',
				'user_notes'         => '',
				'created_at'         => '2025-06-01 10:00:00',
				'confirmation_token' => 'conf_unknown',
			),
		);
		// Calendar 99 is not stored.
		$this->calendars = array();

		$ctrl    = new UserAppointmentsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_appointments( $request );

		$this->assertSame( array( array( 99 ) ), $this->calendar_reads );
		$this->assertIsArray( $result );
		$apt = $result['appointments'][0];
		$this->assertSame( 'Unknown Calendar', $apt['calendar_title'] );
		$this->assertSame( 'Pending', $apt['status_label'] );
	}

	public function test_get_user_appointments_wraps_repository_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		// The query blows up mid-fetch → the catch converts it to a 500 WP_Error.
		$this->appointments = new \RuntimeException( 'db down' );

		$ctrl   = new UserAppointmentsRestController( 'ffc/v1' );
		$result = $ctrl->get_user_appointments( $this->make_request() );

		$this->assertSame( array( 5 ), $this->appointment_reads, 'The failure came from the appointments query.' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'get_appointments_error', $result->get_error_code() );
	}

	public function test_get_user_appointments_skips_invalid_entries(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$future_date = date( 'Y-m-d', strtotime( '+30 days' ) );

		$this->appointments = array(
			// Invalid: not an array
			'not_an_array',
			// Invalid: empty id
			array( 'id' => '' ),
			// Valid
			array(
				'id'                 => '10',
				'calendar_id'        => '2',
				'appointment_date'   => $future_date,
				'start_time'         => '14:00:00',
				'end_time'           => '15:00:00',
				'status'             => 'completed',
				'name'               => 'Valid User',
				'email'              => 'valid@example.com',
				'phone'              => '',
				'user_notes'         => '',
				'created_at'         => '2025-06-01 10:00:00',
				'confirmation_token' => 'conf_valid',
			),
		);

		$ctrl    = new UserAppointmentsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_appointments( $request );

		$this->assertIsArray( $result );
		// Only the valid entry should be included
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 10, $result['appointments'][0]['id'] );
		$this->assertSame( 'Completed', $result['appointments'][0]['status_label'] );
	}
}
