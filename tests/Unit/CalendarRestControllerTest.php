<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\CalendarRestController;

/**
 * Tests for CalendarRestController: route registration, get_calendars,
 * get_calendar, and get_calendar_slots endpoints.
 */
class CalendarRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array Captured route registrations */
    private array $registered_routes = [];

    /** @var \Mockery\MockInterface Mock for CalendarRepository (overload) */
    private $calendar_repo_mock;

    /** @var \Mockery\MockInterface Mock for AppointmentHandler (overload) */
    private $appointment_handler_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->registered_routes = [];

        // Capture register_rest_route calls
        Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
            $this->registered_routes[] = array(
                'namespace' => $namespace,
                'route'     => $route,
                'args'      => $args,
            );
        });

        Functions\when( '__' )->returnArg();
        Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        // Overload mocks for classes instantiated with `new` inside the controller
        $this->calendar_repo_mock = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\CalendarRepository' );
        $this->calendar_repo_mock->shouldReceive( 'getPublicActiveCalendars' )->andReturn( [] )->byDefault();
        $this->calendar_repo_mock->shouldReceive( 'findAll' )->andReturn( [] )->byDefault();
        $this->calendar_repo_mock->shouldReceive( 'getWithWorkingHours' )->andReturn( null )->byDefault();
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false )->byDefault();

        $this->appointment_handler_mock = Mockery::mock( 'overload:\FreeFormCertificate\SelfScheduling\AppointmentHandler' );
        $this->appointment_handler_mock->shouldReceive( 'get_available_slots' )->andReturn( [] )->byDefault();

        // Utils alias for log_rest_error
        $utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utils_mock->shouldReceive( 'debug_log' )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
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

    public function test_get_calendars_returns_public_calendars_for_anon(): void {
        $sample = array(
            array(
                'id' => 1,
                'title' => 'Public Cal',
                'description' => 'Desc',
                'requires_approval' => 0,
                'visibility' => 'public',
                'scheduling_visibility' => 'public',
                'allow_cancellation' => 1,
                'slot_duration' => 30,
                'advance_booking_min' => 1,
                'advance_booking_max' => 30,
            ),
        );

        $this->calendar_repo_mock->shouldReceive( 'getPublicActiveCalendars' )->once()->andReturn( $sample );

        $ctrl = new CalendarRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result = $ctrl->get_calendars( $request );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'calendars', $result );
        $this->assertCount( 1, $result['calendars'] );
        $this->assertSame( 1, $result['calendars'][0]['id'] );
        $this->assertSame( 1, $result['total'] );
    }

    public function test_get_calendars_returns_all_calendars_for_logged_in_user(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );

        $sample = array(
            array(
                'id' => 1,
                'title' => 'Cal A',
                'description' => '',
                'requires_approval' => 0,
                'visibility' => 'public',
                'scheduling_visibility' => 'public',
                'allow_cancellation' => 1,
                'slot_duration' => 30,
                'advance_booking_min' => 1,
                'advance_booking_max' => 30,
            ),
            array(
                'id' => 2,
                'title' => 'Cal B',
                'description' => 'Private',
                'requires_approval' => 1,
                'visibility' => 'private',
                'scheduling_visibility' => 'private',
                'allow_cancellation' => 0,
                'slot_duration' => 60,
                'advance_booking_min' => 2,
                'advance_booking_max' => 60,
            ),
        );

        $this->calendar_repo_mock->shouldReceive( 'findAll' )
            ->once()
            ->with( array( 'status' => 'active' ), 'title', 'ASC' )
            ->andReturn( $sample );

        $ctrl = new CalendarRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result = $ctrl->get_calendars( $request );

        $this->assertCount( 2, $result['calendars'] );
        $this->assertSame( 2, $result['total'] );
    }

    // ------------------------------------------------------------------
    // get_calendar()
    // ------------------------------------------------------------------

    public function test_get_calendar_returns_error_when_not_found(): void {
        $this->calendar_repo_mock->shouldReceive( 'getWithWorkingHours' )
            ->with( '99' )
            ->andReturn( null );

        $ctrl = new CalendarRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '99' ) );
        $result = $ctrl->get_calendar( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'calendar_not_found', $result->get_error_code() );
    }

    public function test_get_calendar_returns_error_when_inactive(): void {
        $this->calendar_repo_mock->shouldReceive( 'getWithWorkingHours' )
            ->with( '5' )
            ->andReturn( array(
                'id' => 5,
                'status' => 'inactive',
                'title' => 'Disabled',
                'visibility' => 'public',
            ));

        $ctrl = new CalendarRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '5' ) );
        $result = $ctrl->get_calendar( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'calendar_inactive', $result->get_error_code() );
    }

    public function test_get_calendar_returns_error_for_private_calendar_anon(): void {
        $this->calendar_repo_mock->shouldReceive( 'getWithWorkingHours' )
            ->with( '7' )
            ->andReturn( array(
                'id' => 7,
                'status' => 'active',
                'title' => 'Private Cal',
                'description' => '',
                'requires_approval' => 0,
                'visibility' => 'private',
                'scheduling_visibility' => 'public',
                'allow_cancellation' => 1,
                'cancellation_min_hours' => 24,
                'slot_duration' => 30,
                'slot_interval' => 30,
                'max_appointments_per_slot' => 1,
                'advance_booking_min' => 1,
                'advance_booking_max' => 30,
                'working_hours' => array(),
            ));

        $ctrl = new CalendarRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '7' ) );
        $result = $ctrl->get_calendar( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'calendar_private', $result->get_error_code() );
    }

    public function test_get_calendar_returns_data_on_success(): void {
        $calendar_data = array(
            'id' => 3,
            'status' => 'active',
            'title' => 'Active Calendar',
            'description' => 'Some description',
            'requires_approval' => 1,
            'visibility' => 'public',
            'scheduling_visibility' => 'public',
            'allow_cancellation' => 1,
            'cancellation_min_hours' => 24,
            'slot_duration' => 30,
            'slot_interval' => 15,
            'max_appointments_per_slot' => 3,
            'advance_booking_min' => 1,
            'advance_booking_max' => 60,
            'working_hours' => array( 'mon' => '09:00-17:00' ),
        );

        $this->calendar_repo_mock->shouldReceive( 'getWithWorkingHours' )
            ->with( '3' )
            ->andReturn( $calendar_data );

        $ctrl = new CalendarRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '3' ) );
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
        $slots = array(
            array( 'time' => '09:00', 'available' => true ),
            array( 'time' => '09:30', 'available' => true ),
        );

        $this->appointment_handler_mock->shouldReceive( 'get_available_slots' )
            ->with( '3', '2026-04-01' )
            ->once()
            ->andReturn( $slots );

        $ctrl = new CalendarRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '3', 'date' => '2026-04-01' ) );
        $result = $ctrl->get_calendar_slots( $request );

        $this->assertIsArray( $result );
        $this->assertSame( $slots, $result['slots'] );
        $this->assertSame( '2026-04-01', $result['date'] );
        $this->assertSame( '3', $result['calendar_id'] );
    }

    public function test_get_calendar_slots_propagates_wp_error(): void {
        $wp_error = new \WP_Error( 'no_slots', 'No available slots', array( 'status' => 404 ) );

        $this->appointment_handler_mock->shouldReceive( 'get_available_slots' )
            ->with( '3', '2026-04-01' )
            ->once()
            ->andReturn( $wp_error );

        $ctrl = new CalendarRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '3', 'date' => '2026-04-01' ) );
        $result = $ctrl->get_calendar_slots( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'no_slots', $result->get_error_code() );
    }
}
