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
 * @covers \FreeFormCertificate\API\UserAudienceRestController
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
        class_exists( '\\FreeFormCertificate\\API\\UserAudienceRestController' );

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

        $user_manager_mock = Mockery::mock( 'alias:\FreeFormCertificate\UserDashboard\CapabilityManager' );
        $user_manager_mock->shouldReceive( 'grant_audience_capabilities' )->byDefault();

        // UserManager alias so the class_exists() guard in join_audience_group()
        // resolves true and the CapabilityManager grant branch is exercised.
        Mockery::mock( 'alias:\FreeFormCertificate\UserDashboard\UserManager' );

        // Audience data-access dependencies (static). Overridable per-test.
        $this->query_service = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceQueryService' );
        $this->query_service->shouldReceive( 'find_user_bookings' )->andReturn( array() )->byDefault();
        $this->query_service->shouldReceive( 'find_user_joinable_audiences' )->andReturn( array() )->byDefault();
        $this->query_service->shouldReceive( 'count_user_self_join_memberships' )->andReturn( 0 )->byDefault();

        $this->reader = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceReader' );
        $this->reader->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
        $this->reader->shouldReceive( 'is_member' )->andReturn( false )->byDefault();

        $this->writer = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceWriter' );
        $this->writer->shouldReceive( 'add_member' )->andReturn( 1 )->byDefault();

        // Global $wpdb mock
        $this->wpdb = Mockery::mock( 'wpdb' )->makePartial();
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( '' )->byDefault();
        $this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function( $v ) { return (string) $v; } )->byDefault();
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
        $this->wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
        $this->wpdb->shouldReceive( 'delete' )->andReturn( 1 )->byDefault();
        $this->wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /** @var \Mockery\MockInterface AudienceQueryService alias */
    private $query_service;

    /** @var \Mockery\MockInterface AudienceReader alias */
    private $reader;

    /** @var \Mockery\MockInterface AudienceWriter alias */
    private $writer;

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
        // Service returns the single booking row (audience batch-load empty).
        $this->query_service->shouldReceive( 'find_user_bookings' )->andReturn( array( $booking_row ) );

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

    public function test_get_user_audience_bookings_maps_rows_and_audiences(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2000-01-01' );
        // DateFormatter helpers return '' → controller falls back to raw
        // value (exercises lines 145 / 153).
        Functions\when( 'wp_date' )->justReturn( '' );
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

        $booking = array(
            'id'               => 7,
            'booking_date'     => '2099-12-31',
            'start_time'       => '08:00:00',
            'end_time'         => '',
            'status'           => 'cancelled',
            'environment_id'   => 3,
            'environment_name' => 'Room',
            'schedule_name'    => 'Sched',
            'description'      => 'desc',
            'audiences'        => array( array( 'id' => 1, 'name' => 'Group' ) ),
        );
        $this->query_service->shouldReceive( 'find_user_bookings' )->andReturn( array( $booking ) );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->get_user_audience_bookings( $this->make_request() );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['total'] );
        $row = $result['bookings'][0];
        $this->assertSame( 7, $row['id'] );
        // Fallback to raw when formatter returns ''.
        $this->assertSame( '2099-12-31', $row['booking_date'] );
        $this->assertSame( '08:00:00', $row['start_time'] );
        $this->assertSame( 'cancelled', $row['status'] );
        $this->assertFalse( $row['is_past'], 'future date is not past' );
        $this->assertSame( array( array( 'id' => 1, 'name' => 'Group' ) ), $row['audiences'] );
    }

    public function test_get_user_audience_bookings_returns_500_on_exception(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->query_service->shouldReceive( 'find_user_bookings' )
            ->andThrow( new \Exception( 'boom' ) );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->get_user_audience_bookings( $this->make_request() );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'get_audience_bookings_error', $result->get_error_code() );
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

    public function test_get_joinable_groups_returns_empty_parents_when_no_audiences(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        // Schema guard passes: table + column exist.
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_audiences' );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'allow_self_join' ) ) );

        // Service returns empty flat list → early empty parents return.
        $this->query_service->shouldReceive( 'find_user_joinable_audiences' )->andReturn( array() );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->get_joinable_groups( $this->make_request() );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'parents', $result );
        $this->assertEmpty( $result['parents'] );
        $this->assertSame( 0, $result['joined_count'] );
        $this->assertSame( 2, $result['max_groups'] );
    }

    public function test_get_joinable_groups_builds_tree_and_counts_members(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_audiences' );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'allow_self_join' ) ) );

        // Parent (id=1) with two leaf children; one child the user is a member of.
        $flat = array(
            array( 'id' => 1, 'parent_id' => null, 'name' => 'Parent', 'color' => '#000', 'is_member' => false ),
            array( 'id' => 2, 'parent_id' => 1, 'name' => 'Child A', 'color' => '#111', 'is_member' => true ),
            array( 'id' => 3, 'parent_id' => 1, 'name' => 'Child B', 'color' => '#222', 'is_member' => false ),
            // A standalone joinable leaf (root itself is a leaf).
            array( 'id' => 4, 'parent_id' => null, 'name' => 'Solo', 'color' => '#333', 'is_member' => true ),
        );
        $this->query_service->shouldReceive( 'find_user_joinable_audiences' )->andReturn( $flat );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->get_joinable_groups( $this->make_request() );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'parents', $result );
        // Two joined leaves (Child A + Solo).
        $this->assertSame( 2, $result['joined_count'] );

        // Locate the branch node.
        $branch = null;
        $solo   = null;
        foreach ( $result['parents'] as $node ) {
            if ( 1 === $node['id'] ) { $branch = $node; }
            if ( 4 === $node['id'] ) { $solo = $node; }
        }
        $this->assertNotNull( $branch );
        $this->assertArrayHasKey( 'children', $branch );
        $this->assertCount( 2, $branch['children'] );
        $this->assertNotNull( $solo );
        $this->assertTrue( $solo['is_member'] );
    }

    public function test_get_joinable_groups_returns_500_on_exception(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_audiences' );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'allow_self_join' ) ) );
        $this->query_service->shouldReceive( 'find_user_joinable_audiences' )
            ->andThrow( new \Exception( 'boom' ) );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->get_joinable_groups( $this->make_request() );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'joinable_groups_error', $result->get_error_code() );
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

    public function test_join_audience_group_returns_error_when_group_invalid(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        // get_by_id returns null → invalid_group.
        $this->reader->shouldReceive( 'get_by_id' )->andReturn( null );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_group', $result->get_error_code() );
    }

    public function test_join_audience_group_returns_error_when_group_not_self_joinable(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        // Active child but allow_self_join = 0.
        $group = (object) array( 'status' => 'active', 'allow_self_join' => 0, 'parent_id' => 1, 'name' => 'G' );
        $this->reader->shouldReceive( 'get_by_id' )->andReturn( $group );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_group', $result->get_error_code() );
    }

    public function test_join_audience_group_returns_error_when_already_member(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $group = (object) array( 'status' => 'active', 'allow_self_join' => 1, 'parent_id' => 1, 'name' => 'G' );
        $this->reader->shouldReceive( 'get_by_id' )->andReturn( $group );
        $this->reader->shouldReceive( 'is_member' )->andReturn( true );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'already_member', $result->get_error_code() );
    }

    public function test_join_audience_group_returns_error_when_max_reached(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $group = (object) array( 'status' => 'active', 'allow_self_join' => 1, 'parent_id' => 1, 'name' => 'G' );
        $this->reader->shouldReceive( 'get_by_id' )->andReturn( $group );
        $this->reader->shouldReceive( 'is_member' )->andReturn( false );
        $this->query_service->shouldReceive( 'count_user_self_join_memberships' )->andReturn( 2 );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'max_groups_reached', $result->get_error_code() );
    }

    public function test_join_audience_group_succeeds(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $group = (object) array( 'status' => 'active', 'allow_self_join' => 1, 'parent_id' => 1, 'name' => 'My Group' );
        $this->reader->shouldReceive( 'get_by_id' )->andReturn( $group );
        $this->reader->shouldReceive( 'is_member' )->andReturn( false );
        $this->query_service->shouldReceive( 'count_user_self_join_memberships' )->andReturn( 1 );
        $this->writer->shouldReceive( 'add_member' )->once()->with( 10, 5 )->andReturn( 1 );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
        $this->assertStringContainsString( 'My Group', $result['message'] );
    }

    public function test_join_audience_group_returns_500_on_exception(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->reader->shouldReceive( 'get_by_id' )->andThrow( new \Exception( 'boom' ) );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'join_group_error', $result->get_error_code() );
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

    public function test_leave_audience_group_returns_error_when_group_invalid(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        // get_row returns null → invalid_group.
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_group', $result->get_error_code() );
    }

    public function test_leave_audience_group_returns_error_when_not_member(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'id' => 10, 'name' => 'G' ) );
        // delete returns 0 rows → not_member.
        $this->wpdb->shouldReceive( 'delete' )->andReturn( 0 );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_member', $result->get_error_code() );
    }

    public function test_leave_audience_group_returns_500_on_exception(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->wpdb->shouldReceive( 'get_row' )->andThrow( new \Exception( 'boom' ) );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'leave_group_error', $result->get_error_code() );
    }

    public function test_leave_audience_group_succeeds(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'id' => 10, 'name' => 'My Group' ) );
        $this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
        $this->assertStringContainsString( 'My Group', $result['message'] );
    }

    // ------------------------------------------------------------------
    // leave_all_audience_groups()
    // ------------------------------------------------------------------

    public function test_leave_all_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->leave_all_audience_groups( $this->make_request() );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_logged_in', $result->get_error_code() );
    }

    public function test_leave_all_succeeds_and_reports_removed_count(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->wpdb->shouldReceive( 'query' )->andReturn( 3 );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->leave_all_audience_groups( $this->make_request() );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
        $this->assertSame( 3, $result['removed'] );
    }

    public function test_leave_all_returns_500_on_exception(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->wpdb->shouldReceive( 'query' )->andThrow( new \Exception( 'boom' ) );

        $result = ( new UserAudienceRestController( 'ffc/v1' ) )
            ->leave_all_audience_groups( $this->make_request() );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'leave_all_error', $result->get_error_code() );
    }
}
