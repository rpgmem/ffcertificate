<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\CertificatesCalendarRestController;

/**
 * Tests for CertificatesCalendarRestController:
 * route registration, permission check, param validation, and the
 * date-resolution logic that powers the calendar payload.
 */
class CertificatesCalendarRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, array{namespace:string,route:string,args:array}> */
    private array $registered_routes = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->registered_routes = array();
        Functions\when( 'register_rest_route' )->alias( function ( $namespace, $route, $args ) {
            $this->registered_routes[] = array(
                'namespace' => $namespace,
                'route'     => $route,
                'args'      => $args,
            );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    public function test_register_routes_creates_calendar_endpoint(): void {
        ( new CertificatesCalendarRestController( 'ffc/v1' ) )->register_routes();

        $this->assertCount( 1, $this->registered_routes );
        $this->assertSame( 'ffc/v1', $this->registered_routes[0]['namespace'] );
        $this->assertSame( '/certificates/calendar', $this->registered_routes[0]['route'] );
    }

    public function test_register_routes_requires_year_and_month(): void {
        ( new CertificatesCalendarRestController( 'ffc/v1' ) )->register_routes();

        $args = $this->registered_routes[0]['args'];
        $this->assertArrayHasKey( 'year', $args['args'] );
        $this->assertArrayHasKey( 'month', $args['args'] );
        $this->assertTrue( $args['args']['year']['required'] );
        $this->assertTrue( $args['args']['month']['required'] );
    }

    // ------------------------------------------------------------------
    // Permission
    // ------------------------------------------------------------------

    public function test_permission_check_passes_when_user_has_capability(): void {
        Functions\when( 'current_user_can' )->alias( function ( $cap ) {
            return 'edit_others_posts' === $cap;
        } );

        $this->assertTrue( ( new CertificatesCalendarRestController( 'ffc/v1' ) )->permission_check() );
    }

    public function test_permission_check_fails_without_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->assertFalse( ( new CertificatesCalendarRestController( 'ffc/v1' ) )->permission_check() );
    }

    // ------------------------------------------------------------------
    // Param validation
    // ------------------------------------------------------------------

    public function test_validate_year_accepts_realistic_years(): void {
        $ctrl = new CertificatesCalendarRestController( 'ffc/v1' );

        $this->assertTrue( $ctrl->validate_year( 2026 ) );
        $this->assertTrue( $ctrl->validate_year( '1970' ) );
        $this->assertTrue( $ctrl->validate_year( 2100 ) );
    }

    public function test_validate_year_rejects_out_of_range(): void {
        $ctrl = new CertificatesCalendarRestController( 'ffc/v1' );

        $this->assertFalse( $ctrl->validate_year( 1969 ) );
        $this->assertFalse( $ctrl->validate_year( 2101 ) );
        $this->assertFalse( $ctrl->validate_year( 0 ) );
    }

    public function test_validate_month_accepts_1_to_12(): void {
        $ctrl = new CertificatesCalendarRestController( 'ffc/v1' );

        $this->assertTrue( $ctrl->validate_month( 1 ) );
        $this->assertTrue( $ctrl->validate_month( 12 ) );
        $this->assertTrue( $ctrl->validate_month( '6' ) );
    }

    public function test_validate_month_rejects_out_of_range(): void {
        $ctrl = new CertificatesCalendarRestController( 'ffc/v1' );

        $this->assertFalse( $ctrl->validate_month( 0 ) );
        $this->assertFalse( $ctrl->validate_month( 13 ) );
    }

    // ------------------------------------------------------------------
    // Date resolution (geofence → post_date fallback)
    // ------------------------------------------------------------------

    public function test_resolve_date_uses_geofence_when_present(): void {
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_start' => '2026-05-12',
                'date_end'   => '2026-05-30',
            )
        );

        $post            = $this->make_post( 42, '2026-01-01 10:00:00' );
        [ $date, $src ] = $this->invoke_resolve_date( $post );

        $this->assertSame( '2026-05-12', $date );
        $this->assertSame( 'geofence', $src );
    }

    public function test_resolve_date_falls_back_to_post_date_when_geofence_empty(): void {
        Functions\when( 'get_post_meta' )->justReturn( array( 'date_start' => '' ) );

        $post            = $this->make_post( 42, '2026-03-08 14:30:00' );
        [ $date, $src ] = $this->invoke_resolve_date( $post );

        $this->assertSame( '2026-03-08', $date );
        $this->assertSame( 'post_date', $src );
    }

    public function test_resolve_date_falls_back_when_geofence_meta_missing(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $post            = $this->make_post( 42, '2026-07-15 00:00:00' );
        [ $date, $src ] = $this->invoke_resolve_date( $post );

        $this->assertSame( '2026-07-15', $date );
        $this->assertSame( 'post_date', $src );
    }

    public function test_resolve_date_falls_back_when_geofence_date_is_invalid(): void {
        Functions\when( 'get_post_meta' )->justReturn( array( 'date_start' => 'not-a-date' ) );

        $post            = $this->make_post( 42, '2026-09-01 12:00:00' );
        [ $date, $src ] = $this->invoke_resolve_date( $post );

        $this->assertSame( '2026-09-01', $date );
        $this->assertSame( 'post_date', $src );
    }

    public function test_resolve_date_returns_null_when_no_dates_available(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $post   = $this->make_post( 42, '' );
        $result = $this->invoke_resolve_date( $post );

        $this->assertNull( $result );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function make_post( int $id, string $post_date ): \WP_Post {
        $post            = new \WP_Post();
        $post->ID        = $id;
        $post->post_date = $post_date;
        return $post;
    }

    /**
     * Reach into the private resolve_date() so we can test the fallback
     * logic without spinning up a real WP_Query.
     *
     * @return array{0:string,1:string}|null
     */
    private function invoke_resolve_date( \WP_Post $post ): ?array {
        $ctrl   = new CertificatesCalendarRestController( 'ffc/v1' );
        $reflex = new \ReflectionClass( $ctrl );
        $method = $reflex->getMethod( 'resolve_date' );
        $method->setAccessible( true );
        return $method->invoke( $ctrl, $post );
    }
}
