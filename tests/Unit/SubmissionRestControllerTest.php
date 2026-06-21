<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\Capabilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\SubmissionRestController;
use FreeFormCertificate\Repositories\SubmissionRepository;

/**
 * Tests for SubmissionRestController: route registration, permission checks,
 * the list / single / verify handlers, and the admin permission gate.
 *
 * The static collaborators (Encryption, DocumentFormatter, Utils, RateLimiter)
 * run as the real autoloaded classes — the unit bootstrap configures
 * encryption keys and the handlers only need WP-function + $wpdb stubs.
 */
class SubmissionRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array Captured route registrations */
    private array $registered_routes = [];

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

        // Stubs for the route-registration + null-repo + not-found tests.
        // These paths do not reach the real static collaborators.
        Functions\when( '__' )->returnArg();
        Functions\when( 'rest_ensure_response' )->alias( fn ( $data ) => $data );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    public function test_register_routes_creates_three_endpoints(): void {
        $repo = Mockery::mock( SubmissionRepository::class );
        $ctrl = new SubmissionRestController( 'ffc/v1', $repo );
        $ctrl->register_routes();

        $this->assertCount( 3, $this->registered_routes );
    }

    public function test_submissions_list_route_requires_admin(): void {
        $repo = Mockery::mock( SubmissionRepository::class );
        $ctrl = new SubmissionRestController( 'ffc/v1', $repo );
        $ctrl->register_routes();

        // First route: GET /submissions
        $route = $this->registered_routes[0];
        $this->assertSame( '/submissions', $route['route'] );
        $this->assertSame( array( $ctrl, 'check_admin_permission' ), $route['args']['permission_callback'] );
    }

    public function test_submission_single_route_requires_admin(): void {
        $repo = Mockery::mock( SubmissionRepository::class );
        $ctrl = new SubmissionRestController( 'ffc/v1', $repo );
        $ctrl->register_routes();

        // Second route: GET /submissions/{id}
        $route = $this->registered_routes[1];
        $this->assertStringContainsString( 'submissions', $route['route'] );
        $this->assertSame( array( $ctrl, 'check_admin_permission' ), $route['args']['permission_callback'] );
    }

    public function test_verify_route_is_public(): void {
        $repo = Mockery::mock( SubmissionRepository::class );
        $ctrl = new SubmissionRestController( 'ffc/v1', $repo );
        $ctrl->register_routes();

        // Third route: POST /verify
        $route = $this->registered_routes[2];
        $this->assertSame( '/verify', $route['route'] );
        $this->assertSame( '__return_true', $route['args']['permission_callback'] );
    }

    public function test_verify_requires_auth_code_arg(): void {
        $repo = Mockery::mock( SubmissionRepository::class );
        $ctrl = new SubmissionRestController( 'ffc/v1', $repo );
        $ctrl->register_routes();

        $route = $this->registered_routes[2];
        $this->assertArrayHasKey( 'auth_code', $route['args']['args'] );
        $this->assertTrue( $route['args']['args']['auth_code']['required'] );
    }

    public function test_verify_auth_code_validation_rejects_short(): void {
        $repo = Mockery::mock( SubmissionRepository::class );
        $ctrl = new SubmissionRestController( 'ffc/v1', $repo );
        $ctrl->register_routes();

        $route = $this->registered_routes[2];
        $validator = $route['args']['args']['auth_code']['validate_callback'];

        $this->assertFalse( $validator( 'short' ) );       // < 12 chars
        $this->assertTrue( $validator( 'ABCD-EFGH-IJKL' ) ); // >= 12 chars
    }

    // ------------------------------------------------------------------
    // Submissions list args
    // ------------------------------------------------------------------

    public function test_submissions_list_has_pagination_args(): void {
        $repo = Mockery::mock( SubmissionRepository::class );
        $ctrl = new SubmissionRestController( 'ffc/v1', $repo );
        $ctrl->register_routes();

        $args = $this->registered_routes[0]['args']['args'];
        $this->assertArrayHasKey( 'page', $args );
        $this->assertArrayHasKey( 'per_page', $args );
        $this->assertArrayHasKey( 'status', $args );
        $this->assertArrayHasKey( 'search', $args );
    }

    // ------------------------------------------------------------------
    // get_submissions() returns error when repo is null
    // ------------------------------------------------------------------

    public function test_get_submissions_returns_error_without_repo(): void {
        // Build controller with null repository
        $ctrl = new SubmissionRestController( 'ffc/v1', null );

        // Mock WP_REST_Request
        $request = Mockery::mock( 'WP_REST_Request' );

        $result = $ctrl->get_submissions( $request );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_get_submission_returns_error_without_repo(): void {
        $ctrl    = new SubmissionRestController( 'ffc/v1', null );
        $request = Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( 5 );

        $result = $ctrl->get_submission( $request );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'repository_not_found', $result->get_error_code() );
    }

    // Note: the verify_certificate() handler-path tests (which exercise the
    // real RateLimiter / Encryption / DocumentFormatter statics) live in the
    // separate-process SubmissionVerifyRestControllerTest so they get a clean
    // function table immune to leaked global/namespaced mocks from earlier
    // same-process tests.

    // ------------------------------------------------------------------
    // check_admin_permission (delegates to Capabilities::current_user_can_admin_or)
    // ------------------------------------------------------------------

    public function test_check_admin_permission_true_for_admin(): void {
        Functions\when( 'current_user_can' )->alias( fn ( $cap ) => 'manage_options' === $cap );

        $ctrl = new SubmissionRestController( 'ffc/v1', null );
        $this->assertTrue( $ctrl->check_admin_permission() );
    }

    public function test_check_admin_permission_true_for_granular_cap(): void {
        Functions\when( 'current_user_can' )->alias( fn ( $cap ) => 'ffc_view_certificates' === $cap );

        $ctrl = new SubmissionRestController( 'ffc/v1', null );
        $this->assertTrue( $ctrl->check_admin_permission() );
    }

    public function test_check_admin_permission_false_without_caps(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl = new SubmissionRestController( 'ffc/v1', null );
        $this->assertFalse( $ctrl->check_admin_permission() );
    }

    // ------------------------------------------------------------------
    // get_submission() — not-found (no real statics on this path)
    // ------------------------------------------------------------------

    public function test_get_submission_returns_not_found(): void {
        $repo = Mockery::mock( SubmissionRepository::class );
        $repo->shouldReceive( 'findById' )->once()->with( 7 )->andReturn( null );

        $ctrl    = new SubmissionRestController( 'ffc/v1', $repo );
        $request = Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( 7 );

        $result = $ctrl->get_submission( $request );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'submission_not_found', $result->get_error_code() );
    }
}
