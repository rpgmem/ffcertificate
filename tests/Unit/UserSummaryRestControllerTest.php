<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserSummaryRestController;

/**
 * Tests for UserSummaryRestController: route registration, summary data,
 * capability gating, and exception handling.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UserSummaryRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array Captured route registrations */
    private array $registered_routes = [];

    /** @var object Mock $wpdb */
    private $wpdb;

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
        // sprintf is a PHP internal — no need to stub it
        Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'date_i18n' )->alias( function( $format, $timestamp = false ) {
            return date( $format, $timestamp ?: time() );
        });

        // Alias mocks for static-only dependencies
        $utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utils_mock->shouldReceive( 'debug_log' )->byDefault();
        $utils_mock->shouldReceive( 'get_submissions_table' )->andReturn( 'wp_ffc_submissions' )->byDefault();

        $rereg_mock = Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\ReregistrationFrontend' );
        $rereg_mock->shouldReceive( 'get_user_reregistrations' )->andReturn( array() )->byDefault();

        // Global $wpdb mock
        $this->wpdb = Mockery::mock( 'wpdb' );
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( '' )->byDefault();
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        unset( $GLOBALS['wpdb'] );
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
        $ctrl = new UserSummaryRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertCount( 1, $this->registered_routes );
    }

    public function test_summary_route_registered(): void {
        $ctrl = new UserSummaryRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertSame( '/user/summary', $this->registered_routes[0]['route'] );
    }

    public function test_summary_route_requires_authentication(): void {
        $ctrl = new UserSummaryRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertSame(
            'is_user_logged_in',
            $this->registered_routes[0]['args']['permission_callback']
        );
    }

    // ------------------------------------------------------------------
    // get_user_summary() — no capabilities
    // ------------------------------------------------------------------

    public function test_get_user_summary_returns_all_zeros_when_no_capabilities(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'user_can' )->justReturn( false );

        $ctrl    = new UserSummaryRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_summary( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 0, $result['total_certificates'] );
        $this->assertNull( $result['next_appointment'] );
        $this->assertSame( 0, $result['upcoming_group_events'] );
        $this->assertSame( 0, $result['pending_reregistrations'] );
    }

    public function test_get_user_summary_returns_zeros_even_when_user_id_is_zero(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserSummaryRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_summary( $request );

        // The method does NOT return WP_Error for user_id=0; it proceeds with defaults
        $this->assertIsArray( $result );
        $this->assertSame( 0, $result['total_certificates'] );
        $this->assertNull( $result['next_appointment'] );
    }

    // ------------------------------------------------------------------
    // get_user_summary() — exception path
    // ------------------------------------------------------------------

    public function test_get_user_summary_returns_defaults_on_exception(): void {
        // Make get_current_user_id throw to trigger the catch block
        Functions\when( 'get_current_user_id' )->alias( function() {
            throw new \Exception( 'Simulated failure' );
        });

        $ctrl    = new UserSummaryRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_summary( $request );

        // On exception, returns defaults (NOT WP_Error)
        $this->assertIsArray( $result );
        $this->assertSame( 0, $result['total_certificates'] );
        $this->assertNull( $result['next_appointment'] );
        $this->assertSame( 0, $result['upcoming_group_events'] );
        $this->assertSame( 0, $result['pending_reregistrations'] );
    }

    // ------------------------------------------------------------------
    // get_user_summary() — certificates count with manage_options
    // ------------------------------------------------------------------

    public function test_get_user_summary_counts_certificates_when_manage_options(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->alias( function( $cap ) {
            // manage_options grants view_own_certificates via user_has_capability
            return $cap === 'manage_options';
        });

        // The DB query for certificates count
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '7' );

        // table_exists check for audience bookings
        // wpdb->get_var for SHOW TABLES LIKE returns table name (truthy)
        // but we've already set default to '7', so table_exists will see a string — that's fine
        // The audience bookings count will also return '7', but that's acceptable.

        $ctrl    = new UserSummaryRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_summary( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 7, $result['total_certificates'] );
    }

    // ------------------------------------------------------------------
    // get_user_summary() — next appointment when capability granted
    // ------------------------------------------------------------------

    public function test_get_user_summary_includes_next_appointment_when_capability_granted(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->alias( function( $cap ) {
            return $cap === 'manage_options';
        });

        // Certificates count
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

        // Next appointment query returns a row
        $appointment_row = array(
            'appointment_date' => '2026-06-15',
            'start_time'       => '10:30:00',
            'calendar_title'   => 'Main Calendar',
        );
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( $appointment_row );

        Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
            if ( $key === 'ffc_settings' ) {
                return array( 'date_format' => 'Y-m-d' );
            }
            return $default;
        });

        $ctrl    = new UserSummaryRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_summary( $request );

        $this->assertIsArray( $result );
        $this->assertNotNull( $result['next_appointment'] );
        $this->assertSame( 'Main Calendar', $result['next_appointment']['title'] );
        $this->assertSame( '10:30', $result['next_appointment']['time'] );
    }

    public function test_get_user_summary_next_appointment_null_when_no_upcoming(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->alias( function( $cap ) {
            return $cap === 'manage_options';
        });

        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

        $ctrl    = new UserSummaryRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_summary( $request );

        $this->assertIsArray( $result );
        $this->assertNull( $result['next_appointment'] );
    }
}
