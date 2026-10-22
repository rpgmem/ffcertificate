<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceRestController;

/**
 * Tests for AudienceRestController: route registration, permission checks,
 * get_bookings, create_booking, cancel_booking, and check_conflicts.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/** @var \Mockery\MockInterface */
	private $booking_repo_mock;

	/** @var \Mockery\MockInterface */
	private $booking_writer_mock;

	/** @var \Mockery\MockInterface */
	private $env_repo_mock;

	/** @var \Mockery\MockInterface */
	private $schedule_repo_mock;

	/** @var \Mockery\MockInterface */
	private $date_blocking_mock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

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

		// Alias mocks for static-only dependencies. The personal-calendar
		// bypass is no longer read here (#1859): the audience bypass lives on
		// AudienceScheduleRepository, mocked below.

		$this->booking_repo_mock   = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceBookingReader' );
		$this->booking_writer_mock = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceBookingWriter' );
		$this->booking_repo_mock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
		$this->booking_repo_mock->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();
		$this->booking_repo_mock->shouldReceive( 'get_booking_audiences' )->andReturn( array() )->byDefault();
		$this->booking_repo_mock->shouldReceive( 'get_conflicts' )->andReturn( array() )->byDefault();
		$this->booking_repo_mock->shouldReceive( 'get_audience_same_day_bookings' )->andReturn( array() )->byDefault();
		$this->booking_repo_mock->shouldReceive( 'get_user_conflicts' )->andReturn( array( 'bookings' => array(), 'affected_users' => array() ) )->byDefault();
		$this->booking_writer_mock->shouldReceive( 'create' )->andReturn( 1 )->byDefault();
		$this->booking_writer_mock->shouldReceive( 'cancel' )->andReturn( true )->byDefault();

		$this->env_repo_mock = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
		$this->env_repo_mock->shouldReceive( 'get_holidays' )->andReturn( array() )->byDefault();
		$this->env_repo_mock->shouldReceive( 'get_working_hours' )->andReturn( null )->byDefault();
		$this->env_repo_mock->shouldReceive( 'is_open' )->andReturn( true )->byDefault();
		$this->env_repo_mock->shouldReceive( 'is_holiday' )->andReturn( false )->byDefault();
		$this->env_repo_mock->shouldReceive( 'get_by_schedule' )->andReturn( array() )->byDefault();

		$this->schedule_repo_mock = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$this->schedule_repo_mock->shouldReceive( 'user_can_cancel_others' )->andReturn( false )->byDefault();
		$this->schedule_repo_mock->shouldReceive( 'user_can_book' )->andReturn( true )->byDefault();
		// An active calendar with no advance limit, unless a test says otherwise.
		$this->schedule_repo_mock->shouldReceive( 'get_by_id' )->andReturn(
			(object) array(
				'id'                => 20,
				'status'            => 'active',
				'future_days_limit' => null,
				'admin_bypass'      => 1,
				'is_isolated'       => 0,
			)
		)->byDefault();
		// Read-path visibility gate (readable_schedule_ids): default to no
		// accessible schedules so tests must opt a schedule in explicitly.
		$this->schedule_repo_mock->shouldReceive( 'get_by_user_access' )->andReturn( array() )->byDefault();
		$this->schedule_repo_mock->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false )->byDefault();
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass' )->andReturn( false )->byDefault();
		$this->schedule_repo_mock->shouldReceive( 'user_can_override_conflicts' )->andReturn( false )->byDefault();

		$this->date_blocking_mock = Mockery::mock( 'alias:\FreeFormCertificate\Scheduling\DateBlockingService' );
		$this->date_blocking_mock->shouldReceive( 'get_global_holidays' )->andReturn( array() )->byDefault();
		$this->date_blocking_mock->shouldReceive( 'is_global_holiday' )->andReturn( false )->byDefault();

		Functions\when( 'current_time' )->justReturn( '2026-03-01' );

		// A booking's dates are rendered for the response (date_label): the
		// formatter reads the site settings and wp_date(), which these tests stub here.
		$settings = Mockery::mock( 'alias:\FreeFormCertificate\Settings\SettingsReader' );
		$settings->shouldReceive( 'all' )->andReturn( array() )->byDefault();
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ): string => gmdate( $format, $ts ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
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
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( true );

		$ctrl = new AudienceRestController();
		$this->assertTrue( $ctrl->check_write_permission() );
	}

	public function test_check_write_permission_returns_true_when_has_capability(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );

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
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( true );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array( 'id' => 1 ) );
		$this->assertTrue( $ctrl->check_cancel_permission( $request ) );
	}

	public function test_check_cancel_permission_returns_true_when_creator(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );

		$booking = (object) array(
			'id'             => 1,
			'created_by'     => 5,
			'environment_id' => 10,
		);
		$this->booking_repo_mock->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( $booking );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array( 'id' => 1 ) );
		$this->assertTrue( $ctrl->check_cancel_permission( $request ) );
	}

	public function test_check_cancel_permission_returns_false_for_other_user(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 99 );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );

		$booking = (object) array(
			'id'             => 1,
			'created_by'     => 5,
			'environment_id' => 10,
		);
		$this->booking_repo_mock->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( $booking );

		$environment = (object) array(
			'id'          => 10,
			'schedule_id' => 20,
		);
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( $environment );
		$this->schedule_repo_mock->shouldReceive( 'user_can_cancel_others' )->with( 20, 99 )->andReturn( false );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array( 'id' => 1 ) );
		$this->assertFalse( $ctrl->check_cancel_permission( $request ) );
	}

	// ------------------------------------------------------------------
	// get_bookings()
	// ------------------------------------------------------------------

	public function test_get_bookings_returns_formatted_bookings_with_holidays(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );
		// User has access to schedule 20 (the booking's owning schedule).
		$this->schedule_repo_mock->shouldReceive( 'get_by_user_access' )->with( 5 )->andReturn( array( (object) array( 'id' => 20 ) ) );

		$booking = (object) array(
			'id'               => 1,
			'environment_id'   => 10,
			'environment_name' => 'Room A',
			'schedule_id'      => 20,
			'booking_date'     => '2026-04-01',
			'start_time'       => '09:00',
			'end_time'         => '10:00',
			'is_all_day'       => 0,
			'booking_type'     => 'audience',
			'description'      => 'Team meeting',
			'status'           => 'active',
			'created_by'       => 5,
		);

		$this->booking_repo_mock->shouldReceive( 'get_all' )->once()->andReturn( array( $booking ) );
		$this->booking_repo_mock->shouldReceive( 'get_booking_audiences' )->with( 1 )->andReturn( array() );
		$this->date_blocking_mock->shouldReceive( 'get_global_holidays' )->andReturn( array() );

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
	}

	public function test_get_bookings_filters_out_bookings_from_unreadable_schedules(): void {
		// Anonymous caller: only active public schedules are readable.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );
		// Public schedule set = {20}; schedule 99 is private and must not leak.
		$this->schedule_repo_mock->shouldReceive( 'get_all' )
			->with( array( 'status' => 'active', 'visibility' => 'public' ) )
			->andReturn( array( (object) array( 'id' => 20 ) ) );

		$public_booking  = (object) array(
			'id' => 1, 'environment_id' => 10, 'environment_name' => 'Public Room',
			'schedule_id' => 20, 'booking_date' => '2026-04-01', 'start_time' => '09:00',
			'end_time' => '10:00', 'is_all_day' => 0, 'booking_type' => 'audience',
			'description' => 'Public', 'status' => 'active', 'created_by' => 7,
		);
		$private_booking = (object) array(
			'id' => 2, 'environment_id' => 11, 'environment_name' => 'Private Room',
			'schedule_id' => 99, 'booking_date' => '2026-04-02', 'start_time' => '11:00',
			'end_time' => '12:00', 'is_all_day' => 0, 'booking_type' => 'audience',
			'description' => 'Secret', 'status' => 'active', 'created_by' => 8,
		);

		$this->booking_repo_mock->shouldReceive( 'get_all' )->once()->andReturn( array( $public_booking, $private_booking ) );
		$this->booking_repo_mock->shouldReceive( 'get_booking_audiences' )->andReturn( array() );
		$this->date_blocking_mock->shouldReceive( 'get_global_holidays' )->andReturn( array() );

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
	}

	public function test_get_bookings_unrestricted_for_bypass_user(): void {
		// Bypass users read everything: readable_schedule_ids() returns null,
		// so no visibility filtering is applied (private schedule included).
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( true );

		$private_booking = (object) array(
			'id' => 2, 'environment_id' => 11, 'environment_name' => 'Private Room',
			'schedule_id' => 99, 'booking_date' => '2026-04-02', 'start_time' => '11:00',
			'end_time' => '12:00', 'is_all_day' => 0, 'booking_type' => 'audience',
			'description' => 'Secret', 'status' => 'active', 'created_by' => 8,
		);

		$this->booking_repo_mock->shouldReceive( 'get_all' )->once()->andReturn( array( $private_booking ) );
		$this->booking_repo_mock->shouldReceive( 'get_booking_audiences' )->andReturn( array() );
		$this->date_blocking_mock->shouldReceive( 'get_global_holidays' )->andReturn( array() );

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
	}

	public function test_check_conflicts_refuses_an_environment_on_an_unreadable_schedule(): void {
		// A caller probing an environment whose schedule (99) they may not
		// read is refused, exactly as for an environment that does not exist
		// (#1861) — no leak of times/descriptions.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );
		$this->schedule_repo_mock->shouldReceive( 'get_all' )
			->with( array( 'status' => 'active', 'visibility' => 'public' ) )
			->andReturn( array( (object) array( 'id' => 20 ) ) );

		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 11 )->andReturn(
			(object) array( 'id' => 11, 'schedule_id' => 99 )
		);
		// A real environment conflict exists on the private schedule.
		$this->booking_repo_mock->shouldReceive( 'get_conflicts' )->andReturn(
			array( (object) array( 'id' => 5, 'start_time' => '09:00', 'end_time' => '10:00' ) )
		);

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'environment_id' => 11,
			'booking_date'   => '2026-04-01',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
			'audience_ids'   => array(),
			'user_ids'       => array(),
		));

		$this->booking_repo_mock->shouldReceive( 'get_conflicts' )->never();

		$result = $ctrl->check_conflicts( $request );

		$this->assertSame( 404, $result->get_status() );
		$this->assertFalse( $result->get_data()['success'] );
		$this->assertArrayNotHasKey( 'conflicts', $result->get_data() );
	}

	public function test_check_conflicts_refuses_a_missing_environment(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		$this->booking_repo_mock->shouldReceive( 'get_conflicts' )->never();
		$this->booking_repo_mock->shouldReceive( 'get_user_conflicts' )->never();

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
	}

	public function test_check_conflicts_scopes_the_cross_schedule_queries_to_readable_schedules(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		$this->schedule_repo_mock->shouldReceive( 'get_by_user_access' )->with( 3 )
			->andReturn( array( (object) array( 'id' => 20 ), (object) array( 'id' => 21 ) ) );
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 11 )->andReturn( (object) array( 'id' => 11, 'schedule_id' => 20 ) );

		$this->booking_repo_mock->shouldReceive( 'get_audience_same_day_bookings' )
			->once()->with( '2026-04-01', array( 1 ), null, null, array( 20, 21 ) )->andReturn( array() );
		$this->booking_repo_mock->shouldReceive( 'get_user_conflicts' )
			->once()->with( '2026-04-01', '09:00', '10:00', array( 1 ), array( 2 ), null, null, array( 20, 21 ) )
			->andReturn( array( 'bookings' => array(), 'affected_users' => array() ) );

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
	}

	// ------------------------------------------------------------------
	// create_booking()
	// ------------------------------------------------------------------

	public function test_create_booking_returns_400_when_environment_not_found(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->andReturn( null );

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
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );

		$environment = (object) array( 'id' => 10, 'schedule_id' => 20 );
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( $environment );
		$this->schedule_repo_mock->shouldReceive( 'user_can_book' )->with( 20, 5 )->andReturn( false );

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
	}

	public function test_create_booking_returns_400_when_past_date(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_time' )->justReturn( '2026-04-01' );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );

		$environment = (object) array( 'id' => 10, 'schedule_id' => 20 );
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( $environment );
		$this->schedule_repo_mock->shouldReceive( 'user_can_book' )->andReturn( true );

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
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );

		$environment = (object) array( 'id' => 10, 'schedule_id' => 20 );
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( $environment );
		$this->schedule_repo_mock->shouldReceive( 'user_can_book' )->andReturn( true );

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
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );

		$environment = (object) array( 'id' => 10, 'schedule_id' => 20 );
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( $environment );
		$this->schedule_repo_mock->shouldReceive( 'user_can_book' )->andReturn( true );

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
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );

		$environment = (object) array( 'id' => 10, 'schedule_id' => 20 );
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( $environment );
		$this->schedule_repo_mock->shouldReceive( 'user_can_book' )->andReturn( true );

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
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );

		$environment = (object) array( 'id' => 10, 'schedule_id' => 20 );
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( $environment );
		$this->env_repo_mock->shouldReceive( 'is_open' )->andReturn( true );
		$this->schedule_repo_mock->shouldReceive( 'user_can_book' )->andReturn( true );

		$conflict = (object) array( 'id' => 99, 'start_time' => '09:00', 'end_time' => '10:00' );
		$this->booking_repo_mock->shouldReceive( 'get_conflicts' )
			->with( 10, '2026-04-01', '09:00', '10:00' )
			->andReturn( array( $conflict ) );

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
	}

	public function test_create_booking_returns_201_on_success(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_time' )->justReturn( '2026-03-01' );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( false );

		$environment = (object) array( 'id' => 10, 'schedule_id' => 20 );
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( $environment );
		$this->env_repo_mock->shouldReceive( 'is_open' )->andReturn( true );
		$this->schedule_repo_mock->shouldReceive( 'user_can_book' )->andReturn( true );
		$this->booking_repo_mock->shouldReceive( 'get_conflicts' )->andReturn( array() );
		$this->booking_writer_mock->shouldReceive( 'create' )->andReturn( 42 );

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
		$this->booking_repo_mock->shouldReceive( 'get_by_id' )->with( 999 )->andReturn( null );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'id'     => 999,
			'reason' => 'Cannot attend the meeting anymore',
		));

		$result = $ctrl->cancel_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 404, $result->get_status() );
	}

	public function test_cancel_booking_returns_400_when_already_cancelled(): void {
		$booking = (object) array(
			'id'     => 1,
			'status' => 'cancelled',
		);
		$this->booking_repo_mock->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( $booking );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'id'     => 1,
			'reason' => 'Cannot attend the meeting anymore',
		));

		$result = $ctrl->cancel_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'already cancelled', $result->get_data()['message'] );
	}

	public function test_cancel_booking_returns_200_on_success(): void {
		$booking = (object) array(
			'id'     => 1,
			'status' => 'active',
		);
		$this->booking_repo_mock->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( $booking );
		$this->booking_writer_mock->shouldReceive( 'cancel' )->with( 1, 'Cannot attend the meeting anymore' )->andReturn( true );

		$ctrl    = new AudienceRestController();
		$request = $this->make_request( array(
			'id'     => 1,
			'reason' => 'Cannot attend the meeting anymore',
		));

		$result = $ctrl->cancel_booking( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
		$this->assertTrue( $result->get_data()['success'] );
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
		$this->schedule_repo_mock->shouldReceive( 'get_by_user_access' )->andReturn( array( (object) array( 'id' => 20 ) ) );
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( (object) array( 'id' => 10, 'schedule_id' => 20 ) );
		$this->booking_repo_mock->shouldReceive( 'get_conflicts' )->andReturn( array() );
		$this->booking_repo_mock->shouldReceive( 'get_user_conflicts' )->andReturn( array(
			'bookings'       => array(),
			'affected_users' => array(),
		));

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
		$this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn(
			(object) array( 'id' => 10, 'schedule_id' => 20, 'status' => 'active' )
		);
		$open = array( 'start' => '08:00', 'end' => '18:00', 'closed' => false );
		$this->env_repo_mock->shouldReceive( 'get_working_hours' )->with( 10 )->andReturn(
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
		$this->booking_writer_mock->shouldReceive( 'create' )->never();

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( $params ) );

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( $code, $result->get_data()['code'] );
		$this->assertStringContainsString( $snippet, $result->get_data()['message'] );
	}

	public function test_a_holiday_refuses_a_caller_without_the_bypass(): void {
		$this->given_an_open_space();
		$this->env_repo_mock->shouldReceive( 'is_holiday' )->with( 10, '2026-04-01' )->andReturn( true );
		$this->booking_writer_mock->shouldReceive( 'create' )->never();

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request() );

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'holiday', $result->get_data()['code'] );
	}

	public function test_a_global_holiday_refuses_a_caller_without_the_bypass(): void {
		$this->given_an_open_space();
		$this->date_blocking_mock->shouldReceive( 'is_global_holiday' )->with( '2026-04-01' )->andReturn( true );

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request() );

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'holiday', $result->get_data()['code'] );
	}

	public function test_the_advance_window_is_counted_from_the_site_date(): void {
		$this->given_an_open_space();
		// The site's date, whatever UTC says: a limit of 1 day from 2026-03-31
		// allows 2026-04-01 and refuses 2026-04-02.
		Functions\when( 'current_time' )->justReturn( '2026-03-31' );
		$this->schedule_repo_mock->shouldReceive( 'get_by_id' )->with( 20 )->andReturn(
			(object) array( 'id' => 20, 'status' => 'active', 'future_days_limit' => '1', 'admin_bypass' => '1' )
		);

		$ctrl = new AudienceRestController();

		$inside = $ctrl->create_booking( $this->booking_request( array( 'booking_date' => '2026-04-01' ) ) );
		$this->assertSame( 201, $inside->get_status() );

		$beyond = $ctrl->create_booking( $this->booking_request( array( 'booking_date' => '2026-04-02' ) ) );
		$this->assertSame( 400, $beyond->get_status() );
		$this->assertSame( 'beyond_window', $beyond->get_data()['code'] );
	}

	public function test_an_inactive_calendar_refuses_every_caller(): void {
		$this->given_an_open_space();
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( true );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass' )->andReturn( true );
		$this->schedule_repo_mock->shouldReceive( 'get_by_id' )->with( 20 )->andReturn(
			(object) array( 'id' => 20, 'status' => 'inactive', 'future_days_limit' => null, 'admin_bypass' => '1' )
		);
		$this->booking_writer_mock->shouldReceive( 'create' )->never();

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( array( 'confirm_override' => true ) ) );

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'inactive', $result->get_data()['code'] );
	}

	public function test_an_all_day_booking_on_an_open_day_takes_the_opening_window(): void {
		$this->given_an_open_space();
		$created = null;
		$this->booking_writer_mock->shouldReceive( 'create' )->once()->andReturnUsing(
			static function ( array $data ) use ( &$created ) {
				$created = $data;
				return 77;
			}
		);

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'is_all_day' => true, 'start_time' => '00:00', 'end_time' => '23:59' ) )
		);

		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( '08:00', $created['start_time'] );
		$this->assertSame( '18:00', $created['end_time'] );
		$this->assertSame( 1, $created['is_all_day'] );
	}

	public function test_a_bypass_holder_without_the_confirmation_gets_every_reason_and_nothing_is_saved(): void {
		$this->given_an_open_space();
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass' )->with( 20, 5 )->andReturn( true );
		$this->schedule_repo_mock->shouldReceive( 'user_can_override_conflicts' )->with( 20, 5 )->andReturn( true );
		$this->env_repo_mock->shouldReceive( 'is_holiday' )->andReturn( true );
		$this->booking_repo_mock->shouldReceive( 'get_conflicts' )->andReturn(
			array( (object) array( 'id' => 99, 'start_time' => '17:00', 'end_time' => '18:00', 'description' => 'Earlier' ) )
		);
		$this->booking_writer_mock->shouldReceive( 'create' )->never();
		$this->booking_writer_mock->shouldReceive( 'cancel' )->never();

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'start_time' => '17:00', 'end_time' => '19:00' ) )
		);

		$this->assertSame( 409, $result->get_status() );
		$data = $result->get_data();
		$this->assertSame( 'override_confirmation_required', $data['code'] );
		$this->assertSame( array( 'holiday', 'outside_hours', 'conflict' ), array_column( $data['reasons'], 'code' ) );
		$this->assertSame( 99, $data['conflicts'][0]['id'] );
	}

	public function test_a_confirmed_override_is_saved_and_logged(): void {
		\Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Core\ActivityLog' => array(
					'LEVEL_INFO'    => 'info',
					'LEVEL_WARNING' => 'warning',
				),
			)
		);
		$log = Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' );
		$log->shouldReceive( 'log' )->once()->with(
			'audience_booking_override',
			'warning',
			Mockery::on(
				static function ( array $context ): bool {
					return 55 === $context['booking_id'] && array( 'closed_day' ) === $context['reasons'] && is_int( $context['confirmed_at'] );
				}
			),
			5
		);

		$this->given_an_open_space();
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass' )->with( 20, 5 )->andReturn( true );
		$this->booking_writer_mock->shouldReceive( 'create' )->once()->andReturn( 55 );

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'booking_date' => '2026-04-04', 'confirm_override' => true ) )
		);

		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( 55, $result->get_data()['booking_id'] );

		\Mockery::getConfiguration()->setConstantsMap( array() );
	}

	public function test_a_bypass_capability_with_the_calendar_toggle_off_gets_every_rule(): void {
		$this->given_an_open_space();
		// The capability is held, but the calendar's Admin bypass is off, so
		// the per-calendar answer is no.
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass_capability' )->andReturn( true );
		$this->schedule_repo_mock->shouldReceive( 'user_has_bypass' )->with( 20, 5 )->andReturn( false );
		$this->booking_writer_mock->shouldReceive( 'create' )->never();

		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'booking_date' => '2026-04-04', 'confirm_override' => true ) )
		);

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'closed_day', $result->get_data()['code'] );
	}

	public function test_the_appointments_bypass_alone_does_not_bypass_audience_rules(): void {
		// `ffc_bypass_appointments` is not read by the audience controller at
		// all (#1859): the only bypass it asks about is the audience one.
		$calendar = Mockery::mock( 'alias:\FreeFormCertificate\Repositories\CalendarRepository' );
		$calendar->shouldReceive( 'userHasSchedulingBypass' )->never();

		$this->given_an_open_space();
		$result = ( new AudienceRestController() )->create_booking(
			$this->booking_request( array( 'booking_date' => '2026-04-04', 'confirm_override' => true ) )
		);

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'closed_day', $result->get_data()['code'] );
	}

	public function test_a_confirmed_conflict_override_cancels_the_earlier_booking(): void {
		$this->given_an_open_space();
		$this->schedule_repo_mock->shouldReceive( 'user_can_override_conflicts' )->with( 20, 5 )->andReturn( true );
		$this->booking_repo_mock->shouldReceive( 'get_conflicts' )->andReturn(
			array( (object) array( 'id' => 99, 'start_time' => '09:00', 'end_time' => '10:00', 'description' => 'Earlier' ) )
		);
		$this->booking_writer_mock->shouldReceive( 'create' )->once()->andReturn( 56 );
		$this->booking_writer_mock->shouldReceive( 'cancel' )->once()->with( 99, Mockery::type( 'string' ) )->andReturn( true );

		$actions = array();
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$actions ) {
				$actions[] = $args;
			}
		);

		\Mockery::getConfiguration()->setConstantsMap(
			array( 'FreeFormCertificate\Core\ActivityLog' => array( 'LEVEL_WARNING' => 'warning' ) )
		);
		$log = Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' );
		$log->shouldReceive( 'log' )->once();

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( array( 'confirm_override' => true ) ) );

		$this->assertSame( 201, $result->get_status() );
		$overridden = array_values(
			array_filter(
				$actions,
				static fn( array $a ): bool => 'ffcertificate_audience_booking_overridden' === $a[0]
			)
		);
		$this->assertCount( 1, $overridden );
		$this->assertSame( 99, $overridden[0][1] );
		$this->assertSame( 56, $overridden[0][2] );

		\Mockery::getConfiguration()->setConstantsMap( array() );
	}

	public function test_a_conflict_without_override_permission_is_refused(): void {
		$this->given_an_open_space();
		$this->booking_repo_mock->shouldReceive( 'get_conflicts' )->andReturn(
			array( (object) array( 'id' => 99, 'start_time' => '09:00', 'end_time' => '10:00' ) )
		);
		$this->booking_writer_mock->shouldReceive( 'create' )->never();
		$this->booking_writer_mock->shouldReceive( 'cancel' )->never();

		$result = ( new AudienceRestController() )->create_booking( $this->booking_request( array( 'confirm_override' => true ) ) );

		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'conflict', $result->get_data()['code'] );
	}

	public function test_get_bookings_for_a_whole_calendar_reports_each_space_and_the_shared_closures(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->schedule_repo_mock->shouldReceive( 'get_by_user_access' )->with( 5 )->andReturn( array( (object) array( 'id' => 20 ) ) );

		$this->env_repo_mock->shouldReceive( 'get_by_schedule' )->with( 20, 'active' )->andReturn(
			array(
				(object) array( 'id' => 10, 'schedule_id' => 20, 'status' => 'active' ),
				(object) array( 'id' => 11, 'schedule_id' => 20, 'status' => 'active' ),
			)
		);
		$open   = array( 'start' => '08:00', 'end' => '18:00', 'closed' => false );
		$closed = array( 'start' => '08:00', 'end' => '18:00', 'closed' => true );
		// Space 10 is closed at weekends; space 11 opens on Saturday.
		$this->env_repo_mock->shouldReceive( 'get_working_hours' )->with( 10 )->andReturn(
			array( 'sun' => $closed, 'mon' => $open, 'tue' => $open, 'wed' => $open, 'thu' => $open, 'fri' => $open, 'sat' => $closed )
		);
		$this->env_repo_mock->shouldReceive( 'get_working_hours' )->with( 11 )->andReturn(
			array( 'sun' => $closed, 'mon' => $open, 'tue' => $open, 'wed' => $open, 'thu' => $open, 'fri' => $open, 'sat' => $open )
		);
		$this->env_repo_mock->shouldReceive( 'get_holidays' )->with( 20, '2026-04-01', '2026-04-30' )->andReturn(
			array( (object) array( 'holiday_date' => '2026-04-21', 'description' => 'Local' ) )
		);

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
	}
}
