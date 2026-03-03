<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\RestController;

/**
 * Tests for RestController: REST API coordinator that initialises sub-controllers.
 *
 * @covers \FreeFormCertificate\API\RestController
 */
class RestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_filter' )->justReturn( true );
        Functions\when( 'register_rest_route' )->justReturn( true );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );

        // Namespaced stubs for repositories
        Functions\when( 'FreeFormCertificate\Repositories\wp_cache_get' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Repositories\wp_cache_set' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Repositories\current_user_can' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Repositories\user_can' )->justReturn( false );

        // Mock $wpdb
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $controller = new RestController();
        $this->assertInstanceOf( RestController::class, $controller );
    }

    // ==================================================================
    // register_routes() — instantiates sub-controllers
    // ==================================================================

    public function test_register_routes_calls_register_rest_route(): void {
        // register_rest_route should be called multiple times by sub-controllers
        $call_count = 0;
        Functions\when( 'register_rest_route' )->alias( function () use ( &$call_count ) {
            $call_count++;
            return true;
        } );

        $controller = new RestController();
        $controller->register_routes();

        // All 5 sub-controllers register at least 1 route each
        $this->assertGreaterThanOrEqual( 5, $call_count );
    }

    // ==================================================================
    // suppress_rest_api_notices() — no-op when not REST_REQUEST
    // ==================================================================

    public function test_suppress_notices_noop_when_not_rest_request(): void {
        // REST_REQUEST not defined => no ob_start or add_filter
        $controller = new RestController();

        // Should not throw
        $controller->suppress_rest_api_notices();
        $this->assertTrue( true );
    }

    // ==================================================================
    // suppress_rest_api_notices() — starts buffer when REST_REQUEST
    // ==================================================================

    public function test_suppress_notices_starts_buffer_when_rest_request(): void {
        if ( ! defined( 'REST_REQUEST' ) ) {
            define( 'REST_REQUEST', true );
        }

        $filter_added = false;
        Functions\when( 'add_filter' )->alias( function ( $tag ) use ( &$filter_added ) {
            if ( $tag === 'rest_pre_serve_request' ) {
                $filter_added = true;
            }
            return true;
        } );

        $level_before = ob_get_level();

        $controller = new RestController();
        $controller->suppress_rest_api_notices();

        $this->assertTrue( $filter_added );

        // Clean up any output buffers we started
        while ( ob_get_level() > $level_before ) {
            ob_end_clean();
        }
    }

    // ==================================================================
    // namespace is ffc/v1
    // ==================================================================

    public function test_routes_use_ffc_v1_namespace(): void {
        $namespaces = array();
        Functions\when( 'register_rest_route' )->alias( function ( $ns ) use ( &$namespaces ) {
            $namespaces[] = $ns;
            return true;
        } );

        $controller = new RestController();
        $controller->register_routes();

        // All registered routes should use ffc/v1
        foreach ( $namespaces as $ns ) {
            $this->assertSame( 'ffc/v1', $ns );
        }
    }
}
