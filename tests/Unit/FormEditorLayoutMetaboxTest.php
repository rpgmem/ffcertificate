<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorLayoutMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorLayoutMetabox
 */
class FormEditorLayoutMetaboxTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private FormEditorLayoutMetabox $metabox;

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
        Functions\when( 'get_post_meta' )->justReturn( '' );
        // The layout dropdown now lists the DB-backed template pool (#865) via
        // CertTemplateReader::list_for_editor() → get_posts(); default empty so
        // the base render tests emit no <select> (the dedicated test overrides).
        Functions\when( 'get_posts' )->justReturn( array() );
        Functions\when( 'wp_nonce_field' )->alias( function ( $action, $name ) { echo "<input name=\"{$name}\" />"; } );

        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', '/tmp/ffc_test/' );
        }

        $this->metabox = new FormEditorLayoutMetabox();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function render( array $config = array() ): string {
        Functions\when( 'get_post_meta' )->justReturn( $config );
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 11;
        ob_start();
        $this->metabox->render( $post );
        return (string) ob_get_clean();
    }

    public function test_render_emits_nonce_field_with_expected_name(): void {
        $html = $this->render();
        $this->assertStringContainsString( 'name="ffc_form_nonce"', $html );
    }

    public function test_render_pre_populates_layout_textarea_from_meta(): void {
        $html = $this->render( array( 'pdf_layout' => '<h1>Hello</h1>' ) );
        $this->assertStringContainsString( '<h1>Hello</h1>', $html );
        $this->assertStringContainsString( 'name="ffc_config[pdf_layout]"', $html );
    }

    public function test_render_pre_populates_bg_image_input_from_meta(): void {
        $html = $this->render( array( 'bg_image' => 'https://example.com/bg.png' ) );
        $this->assertStringContainsString( 'https://example.com/bg.png', $html );
        $this->assertStringContainsString( 'id="ffc_bg_image_input"', $html );
    }

    public function test_render_exposes_action_buttons(): void {
        $html = $this->render();
        $this->assertStringContainsString( 'id="ffc_btn_import_html"', $html );
        $this->assertStringContainsString( 'id="ffc_btn_media_lib"', $html );
        $this->assertStringContainsString( 'id="ffc_btn_preview"', $html );
        $this->assertStringContainsString( 'id="ffc_save_as_model_btn"', $html );
    }

    public function test_render_lists_pool_templates_grouped_by_id(): void {
        // #865: the "Load" dropdown is populated from the DB-backed pool,
        // grouped defaults / user templates, each <option> valued by post id.
        $default_post             = new \WP_Post();
        $default_post->ID         = 10;
        $default_post->post_title = 'Certificate model 1';
        $user_post                = new \WP_Post();
        $user_post->ID            = 20;
        $user_post->post_title    = 'My layout';
        Functions\when( 'get_posts' )->justReturn( array( $default_post, $user_post ) );
        Functions\when( 'get_post_meta' )->alias( static function ( $id, $key ) {
            if ( '_ffc_form_config' === $key ) {
                return array();
            }
            // META_IS_DEFAULT — only post 10 is a shipped default.
            return ( 10 === $id ) ? '1' : '';
        } );

        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 11;
        ob_start();
        $this->metabox->render( $post );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( '<optgroup label="Default templates">', $html );
        $this->assertStringContainsString( '<optgroup label="My templates">', $html );
        $this->assertStringContainsString( '<option value="10">Certificate model 1</option>', $html );
        $this->assertStringContainsString( '<option value="20">My layout</option>', $html );
        $this->assertStringContainsString( 'id="ffc_load_template_btn"', $html );
    }
}
