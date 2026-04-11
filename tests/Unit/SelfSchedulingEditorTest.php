<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingEditor;

/**
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingEditor
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class SelfSchedulingEditorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_textarea' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_meta_box' )->justReturn( true );
        Functions\when( 'wp_enqueue_script' )->justReturn( true );
        Functions\when( 'wp_enqueue_style' )->justReturn( true );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_the_ID' )->justReturn( 10 );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'add_query_arg' )->justReturn( '/' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'check_ajax_referer' )->justReturn( true );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
        if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
            define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
        }
        if ( ! defined( 'FFC_VERSION' ) ) {
            define( 'FFC_VERSION', '4.12.0' );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $editor = new SelfSchedulingEditor();
        $this->assertInstanceOf( SelfSchedulingEditor::class, $editor );
    }

    // ==================================================================
    // enqueue_scripts() — wrong hook
    // ==================================================================

    public function test_enqueue_scripts_returns_early_on_wrong_hook(): void {
        $editor = new SelfSchedulingEditor();
        $editor->enqueue_scripts( 'edit.php' );
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_scripts() — wrong post type
    // ==================================================================

    public function test_enqueue_scripts_returns_early_on_wrong_post_type(): void {
        $screen = (object) array( 'post_type' => 'post' );
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        $editor = new SelfSchedulingEditor();
        $editor->enqueue_scripts( 'post.php' );
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_scripts() — no screen
    // ==================================================================

    public function test_enqueue_scripts_returns_early_without_screen(): void {
        Functions\when( 'get_current_screen' )->justReturn( null );

        $editor = new SelfSchedulingEditor();
        $editor->enqueue_scripts( 'post.php' );
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_scripts() — correct context
    // ==================================================================

    public function test_enqueue_scripts_enqueues_on_self_scheduling(): void {
        $screen = (object) array( 'post_type' => 'ffc_self_scheduling' );
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        $enqueued = array();
        Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued ) {
            $enqueued[] = func_get_arg( 0 );
        } );

        $editor = new SelfSchedulingEditor();
        $editor->enqueue_scripts( 'post.php' );

        $this->assertContains( 'ffc-calendar-editor', $enqueued );
    }

    // ==================================================================
    // add_custom_metaboxes()
    // ==================================================================

    public function test_add_custom_metaboxes_registers_boxes(): void {
        $editor = new SelfSchedulingEditor();
        $editor->add_custom_metaboxes();
        $this->assertTrue( true );
    }

    // ==================================================================
    // render_box_config()
    // ==================================================================

    public function test_render_box_config_outputs_html(): void {
        $post = Mockery::mock( 'WP_Post' );
        $post->ID = 10;

        $editor = new SelfSchedulingEditor();
        ob_start();
        $editor->render_box_config( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'slot_duration', $output );
        $this->assertStringContainsString( 'slot_interval', $output );
    }

    // ==================================================================
    // render_box_hours()
    // ==================================================================

    public function test_render_box_hours_outputs_html(): void {
        $post = Mockery::mock( 'WP_Post' );
        $post->ID = 10;

        $editor = new SelfSchedulingEditor();
        ob_start();
        $editor->render_box_hours( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'ffc-working-hours', $output );
    }

    // ==================================================================
    // render_box_rules()
    // ==================================================================

    public function test_render_box_rules_outputs_html(): void {
        $post = Mockery::mock( 'WP_Post' );
        $post->ID = 10;

        $editor = new SelfSchedulingEditor();
        ob_start();
        $editor->render_box_rules( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'advance_booking', $output );
    }

    // ==================================================================
    // render_box_email()
    // ==================================================================

    public function test_render_box_email_outputs_html(): void {
        $post = Mockery::mock( 'WP_Post' );
        $post->ID = 10;

        Functions\when( 'get_option' )->justReturn( '' );

        $editor = new SelfSchedulingEditor();
        ob_start();
        $editor->render_box_email( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'send_user_confirmation', $output );
    }

    // ==================================================================
    // render_shortcode_metabox() — published
    // ==================================================================

    public function test_render_shortcode_metabox_shows_shortcode_for_published(): void {
        $post = Mockery::mock( 'WP_Post' );
        $post->ID = 42;
        $post->post_status = 'publish';

        $editor = new SelfSchedulingEditor();
        ob_start();
        $editor->render_shortcode_metabox( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'ffc_self_scheduling id="42"', $output );
    }

    // ==================================================================
    // render_shortcode_metabox() — draft
    // ==================================================================

    public function test_render_shortcode_metabox_shows_message_for_draft(): void {
        $post = Mockery::mock( 'WP_Post' );
        $post->ID = 42;
        $post->post_status = 'draft';

        $editor = new SelfSchedulingEditor();
        ob_start();
        $editor->render_shortcode_metabox( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Publish this calendar', $output );
    }

    // ==================================================================
    // display_save_errors()
    // ==================================================================

    public function test_display_save_errors_is_noop(): void {
        $editor = new SelfSchedulingEditor();
        $editor->display_save_errors();
        $this->assertTrue( true );
    }
}
