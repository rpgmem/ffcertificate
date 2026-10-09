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

	public function test_load_button_renders_without_a_template_select_when_the_pool_has_templates(): void {
		// #1625: the Load button opens the modal built from `ffc_ajax.templates`;
		// the hidden <select> that once sat beside it was read by no script.
		$default_post             = new \WP_Post();
		$default_post->ID         = 10;
		$default_post->post_title = 'Certificate model 1';
		Functions\when( 'get_posts' )->justReturn( array( $default_post ) );
		Functions\when( 'get_post_meta' )->alias( static function ( $id, $key ) {
			return '_ffc_form_config' === $key ? array() : '1';
		} );

		$html = $this->render_for_post( 11 );

		$this->assertStringContainsString( 'id="ffc_load_template_btn"', $html );
		$this->assertStringNotContainsString( 'ffc_template_select', $html );
		$this->assertStringNotContainsString( '<select', $html );
	}

	public function test_load_button_is_omitted_when_the_pool_is_empty(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( array() );

		$html = $this->render_for_post( 11 );

		$this->assertStringNotContainsString( 'id="ffc_load_template_btn"', $html );
		$this->assertStringContainsString( 'id="ffc_btn_preview"', $html, 'the other actions still render' );
	}

	/**
	 * Render the metabox for a post id.
	 *
	 * @param int $id Post id.
	 * @return string
	 */
	private function render_for_post( int $id ): string {
		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = $id;
		ob_start();
		$this->metabox->render( $post );
		return (string) ob_get_clean();
	}
}
