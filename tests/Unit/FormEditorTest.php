<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditor;

/**
 * @covers \FreeFormCertificate\Admin\FormEditor
 */
class FormEditorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, array{type: string, data: mixed}> */
    private array $json_responses = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_meta_box' )->justReturn( true );
        Functions\when( 'remove_meta_box' )->justReturn( true );
        Functions\when( 'wp_enqueue_script' )->justReturn( true );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_file_name' )->returnArg();

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
        if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
            define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
        }
        if ( ! defined( 'FFC_VERSION' ) ) {
            define( 'FFC_VERSION', '4.12.0' );
        }
        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', '/tmp/ffc_test/' );
        }

        $this->json_responses = array();
        $responses = &$this->json_responses;

        Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) use ( &$responses ) {
            $responses[] = array( 'type' => 'success', 'data' => $data );
            throw new \RuntimeException( 'wp_send_json_success' );
        } );
        Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) use ( &$responses ) {
            $responses[] = array( 'type' => 'error', 'data' => $data );
            throw new \RuntimeException( 'wp_send_json_error' );
        } );
    }

    protected function tearDown(): void {
        unset( $_POST['qty'], $_POST['filename'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $editor = new FormEditor();
        $this->assertInstanceOf( FormEditor::class, $editor );
    }

    // ==================================================================
    // enqueue_scripts() — wrong hook
    // ==================================================================

    public function test_enqueue_scripts_returns_early_on_wrong_hook(): void {
        $editor = new FormEditor();
        $editor->enqueue_scripts( 'edit.php' );
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_scripts() — wrong post type
    // ==================================================================

    public function test_enqueue_scripts_returns_early_on_wrong_post_type(): void {
        $screen = (object) array( 'post_type' => 'post' );
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        $editor = new FormEditor();
        $editor->enqueue_scripts( 'post.php' );
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_scripts() — correct context
    // ==================================================================

    public function test_enqueue_scripts_enqueues_on_ffc_form(): void {
        $screen = (object) array( 'post_type' => 'ffc_form' );
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        $enqueued = array();
        Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued ) {
            $enqueued[] = func_get_arg( 0 );
        } );

        $editor = new FormEditor();
        $editor->enqueue_scripts( 'post.php' );

        $this->assertContains( 'ffc-geofence-admin', $enqueued );
    }

    // ==================================================================
    // add_custom_metaboxes()
    // ==================================================================

    public function test_add_custom_metaboxes_registers_boxes(): void {
        $editor = new FormEditor();
        $editor->add_custom_metaboxes();
        $this->assertTrue( true ); // No error means metaboxes registered
    }

    // ==================================================================
    // ajax_generate_random_codes() — no permission
    // ==================================================================

    public function test_ajax_generate_codes_returns_error_without_permission(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );

        $editor = new FormEditor();
        try {
            $editor->ajax_generate_random_codes();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'error', $this->json_responses[0]['type'] );
    }

    // ==================================================================
    // ajax_generate_random_codes() — success
    // ==================================================================

    public function test_ajax_generate_codes_returns_codes(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        $_POST['qty'] = 3;

        $editor = new FormEditor();
        try {
            $editor->ajax_generate_random_codes();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'success', $this->json_responses[0]['type'] );
        $codes = $this->json_responses[0]['data']['codes'];
        // 3 codes with XXXX-XXXX format separated by newlines
        $this->assertSame( 2, substr_count( $codes, "\n" ) );
    }

    // ==================================================================
    // ajax_load_template() — no permission
    // ==================================================================

    public function test_ajax_load_template_returns_error_without_permission(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );

        $editor = new FormEditor();
        try {
            $editor->ajax_load_template();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'error', $this->json_responses[0]['type'] );
    }

    // ==================================================================
    // ajax_load_template() — empty filename
    // ==================================================================

    public function test_ajax_load_template_returns_error_for_empty_filename(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        $_POST['filename'] = '';

        $editor = new FormEditor();
        try {
            $editor->ajax_load_template();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'error', $this->json_responses[0]['type'] );
    }

    // ==================================================================
    // ajax_load_template() — file not found
    // ==================================================================

    public function test_ajax_load_template_returns_error_for_missing_file(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        $_POST['filename'] = 'nonexistent.html';

        $editor = new FormEditor();
        try {
            $editor->ajax_load_template();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'error', $this->json_responses[0]['type'] );
    }

    // ==================================================================
    // ajax_load_template() — success
    // ==================================================================

    public function test_ajax_load_template_returns_content(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        $dir = FFC_PLUGIN_DIR . 'html';
        @mkdir( $dir, 0777, true );
        file_put_contents( $dir . '/test_tpl.html', '<div>Template</div>' );
        $_POST['filename'] = 'test_tpl.html';

        $editor = new FormEditor();
        try {
            $editor->ajax_load_template();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'success', $this->json_responses[0]['type'] );
        $this->assertSame( '<div>Template</div>', $this->json_responses[0]['data'] );

        @unlink( $dir . '/test_tpl.html' );
    }
}
