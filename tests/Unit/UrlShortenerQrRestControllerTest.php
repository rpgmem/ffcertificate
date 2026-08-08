<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerQrRestController;
use FreeFormCertificate\UrlShortener\UrlShortenerService;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

/**
 * Tests for UrlShortenerQrRestController: the /ffc/v1/short-urls/{code}/qr
 * endpoint (permission gate, 404, png/svg base64 payloads, route registration).
 *
 * Runs in separate processes: it overloads UrlShortenerQrHandler (instantiated
 * inside the callback) and aliases Capabilities for the permission gate.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerQrRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UrlShortenerQrRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private UrlShortenerQrRestController $controller;

    /** @var UrlShortenerService|Mockery\MockInterface */
    private $service;

    /** @var UrlShortenerRepository|Mockery\MockInterface */
    private $repo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        class_exists( '\\FreeFormCertificate\\UrlShortener\\UrlShortenerQrRestController' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'rest_ensure_response' )->returnArg();
        Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );

        $this->service = Mockery::mock( UrlShortenerService::class );
        $this->repo    = Mockery::mock( UrlShortenerRepository::class );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $this->repo );

        $this->controller = new UrlShortenerQrRestController( $this->service );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Build a WP_REST_Request double returning the given params.
     *
     * @param array<string, mixed> $params Params.
     * @return \Mockery\MockInterface
     */
    private function request( array $params ) {
        $req = Mockery::mock( 'WP_REST_Request' );
        $req->shouldReceive( 'get_param' )->andReturnUsing(
            static fn( $key ) => $params[ $key ] ?? null
        );
        return $req;
    }

    public function test_register_routes_registers_qr_route(): void {
        $captured = array();
        Functions\when( 'register_rest_route' )->alias(
            static function ( $ns, $route, $args ) use ( &$captured ) {
                $captured = array( $ns, $route, $args );
            }
        );

        $this->controller->register_routes();

        $this->assertSame( 'ffc/v1', $captured[0] );
        $this->assertStringContainsString( '/short-urls/', $captured[1] );
        $this->assertStringContainsString( '/qr', $captured[1] );
        $this->assertSame( 'GET', $captured[2]['methods'] );
    }

    public function test_check_permission_delegates_to_capability(): void {
        $caps = Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' );
        $caps->shouldReceive( 'current_user_can_admin_or' )->with( 'ffc_view_url_shortener' )->andReturn( true );

        $this->assertTrue( $this->controller->check_permission() );
    }

    public function test_get_qr_returns_404_when_not_found(): void {
        $this->repo->shouldReceive( 'findByShortCode' )->with( 'nope' )->andReturn( null );

        $result = $this->controller->get_qr( $this->request( array( 'code' => 'nope' ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'ffc_short_url_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
    }

    public function test_get_qr_png_returns_base64_payload(): void {
        $this->repo->shouldReceive( 'findByShortCode' )->with( 'abc123' )->andReturn(
            array( 'id' => 1, 'short_code' => 'abc123', 'status' => 'active' )
        );
        $this->service->shouldReceive( 'get_short_url' )->with( 'abc123' )->andReturn( 'https://example.com/go/abc123' );

        $qr = Mockery::mock( 'overload:FreeFormCertificate\UrlShortener\UrlShortenerQrHandler' );
        $qr->shouldReceive( 'generate_qr_base64' )->andReturn( 'PNGBASE64' );

        $result = $this->controller->get_qr( $this->request( array( 'code' => 'abc123', 'format' => 'png', 'size' => 300 ) ) );

        $this->assertSame( 'abc123', $result['code'] );
        $this->assertSame( 'https://example.com/go/abc123', $result['short_url'] );
        $this->assertSame( 'png', $result['format'] );
        $this->assertSame( 'PNGBASE64', $result['data_base64'] );
    }

    public function test_get_qr_svg_returns_base64_of_markup(): void {
        $this->repo->shouldReceive( 'findByShortCode' )->with( 'abc123' )->andReturn(
            array( 'id' => 1, 'short_code' => 'abc123', 'status' => 'active' )
        );
        $this->service->shouldReceive( 'get_short_url' )->with( 'abc123' )->andReturn( 'https://example.com/go/abc123' );

        $qr = Mockery::mock( 'overload:FreeFormCertificate\UrlShortener\UrlShortenerQrHandler' );
        $qr->shouldReceive( 'generate_svg' )->andReturn( '<svg></svg>' );

        $result = $this->controller->get_qr( $this->request( array( 'code' => 'abc123', 'format' => 'svg' ) ) );

        $this->assertSame( 'svg', $result['format'] );
        $this->assertSame( 'image/svg+xml', $result['mime'] );
        $this->assertSame( base64_encode( '<svg></svg>' ), $result['data_base64'] );
    }

    public function test_get_qr_returns_500_on_generation_failure(): void {
        $this->repo->shouldReceive( 'findByShortCode' )->with( 'abc123' )->andReturn(
            array( 'id' => 1, 'short_code' => 'abc123', 'status' => 'active' )
        );
        $this->service->shouldReceive( 'get_short_url' )->with( 'abc123' )->andReturn( 'https://example.com/go/abc123' );

        $qr = Mockery::mock( 'overload:FreeFormCertificate\UrlShortener\UrlShortenerQrHandler' );
        $qr->shouldReceive( 'generate_qr_base64' )->andReturn( '' );

        $result = $this->controller->get_qr( $this->request( array( 'code' => 'abc123', 'format' => 'png' ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 500, $result->get_error_data()['status'] );
    }
}
