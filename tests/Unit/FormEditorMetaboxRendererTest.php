<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorMetaboxRenderer;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorMetaboxRenderer
 */
class FormEditorMetaboxRendererTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private FormEditorMetaboxRenderer $renderer;

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
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'get_option' )->justReturn( array() );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', '/tmp/ffc_test/' );
        }

        $this->renderer = new FormEditorMetaboxRenderer();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // render_shortcode_metabox()
    // ==================================================================

    public function test_render_shortcode_metabox_shows_shortcode(): void {
        $post = Mockery::mock( 'WP_Post' );
        $post->ID = 42;

        ob_start();
        $this->renderer->render_shortcode_metabox( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( '[ffc_form id="42"]', $output );
        $this->assertStringContainsString( 'ffc-shortcode-display', $output );
        $this->assertStringContainsString( '{{field_name}}', $output );
    }

    // ==================================================================
    // render_box_layout()
    // ==================================================================

    public function test_render_box_layout_outputs_html(): void {
        $post = Mockery::mock( 'WP_Post' );
        $post->ID = 10;

        ob_start();
        $this->renderer->render_box_layout( $post );
        $output = ob_get_clean();

        $this->assertNotEmpty( $output );
    }

    // ==================================================================
    // render_box_builder()
    // ==================================================================

    public function test_render_box_builder_outputs_html(): void {
        $post = Mockery::mock( 'WP_Post' );
        $post->ID = 10;
        $post->post_status = 'publish';

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
            if ( $key === '_ffc_form_fields' ) return array();
            return '';
        } );

        ob_start();
        $this->renderer->render_box_builder( $post );
        $output = ob_get_clean();

        $this->assertNotEmpty( $output );
    }

    // ==================================================================
    // render_box_restriction()
    // ==================================================================

    public function test_render_box_restriction_outputs_html(): void {
        $post = Mockery::mock( 'WP_Post' );
        $post->ID = 10;

        ob_start();
        $this->renderer->render_box_restriction( $post );
        $output = ob_get_clean();

        $this->assertNotEmpty( $output );
    }

    // ==================================================================
    // render_field_row()
    // ==================================================================

    public function test_render_field_row_outputs_field_html(): void {
        $field = array(
            'type'    => 'text',
            'name'    => 'test_field',
            'label'   => 'Test Field',
            'required' => true,
        );

        ob_start();
        $this->renderer->render_field_row( 0, $field );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'test_field', $output );
        $this->assertStringContainsString( 'Test Field', $output );
    }
}
