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
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        // Namespaced stubs for UrlShortener
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

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
        Functions\when( 'get_post' )->justReturn( $mock_post );

        $result = $this->invoke_resolve_qr_target();

        $this->assertSame( 'https://example.com/go/pst42x', $result['url'] );
        $this->assertSame( 'qr-my-page', $result['prefix'] );
    }

    public function test_resolve_qr_target_post_has_no_short_url_sends_error(): void {
        $_POST['post_id'] = '999';

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByPostId' )->with( 999 )->andReturn( null );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );

        Functions\when( 'wp_send_json_error' )->alias( function () {
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

        Functions\when( 'wp_send_json_error' )->alias( function () {
            throw new \RuntimeException( 'json_error_code_not_found' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error_code_not_found' );

        $this->invoke_resolve_qr_target();
    }

    public function test_resolve_qr_target_no_post_id_no_code_sends_error(): void {
        // Neither post_id nor code set

        Functions\when( 'wp_send_json_error' )->alias( function () {
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
    // ==================================================================
    // generate_svg() — pure matrix → SVG (phpqrcode raw, no GD/temp files)
    // ==================================================================

    public function test_generate_svg_returns_svg_markup(): void {
        $svg = $this->handler->generate_svg( 'https://example.com/x', 200 );

        $this->assertStringContainsString( '<svg', $svg );
        $this->assertStringContainsString( 'viewBox', $svg );
        $this->assertStringContainsString( 'fill="black"', $svg );
    }

    public function test_generate_svg_clamps_module_size_for_tiny_size(): void {
        // size smaller than the module count forces module_size = 1.
        $svg = $this->handler->generate_svg( 'https://example.com/x', 1 );
        $this->assertStringContainsString( '<svg', $svg );
    }

    // ==================================================================
    // init()
    // ==================================================================

    public function test_init_registers_download_hooks(): void {
        $hooks = array();
        Functions\when( 'add_action' )->alias(
            static function ( $hook ) use ( &$hooks ) {
                $hooks[] = $hook;
            }
        );

        $this->handler->init();

        $this->assertContains( 'wp_ajax_ffc_download_qr_png', $hooks );
        $this->assertContains( 'wp_ajax_ffc_download_qr_svg', $hooks );
    }

    // ==================================================================
    // generate_qr_base64() — cache hit / miss+persist
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_generate_qr_base64_returns_cached_when_present(): void {
        $repo = Mockery::mock( 'overload:FreeFormCertificate\UrlShortener\UrlShortenerRepository' );
        $repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'code1' )->andReturn( 'CACHED64' );
        // Generator must NOT run and cache must NOT be written on a hit.
        $repo->shouldNotReceive( 'setQrCacheForShortCode' );

        $result = $this->handler->generate_qr_base64( 'https://example.com/x', 200, 'code1' );

        $this->assertSame( 'CACHED64', $result );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_generate_qr_base64_generates_and_persists_on_miss(): void {
        $persisted = null;
        $repo      = Mockery::mock( 'overload:FreeFormCertificate\UrlShortener\UrlShortenerRepository' );
        $repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'code2' )->andReturn( '' );
        $repo->shouldReceive( 'setQrCacheForShortCode' )->andReturnUsing(
            static function ( $code, $b64 ) use ( &$persisted ) {
                $persisted = array( $code, $b64 );
            }
        );

        $gen = Mockery::mock( 'overload:FreeFormCertificate\Generators\QRCodeGenerator' );
        $gen->shouldReceive( 'generate' )->andReturn( 'FRESH64' );

        $result = $this->handler->generate_qr_base64( 'https://example.com/x', 200, 'code2' );

        $this->assertSame( 'FRESH64', $result );
        $this->assertSame( array( 'code2', 'FRESH64' ), $persisted );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_generate_qr_base64_no_short_code_skips_cache(): void {
        $gen = Mockery::mock( 'overload:FreeFormCertificate\Generators\QRCodeGenerator' );
        $gen->shouldReceive( 'generate' )->once()->andReturn( 'NOCODE64' );

        $result = $this->handler->generate_qr_base64( 'https://example.com/x', 200 );

        $this->assertSame( 'NOCODE64', $result );
    }

    // ==================================================================
    // handle_download_png() / handle_download_svg() — success + failure
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_download_png_success(): void {
        $_POST['nonce'] = 'valid';
        $_POST['code']  = 'abc';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $repo = Mockery::mock( 'overload:FreeFormCertificate\UrlShortener\UrlShortenerRepository' );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $repo->shouldReceive( 'findByShortCode' )->with( 'abc' )->andReturn( array( 'short_code' => 'abc' ) );
        $this->service->shouldReceive( 'get_short_url' )->with( 'abc' )->andReturn( 'https://example.com/go/abc' );

        $gen = Mockery::mock( 'overload:FreeFormCertificate\Generators\QRCodeGenerator' );
        $gen->shouldReceive( 'generate' )->andReturn( 'PNG64' );

        $sent = null;
        Functions\when( 'wp_send_json_success' )->alias(
            static function ( $data ) use ( &$sent ) {
                $sent = $data;
                throw new \RuntimeException( 'ok' );
            }
        );

        try {
            $this->handler->handle_download_png();
        } catch ( \RuntimeException $e ) {
            // Expected.
        }

        $this->assertSame( 'PNG64', $sent['data'] );
        $this->assertSame( 'qr-abc.png', $sent['filename'] );
        $this->assertSame( 'image/png', $sent['mime'] );
    }

    /**
     * #739 §4.2 — a url-shortener *manager* (holds `ffc_manage_url_shortener`
     * but not the `view` cap, and is not a WP admin) can still download the QR.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_download_png_accepts_manage_cap_without_view(): void {
        $_POST['nonce'] = 'valid';
        $_POST['code']  = 'abc';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->alias(
            static function ( $cap ) {
                return 'ffc_manage_url_shortener' === $cap;
            }
        );

        $repo = Mockery::mock( 'overload:FreeFormCertificate\UrlShortener\UrlShortenerRepository' );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $repo->shouldReceive( 'findByShortCode' )->with( 'abc' )->andReturn( array( 'short_code' => 'abc' ) );
        $this->service->shouldReceive( 'get_short_url' )->with( 'abc' )->andReturn( 'https://example.com/go/abc' );

        $gen = Mockery::mock( 'overload:FreeFormCertificate\Generators\QRCodeGenerator' );
        $gen->shouldReceive( 'generate' )->andReturn( 'PNG64' );

        $sent = null;
        Functions\when( 'wp_send_json_success' )->alias(
            static function ( $data ) use ( &$sent ) {
                $sent = $data;
                throw new \RuntimeException( 'ok' );
            }
        );
        Functions\when( 'wp_send_json_error' )->alias(
            static function () {
                throw new \RuntimeException( 'denied' );
            }
        );

        try {
            $this->handler->handle_download_png();
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'ok', $e->getMessage() );
        }

        $this->assertSame( 'PNG64', $sent['data'] );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_download_png_generation_failure_sends_error(): void {
        $_POST['nonce'] = 'valid';
        $_POST['code']  = 'abc';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $repo = Mockery::mock( 'overload:FreeFormCertificate\UrlShortener\UrlShortenerRepository' );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $repo->shouldReceive( 'findByShortCode' )->with( 'abc' )->andReturn( array( 'short_code' => 'abc' ) );
        $this->service->shouldReceive( 'get_short_url' )->with( 'abc' )->andReturn( 'https://example.com/go/abc' );

        $gen = Mockery::mock( 'overload:FreeFormCertificate\Generators\QRCodeGenerator' );
        $gen->shouldReceive( 'generate' )->andReturn( '' );

        Functions\when( 'wp_send_json_error' )->alias(
            static function () {
                throw new \RuntimeException( 'gen_failed' );
            }
        );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'gen_failed' );
        $this->handler->handle_download_png();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_download_svg_success(): void {
        $_POST['nonce'] = 'valid';
        $_POST['code']  = 'abc';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $repo = Mockery::mock( 'overload:FreeFormCertificate\UrlShortener\UrlShortenerRepository' );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $repo->shouldReceive( 'findByShortCode' )->with( 'abc' )->andReturn( array( 'short_code' => 'abc' ) );
        $this->service->shouldReceive( 'get_short_url' )->with( 'abc' )->andReturn( 'https://example.com/go/abc' );

        $sent = null;
        Functions\when( 'wp_send_json_success' )->alias(
            static function ( $data ) use ( &$sent ) {
                $sent = $data;
                throw new \RuntimeException( 'ok' );
            }
        );

        try {
            $this->handler->handle_download_svg();
        } catch ( \RuntimeException $e ) {
            // Expected.
        }

        $this->assertSame( 'qr-abc.svg', $sent['filename'] );
        $this->assertSame( 'image/svg+xml', $sent['mime'] );
        // Payload is base64-encoded SVG markup.
        $this->assertStringContainsString( '<svg', base64_decode( $sent['data'] ) );
    }
}
