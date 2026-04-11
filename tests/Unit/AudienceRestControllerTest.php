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
    private $calendar_repo_mock;

    /** @var \Mockery\MockInterface */
    private $booking_repo_mock;

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

        // Alias mocks for static-only dependencies
        $this->calendar_repo_mock = Mockery::mock( 'alias:\FreeFormCertificate\Repositories\CalendarRepository' );
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false )->byDefault();

        $this->booking_repo_mock = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceBookingRepository' );
        $this->booking_repo_mock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
        $this->booking_repo_mock->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();
        $this->booking_repo_mock->shouldReceive( 'get_booking_audiences' )->andReturn( array() )->byDefault();
        $this->booking_repo_mock->shouldReceive( 'get_conflicts' )->andReturn( array() )->byDefault();
        $this->booking_repo_mock->shouldReceive( 'get_audience_same_day_bookings' )->andReturn( array() )->byDefault();
        $this->booking_repo_mock->shouldReceive( 'get_user_conflicts' )->andReturn( array( 'bookings' => array(), 'affected_users' => array() ) )->byDefault();
        $this->booking_repo_mock->shouldReceive( 'create' )->andReturn( 1 )->byDefault();
        $this->booking_repo_mock->shouldReceive( 'cancel' )->andReturn( true )->byDefault();

        $this->env_repo_mock = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
        $this->env_repo_mock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
        $this->env_repo_mock->shouldReceive( 'get_holidays' )->andReturn( array() )->byDefault();
        $this->env_repo_mock->shouldReceive( 'get_working_hours' )->andReturn( null )->byDefault();
        $this->env_repo_mock->shouldReceive( 'is_open' )->andReturn( true )->byDefault();

        $this->schedule_repo_mock = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceScheduleRepository' );
        $this->schedule_repo_mock->shouldReceive( 'user_can_cancel_others' )->andReturn( false )->byDefault();
        $this->schedule_repo_mock->shouldReceive( 'user_can_book' )->andReturn( true )->byDefault();
        $this->schedule_repo_mock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();

        $this->date_blocking_mock = Mockery::mock( 'alias:\FreeFormCertificate\Scheduling\DateBlockingService' );
        $this->date_blocking_mock->shouldReceive( 'get_global_holidays' )->andReturn( array() )->byDefault();
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
        $this->assertSame( array( $ctrl, 'check_read_permission' ), $route['args']['permission_callback'] );
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
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( true );

        $ctrl = new AudienceRestController();
        $this->assertTrue( $ctrl->check_write_permission() );
    }

    public function test_check_write_permission_returns_true_when_has_capability(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );

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
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( true );

        $ctrl    = new AudienceRestController();
        $request = $this->make_request( array( 'id' => 1 ) );
        $this->assertTrue( $ctrl->check_cancel_permission( $request ) );
    }

    public function test_check_cancel_permission_returns_true_when_creator(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );

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
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );

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
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );

        $booking = (object) array(
            'id'               => 1,
            'environment_id'   => 10,
            'environment_name' => 'Room A',
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

    // ------------------------------------------------------------------
    // create_booking()
    // ------------------------------------------------------------------

    public function test_create_booking_returns_400_when_environment_not_found(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );
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
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );

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
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );

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
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );

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
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );

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
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );

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
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );

        $environment = (object) array( 'id' => 10, 'schedule_id' => 20 );
        $this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( $environment );
        $this->env_repo_mock->shouldReceive( 'is_open' )->andReturn( true );
        $this->schedule_repo_mock->shouldReceive( 'user_can_book' )->andReturn( true );
        $this->schedule_repo_mock->shouldReceive( 'get_by_id' )->andReturn( null );

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
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false );

        $environment = (object) array( 'id' => 10, 'schedule_id' => 20 );
        $this->env_repo_mock->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( $environment );
        $this->env_repo_mock->shouldReceive( 'is_open' )->andReturn( true );
        $this->schedule_repo_mock->shouldReceive( 'user_can_book' )->andReturn( true );
        $this->schedule_repo_mock->shouldReceive( 'get_by_id' )->andReturn( null );
        $this->booking_repo_mock->shouldReceive( 'get_conflicts' )->andReturn( array() );
        $this->booking_repo_mock->shouldReceive( 'create' )->andReturn( 42 );

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
        $this->booking_repo_mock->shouldReceive( 'cancel' )->with( 1, 'Cannot attend the meeting anymore' )->andReturn( true );

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
}
