<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\Capabilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerAdminPage;
use FreeFormCertificate\UrlShortener\UrlShortenerService;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

/**
 * Tests for UrlShortenerAdminPage: AJAX handlers (create, delete, trash,
 * restore, toggle, empty_trash) and admin action routing.
 *
 * Runs in separate processes because the AJAX handlers call
 * Capabilities::current_user_can_manage() via AjaxTrait::check_ajax_permission(),
 * and other tests in the suite leave a Mockery alias for Utils loaded,
 * which makes the permission check resolve to a null mock instance.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerAdminPage
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UrlShortenerAdminPageTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private UrlShortenerAdminPage $page;

    /** @var UrlShortenerService|Mockery\MockInterface */
    private $service;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub common WP functions
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        // Namespaced stubs for AjaxTrait (FreeFormCertificate\Core namespace)
        Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        // Namespaced stubs for UrlShortener namespace
        Functions\when( 'FreeFormCertificate\UrlShortener\sanitize_text_field' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\sanitize_key' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\wp_unslash' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\esc_url_raw' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'FreeFormCertificate\UrlShortener\__' )->returnArg();

        $this->service = Mockery::mock( UrlShortenerService::class );
        $this->page    = new UrlShortenerAdminPage( $this->service );
    }

    protected function tearDown(): void {
        unset( $_POST['nonce'], $_POST['target_url'], $_POST['title'], $_POST['id'] );
        unset( $_GET['page'], $_GET['ffc_action'], $_GET['_wpnonce'], $_GET['id'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // ajax_create()
    // ==================================================================

    public function test_ajax_create_success(): void {
        $_POST['nonce']      = 'valid';
        $_POST['target_url'] = 'https://example.com/long-page';
        $_POST['title']      = 'My Link';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->service->shouldReceive( 'create_short_url' )->once()->andReturn( [
            'success' => true,
            'data'    => [
                'id'         => 1,
                'short_code' => 'abc123',
                'target_url' => 'https://example.com/long-page',
            ],
        ] );
        $this->service->shouldReceive( 'get_short_url' )->with( 'abc123' )->andReturn( 'https://example.com/go/abc123' );

        $sent_data = null;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$sent_data ) {
            $sent_data = $data;
            throw new \RuntimeException( 'json_success' );
        } );

        try {
            $this->page->ajax_create();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'abc123', $sent_data['short_code'] );
        $this->assertSame( 'https://example.com/go/abc123', $sent_data['short_url'] );
    }

    public function test_ajax_create_empty_url_sends_error(): void {
        $_POST['nonce']      = 'valid';
        $_POST['target_url'] = '';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\UrlShortener\esc_url_raw' )->justReturn( '' );

        $error_sent = false;
        Functions\when( 'wp_send_json_error' )->alias( function () use ( &$error_sent ) {
            $error_sent = true;
            throw new \RuntimeException( 'json_error' );
        } );

        try {
            $this->page->ajax_create();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertTrue( $error_sent );
    }

    public function test_ajax_create_service_failure_sends_error(): void {
        $_POST['nonce']      = 'valid';
        $_POST['target_url'] = 'https://example.com/page';
        $_POST['title']      = '';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->service->shouldReceive( 'create_short_url' )->once()->andReturn( [
            'success' => false,
            'error'   => 'Failed to create short URL.',
        ] );

        $error_msg = '';
        Functions\when( 'wp_send_json_error' )->alias( function ( $data ) use ( &$error_msg ) {
            $error_msg = $data['message'] ?? '';
            throw new \RuntimeException( 'json_error' );
        } );

        try {
            $this->page->ajax_create();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'Failed to create short URL.', $error_msg );
    }

    // ==================================================================
    // ajax_delete()
    // ==================================================================

    public function test_ajax_delete_success(): void {
        $_POST['nonce'] = 'valid';
        $_POST['id']    = '5';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->service->shouldReceive( 'delete_short_url' )->with( 5 )->once();

        Functions\when( 'wp_send_json_success' )->alias( function () {
            throw new \RuntimeException( 'json_success' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_success' );

        $this->page->ajax_delete();
    }

    public function test_ajax_delete_invalid_id_sends_error(): void {
        $_POST['nonce'] = 'valid';
        $_POST['id']    = '0';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $error_sent = false;
        Functions\when( 'wp_send_json_error' )->alias( function () use ( &$error_sent ) {
            $error_sent = true;
            throw new \RuntimeException( 'json_error' );
        } );

        try {
            $this->page->ajax_delete();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertTrue( $error_sent );
    }

    // ==================================================================
    // ajax_trash()
    // ==================================================================

    public function test_ajax_trash_success(): void {
        $_POST['nonce'] = 'valid';
        $_POST['id']    = '3';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->service->shouldReceive( 'trash_short_url' )->with( 3 )->once();

        Functions\when( 'wp_send_json_success' )->alias( function () {
            throw new \RuntimeException( 'json_success' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->page->ajax_trash();
    }

    public function test_ajax_trash_invalid_id_sends_error(): void {
        $_POST['nonce'] = 'valid';
        $_POST['id']    = '-1';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        Functions\when( 'wp_send_json_error' )->alias( function () {
            throw new \RuntimeException( 'json_error' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );

        $this->page->ajax_trash();
    }

    // ==================================================================
    // ajax_restore()
    // ==================================================================

    public function test_ajax_restore_success(): void {
        $_POST['nonce'] = 'valid';
        $_POST['id']    = '7';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->service->shouldReceive( 'restore_short_url' )->with( 7 )->once();

        Functions\when( 'wp_send_json_success' )->alias( function () {
            throw new \RuntimeException( 'json_success' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->page->ajax_restore();
    }

    // ==================================================================
    // ajax_toggle()
    // ==================================================================

    public function test_ajax_toggle_success(): void {
        $_POST['nonce'] = 'valid';
        $_POST['id']    = '4';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->service->shouldReceive( 'toggle_status' )->with( 4 )->once();

        Functions\when( 'wp_send_json_success' )->alias( function () {
            throw new \RuntimeException( 'json_success' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->page->ajax_toggle();
    }

    public function test_ajax_toggle_invalid_id_sends_error(): void {
        $_POST['nonce'] = 'valid';
        // $_POST['id'] not set — defaults to 0

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        Functions\when( 'wp_send_json_error' )->alias( function () {
            throw new \RuntimeException( 'json_error' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );

        $this->page->ajax_toggle();
    }

    // ==================================================================
    // ajax_empty_trash()
    // ==================================================================

    public function test_ajax_empty_trash_deletes_all_trashed_items(): void {
        $_POST['nonce'] = 'valid';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            return array_merge( $defaults, $args );
        } );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findPaginated' )->once()->andReturn( [
            'items' => [
                [ 'id' => '10' ],
                [ 'id' => '11' ],
                [ 'id' => '12' ],
            ],
            'total' => 3,
        ] );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'delete_short_url' )->with( 10 )->once();
        $this->service->shouldReceive( 'delete_short_url' )->with( 11 )->once();
        $this->service->shouldReceive( 'delete_short_url' )->with( 12 )->once();

        Functions\when( 'wp_send_json_success' )->alias( function () {
            throw new \RuntimeException( 'json_success' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->page->ajax_empty_trash();
    }

    public function test_ajax_empty_trash_with_no_items(): void {
        $_POST['nonce'] = 'valid';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            return array_merge( $defaults, $args );
        } );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findPaginated' )->once()->andReturn( [
            'items' => [],
            'total' => 0,
        ] );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        // delete_short_url should NOT be called
        $this->service->shouldNotReceive( 'delete_short_url' );

        Functions\when( 'wp_send_json_success' )->alias( function () {
            throw new \RuntimeException( 'json_success' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->page->ajax_empty_trash();
    }

    // ==================================================================
    // handle_actions() — admin GET-based actions
    // ==================================================================

    public function test_handle_actions_returns_early_without_page(): void {
        $this->service->shouldNotReceive( 'trash_short_url' );
        $this->service->shouldNotReceive( 'restore_short_url' );
        $this->service->shouldNotReceive( 'delete_short_url' );
        $this->service->shouldNotReceive( 'toggle_status' );

        $this->page->handle_actions();
    }

    public function test_handle_actions_returns_early_without_action(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page'] = 'ffc-short-urls';

        $this->service->shouldNotReceive( 'trash_short_url' );
        $this->service->shouldNotReceive( 'restore_short_url' );
        $this->service->shouldNotReceive( 'delete_short_url' );
        $this->service->shouldNotReceive( 'toggle_status' );

        $this->page->handle_actions();
    }

    public function test_handle_actions_read_only_user_cannot_write(): void {
        // GAP B 3-state: a user without ffc_manage_url_shortener (and not admin)
        // reaches the page (view cap) but the GET-link write actions no-op.
        Functions\when( 'current_user_can' )->justReturn( false );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'trash';
        $_GET['id']         = '5';
        $_GET['_wpnonce']   = 'valid_nonce';

        $this->service->shouldNotReceive( 'trash_short_url' );

        $this->page->handle_actions();
    }

    public function test_handle_actions_trash_calls_service(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'trash';
        $_GET['id']         = '5';
        $_GET['_wpnonce']   = 'valid_nonce';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'admin_url' )->returnArg();

        $this->service->shouldReceive( 'trash_short_url' )->with( 5 )->once();
        Functions\when( 'wp_safe_redirect' )->alias( function () {
            throw new \RuntimeException( 'redirected' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->page->handle_actions();
    }

    public function test_handle_actions_toggle_calls_service(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'toggle';
        $_GET['id']         = '8';
        $_GET['_wpnonce']   = 'valid_nonce';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'admin_url' )->returnArg();

        $this->service->shouldReceive( 'toggle_status' )->with( 8 )->once();
        Functions\when( 'wp_safe_redirect' )->alias( function () {
            throw new \RuntimeException( 'redirected' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->page->handle_actions();
    }

    public function test_handle_actions_nonce_failure_dies(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'trash';
        $_GET['id']         = '5';
        $_GET['_wpnonce']   = 'bad_nonce';

        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'wp_die' )->alias( function () {
            throw new \RuntimeException( 'wp_die' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );

        $this->page->handle_actions();
    }

    public function test_handle_actions_restore_calls_service(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'restore';
        $_GET['id']         = '3';
        $_GET['_wpnonce']   = 'valid_nonce';
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'admin_url' )->returnArg();
        $this->service->shouldReceive( 'restore_short_url' )->with( 3 )->once();
        Functions\when( 'wp_safe_redirect' )->alias(
            static function () {
                throw new \RuntimeException( 'redirected' );
            }
        );

        $this->expectException( \RuntimeException::class );
        $this->page->handle_actions();
    }

    public function test_handle_actions_delete_calls_service(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'delete';
        $_GET['id']         = '9';
        $_GET['_wpnonce']   = 'valid_nonce';
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'admin_url' )->returnArg();
        $this->service->shouldReceive( 'delete_short_url' )->with( 9 )->once();
        Functions\when( 'wp_safe_redirect' )->alias(
            static function () {
                throw new \RuntimeException( 'redirected' );
            }
        );

        $this->expectException( \RuntimeException::class );
        $this->page->handle_actions();
    }

    public function test_handle_actions_empty_trash_deletes_all_trashed(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'empty_trash';
        $_GET['_wpnonce']   = 'valid_nonce';
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'admin_url' )->returnArg();

        $repo = Mockery::mock( 'FreeFormCertificate\UrlShortener\UrlShortenerRepository' );
        $repo->shouldReceive( 'findPaginated' )->once()->andReturn(
            array( 'items' => array( array( 'id' => 1 ), array( 'id' => 2 ) ) )
        );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'delete_short_url' )->with( 1 )->once();
        $this->service->shouldReceive( 'delete_short_url' )->with( 2 )->once();
        Functions\when( 'wp_safe_redirect' )->alias(
            static function () {
                throw new \RuntimeException( 'redirected' );
            }
        );

        $this->expectException( \RuntimeException::class );
        $this->page->handle_actions();
    }

    public function test_handle_actions_restore_nonce_failure_dies(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'restore';
        $_GET['id']         = '3';
        $_GET['_wpnonce']   = 'bad';
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'wp_die' )->alias(
            static function () {
                throw new \RuntimeException( 'wp_die' );
            }
        );

        $this->service->shouldNotReceive( 'restore_short_url' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->page->handle_actions();
    }

    public function test_handle_actions_delete_nonce_failure_dies(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'delete';
        $_GET['id']         = '9';
        $_GET['_wpnonce']   = 'bad';
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'wp_die' )->alias(
            static function () {
                throw new \RuntimeException( 'wp_die' );
            }
        );

        $this->service->shouldNotReceive( 'delete_short_url' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->page->handle_actions();
    }

    public function test_handle_actions_empty_trash_nonce_failure_dies(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'empty_trash';
        $_GET['_wpnonce']   = 'bad';
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'wp_die' )->alias(
            static function () {
                throw new \RuntimeException( 'wp_die' );
            }
        );

        $this->service->shouldNotReceive( 'get_repository' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->page->handle_actions();
    }

    public function test_handle_actions_toggle_nonce_failure_dies(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'toggle';
        $_GET['id']         = '8';
        $_GET['_wpnonce']   = 'bad';
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'wp_die' )->alias(
            static function () {
                throw new \RuntimeException( 'wp_die' );
            }
        );

        $this->service->shouldNotReceive( 'toggle_status' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->page->handle_actions();
    }

    // ==================================================================
    // init() / register_menu() / enqueue_assets()
    // ==================================================================

    public function test_init_registers_hooks(): void {
        $actions = array();
        Functions\when( 'add_action' )->alias(
            static function ( $hook ) use ( &$actions ) {
                $actions[] = $hook;
            }
        );

        $this->page->init();

        $this->assertContains( 'admin_menu', $actions );
        $this->assertContains( 'admin_init', $actions );
        $this->assertContains( 'admin_enqueue_scripts', $actions );
        $this->assertContains( 'wp_ajax_ffc_create_short_url', $actions );
        $this->assertContains( 'wp_ajax_ffc_empty_trash_short_urls', $actions );
    }

    public function test_register_menu_adds_top_level_menu(): void {
        $captured = array();
        Functions\when( 'add_menu_page' )->alias(
            static function ( $page_title, $menu_title, $cap, $slug, $cb, $icon ) use ( &$captured ) {
                $captured = compact( 'cap', 'slug', 'icon' );
            }
        );

        $this->page->register_menu();

        $this->assertSame( 'ffc_view_url_shortener', $captured['cap'] );
        $this->assertSame( 'ffc-short-urls', $captured['slug'] );
        $this->assertSame( 'dashicons-admin-links', $captured['icon'] );
    }

    public function test_enqueue_assets_skips_on_wrong_page(): void {
        $_GET['page'] = 'some-other-page';

        Functions\when( 'wp_enqueue_style' )->alias(
            static function () {
                throw new \RuntimeException( 'should_not_enqueue' );
            }
        );

        // No exception thrown means the early return fired.
        $this->page->enqueue_assets( 'anything' );
        $this->assertTrue( true );
    }

    public function test_enqueue_assets_enqueues_on_short_urls_page(): void {
        $_GET['page'] = 'ffc-short-urls';

        $enqueued = array();
        Functions\when( 'wp_enqueue_style' )->alias(
            static function ( $handle ) use ( &$enqueued ) {
                $enqueued[] = $handle;
            }
        );
        Functions\when( 'wp_enqueue_script' )->alias(
            static function ( $handle ) use ( &$enqueued ) {
                $enqueued[] = $handle;
            }
        );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'admin_url' )->returnArg();
        Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );

        $this->page->enqueue_assets( 'ffc_form_page_ffc-short-urls' );

        $this->assertContains( 'ffc-url-shortener-admin', $enqueued );
    }

    // ==================================================================
    // render_page()
    // ==================================================================

    public function test_render_page_includes_template(): void {
        $_GET['paged']   = '1';
        $_GET['orderby'] = 'created_at';
        $_GET['order']   = 'desc';
        $_GET['status']  = 'all';

        Functions\when( 'number_format_i18n' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_html_e' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr_e' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'wp_nonce_url' )->returnArg();
        Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/x' );
        Functions\when( 'admin_url' )->returnArg();
        Functions\when( 'paginate_links' )->justReturn( '' );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findPaginated' )->once()->andReturn(
            array(
                'items' => array(),
                'total' => 0,
            )
        );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'get_stats' )->once()->andReturn(
            array(
                'total_links'   => 0,
                'active_links'  => 0,
                'total_clicks'  => 0,
                'trashed_links' => 0,
            )
        );

        ob_start();
        $this->page->render_page();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'wrap', $html );
    }

    public function test_handle_actions_delete_without_delete_cap_dies(): void {
        // manage cap granted, delete cap denied (manage_options false too).
        Functions\when( 'current_user_can' )->alias(
            static function ( $cap ) {
                return 'ffc_manage_url_shortener' === $cap;
            }
        );
        $_GET['page']       = 'ffc-short-urls';
        $_GET['ffc_action'] = 'delete';
        $_GET['id']         = '9';
        $_GET['_wpnonce']   = 'valid_nonce';
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'wp_die' )->alias(
            static function () {
                throw new \RuntimeException( 'wp_die' );
            }
        );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->page->handle_actions();
    }
}
