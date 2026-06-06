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
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
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
        $this->wpdb = Mockery::mock( 'wpdb' )->makePartial();
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

    public function test_register_routes_creates_five_endpoints(): void {
        $ctrl = new UserAudienceRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertCount( 5, $this->registered_routes );
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

    public function test_leave_all_route_registered(): void {
        $ctrl = new UserAudienceRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/audience-group/leave-all', $paths );
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

    public function test_get_user_audience_bookings_renders_wall_clock_without_tz_shift(): void {
        // Regression: booking_date/start_time/end_time are wall-clock (Category B)
        // values. On a UTC-3 site they must render literally — a 05/06 09:00–17:00
        // booking must NOT shift to 04/06 06:00–14:00. Drives a real booking row
        // through the controller with a timezone-honouring wp_date stub.
        $prev_tz = date_default_timezone_get(); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_get
        date_default_timezone_set( 'UTC' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2026-06-06' );
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            return 'ffc_settings' === $key ? array() : ( false !== $default ? $default : '' );
        } );
        Functions\when( 'wp_timezone' )->alias( static function () {
            return new \DateTimeZone( '-03:00' );
        } );
        Functions\when( 'wp_date' )->alias( static function ( $format, $ts = null, $tz = null ) {
            $ts = null === $ts ? time() : (int) $ts;
            $dt = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone(
                $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' )
            );
            return $dt->format( $format );
        } );

        $booking_row = array(
            'id'               => 1,
            'booking_date'     => '2026-06-05',
            'start_time'       => '09:00:00',
            'end_time'         => '17:00:00',
            'status'           => 'active',
            'environment_id'   => 2,
            'environment_name' => 'Env',
            'schedule_name'    => 'Sched',
            'description'      => '',
        );
        // 1st get_results = the booking; 2nd = audience batch-load (empty).
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( $booking_row ), array() );

        $ctrl    = new UserAudienceRestController( 'ffc/v1' );
        $result  = $ctrl->get_user_audience_bookings( $this->make_request() );

        date_default_timezone_set( $prev_tz ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

        $this->assertIsArray( $result );
        $this->assertNotEmpty( $result['bookings'] );
        $row = $result['bookings'][0];
        $this->assertSame( '05/06/2026', $row['booking_date'], 'date must not shift back a day' );
        $this->assertSame( '09:00', $row['start_time'], 'start time must not shift' );
        $this->assertSame( '17:00', $row['end_time'], 'end time must not shift' );
        $this->assertSame( '2026-06-05', $row['booking_date_raw'] );
        $this->assertTrue( $row['is_past'], '2026-06-05 is before site-local today 2026-06-06' );
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
