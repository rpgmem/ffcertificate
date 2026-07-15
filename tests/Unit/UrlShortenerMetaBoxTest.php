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
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerMetaBox
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class UrlShortenerMetaBoxTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private UrlShortenerMetaBox $meta_box;

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
        Functions\when( 'FreeFormCertificate\UrlShortener\get_permalink' )->justReturn( 'https://example.com/my-post' );

        $this->service  = Mockery::mock( UrlShortenerService::class );
        $this->meta_box = new UrlShortenerMetaBox( $this->service );
    }

    protected function tearDown(): void {
        unset( $_POST['nonce'], $_POST['post_id'] );
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

    public function test_ajax_regenerate_success(): void {
        $_POST['nonce']   = 'valid';
        $_POST['post_id'] = '10';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_permalink' )->justReturn( 'https://example.com/page' );

        $mock_post = $this->make_post( 10, 'publish', 'page' );
        $mock_post->post_title = 'Page Title';
        Functions\when( 'get_post' )->justReturn( $mock_post );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByPostId' )->with( 10 )->andReturn( [ 'id' => 20 ] );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'delete_short_url' )->with( 20 )->once();
        $this->service->shouldReceive( 'create_short_url' )->once()->andReturn( [
            'success' => true,
            'data'    => [ 'id' => 21, 'short_code' => 'new123' ],
        ] );
        $this->service->shouldReceive( 'get_short_url' )->with( 'new123' )->andReturn( 'https://example.com/go/new123' );

        $sent_data = null;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$sent_data ) {
            $sent_data = $data;
            throw new \RuntimeException( 'json_success' );
        } );

        try {
            $this->meta_box->ajax_regenerate();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'new123', $sent_data['short_code'] );
        $this->assertSame( 'https://example.com/go/new123', $sent_data['short_url'] );
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

    /**
     * Runs in a separate process because AjaxTrait::check_ajax_permission()
     * calls Capabilities::current_user_can_manage(), and other tests in the suite
     * leave a Mockery alias for Utils loaded.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
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
        // delete_short_url should NOT be called since no existing
        $this->service->shouldNotReceive( 'delete_short_url' );
        $this->service->shouldReceive( 'create_short_url' )->once()->andReturn( [
            'success' => true,
            'data'    => [ 'id' => 30, 'short_code' => 'fresh1' ],
        ] );
        $this->service->shouldReceive( 'get_short_url' )->with( 'fresh1' )->andReturn( 'https://example.com/go/fresh1' );

        Functions\when( 'wp_send_json_success' )->alias( function () {
            throw new \RuntimeException( 'json_success' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->meta_box->ajax_regenerate();
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

    public function test_init_registers_hooks(): void {
        $hooks = array();
        Functions\when( 'add_action' )->alias(
            static function ( $hook ) use ( &$hooks ) {
                $hooks[] = $hook;
            }
        );

        $this->meta_box->init();

        $this->assertContains( 'add_meta_boxes', $hooks );
        $this->assertContains( 'save_post', $hooks );
        $this->assertContains( 'admin_enqueue_scripts', $hooks );
        $this->assertContains( 'wp_ajax_ffc_regenerate_short_url', $hooks );
    }

    // ==================================================================
    // render()
    // ==================================================================

    public function test_render_unpublished_no_record_shows_publish_hint(): void {
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\esc_html__' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\wp_nonce_field' )->justReturn( '' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );

        $post = $this->make_post( 3, 'draft', 'post' );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByPostId' )->with( 3 )->andReturn( null );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );

        // Auto-create must NOT run for a draft.
        $this->service->shouldNotReceive( 'create_short_url' );

        ob_start();
        $this->meta_box->render( $post );
        $html = ob_get_clean();

        $this->assertStringContainsString( 'Publish the post', $html );
    }

    public function test_render_published_autocreate_fails_shows_error(): void {
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\esc_html__' )->returnArg();

        $post = $this->make_post( 4, 'publish', 'post' );
        $post->post_title = 'Title';

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByPostId' )->with( 4 )->andReturn( null );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        // setUp stubs the namespaced get_permalink to '.../my-post'.
        $this->service->shouldReceive( 'create_short_url' )
            ->with( 'https://example.com/my-post', 'Title', 4 )
            ->andReturn( array( 'success' => false ) );

        ob_start();
        $this->meta_box->render( $post );
        $html = ob_get_clean();

        $this->assertStringContainsString( 'Could not generate', $html );
    }

    /**
     * Full render path with an existing record and a cached QR code.
     *
     * Overloads UrlShortenerRepository so the QR handler's cache lookup
     * (new UrlShortenerRepository()->findQrCacheByShortCode()) returns a
     * cached base64 string, short-circuiting the CPU-heavy generator.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_with_record_outputs_qr_and_buttons(): void {
        foreach ( array( '', 'FreeFormCertificate\UrlShortener\\' ) as $ns ) {
            Functions\when( $ns . 'esc_html__' )->returnArg();
            Functions\when( $ns . 'esc_html_e' )->returnArg();
            Functions\when( $ns . 'esc_html' )->returnArg();
            Functions\when( $ns . 'esc_attr' )->returnArg();
            Functions\when( $ns . 'esc_attr_e' )->returnArg();
            Functions\when( $ns . 'number_format_i18n' )->returnArg();
            Functions\when( $ns . 'wp_nonce_field' )->justReturn( '' );
        }

        $post = $this->make_post( 6, 'publish', 'post' );

        // Overload the repository so both the meta-box's findByPostId lookup
        // and the QR handler's internal `new UrlShortenerRepository()` cache
        // lookup resolve to the same stubbed class (the QR cache hit
        // short-circuits the CPU-heavy generator).
        $overload = Mockery::mock( 'overload:FreeFormCertificate\UrlShortener\UrlShortenerRepository' );
        $overload->shouldReceive( 'findByPostId' )->with( 6 )->andReturn(
            array(
                'id'          => 60,
                'short_code'  => 'code60',
                'click_count' => 12,
            )
        );
        $overload->shouldReceive( 'findQrCacheByShortCode' )->andReturn( 'CACHEDBASE64' );

        $this->service->shouldReceive( 'get_repository' )->andReturn( $overload );
        $this->service->shouldReceive( 'get_short_url' )->with( 'code60' )->andReturn( 'https://example.com/go/code60' );

        ob_start();
        $this->meta_box->render( $post );
        $html = ob_get_clean();

        $this->assertStringContainsString( 'CACHEDBASE64', $html );
        $this->assertStringContainsString( 'data-format="png"', $html );
        $this->assertStringContainsString( 'data-format="svg"', $html );
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
    // on_save_post() — autosave guard (MUST be last: define() is permanent)
    // ==================================================================

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
        Functions\when( 'FreeFormCertificate\UrlShortener\get_post_type' )->justReturn( 'post' );

        $this->meta_box->enqueue_assets( 'index.php' );
        $this->assertTrue( true );
    }

    public function test_enqueue_assets_skips_when_post_type_not_enabled(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_post_type' )->justReturn( 'attachment' );
        $this->service->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'page' ) );

        $this->meta_box->enqueue_assets( 'post.php' );
        $this->assertTrue( true );
    }

    public function test_enqueue_assets_enqueues_on_enabled_post_edit(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_post_type' )->justReturn( 'page' );
        $this->service->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'page' ) );

        $enqueued = array();
        Functions\when( 'FreeFormCertificate\UrlShortener\wp_enqueue_style' )->alias(
            static function ( $h ) use ( &$enqueued ) {
                $enqueued[] = $h;
            }
        );
        Functions\when( 'FreeFormCertificate\UrlShortener\wp_enqueue_script' )->alias(
            static function ( $h ) use ( &$enqueued ) {
                $enqueued[] = $h;
            }
        );
        Functions\when( 'FreeFormCertificate\UrlShortener\wp_localize_script' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\UrlShortener\admin_url' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\wp_create_nonce' )->justReturn( 'nonce' );

        $this->meta_box->enqueue_assets( 'post-new.php' );

        $this->assertContains( 'ffc-url-shortener-admin', $enqueued );
    }
}
