<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserAppointmentsRestController;

/**
 * Tests for UserAppointmentsRestController: route registration, permission checks,
 * and get_user_appointments success/error paths.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UserAppointmentsRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array Captured route registrations */
    private array $registered_routes = [];

    /** @var Mockery\MockInterface Mock AppointmentRepository */
    private $appointment_repo;

    /** @var Mockery\MockInterface Mock CalendarRepository */
    private $calendar_repo;

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
        Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'user_can' )->justReturn( false );
        Functions\when( 'get_option' )->justReturn( 'F j, Y' );
        Functions\when( 'date_i18n' )->alias( function( $format, $timestamp = false ) {
            return date( $format, $timestamp ?: time() );
        });
        Functions\when( 'wp_timezone' )->alias( function() {
            return new \DateTimeZone( 'UTC' );
        });
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );

        // Alias mocks for static-only dependencies
        $utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utils_mock->shouldReceive( 'debug_log' )->byDefault();

        $encryption_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Encryption' );
        $encryption_mock->shouldReceive( 'decrypt_field' )->andReturnUsing( function( $row, $field ) {
            return $row[ $field ] ?? '';
        })->byDefault();

        $magic_link_mock = Mockery::mock( 'alias:\FreeFormCertificate\Generators\MagicLinkHelper' );
        $magic_link_mock->shouldReceive( 'generate_magic_link' )->andReturnUsing( function( $token ) {
            return 'https://example.com/magic/' . $token;
        })->byDefault();

        // Overload mocks for repositories instantiated with `new`
        $this->appointment_repo = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\AppointmentRepository' );
        $this->appointment_repo->shouldReceive( 'findByUserId' )->andReturn( array() )->byDefault();

        $this->calendar_repo = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\CalendarRepository' );
        $this->calendar_repo->shouldReceive( 'findByIds' )->andReturn( array() )->byDefault();
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
    }

    // ------------------------------------------------------------------
    // get_user_appointments — success paths
    // ------------------------------------------------------------------

    public function test_get_user_appointments_returns_empty_when_no_appointments(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->appointment_repo->shouldReceive( 'findByUserId' )->with( 5 )->andReturn( array() );

        $ctrl    = new UserAppointmentsRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_appointments( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 0, $result['total'] );
        $this->assertEmpty( $result['appointments'] );
    }

    public function test_get_user_appointments_returns_formatted_appointment_data(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( true );

        // Use a future date to test can_cancel logic
        $future_date = date( 'Y-m-d', strtotime( '+30 days' ) );

        $appointments = array(
            array(
                'id'                 => '10',
                'calendar_id'        => '2',
                'appointment_date'   => $future_date,
                'start_time'         => '14:00:00',
                'end_time'           => '15:00:00',
                'status'             => 'confirmed',
                'name'               => 'John Doe',
                'email'              => 'john@example.com',
                'phone'              => '(11) 99999-0000',
                'user_notes'         => 'Test note',
                'created_at'         => '2025-06-01 10:00:00',
                'confirmation_token' => 'conf_token_123',
            ),
        );

        $calendars_map = array(
            2 => array(
                'id'                      => 2,
                'title'                   => 'Main Calendar',
                'allow_cancellation'      => true,
                'cancellation_min_hours'  => 24,
            ),
        );

        $this->appointment_repo->shouldReceive( 'findByUserId' )->with( 5 )->andReturn( $appointments );
        $this->calendar_repo->shouldReceive( 'findByIds' )->andReturn( $calendars_map );

        $ctrl    = new UserAppointmentsRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_appointments( $request );

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
        $this->assertNotEmpty( $apt['receipt_url'] );
    }

    public function test_get_user_appointments_uses_unknown_calendar_when_not_found(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $future_date = date( 'Y-m-d', strtotime( '+30 days' ) );

        $appointments = array(
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

        $this->appointment_repo->shouldReceive( 'findByUserId' )->with( 5 )->andReturn( $appointments );
        // Calendar 99 not found in the map
        $this->calendar_repo->shouldReceive( 'findByIds' )->andReturn( array() );

        $ctrl    = new UserAppointmentsRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_appointments( $request );

        $this->assertIsArray( $result );
        $apt = $result['appointments'][0];
        $this->assertSame( 'Unknown Calendar', $apt['calendar_title'] );
        $this->assertSame( 'Pending', $apt['status_label'] );
    }

    public function test_get_user_appointments_skips_invalid_entries(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $future_date = date( 'Y-m-d', strtotime( '+30 days' ) );

        $appointments = array(
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

        $this->appointment_repo->shouldReceive( 'findByUserId' )->with( 5 )->andReturn( $appointments );
        $this->calendar_repo->shouldReceive( 'findByIds' )->andReturn( array() );

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
