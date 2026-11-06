<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\Capabilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerMetaBox;
use FreeFormCertificate\UrlShortener\UrlShortenerService;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

/**
 * Tests for UrlShortenerMetaBox: meta box registration per post type,
 * on_save_post guards and auto-create, and ajax_regenerate flow.
 *
 * The service and the repository it hands out are instance mocks injected
 * through the constructor, so every test runs in the shared process except
 * the autosave guard, which defines a constant.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerMetaBox
 */
class UrlShortenerMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private UrlShortenerMetaBox $meta_box;

	/** @var UrlShortenerService|Mockery\MockInterface */
	private $service;

	/** @var array<string, mixed> The `$_POST` an earlier test left, restored afterwards. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->post_backup = $_POST ?? array();
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 1 === (int) $n ? $one : $many );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		// Global stubs only: a namespaced one would shadow the global function
		// for every later test in the process (#2123).
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/my-post' );

		$this->service  = Mockery::mock( UrlShortenerService::class );
		$this->meta_box = new UrlShortenerMetaBox( $this->service );
	}

	protected function tearDown(): void {
		$_POST = $this->post_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// register_meta_box()
	// ==================================================================

	public function test_register_meta_box_for_each_post_type(): void {
		$this->service->shouldReceive( 'get_enabled_post_types' )->once()->andReturn( [ 'post', 'page', 'product' ] );

		$registered = [];
		Functions\when( 'add_meta_box' )->alias( function ( $id, $title, $callback, $screen ) use ( &$registered ) {
			$registered[] = $screen;
		} );

		$this->meta_box->register_meta_box();

		$this->assertCount( 3, $registered );
		$this->assertSame( [ 'post', 'page', 'product' ], $registered );
	}

	public function test_register_meta_box_empty_post_types(): void {
		$this->service->shouldReceive( 'get_enabled_post_types' )->once()->andReturn( [] );

		$count = 0;
		Functions\when( 'add_meta_box' )->alias( function () use ( &$count ) {
			$count++;
		} );

		$this->meta_box->register_meta_box();

		$this->assertSame( 0, $count );
	}

	// ==================================================================
	// on_save_post() — guard clauses
	// ==================================================================

	public function test_on_save_post_skips_revision(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( true );

		$post = $this->make_post( 1, 'publish', 'post' );

		$this->service->shouldNotReceive( 'is_auto_create_enabled' );

		$this->meta_box->on_save_post( 1, $post );
	}

	public function test_on_save_post_skips_non_publish(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		$post = $this->make_post( 1, 'draft', 'post' );

		$this->service->shouldNotReceive( 'is_auto_create_enabled' );

		$this->meta_box->on_save_post( 1, $post );
	}

	public function test_on_save_post_skips_when_auto_create_disabled(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		$post = $this->make_post( 1, 'publish', 'post' );

		$this->service->shouldReceive( 'is_auto_create_enabled' )->once()->andReturn( false );
		$this->service->shouldNotReceive( 'get_enabled_post_types' );

		$this->meta_box->on_save_post( 1, $post );
	}

	public function test_on_save_post_skips_wrong_post_type(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		$post = $this->make_post( 1, 'publish', 'attachment' );

		$this->service->shouldReceive( 'is_auto_create_enabled' )->andReturn( true );
		$this->service->shouldReceive( 'get_enabled_post_types' )->andReturn( [ 'post', 'page' ] );
		$this->service->shouldNotReceive( 'get_repository' );

		$this->meta_box->on_save_post( 1, $post );
	}

	public function test_on_save_post_skips_existing_short_url(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		$post = $this->make_post( 5, 'publish', 'post' );

		$this->service->shouldReceive( 'is_auto_create_enabled' )->andReturn( true );
		$this->service->shouldReceive( 'get_enabled_post_types' )->andReturn( [ 'post' ] );

		$repo = Mockery::mock( UrlShortenerRepository::class );
		$repo->shouldReceive( 'findByPostId' )->with( 5 )->andReturn( [ 'id' => 10 ] );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $repo );

		// Should NOT call create_short_url
		$this->service->shouldNotReceive( 'create_short_url' );

		$this->meta_box->on_save_post( 5, $post );
	}

	public function test_on_save_post_creates_short_url(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/my-post' );

		$post = $this->make_post( 5, 'publish', 'post' );
		$post->post_title = 'My Post';

		$this->service->shouldReceive( 'is_auto_create_enabled' )->andReturn( true );
		$this->service->shouldReceive( 'get_enabled_post_types' )->andReturn( [ 'post' ] );

		$repo = Mockery::mock( UrlShortenerRepository::class );
		$repo->shouldReceive( 'findByPostId' )->with( 5 )->andReturn( null );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $repo );

		$this->service->shouldReceive( 'create_short_url' )
			->with( 'https://example.com/my-post', 'My Post', 5 )
			->once();

		$this->meta_box->on_save_post( 5, $post );
	}

	// ==================================================================
	// ajax_regenerate()
	// ==================================================================

	/**
	 * Runs ajax_regenerate() and returns what it sent.
	 *
	 * @return array{0: string, 1: array<string, mixed>} `success` or `error`, and the payload.
	 */
	private function run_ajax(): array {
		$sent = array( '', array() );
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data ) use ( &$sent ) {
				$sent = array( 'success', $data );
				throw new \RuntimeException( 'json' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data ) use ( &$sent ) {
				$sent = array( 'error', $data );
				throw new \RuntimeException( 'json' );
			}
		);
		// The redrawn box is drawn by box_html(), covered by the render tests.
		$box = Mockery::mock( UrlShortenerMetaBox::class, array( $this->service ) )->makePartial();
		$box->shouldReceive( 'box_html' )->andReturn( '<div class="ffc-shorturl-metabox">redrawn</div>' );
		try {
			$box->ajax_regenerate();
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Expected: the stubs stop the request like wp_die().
		}
		return $sent;
	}

	public function test_ajax_regenerate_swaps_the_code_of_an_active_link(): void {
		$_POST['nonce']   = 'valid';
		$_POST['post_id'] = '10';
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( $this->make_post( 10, 'publish', 'page' ) );

		$link = array( 'id' => 20, 'short_code' => 'old123', 'status' => 'active' );
		$repo = Mockery::mock( UrlShortenerRepository::class );
		$repo->shouldReceive( 'findByPostId' )->with( 10 )->andReturn( $link );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
		// Same row, new code: nothing is deleted or created (#2337).
		$this->service->shouldNotReceive( 'delete_short_url', 'create_short_url' );
		$this->service->shouldReceive( 'regenerate_code' )->with( $link )->once()->andReturn(
			array(
				'success' => true,
				'data'    => array( 'id' => 20, 'short_code' => 'new123' ),
			)
		);
		$this->service->shouldReceive( 'get_short_url' )->with( 'new123' )->andReturn( 'https://example.com/go/new123' );

		list( $kind, $data ) = $this->run_ajax();

		$this->assertSame( 'success', $kind );
		$this->assertSame( 20, $data['id'] );
		$this->assertSame( 'https://example.com/go/new123', $data['short_url'] );
		$this->assertStringContainsString( 'old code is retired', $data['message'] );
		$this->assertSame( '<div class="ffc-shorturl-metabox">redrawn</div>', $data['html'], 'The box is redrawn in place, no reload (#2337).' );
	}

	public function test_ajax_reactivates_a_disabled_or_trashed_link_with_its_code(): void {
		$_POST['nonce']   = 'valid';
		$_POST['post_id'] = '10';
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( $this->make_post( 10, 'publish', 'page' ) );

		$repo = Mockery::mock( UrlShortenerRepository::class );
		$repo->shouldReceive( 'findByPostId' )->with( 10 )->andReturn( array( 'id' => 20, 'short_code' => 'keep12', 'status' => 'trashed' ) );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
		$this->service->shouldNotReceive( 'regenerate_code' );
		$this->service->shouldReceive( 'reactivate_short_url' )->with( 20 )->once()->andReturn( true );
		$this->service->shouldReceive( 'get_short_url' )->with( 'keep12' )->andReturn( 'https://example.com/go/keep12' );

		list( $kind, $data ) = $this->run_ajax();

		$this->assertSame( 'success', $kind );
		$this->assertSame( 'active', $data['status'] );
		$this->assertSame( 'https://example.com/go/keep12', $data['short_url'], 'The same code comes back.' );
	}

	public function test_ajax_regenerate_needs_to_edit_the_post(): void {
		$_POST['nonce']   = 'valid';
		$_POST['post_id'] = '10';
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		// Manages short URLs, cannot edit this post.
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'edit_post' !== $cap );
		Functions\when( 'get_post' )->justReturn( $this->make_post( 10, 'publish', 'page' ) );
		$this->service->shouldNotReceive( 'get_repository' );

		list( $kind, $data ) = $this->run_ajax();

		$this->assertSame( 'error', $kind );
		$this->assertSame( 'You cannot edit this post.', $data['message'] );
	}

	public function test_ajax_generate_refuses_an_unpublished_post(): void {
		$_POST['nonce']   = 'valid';
		$_POST['post_id'] = '10';
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( $this->make_post( 10, 'draft', 'page' ) );

		$repo = Mockery::mock( UrlShortenerRepository::class );
		$repo->shouldReceive( 'findByPostId' )->with( 10 )->andReturn( null );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
		$this->service->shouldNotReceive( 'create_short_url' );

		list( $kind ) = $this->run_ajax();

		$this->assertSame( 'error', $kind );
	}

	public function test_ajax_regenerate_invalid_post_id_sends_error(): void {
		$_POST['nonce'] = 'valid';
		// No post_id set

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$error_sent = false;
		Functions\when( 'wp_send_json_error' )->alias( function () use ( &$error_sent ) {
			$error_sent = true;
			throw new \RuntimeException( 'json_error' );
		} );

		try {
			$this->meta_box->ajax_regenerate();
		} catch ( \RuntimeException $e ) {
			// Expected
		}

		$this->assertTrue( $error_sent );
	}

	public function test_ajax_regenerate_no_existing_creates_new(): void {
		$_POST['nonce']   = 'valid';
		$_POST['post_id'] = '15';

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/another' );

		$mock_post = $this->make_post( 15, 'publish', 'post' );
		$mock_post->post_title = 'Another Post';
		Functions\when( 'get_post' )->justReturn( $mock_post );

		$repo = Mockery::mock( UrlShortenerRepository::class );
		$repo->shouldReceive( 'findByPostId' )->with( 15 )->andReturn( null );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
		$this->service->shouldNotReceive( 'delete_short_url' );
		$this->service->shouldReceive( 'create_short_url' )->once()->andReturn( [
			'success' => true,
			'data'    => [ 'id' => 30, 'short_code' => 'fresh1' ],
		] );
		$this->service->shouldReceive( 'get_short_url' )->with( 'fresh1' )->andReturn( 'https://example.com/go/fresh1' );

		list( $kind ) = $this->run_ajax();
		$this->assertSame( 'success', $kind );
	}

	public function test_ajax_regenerate_service_failure_sends_error(): void {
		$_POST['nonce']   = 'valid';
		$_POST['post_id'] = '11';

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/page' );

		$mock_post = $this->make_post( 11, 'publish', 'post' );
		Functions\when( 'get_post' )->justReturn( $mock_post );

		$repo = Mockery::mock( UrlShortenerRepository::class );
		$repo->shouldReceive( 'findByPostId' )->with( 11 )->andReturn( null );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
		$this->service->shouldReceive( 'create_short_url' )->once()->andReturn(
			array(
				'success' => false,
				'error'   => 'boom',
			)
		);

		$error_msg = '';
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data ) use ( &$error_msg ) {
				$error_msg = $data['message'] ?? '';
				throw new \RuntimeException( 'json_error' );
			}
		);

		try {
			$this->meta_box->ajax_regenerate();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertSame( 'boom', $error_msg );
	}

	// ==================================================================
	// init()
	// ==================================================================

	/**
	 * Capture the hook names a registration method passes to add_action().
	 *
	 * @param callable $register Registration method to run.
	 * @return array<int, string> Hook names, in registration order.
	 */
	private function capture_hooks( callable $register ): array {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
			}
		);

		$register();

		return $hooks;
	}

	public function test_init_registers_admin_ui_hooks_only(): void {
		$hooks = $this->capture_hooks( fn () => $this->meta_box->init() );

		$this->assertContains( 'add_meta_boxes', $hooks );
		$this->assertContains( 'admin_enqueue_scripts', $hooks );
		$this->assertContains( 'wp_ajax_ffc_regenerate_short_url', $hooks );

		// save_post moved to init_auto_create(), which the loader calls outside
		// its is_admin() gate — the block editor saves over REST (#1013).
		$this->assertNotContains( 'save_post', $hooks );
	}

	public function test_init_auto_create_registers_only_save_post(): void {
		$hooks = $this->capture_hooks( fn () => $this->meta_box->init_auto_create() );

		$this->assertSame( array( 'save_post' ), $hooks );
	}

	// ==================================================================
	// render()
	// ==================================================================

	/**
	 * Renders the box for a post with no link.
	 *
	 * @param string $status        Post status.
	 * @param bool   $auto_create   Whether auto-create is on.
	 * @param bool   $can_manage    Whether the user may act on the link.
	 * @return string
	 */
	private function render_without_link( string $status, bool $auto_create, bool $can_manage ): string {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'current_user_can' )->justReturn( $can_manage );

		$repo = Mockery::mock( UrlShortenerRepository::class );
		$repo->shouldReceive( 'findByPostId' )->with( 3 )->andReturn( null );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
		$this->service->shouldReceive( 'is_auto_create_enabled' )->andReturn( $auto_create );
		// Viewing the editor never creates a link (#2337).
		$this->service->shouldNotReceive( 'create_short_url' );

		ob_start();
		$this->meta_box->render( $this->make_post( 3, $status, 'post' ) );
		return (string) ob_get_clean();
	}

	public function test_render_unpublished_no_record_shows_publish_hint(): void {
		$this->assertStringContainsString( 'Publish the post', $this->render_without_link( 'draft', false, true ) );
	}

	public function test_render_published_without_a_link_waits_for_the_save_when_auto_create_is_on(): void {
		$html = $this->render_without_link( 'publish', true, true );

		$this->assertStringContainsString( 'created when the post is saved', $html );
		$this->assertStringNotContainsString( 'ffc-regenerate-shorturl', $html, 'No button: the next save creates it.' );
	}

	public function test_render_published_without_a_link_offers_generate_when_auto_create_is_off(): void {
		$html = $this->render_without_link( 'publish', false, true );

		$this->assertStringContainsString( 'ffc-regenerate-shorturl', $html );
		$this->assertStringContainsString( 'Generate', $html );
		$this->assertStringContainsString( 'data-ffc-confirm=""', $html, 'Generating asks nothing first.' );
	}

	public function test_render_offers_no_action_without_the_manage_capability(): void {
		$html = $this->render_without_link( 'publish', false, false );

		$this->assertStringContainsString( 'no short URL yet', $html );
		$this->assertStringNotContainsString( 'ffc-regenerate-shorturl', $html );
	}

	public function test_render_a_disabled_link_offers_reactivate_and_no_qr(): void {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias( static function ( $t ) { echo $t; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'current_user_can' )->justReturn( true );

		$repo = Mockery::mock( UrlShortenerRepository::class );
		$repo->shouldReceive( 'findByPostId' )->with( 6 )->andReturn(
			array(
				'id'          => 60,
				'short_code'  => 'code60',
				'click_count' => 12,
				'status'      => 'disabled',
			)
		);
		$repo->shouldNotReceive( 'findQrCacheByShortCode' );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
		$this->service->shouldReceive( 'get_short_url' )->with( 'code60' )->andReturn( 'https://example.com/go/code60' );

		ob_start();
		$this->meta_box->render( $this->make_post( 6, 'publish', 'post' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'disabled and does not redirect', $html );
		$this->assertStringContainsString( 'ffc-shorturl-status-disabled', $html, 'The box shows the state (#2337).' );
		$this->assertStringContainsString( '<strong>12</strong> clicks', $html );
		$this->assertStringContainsString( 'Reactivate', $html );
		$this->assertStringNotContainsString( 'data-format="png"', $html, 'An off link has no QR to download.' );
	}

	/**
	 * Full render path with an existing record and a cached QR code.
	 *
	 * The repository the service hands out answers both the meta box's
	 * findByPostId() lookup and the QR handler's cache lookup, which the
	 * handler reaches through the same service since #1233; the cache hit
	 * short-circuits the CPU-heavy generator.
	 */
	public function test_render_with_record_outputs_qr_and_buttons(): void {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias(
			static function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
			}
		);
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'current_user_can' )->justReturn( true );

		// The QR design switch (#1563) is off, so the preview is the cached PNG.
		Functions\when( 'get_option' )->justReturn( array() );

		$post = $this->make_post( 6, 'publish', 'post' );

		$repo = Mockery::mock( UrlShortenerRepository::class );
		$repo->shouldReceive( 'findByPostId' )->with( 6 )->andReturn(
			array(
				'id'          => 60,
				'short_code'  => 'code60',
				'click_count' => 12,
				'status'      => 'active',
			)
		);
		// The cache stores an envelope that declares the size (#1233); raw base64
		// would be read as an old-schema row, i.e. a miss, and the rendering
		// would fall through to the real generator.
		$repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'code60' )->andReturn(
			(string) json_encode(
				array(
					'v'    => 2,
					'size' => \FreeFormCertificate\UrlShortener\UrlShortenerQrHandler::CACHE_SIZE,
					'url'  => 'https://example.com/go/code60',
					'png'  => 'CACHEDBASE64',
				)
			)
		);
		$repo->shouldNotReceive( 'setQrCacheForShortCode' );

		$this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
		$this->service->shouldReceive( 'get_short_url' )->with( 'code60' )->andReturn( 'https://example.com/go/code60' );

		ob_start();
		$this->meta_box->render( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'src="data:image/png;base64,CACHEDBASE64"', $html );
		$this->assertStringContainsString( 'data-format="png"', $html );
		$this->assertStringContainsString( 'data-format="svg"', $html );
		$this->assertStringContainsString( 'Regenerate', $html );
		$this->assertStringContainsString( 'never issued again', $html, 'Regenerate asks first.' );
	}

	// ==================================================================
	// Helpers
	// ==================================================================

	/**
	 * @param int    $id
	 * @param string $status
	 * @param string $type
	 * @return \WP_Post|Mockery\MockInterface
	 */
	private function make_post( int $id, string $status, string $type ) {
		$post = Mockery::mock( \WP_Post::class );
		$post->ID          = $id;
		$post->post_status = $status;
		$post->post_type   = $type;
		$post->post_title  = '';
		$post->post_name   = 'test-post';
		return $post;
	}

	// ==================================================================
	// on_save_post() — autosave guard
	// ==================================================================

	/**
	 * Its own process: it defines DOING_AUTOSAVE, a constant no later test may inherit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_on_save_post_skips_autosave(): void {
		if ( ! defined( 'DOING_AUTOSAVE' ) ) {
			define( 'DOING_AUTOSAVE', true );
		}

		$post = $this->make_post( 1, 'publish', 'post' );

		$this->service->shouldNotReceive( 'is_auto_create_enabled' );

		$this->meta_box->on_save_post( 1, $post );
	}

	// ==================================================================
	// enqueue_assets()
	// ==================================================================

	public function test_enqueue_assets_skips_on_unrelated_hook(): void {
		$this->service->shouldNotReceive( 'get_enabled_post_types' );
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		$this->meta_box->enqueue_assets( 'index.php' );
		$this->assertTrue( true );
	}

	public function test_enqueue_assets_skips_when_post_type_not_enabled(): void {
		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		$this->service->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'page' ) );

		$this->meta_box->enqueue_assets( 'post.php' );
		$this->assertTrue( true );
	}

	public function test_enqueue_assets_enqueues_on_enabled_post_edit(): void {
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		$this->service->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'page' ) );

		$enqueued = array();
		// The meta box, AssetHelper::enqueue_common_style() and AssetRegistry
		// all reach the global functions (#1126 B, #1931).
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $h ) use ( &$enqueued ) {
				$enqueued[] = $h;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $h ) use ( &$enqueued ) {
				$enqueued[] = $h;
			}
		);
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );

		$this->meta_box->enqueue_assets( 'post-new.php' );

		$this->assertContains( 'ffc-url-shortener-admin-css', $enqueued );
	}
}
