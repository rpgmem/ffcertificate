<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerQrHandler;
use FreeFormCertificate\UrlShortener\UrlShortenerService;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

/**
 * Tests for UrlShortenerQrHandler: resolve_qr_target (via reflection) with
 * post_id and short code scenarios, and download handlers.
 *
 * Note: generate_qr_base64() and generate_svg() depend on external libraries
 * (QRCodeGenerator, phpqrcode, GD) and are not unit-tested here.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerQrHandler
 */
class UrlShortenerQrHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private UrlShortenerQrHandler $handler;

    /** @var UrlShortenerService|Mockery\MockInterface */
    private $service;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        // Namespaced stubs for AjaxTrait
        Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        // Namespaced stubs for UrlShortener
        Functions\when( 'FreeFormCertificate\UrlShortener\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\sanitize_text_field' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\wp_unslash' )->returnArg();

        $this->service = Mockery::mock( UrlShortenerService::class );
        $this->handler = new UrlShortenerQrHandler( $this->service );
    }

    protected function tearDown(): void {
        unset( $_POST['nonce'], $_POST['post_id'], $_POST['code'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // resolve_qr_target() — via Reflection
    // ==================================================================

    public function test_resolve_qr_target_with_post_id(): void {
        $_POST['post_id'] = '42';

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByPostId' )->with( 42 )->andReturn( [
            'id'         => 7,
            'short_code' => 'pst42x',
        ] );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'get_short_url' )->with( 'pst42x' )->andReturn( 'https://example.com/go/pst42x' );

        $mock_post = Mockery::mock( \WP_Post::class );
        $mock_post->post_name = 'my-page';
        Functions\when( 'FreeFormCertificate\UrlShortener\get_post' )->justReturn( $mock_post );

        $result = $this->invoke_resolve_qr_target();

        $this->assertSame( 'https://example.com/go/pst42x', $result['url'] );
        $this->assertSame( 'qr-my-page', $result['prefix'] );
    }

    public function test_resolve_qr_target_post_has_no_short_url_sends_error(): void {
        $_POST['post_id'] = '999';

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByPostId' )->with( 999 )->andReturn( null );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );

        Functions\when( 'FreeFormCertificate\UrlShortener\wp_send_json_error' )->alias( function () {
            throw new \RuntimeException( 'json_error_post_not_found' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error_post_not_found' );

        $this->invoke_resolve_qr_target();
    }

    public function test_resolve_qr_target_with_short_code(): void {
        // No post_id, provide code
        $_POST['code'] = 'abc123';

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByShortCode' )->with( 'abc123' )->andReturn( [
            'id'         => 5,
            'short_code' => 'abc123',
        ] );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'get_short_url' )->with( 'abc123' )->andReturn( 'https://example.com/go/abc123' );

        $result = $this->invoke_resolve_qr_target();

        $this->assertSame( 'https://example.com/go/abc123', $result['url'] );
        $this->assertSame( 'qr-abc123', $result['prefix'] );
    }

    public function test_resolve_qr_target_code_not_found_sends_error(): void {
        $_POST['code'] = 'nonexistent';

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByShortCode' )->with( 'nonexistent' )->andReturn( null );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );

        Functions\when( 'FreeFormCertificate\UrlShortener\wp_send_json_error' )->alias( function () {
            throw new \RuntimeException( 'json_error_code_not_found' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error_code_not_found' );

        $this->invoke_resolve_qr_target();
    }

    public function test_resolve_qr_target_no_post_id_no_code_sends_error(): void {
        // Neither post_id nor code set

        Functions\when( 'FreeFormCertificate\UrlShortener\wp_send_json_error' )->alias( function () {
            throw new \RuntimeException( 'json_error_invalid_code' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error_invalid_code' );

        $this->invoke_resolve_qr_target();
    }

    // ==================================================================
    // handle_download_png() — nonce/permission flow
    // ==================================================================

    public function test_handle_download_png_nonce_failure_sends_error(): void {
        $_POST['nonce'] = 'invalid';

        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'wp_send_json_error' )->alias( function () {
            throw new \RuntimeException( 'nonce_failed' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'nonce_failed' );

        $this->handler->handle_download_png();
    }

    public function test_handle_download_svg_nonce_failure_sends_error(): void {
        $_POST['nonce'] = 'invalid';

        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'wp_send_json_error' )->alias( function () {
            throw new \RuntimeException( 'nonce_failed' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'nonce_failed' );

        $this->handler->handle_download_svg();
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Invoke the private resolve_qr_target() method via reflection.
     *
     * @return array{url: string, prefix: string}
     */
    private function invoke_resolve_qr_target(): array {
        $ref = new \ReflectionMethod( UrlShortenerQrHandler::class, 'resolve_qr_target' );
        $ref->setAccessible( true );
        return $ref->invoke( $this->handler );
    }
}
