<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\AppointmentRestController;

/**
 * Tests for AppointmentRestController: route registration, create_appointment,
 * get_appointment, cancel_appointment, and check_appointment_access endpoints.
 */
class AppointmentRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array Captured route registrations */
    private array $registered_routes = [];

    /** @var \Mockery\MockInterface Mock for CalendarRepository (overload) */
    private $calendar_repo_mock;

    /** @var \Mockery\MockInterface Mock for AppointmentRepository (overload) */
    private $appointment_repo_mock;

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
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'is_email' )->alias( function( $email ) { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_email' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        // Overload mocks for classes instantiated with `new` inside the controller
        $this->calendar_repo_mock = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\CalendarRepository' );
        $this->calendar_repo_mock->shouldReceive( 'findById' )->andReturn( null )->byDefault();
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false )->byDefault();

        $this->appointment_repo_mock = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\AppointmentRepository' );
        $this->appointment_repo_mock->shouldReceive( 'findById' )->andReturn( null )->byDefault();

        $this->appointment_handler_mock = Mockery::mock( 'overload:\FreeFormCertificate\SelfScheduling\AppointmentHandler' );
        $this->appointment_handler_mock->shouldReceive( 'process_appointment' )->andReturn( array( 'appointment_id' => 1, 'requires_approval' => false ) )->byDefault();
        $this->appointment_handler_mock->shouldReceive( 'cancel_appointment' )->andReturn( true )->byDefault();

        // Alias mocks for static-only classes
        $utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utils_mock->shouldReceive( 'get_user_ip' )->andReturn( '127.0.0.1' )->byDefault();
        $utils_mock->shouldReceive( 'debug_log' )->byDefault();

        $encryption_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Encryption' );
        $encryption_mock->shouldReceive( 'decrypt_field' )->andReturnUsing( function( $row, $field ) {
            return $row[ $field ] ?? '';
        })->byDefault();

        // RateLimiter alias
        $rate_limiter_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
        $rate_limiter_mock->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();
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
        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertCount( 3, $this->registered_routes );
    }

    public function test_create_appointment_route_is_public(): void {
        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $route = $this->registered_routes[0];
        $this->assertStringContainsString( 'appointments', $route['route'] );
        $this->assertSame( '__return_true', $route['args']['permission_callback'] );
    }

    public function test_get_and_cancel_appointment_routes_require_access_check(): void {
        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $ctrl->register_routes();

        // GET /appointments/{id}
        $route_get = $this->registered_routes[1];
        $this->assertSame( array( $ctrl, 'check_appointment_access' ), $route_get['args']['permission_callback'] );

        // DELETE /appointments/{id}
        $route_delete = $this->registered_routes[2];
        $this->assertSame( array( $ctrl, 'check_appointment_access' ), $route_delete['args']['permission_callback'] );
    }

    // ------------------------------------------------------------------
    // create_appointment()
    // ------------------------------------------------------------------

    public function test_create_appointment_returns_error_when_params_empty(): void {
        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '1' ), [] );
        $result = $ctrl->create_appointment( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'no_data', $result->get_error_code() );
    }

    public function test_create_appointment_returns_error_when_required_fields_missing(): void {
        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request(
            array( 'id' => '1' ),
            array( 'date' => '2026-04-01', 'time' => '09:00' )
        );
        $result = $ctrl->create_appointment( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'missing_field', $result->get_error_code() );
    }

    public function test_create_appointment_returns_error_for_invalid_email(): void {
        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request(
            array( 'id' => '1' ),
            array(
                'date'  => '2026-04-01',
                'time'  => '09:00',
                'name'  => 'John Doe',
                'email' => 'not-an-email',
            )
        );
        $result = $ctrl->create_appointment( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_email', $result->get_error_code() );
    }

    public function test_create_appointment_returns_error_when_calendar_not_found(): void {
        $this->calendar_repo_mock->shouldReceive( 'findById' )->with( '1' )->andReturn( null );

        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request(
            array( 'id' => '1' ),
            array(
                'date'  => '2026-04-01',
                'time'  => '09:00',
                'name'  => 'John Doe',
                'email' => 'john@example.com',
            )
        );
        $result = $ctrl->create_appointment( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'calendar_not_found', $result->get_error_code() );
    }

    public function test_create_appointment_returns_error_for_private_scheduling_anon(): void {
        $this->calendar_repo_mock->shouldReceive( 'findById' )->with( '1' )->andReturn( array(
            'id' => 1,
            'scheduling_visibility' => 'private',
        ));

        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request(
            array( 'id' => '1' ),
            array(
                'date'  => '2026-04-01',
                'time'  => '09:00',
                'name'  => 'John Doe',
                'email' => 'john@example.com',
            )
        );
        $result = $ctrl->create_appointment( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'scheduling_private', $result->get_error_code() );
    }

    public function test_create_appointment_success_returns_data(): void {
        $this->calendar_repo_mock->shouldReceive( 'findById' )->with( '1' )->andReturn( array(
            'id' => 1,
            'scheduling_visibility' => 'public',
        ));

        $this->appointment_handler_mock->shouldReceive( 'process_appointment' )
            ->once()
            ->andReturn( array(
                'appointment_id'    => 42,
                'requires_approval' => true,
            ));

        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request(
            array( 'id' => '1' ),
            array(
                'date'  => '2026-04-01',
                'time'  => '09:00',
                'name'  => 'John Doe',
                'email' => 'john@example.com',
            )
        );
        $result = $ctrl->create_appointment( $request );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
        $this->assertSame( 42, $result['appointment_id'] );
        $this->assertTrue( $result['requires_approval'] );
    }

    // ------------------------------------------------------------------
    // get_appointment()
    // ------------------------------------------------------------------

    public function test_get_appointment_returns_error_when_not_found(): void {
        $this->appointment_repo_mock->shouldReceive( 'findById' )->with( '99' )->andReturn( null );

        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '99' ) );
        $result = $ctrl->get_appointment( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'appointment_not_found', $result->get_error_code() );
    }

    public function test_get_appointment_returns_formatted_data_on_success(): void {
        $appointment_data = array(
            'id'               => 10,
            'calendar_id'      => 1,
            'appointment_date' => '2026-04-01',
            'start_time'       => '09:00',
            'end_time'         => '09:30',
            'status'           => 'confirmed',
            'name'             => 'Jane Doe',
            'email'            => 'jane@example.com',
            'phone'            => '11999998888',
            'user_notes'       => 'Test note',
            'created_at'       => '2026-03-01 10:00:00',
        );

        $this->appointment_repo_mock->shouldReceive( 'findById' )->with( '10' )->andReturn( $appointment_data );
        $this->calendar_repo_mock->shouldReceive( 'findById' )->with( 1 )->andReturn( array(
            'id'    => 1,
            'title' => 'Test Calendar',
        ));

        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '10' ) );
        $result = $ctrl->get_appointment( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 10, $result['id'] );
        $this->assertSame( 1, $result['calendar_id'] );
        $this->assertSame( 'Test Calendar', $result['calendar_title'] );
        $this->assertSame( 'confirmed', $result['status'] );
        $this->assertSame( 'jane@example.com', $result['email'] );
    }

    // ------------------------------------------------------------------
    // cancel_appointment()
    // ------------------------------------------------------------------

    public function test_cancel_appointment_propagates_wp_error(): void {
        $wp_error = new \WP_Error( 'cannot_cancel', 'Cannot cancel', array( 'status' => 400 ) );

        $this->appointment_handler_mock->shouldReceive( 'cancel_appointment' )
            ->with( '5', '', '' )
            ->once()
            ->andReturn( $wp_error );

        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '5' ), [] );
        $result = $ctrl->cancel_appointment( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'cannot_cancel', $result->get_error_code() );
    }

    public function test_cancel_appointment_returns_success(): void {
        $this->appointment_handler_mock->shouldReceive( 'cancel_appointment' )
            ->with( '5', '', 'Scheduling conflict' )
            ->once()
            ->andReturn( true );

        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request(
            array( 'id' => '5' ),
            array( 'reason' => 'Scheduling conflict' )
        );
        $result = $ctrl->cancel_appointment( $request );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
    }

    // ------------------------------------------------------------------
    // check_appointment_access()
    // ------------------------------------------------------------------

    public function test_check_appointment_access_bypass_returns_true(): void {
        $this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( true );

        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '1' ) );

        $this->assertTrue( $ctrl->check_appointment_access( $request ) );
    }

    public function test_check_appointment_access_not_logged_in_returns_false(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '1' ) );

        $this->assertFalse( $ctrl->check_appointment_access( $request ) );
    }

    public function test_check_appointment_access_own_appointment_returns_true(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 42 );

        $this->appointment_repo_mock->shouldReceive( 'findById' )->with( '10' )->andReturn( array(
            'id'      => 10,
            'user_id' => 42,
        ));

        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '10' ) );

        $this->assertTrue( $ctrl->check_appointment_access( $request ) );
    }

    public function test_check_appointment_access_other_appointment_returns_false(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 42 );

        $this->appointment_repo_mock->shouldReceive( 'findById' )->with( '10' )->andReturn( array(
            'id'      => 10,
            'user_id' => 99,
        ));

        $ctrl = new AppointmentRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'id' => '10' ) );

        $this->assertFalse( $ctrl->check_appointment_access( $request ) );
    }
}
