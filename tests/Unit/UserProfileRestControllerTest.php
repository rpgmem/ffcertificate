<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserProfileRestController;

/**
 * Tests for UserProfileRestController: route registration, permission checks,
 * and endpoint callback error/success paths for profile, password, and privacy endpoints.
 */
class UserProfileRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array Captured route registrations */
    private array $registered_routes = [];

    /** @var object Mock $wpdb */
    private $wpdb;

    /** @var Mockery\MockInterface Mock UserManager */
    private $user_manager_mock;

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
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'date_i18n' )->alias( function( $format, $timestamp = false ) {
            return date( $format, $timestamp ?: time() );
        });

        // Alias mocks for static-only dependencies
        $this->user_manager_mock = Mockery::mock( 'alias:\FreeFormCertificate\UserDashboard\UserManager' );
        $user_manager_mock = $this->user_manager_mock;
        $user_manager_mock->shouldReceive( 'get_profile' )->andReturn( array(
            'display_name' => 'John Doe',
            'phone'        => '(11) 99999-0000',
            'department'   => 'Engineering',
            'organization' => 'Acme',
            'notes'        => '',
            'preferences'  => '{}',
        ) )->byDefault();
        $user_manager_mock->shouldReceive( 'get_user_cpfs_masked' )->andReturn( array( '123.***.***-00' ) )->byDefault();
        $user_manager_mock->shouldReceive( 'get_user_identifiers_masked' )->andReturn( array( 'cpfs' => array(), 'rfs' => array() ) )->byDefault();
        $user_manager_mock->shouldReceive( 'get_user_emails' )->andReturn( array( 'john@example.com' ) )->byDefault();
        $user_manager_mock->shouldReceive( 'get_user_names' )->andReturn( array( 'John Doe' ) )->byDefault();
        $user_manager_mock->shouldReceive( 'update_profile' )->andReturn( true )->byDefault();
        $user_manager_mock->shouldReceive( 'grant_audience_capabilities' )->byDefault();

        $activity_log_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLog' );
        $activity_log_mock->shouldReceive( 'log_profile_updated' )->byDefault();
        $activity_log_mock->shouldReceive( 'log_password_changed' )->byDefault();
        $activity_log_mock->shouldReceive( 'log_privacy_request' )->byDefault();

        $rate_limiter_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
        $rate_limiter_mock->shouldReceive( 'check_user_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();

        // Global $wpdb mock
        $this->wpdb = Mockery::mock( 'wpdb' );
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( '' )->byDefault();
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
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

    /**
     * Helper: create a WP_User stub with standard properties.
     */
    private function make_user( int $id = 5 ): \WP_User {
        $user = new \WP_User( $id );
        $user->user_email      = 'john@example.com';
        $user->user_pass       = 'hashed_password';
        $user->display_name    = 'John Doe';
        $user->user_registered = '2024-01-15 08:00:00';
        $user->roles           = array( 'subscriber' );
        return $user;
    }

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    public function test_register_routes_creates_three_endpoints(): void {
        $ctrl = new UserProfileRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertCount( 3, $this->registered_routes );
    }

    public function test_profile_route_registered(): void {
        $ctrl = new UserProfileRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/profile', $paths );
    }

    public function test_change_password_route_registered(): void {
        $ctrl = new UserProfileRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/change-password', $paths );
    }

    public function test_privacy_request_route_registered(): void {
        $ctrl = new UserProfileRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/privacy-request', $paths );
    }

    public function test_all_routes_require_authentication(): void {
        $ctrl = new UserProfileRestController( 'ffc/v1' );
        $ctrl->register_routes();

        foreach ( $this->registered_routes as $route ) {
            $route_path = $route['route'];

            // Profile route has sub-arrays (GET + PUT)
            if ( isset( $route['args'][0] ) ) {
                foreach ( $route['args'] as $endpoint ) {
                    $this->assertSame(
                        'is_user_logged_in',
                        $endpoint['permission_callback'],
                        "Route {$route_path} should require authentication"
                    );
                }
            } else {
                $this->assertSame(
                    'is_user_logged_in',
                    $route['args']['permission_callback'],
                    "Route {$route_path} should require authentication"
                );
            }
        }
    }

    public function test_profile_route_supports_get_and_put(): void {
        $ctrl = new UserProfileRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $profile = null;
        foreach ( $this->registered_routes as $route ) {
            if ( $route['route'] === '/user/profile' ) {
                $profile = $route;
                break;
            }
        }

        $this->assertNotNull( $profile, 'Profile route should exist' );
        $this->assertIsArray( $profile['args'] );
        $this->assertArrayHasKey( 0, $profile['args'] );
        $this->assertArrayHasKey( 1, $profile['args'] );
    }

    // ------------------------------------------------------------------
    // get_user_profile — error paths
    // ------------------------------------------------------------------

    public function test_get_user_profile_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_profile( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_logged_in', $result->get_error_code() );
    }

    public function test_get_user_profile_returns_error_when_user_not_found(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'get_user_by' )->justReturn( false );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_profile( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'user_not_found', $result->get_error_code() );
    }

    public function test_get_user_profile_returns_profile_data_on_success(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $user = $this->make_user( 5 );
        Functions\when( 'get_user_by' )->justReturn( $user );

        // table_exists returns false so audience groups skipped
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_profile( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 5, $result['user_id'] );
        $this->assertSame( 'John Doe', $result['display_name'] );
        $this->assertSame( 'john@example.com', $result['email'] );
        $this->assertSame( '(11) 99999-0000', $result['phone'] );
        $this->assertSame( 'Engineering', $result['department'] );
        $this->assertSame( 'Acme', $result['organization'] );
        $this->assertSame( array( 'subscriber' ), $result['roles'] );
        $this->assertArrayHasKey( 'member_since', $result );
        $this->assertArrayHasKey( 'audience_groups', $result );
    }

    // ------------------------------------------------------------------
    // update_user_profile — error paths
    // ------------------------------------------------------------------

    public function test_update_user_profile_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->update_user_profile( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_logged_in', $result->get_error_code() );
    }

    public function test_update_user_profile_returns_error_when_no_data(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        // Request with no profile fields
        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->update_user_profile( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'no_data', $result->get_error_code() );
    }

    public function test_update_user_profile_returns_error_when_update_fails(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        // Override the default to return false for this test
        $this->user_manager_mock->shouldReceive( 'update_profile' )
            ->once()
            ->andReturn( false );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'display_name' => 'Jane Doe' ) );
        $result  = $ctrl->update_user_profile( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'update_failed', $result->get_error_code() );
    }

    public function test_update_user_profile_succeeds_with_valid_data(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $user = $this->make_user( 5 );
        Functions\when( 'get_user_by' )->justReturn( $user );

        // table_exists returns false
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $this->user_manager_mock->shouldReceive( 'update_profile' )
            ->once()
            ->andReturn( true );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'display_name' => 'Jane Doe' ) );
        $result  = $ctrl->update_user_profile( $request );

        // On success, it calls get_user_profile which returns the profile array
        $this->assertIsArray( $result );
        $this->assertSame( 5, $result['user_id'] );
    }

    // ------------------------------------------------------------------
    // change_password — error paths
    // ------------------------------------------------------------------

    public function test_change_password_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->change_password( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_logged_in', $result->get_error_code() );
    }

    public function test_change_password_returns_error_when_new_password_empty(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'new_password' => '' ) );
        $result  = $ctrl->change_password( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'missing_fields', $result->get_error_code() );
    }

    public function test_change_password_returns_error_when_password_too_short(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'new_password' => 'short' ) );
        $result  = $ctrl->change_password( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'password_too_short', $result->get_error_code() );
    }

    public function test_change_password_returns_error_when_current_password_wrong(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $user = $this->make_user( 5 );
        Functions\when( 'get_user_by' )->justReturn( $user );
        Functions\when( 'wp_check_password' )->justReturn( false );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request( array(
            'current_password' => 'wrongpassword',
            'new_password'     => 'newpassword123',
        ) );
        $result  = $ctrl->change_password( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'wrong_password', $result->get_error_code() );
    }

    public function test_change_password_succeeds_with_correct_credentials(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $user = $this->make_user( 5 );
        Functions\when( 'get_user_by' )->justReturn( $user );
        Functions\when( 'wp_check_password' )->justReturn( true );
        Functions\when( 'wp_set_password' )->justReturn( null );
        Functions\when( 'wp_set_current_user' )->justReturn( null );
        Functions\when( 'wp_set_auth_cookie' )->justReturn( null );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request( array(
            'current_password' => 'correctpassword',
            'new_password'     => 'newpassword123',
        ) );
        $result  = $ctrl->change_password( $request );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
    }

    // ------------------------------------------------------------------
    // create_privacy_request — error paths
    // ------------------------------------------------------------------

    public function test_create_privacy_request_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->create_privacy_request( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_logged_in', $result->get_error_code() );
    }

    public function test_create_privacy_request_returns_error_for_invalid_type(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'type' => 'invalid_type' ) );
        $result  = $ctrl->create_privacy_request( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_type', $result->get_error_code() );
    }

    public function test_create_privacy_request_succeeds_with_export_type(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $user = $this->make_user( 5 );
        Functions\when( 'get_user_by' )->justReturn( $user );
        Functions\when( 'wp_create_user_request' )->justReturn( 42 );

        $ctrl    = new UserProfileRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'type' => 'export_personal_data' ) );
        $result  = $ctrl->create_privacy_request( $request );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
    }
}
