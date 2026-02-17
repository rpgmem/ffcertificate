<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserDataRestController;

/**
 * Tests for UserDataRestController: route registration, permission callbacks,
 * and endpoint callback error paths.
 */
class UserDataRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array Captured route registrations */
    private array $registered_routes = [];

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
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: create a mock WP_REST_Request with given params.
     */
    private function make_request( array $params = array() ): object {
        $request = Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_param' )->andReturnUsing( function( $key ) use ( $params ) {
            return $params[ $key ] ?? null;
        } );
        return $request;
    }

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    public function test_register_routes_creates_expected_endpoints(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        // Should register multiple routes
        $this->assertGreaterThanOrEqual( 8, count( $this->registered_routes ) );
    }

    public function test_all_user_routes_require_authentication(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        foreach ( $this->registered_routes as $route ) {
            $route_path = $route['route'];

            // If the route has sub-arrays (multiple methods), check each
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

    public function test_certificates_route_registered(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/certificates', $paths );
    }

    public function test_profile_route_registered(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/profile', $paths );
    }

    public function test_appointments_route_registered(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/appointments', $paths );
    }

    public function test_change_password_route_registered(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/change-password', $paths );
    }

    public function test_privacy_request_route_registered(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/privacy-request', $paths );
    }

    public function test_summary_route_registered(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/summary', $paths );
    }

    public function test_joinable_groups_route_registered(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/joinable-groups', $paths );
    }

    public function test_audience_group_join_route_registered(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/audience-group/join', $paths );
    }

    public function test_audience_group_leave_route_registered(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/audience-group/leave', $paths );
    }

    public function test_reregistrations_route_registered(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $paths = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/user/reregistrations', $paths );
    }

    public function test_profile_route_supports_get_and_put(): void {
        $ctrl = new UserDataRestController( 'ffc/v1' );
        $ctrl->register_routes();

        // Find /user/profile route
        $profile = null;
        foreach ( $this->registered_routes as $route ) {
            if ( $route['route'] === '/user/profile' ) {
                $profile = $route;
                break;
            }
        }

        $this->assertNotNull( $profile, 'Profile route should exist' );

        // Should have 2 methods (GET + PUT as array)
        $this->assertIsArray( $profile['args'] );
        $this->assertArrayHasKey( 0, $profile['args'] );
        $this->assertArrayHasKey( 1, $profile['args'] );
    }

    // ------------------------------------------------------------------
    // Endpoint callbacks — error paths
    // ------------------------------------------------------------------

    public function test_get_user_certificates_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserDataRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_certificates( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_get_user_certificates_returns_error_when_no_capability(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'user_can' )->justReturn( false );

        $ctrl    = new UserDataRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_certificates( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_get_user_appointments_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserDataRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_appointments( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_get_user_appointments_returns_error_when_no_capability(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'user_can' )->justReturn( false );

        $ctrl    = new UserDataRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_appointments( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_get_user_profile_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserDataRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_profile( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_change_password_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );
        $ctrl    = new UserDataRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->change_password( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_change_password_returns_error_when_new_password_empty(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );
        $ctrl    = new UserDataRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'new_password' => '' ) );
        $result  = $ctrl->change_password( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_change_password_returns_error_when_password_too_short(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );
        $ctrl    = new UserDataRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'new_password' => 'short' ) );
        $result  = $ctrl->change_password( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_create_privacy_request_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );
        $ctrl    = new UserDataRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->create_privacy_request( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_create_privacy_request_returns_error_for_invalid_type(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserDataRestController( 'ffc/v1' );
        $request = $this->make_request( array( 'type' => 'invalid_type' ) );
        $result  = $ctrl->create_privacy_request( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_update_user_profile_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserDataRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->update_user_profile( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }
}
