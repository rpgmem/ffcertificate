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
 * post_id and short code scenarios, the QR cache and the download handlers.
 *
 * The PNG paths run the real QRCodeGenerator (phpqrcode over GD) with
 * `wp_tempnam()` stubbed, so a test chooses whether a temp file exists --
 * which is how a generation failure is produced. The cache and download
 * paths run the real UrlShortenerRepository, Reader and Writer over a
 * `$wpdb` double: `ffc_short_urls` rows and their `qr_cache` column live in
 * this class, every UPDATE is recorded, and an unexpected query fails the
 * test.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerQrHandler
 */
class UrlShortenerQrHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private UrlShortenerQrHandler $handler;

	/** @var UrlShortenerService|Mockery\MockInterface */
	private $service;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/**
	 * Every prepared statement, in order: [ sql, args ].
	 *
	 * @var list<array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = array();

	/**
	 * Stored `ffc_short_urls` rows, by short code.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $short_urls = array();

	/**
	 * Stored `qr_cache` column values, by short code.
	 *
	 * @var array<string, string>
	 */
	private array $qr_cache = array();

	/**
	 * Every `$wpdb->update()`: [ table, data, where ].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private array $updates = array();

	/** How many temp files the QR generator asked for. */
	private int $tempnam_calls = 0;

	/** Whether `wp_tempnam()` hands out a file (false makes generation fail). */
	private bool $tempnam_works = true;

	/**
	 * The `$wpdb` global before the test, restored in tearDown().
	 *
	 * @var mixed
	 */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

		// The QR design switch (#1563) and the debug areas are read from
		// `ffc_settings`; off unless a test turns the design on.
		Functions\when( 'get_option' )->justReturn( array() );

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// UrlShortenerWriter clears the whole group after a qr_cache write.
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);

		// QRCodeGenerator::generate() prefers wp_tempnam() when it exists, so
		// it is stubbed here and the branch is chosen rather than inherited.
		$this->tempnam_calls = 0;
		$this->tempnam_works = true;
		Functions\when( 'wp_tempnam' )->alias(
			function () {
				++$this->tempnam_calls;
				return $this->tempnam_works ? (string) tempnam( sys_get_temp_dir(), 'ffc_qr_' ) : false;
			}
		);
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
		);

		$this->prepared   = array();
		$this->short_urls = array();
		$this->qr_cache   = array();
		$this->updates    = array();

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = array( (string) $flat[0], array_slice( $flat, 1 ) );
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $handle ) {
				[ $sql, $args ] = $this->statement( (string) $handle );
				if ( 'SELECT * FROM %i WHERE short_code = %s' === $sql && 'wp_ffc_short_urls' === $args[0] ) {
					return $this->short_urls[ (string) $args[1] ] ?? null;
				}
				$this->fail( 'Unexpected get_row(): ' . $sql . ' with ' . var_export( $args, true ) );
			}
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $handle ) {
				[ $sql, $args ] = $this->statement( (string) $handle );
				if ( 'SELECT qr_cache FROM %i WHERE short_code = %s' === $sql && 'wp_ffc_short_urls' === $args[0] ) {
					return $this->qr_cache[ (string) $args[1] ] ?? null;
				}
				$this->fail( 'Unexpected get_var(): ' . $sql . ' with ' . var_export( $args, true ) );
			}
		);
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( (string) $table, (array) $data, (array) $where );
				return 1;
			}
		);

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = $this->wpdb;

		$this->service = Mockery::mock( UrlShortenerService::class );
		$this->handler = new UrlShortenerQrHandler( $this->service );
	}

	protected function tearDown(): void {
		unset( $_POST['nonce'], $_POST['post_id'], $_POST['code'] );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The statement a `prepare()` handle stands for.
	 *
	 * @param string $handle Value prepare() returned.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( string $handle ): array {
		$index = (int) substr( $handle, 1 );
		if ( 'Q' !== $handle[0] || ! isset( $this->prepared[ $index ] ) ) {
			$this->fail( 'A query ran without prepare(): ' . $handle );
		}
		return $this->prepared[ $index ];
	}

	/**
	 * Hand the service a real repository over the `$wpdb` double.
	 *
	 * @return void
	 */
	private function use_real_repository(): void {
		$this->service->shouldReceive( 'get_repository' )->andReturn( new UrlShortenerRepository() );
	}

	/**
	 * Store a short URL row the repository will find by its code.
	 *
	 * @param string $code Short code.
	 * @return void
	 */
	private function store_short_url( string $code ): void {
		$this->short_urls[ $code ] = array(
			'id'         => '5',
			'short_code' => $code,
			'target_url' => 'https://example.com/target',
			'status'     => 'active',
		);
	}

	/**
	 * Assert a value is base64 of a PNG image.
	 *
	 * @param mixed $base64 Value under test.
	 * @return void
	 */
	private function assertIsPngBase64( $base64 ): void {
		$this->assertIsString( $base64 );
		$this->assertStringStartsWith( "\x89PNG", (string) base64_decode( $base64, true ), 'Expected base64 of a PNG image.' );
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
		$this->assertStringContainsString( 'fill="#000000"', $svg );
		$this->assertStringContainsString( 'width="200" height="200"', $svg );
	}

	public function test_generate_svg_paints_only_the_dark_modules(): void {
		// The previous body tested phpqrcode's raw frame bytes for truth, and
		// a light module there is a non-zero byte -- so every module came out
		// dark and the download was a solid square (#1563).
		$svg    = $this->handler->generate_svg( 'https://example.com/x', 200 );
		$matrix = \FreeFormCertificate\Generators\QrSvgRenderer::matrix( 'https://example.com/x', 'M' );
		$n      = count( $matrix );

		$this->assertLessThan( $n * $n * 0.7, substr_count( $svg, '<rect x=' ) );
	}

	public function test_generate_svg_draws_the_design_when_applied_to_short_urls(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'qr_design_on_short_urls' => 1,
				'qr_design_dots'          => 'dots',
			)
		);

		$this->assertTrue( $this->handler->is_designed() );
		$this->assertStringContainsString( '<circle', $this->handler->generate_svg( 'https://example.com/x', 200 ) );
	}

	public function test_preview_src_is_the_svg_when_designed(): void {
		Functions\when( 'get_option' )->justReturn( array( 'qr_design_on_short_urls' => 1 ) );

		$src = $this->handler->preview_src( 'https://example.com/x' );

		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $src );
		$this->assertStringContainsString( '<svg', base64_decode( substr( $src, 26 ) ) );
	}

	public function test_preview_src_is_the_png_otherwise(): void {
		$src = $this->handler->preview_src( 'https://example.com/x', 400 );

		$this->assertStringStartsWith( 'data:image/png;base64,', $src );
		$this->assertIsPngBase64( substr( $src, strlen( 'data:image/png;base64,' ) ) );
		$this->assertSame( 1, $this->tempnam_calls, 'The PNG is drawn by the server generator.' );
	}

	public function test_preview_src_is_empty_when_generation_fails(): void {
		// No temp file: the generator gives up and returns ''.
		$this->tempnam_works = false;

		$this->assertSame( '', $this->handler->preview_src( 'https://example.com/x', 400 ) );
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


	public function test_generate_qr_base64_returns_cached_when_present(): void {
		// Since #1233 the cache stores an ENVELOPE that declares the size, not
		// raw base64: the hit only counts when the stored size and the requested
		// one match. Raw base64 here would be a miss -- which is exactly the
		// upgrade path covered in UrlShortenerQrCacheSizeTest.
		$this->qr_cache['code1'] = (string) json_encode(
			array(
				'v'    => 1,
				'size' => 200,
				'png'  => 'CACHED64',
			)
		);
		$this->use_real_repository();

		$result = $this->handler->generate_qr_base64( 'https://example.com/x', 200, 'code1' );

		$this->assertSame( 'CACHED64', $result );
		// Generator must NOT run and cache must NOT be written on a hit.
		$this->assertSame( 0, $this->tempnam_calls, 'The generator ran on a cache hit.' );
		$this->assertSame( array(), $this->updates, 'The cache was written on a hit.' );
	}

	public function test_generate_qr_base64_generates_and_persists_on_miss(): void {
		$this->qr_cache['code2'] = '';
		$this->use_real_repository();

		$result = $this->handler->generate_qr_base64( 'https://example.com/x', 200, 'code2' );

		$this->assertIsPngBase64( $result );
		$this->assertSame( 1, $this->tempnam_calls );

		// What was stored is the envelope, not the loose base64 (#1233).
		$this->assertCount( 1, $this->updates );
		[ $table, $data, $where ] = $this->updates[0];
		$this->assertSame( 'wp_ffc_short_urls', $table );
		$this->assertSame( array( 'short_code' => 'code2' ), $where );
		$decoded = json_decode( (string) ( $data['qr_cache'] ?? '' ), true );
		$this->assertSame( 200, $decoded['size'] ?? null );
		$this->assertSame( $result, $decoded['png'] ?? null );
	}

	public function test_generate_qr_base64_no_short_code_skips_cache(): void {
		// The repository is never asked for: no read, no write.
		$this->service->shouldNotReceive( 'get_repository' );

		$result = $this->handler->generate_qr_base64( 'https://example.com/x', 200 );

		$this->assertIsPngBase64( $result );
		$this->assertSame( 1, $this->tempnam_calls, 'The generator must run exactly once.' );
		$this->assertSame( array(), $this->prepared );
	}

	// ==================================================================
	// handle_download_png() / handle_download_svg() — success + failure
	// ==================================================================

	public function test_handle_download_png_success(): void {
		$_POST['nonce'] = 'valid';
		$_POST['code']  = 'abc';

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->store_short_url( 'abc' );
		$this->use_real_repository();
		$this->service->shouldReceive( 'get_short_url' )->with( 'abc' )->andReturn( 'https://example.com/go/abc' );

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

		$this->assertIsPngBase64( $sent['data'] );
		$this->assertSame( 'qr-abc.png', $sent['filename'] );
		$this->assertSame( 'image/png', $sent['mime'] );
	}

	/**
	 * #739 §4.2 — a url-shortener *manager* (holds `ffc_manage_url_shortener`
	 * but not the `view` cap, and is not a WP admin) can still download the QR.
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

		$this->store_short_url( 'abc' );
		$this->use_real_repository();
		$this->service->shouldReceive( 'get_short_url' )->with( 'abc' )->andReturn( 'https://example.com/go/abc' );

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

		$this->assertIsPngBase64( $sent['data'] );
	}

	public function test_handle_download_png_generation_failure_sends_error(): void {
		$_POST['nonce'] = 'valid';
		$_POST['code']  = 'abc';

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->store_short_url( 'abc' );
		$this->use_real_repository();
		$this->service->shouldReceive( 'get_short_url' )->with( 'abc' )->andReturn( 'https://example.com/go/abc' );

		// No temp file: the generator returns '' and the handler reports it.
		$this->tempnam_works = false;

		Functions\when( 'wp_send_json_error' )->alias(
			static function () {
				throw new \RuntimeException( 'gen_failed' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'gen_failed' );
		$this->handler->handle_download_png();
	}

	public function test_handle_download_svg_success(): void {
		$_POST['nonce'] = 'valid';
		$_POST['code']  = 'abc';

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->store_short_url( 'abc' );
		$this->use_real_repository();
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

	/**
	 * With the design applied the PNG download ships the styled SVG flagged
	 * for rasterising in the browser, never a plain server PNG (#1563).
	 */
	public function test_handle_download_png_ships_the_svg_for_rasterising_when_designed(): void {
		$_POST['nonce'] = 'valid';
		$_POST['code']  = 'abc';

		Functions\when( 'get_option' )->justReturn( array( 'qr_design_on_short_urls' => 1, 'qr_design_dots' => 'diamond' ) );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->store_short_url( 'abc' );
		$this->use_real_repository();
		$this->service->shouldReceive( 'get_short_url' )->with( 'abc' )->andReturn( 'https://example.com/go/abc' );

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

		$this->assertSame( 0, $this->tempnam_calls, 'The server PNG generator ran although the design applies.' );
		$this->assertTrue( $sent['rasterize'] );
		$this->assertSame( 'qr-abc.png', $sent['filename'] );
		$this->assertSame( 'image/png', $sent['mime'] );
		$this->assertStringContainsString( '<path d="M', base64_decode( $sent['data'] ) );
	}
}
