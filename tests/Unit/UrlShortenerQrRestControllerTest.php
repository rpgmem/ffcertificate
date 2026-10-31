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
 * Capabilities, UrlShortenerQrHandler and QRCodeGenerator are the real
 * classes: the payload is a QR code the real generator drew (#2121), and the
 * generation-failure test makes the real generator fail by refusing it a
 * temporary file.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerQrRestController
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
		// No QR design or settings stored: the plain defaults.
		Functions\when( 'get_option' )->justReturn( array() );

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
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_view_url_shortener' === $cap );
		$this->assertTrue( $this->controller->check_permission() );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $this->controller->check_permission() );
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

		// The generator writes the PNG to a temporary file and reads it back.
		Functions\when( 'wp_tempnam' )->alias( static fn( $prefix = '' ) => tempnam( sys_get_temp_dir(), (string) $prefix ) );
		Functions\when( 'wp_delete_file' )->alias( static fn( $file ) => @unlink( $file ) );

		$result = $this->controller->get_qr( $this->request( array( 'code' => 'abc123', 'format' => 'png', 'size' => 300 ) ) );

		$this->assertSame( 'abc123', $result['code'] );
		$this->assertSame( 'https://example.com/go/abc123', $result['short_url'] );
		$this->assertSame( 'png', $result['format'] );
		$this->assertStringStartsWith( "\x89PNG", (string) base64_decode( $result['data_base64'] ), 'a PNG drawn by the real generator' );
	}

	public function test_get_qr_svg_returns_base64_of_markup(): void {
		$this->repo->shouldReceive( 'findByShortCode' )->with( 'abc123' )->andReturn(
			array( 'id' => 1, 'short_code' => 'abc123', 'status' => 'active' )
		);
		$this->service->shouldReceive( 'get_short_url' )->with( 'abc123' )->andReturn( 'https://example.com/go/abc123' );

		$result = $this->controller->get_qr( $this->request( array( 'code' => 'abc123', 'format' => 'svg' ) ) );

		$this->assertSame( 'svg', $result['format'] );
		$this->assertSame( 'image/svg+xml', $result['mime'] );
		$this->assertStringContainsString( '<svg', (string) base64_decode( $result['data_base64'] ) );
	}

	public function test_get_qr_returns_500_on_generation_failure(): void {
		$this->repo->shouldReceive( 'findByShortCode' )->with( 'abc123' )->andReturn(
			array( 'id' => 1, 'short_code' => 'abc123', 'status' => 'active' )
		);
		$this->service->shouldReceive( 'get_short_url' )->with( 'abc123' )->andReturn( 'https://example.com/go/abc123' );

		// No temporary file can be created: the real generator gives up and
		// returns an empty image. The default size is not the cached one, so
		// no cache row is read or written.
		$tempnam_calls = 0;
		Functions\when( 'wp_tempnam' )->alias(
			static function () use ( &$tempnam_calls ) {
				++$tempnam_calls;
				return false;
			}
		);

		$result = $this->controller->get_qr( $this->request( array( 'code' => 'abc123', 'format' => 'png' ) ) );

		$this->assertSame( 1, $tempnam_calls, 'the real generator was reached' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}
}
