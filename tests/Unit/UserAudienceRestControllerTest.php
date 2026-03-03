<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserAudienceRestController;

/**
 * Tests for UserAudienceRestController: route registration, permission checks,
 * and endpoint callback error paths.
 */
class UserAudienceRestControllerTest extends TestCase {

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
        Functions\when( 'get_option' )->justReturn( 'F j, Y' );
        Functions\when( 'date_i18n' )->alias( function( $format, $timestamp = false ) {
            return date( $format, $timestamp ?: time() );
        });

        // Alias mocks for static-only dependencies
        $utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utils_mock->shouldReceive( 'debug_log' )->byDefault();

        $user_manager_mock = Mockery::mock( 'alias:\FreeFormCertificate\UserDashboard\UserManager' );
        $user_manager_mock->shouldReceive( 'grant_audience_capabilities' )->byDefault();

        // Global $wpdb mock
        $this->wpdb = Mockery::mock( 'wpdb' );
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( '' )->byDefault();
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
        $this->wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
        $this->wpdb->shouldReceive( 'delete' )->andReturn( 1 )->byDefault();
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

    public function test_register_routes_creates_four_endpoints(): void {
        $ctrl = new UserAudienceRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertCount( 4, $this->registered_routes );
    }

    public function test_audience_bookings_route_registered(): void {
        $ctrl = new UserAudienceRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/audience-bookings', $paths );
    }

    public function test_joinable_groups_route_registered(): void {
        $ctrl = new UserAudienceRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/joinable-groups', $paths );
    }

    public function test_all_routes_require_authentication(): void {
        $ctrl = new UserAudienceRestController( 'ffc/v1' );
        $ctrl->register_routes();

        foreach ( $this->registered_routes as $route ) {
            $this->assertSame(
                'is_user_logged_in',
                $route['args']['permission_callback'],
                "Route {$route['route']} should require authentication"
            );
        }
    }

    // ------------------------------------------------------------------
    // get_user_audience_bookings()
    // ------------------------------------------------------------------

    public function test_get_user_audience_bookings_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserAudienceRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_audience_bookings( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_logged_in', $result->get_error_code() );
    }

    public function test_get_user_audience_bookings_returns_error_when_capability_denied(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'user_can' )->justReturn( false );

        $ctrl    = new UserAudienceRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_audience_bookings( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'capability_denied', $result->get_error_code() );
    }

    public function test_get_user_audience_bookings_returns_empty_when_no_bookings(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        $ctrl    = new UserAudienceRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_audience_bookings( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 0, $result['total'] );
        $this->assertEmpty( $result['bookings'] );
    }

    // ------------------------------------------------------------------
    // get_joinable_groups()
    // ------------------------------------------------------------------

    public function test_get_joinable_groups_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserAudienceRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_joinable_groups( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_logged_in', $result->get_error_code() );
    }

    public function test_get_joinable_groups_returns_empty_when_tables_not_exist(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        // table_exists returns false (SHOW TABLES LIKE returns null)
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $ctrl    = new UserAudienceRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_joinable_groups( $request );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'groups', $result );
        $this->assertEmpty( $result['groups'] );
        $this->assertSame( 2, $result['max_groups'] );
    }

    // ------------------------------------------------------------------
    // join_audience_group()
    // ------------------------------------------------------------------

    public function test_join_audience_group_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserAudienceRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->join_audience_group( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_logged_in', $result->get_error_code() );
    }

    public function test_join_audience_group_returns_error_when_missing_group_id(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserAudienceRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'group_id' => 0 ) );
        $result  = $ctrl->join_audience_group( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'missing_group', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // leave_audience_group()
    // ------------------------------------------------------------------

    public function test_leave_audience_group_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserAudienceRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->leave_audience_group( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_logged_in', $result->get_error_code() );
    }

    public function test_leave_audience_group_returns_error_when_missing_group_id(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserAudienceRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'group_id' => 0 ) );
        $result  = $ctrl->leave_audience_group( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'missing_group', $result->get_error_code() );
    }
}
