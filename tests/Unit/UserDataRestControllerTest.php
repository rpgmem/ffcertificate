<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserDataRestController;

/**
 * Tests for UserDataRestController: route registration and permission callbacks.
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
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
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
}
