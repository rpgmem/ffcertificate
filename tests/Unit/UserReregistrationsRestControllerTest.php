<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserReregistrationsRestController;
use FreeFormCertificate\Core\DocumentFormatter;

/**
 * Tests for UserReregistrationsRestController: route registration,
 * authentication checks, empty/formatted submission responses.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UserReregistrationsRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array Captured route registrations */
    private array $registered_routes = [];

    /** @var \Mockery\MockInterface */
    private $rereg_repo_mock;

    /** @var \Mockery\MockInterface */
    private $utils_mock;

    /** @var \Mockery\MockInterface */
    private $magic_link_mock;

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
        $this->rereg_repo_mock = Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository' );
        $this->rereg_repo_mock->shouldReceive( 'get_all_by_user' )->andReturn( array() )->byDefault();
        $this->rereg_repo_mock->shouldReceive( 'ensure_magic_token' )->andReturn( 'test-token-123' )->byDefault();

        $this->utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $this->utils_mock->shouldReceive( 'debug_log' )->byDefault();
        $this->utils_mock->shouldReceive( 'format_auth_code' )->andReturnUsing( function( $code, $prefix = '' ) {
            return DocumentFormatter::format_auth_code( $code, $prefix );
        })->byDefault();

        $this->magic_link_mock = Mockery::mock( 'alias:\FreeFormCertificate\Generators\MagicLinkHelper' );
        $this->magic_link_mock->shouldReceive( 'generate_magic_link' )->andReturnUsing( function( $token ) {
            return 'https://example.com/verify?token=' . $token;
        })->byDefault();
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

    /**
     * Helper: build a mock submission object.
     */
    private function make_submission( array $overrides = [] ): object {
        $defaults = array(
            'id'                     => 1,
            'reregistration_id'      => 10,
            'reregistration_title'   => 'Annual Renewal',
            'status'                 => 'pending',
            'reregistration_status'  => 'active',
            'start_date'             => '2026-01-01',
            'end_date'               => '2026-12-31',
            'submitted_at'           => null,
            'auth_code'              => '',
        );

        return (object) array_merge( $defaults, $overrides );
    }

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    public function test_register_routes_creates_one_endpoint(): void {
        $ctrl = new UserReregistrationsRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertCount( 1, $this->registered_routes );
    }

    public function test_reregistrations_route_registered(): void {
        $ctrl = new UserReregistrationsRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertSame( '/user/reregistrations', $this->registered_routes[0]['route'] );
    }

    public function test_reregistrations_route_requires_authentication(): void {
        $ctrl = new UserReregistrationsRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertSame(
            'is_user_logged_in',
            $this->registered_routes[0]['args']['permission_callback']
        );
    }

    // ------------------------------------------------------------------
    // get_user_reregistrations() — not logged in
    // ------------------------------------------------------------------

    public function test_get_user_reregistrations_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_reregistrations( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_logged_in', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // get_user_reregistrations() — empty submissions
    // ------------------------------------------------------------------

    public function test_get_user_reregistrations_returns_empty_when_no_submissions(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->rereg_repo_mock->shouldReceive( 'get_all_by_user' )
            ->with( 5 )
            ->andReturn( array() );

        $ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_reregistrations( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 0, $result['total'] );
        $this->assertEmpty( $result['reregistrations'] );
    }

    // ------------------------------------------------------------------
    // get_user_reregistrations() — formatted submissions
    // ------------------------------------------------------------------

    public function test_get_user_reregistrations_returns_formatted_submissions(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $sub = $this->make_submission( array(
            'id'                    => 42,
            'reregistration_id'     => 10,
            'reregistration_title'  => 'Annual Renewal',
            'status'                => 'submitted',
            'reregistration_status' => 'active',
            'start_date'            => '2026-01-01',
            'end_date'              => '2026-12-31',
            'submitted_at'          => '2026-03-01 14:30:00',
            'auth_code'             => 'ABCD1234EFGH',
        ));

        $this->rereg_repo_mock->shouldReceive( 'get_all_by_user' )
            ->with( 5 )
            ->andReturn( array( $sub ) );

        $this->rereg_repo_mock->shouldReceive( 'ensure_magic_token' )
            ->with( $sub )
            ->andReturn( 'magic-token-42' );

        $this->magic_link_mock->shouldReceive( 'generate_magic_link' )
            ->with( 'magic-token-42' )
            ->andReturn( 'https://example.com/verify?token=magic-token-42' );

        $ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_reregistrations( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['total'] );

        $formatted = $result['reregistrations'][0];
        $this->assertSame( 42, $formatted['submission_id'] );
        $this->assertSame( 10, $formatted['reregistration_id'] );
        $this->assertSame( 'Annual Renewal', $formatted['title'] );
        $this->assertSame( 'submitted', $formatted['status'] );
        $this->assertSame( 'Submitted — Pending Review', $formatted['status_label'] );
        $this->assertTrue( $formatted['can_download'] );
        $this->assertFalse( $formatted['can_submit'] );
        $this->assertTrue( $formatted['is_active'] );
        $this->assertNotEmpty( $formatted['auth_code'] );
        $this->assertStringContainsString( 'R-', $formatted['auth_code'] );
        $this->assertSame( 'https://example.com/verify?token=magic-token-42', $formatted['magic_link'] );
    }

    public function test_get_user_reregistrations_pending_submission_can_submit(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $sub = $this->make_submission( array(
            'status'                => 'pending',
            'reregistration_status' => 'active',
            'auth_code'             => '',
        ));

        $this->rereg_repo_mock->shouldReceive( 'get_all_by_user' )
            ->with( 5 )
            ->andReturn( array( $sub ) );

        $ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_reregistrations( $request );

        $formatted = $result['reregistrations'][0];
        $this->assertTrue( $formatted['can_submit'] );
        $this->assertFalse( $formatted['can_download'] );
        $this->assertSame( '', $formatted['magic_link'] );
        $this->assertSame( '', $formatted['auth_code'] );
    }

    public function test_get_user_reregistrations_exception_returns_wp_error(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->rereg_repo_mock->shouldReceive( 'get_all_by_user' )
            ->andThrow( new \Exception( 'Database failure' ) );

        $ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_reregistrations( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'reregistrations_error', $result->get_error_code() );
    }
}
