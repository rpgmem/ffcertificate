<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\FormSubmissionCountWpdb;
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
		FormSubmissionCountWpdb::install();
		Monkey\setUp();
		// The certificate renderers take the site's Font Library fonts (#2569):
		// a site with none, read the same way whatever ran before.
		Functions\when( 'post_type_exists' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->returnArg();
		\FreeFormCertificate\Core\CertificateFonts::reset();
		// A copy of an earlier default is offered the current one (#2577).
		Functions\when( 'wp_kses' )->returnArg();
		\FreeFormCertificate\Admin\DefaultLayoutVersions::reset();
		// SettingsReader reads the required certificate tags (#1734).
		Functions\when( 'get_option' )->justReturn( array() );
		// The colour field is normalised on render; null falls back to white.
		Functions\when( 'sanitize_hex_color' )->justReturn( null );
		Functions\when( 'admin_url' )->returnArg();
		// The QR level note measures a link of the real length (#2664).
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://certificados.example.test' . $path );

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

		$this->metabox = new FormEditorLayoutMetabox();
	}

	protected function tearDown(): void {
		FormSubmissionCountWpdb::restore();
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

	public function test_render_warns_how_many_certificates_a_layout_change_reaches(): void {
		FormSubmissionCountWpdb::restore();
		FormSubmissionCountWpdb::install( 12 );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'wp_kses_post' )->returnArg();

		$html = $this->render( array( 'pdf_layout' => '<p>x</p>' ) );

		$this->assertStringContainsString( 'This form has 12 issued certificates; layout changes apply to them the next time they are generated or verified.', $html );
	}

	public function test_render_has_no_issued_warning_without_submissions(): void {
		$html = $this->render( array( 'pdf_layout' => '<p>x</p>' ) );

		$this->assertStringNotContainsString( 'ffc-layout-issued-notice', $html );
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

		// The stored '1' names no template the form can reference (#2581).
		Functions\when( 'get_post' )->justReturn( null );
		$this->stub_template_panel();

		$html = $this->render_for_post( 11 );

		$this->assertStringContainsString( 'id="ffc_load_template_btn"', $html );
		$this->assertStringNotContainsString( 'ffc_template_select', $html );
		$this->assertSame( 4, substr_count( $html, '<select' ), 'the template picker of #2581 and the three signer positions of #2620' );
		$this->assertStringContainsString( 'id="ffc_layout_template_id"', $html );
	}

	private function stub_template_panel(): void {
		Functions\when( 'selected' )->alias(
			static function ( $a, $b ) {
				echo (string) $a === (string) $b ? ' selected="selected"' : '';
			}
		);
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'get_edit_post_link' )->justReturn( 'post.php?post=50&action=edit' );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
	}

	public function test_a_form_that_references_a_template_shows_its_panel_and_fields(): void {
		$this->stub_template_panel();
		Functions\when( 'current_user_can' )->justReturn( true );
		$template              = new \WP_Post();
		$template->ID          = 50;
		$template->post_title  = 'Declaration';
		$template->post_type   = 'ffc_cert_template';
		$template->post_status = 'publish';
		Functions\when( 'get_post' )->justReturn( $template );
		Functions\when( 'get_posts' )->alias( static fn( $args ) => isset( $args['meta_key'] ) && '_ffc_layout_template_id' === $args['meta_key'] ? array( 11, 12 ) : array( $template ) );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				$meta = array(
					'_ffc_form_config'        => array( 'pdf_layout' => '<p>own</p>' ),
					'_ffc_layout_template_id' => '50',
					'_ffc_template_values'    => array( 'local' => 'Sala 2' ),
					'_ffc_template_fields'    => array( array( 'key' => 'local', 'label' => 'Place', 'required' => '1' ) ),
					'_ffc_template_visible'   => '1',
				);
				return $meta[ $key ] ?? '';
			}
		);

		$html = $this->render_for_post( 11 );

		$this->assertMatchesRegularExpression( '/<tr class="ffc-layout-own" hidden>/', $html, 'the own editor is hidden' );
		$this->assertStringContainsString( 'name="ffc_layout_template_id"', $html );
		$this->assertStringContainsString( '<option value="50"  selected="selected">Declaration</option>', $html );
		$this->assertStringContainsString( 'data-template-id="50">', $html, 'the chosen panel shows' );
		$this->assertStringContainsString( 'Used by 2 forms.', $html );
		$this->assertStringContainsString( 'name="ffc_template_values[50][local]"', $html );
		$this->assertStringContainsString( 'value="Sala 2"', $html );
		$this->assertStringContainsString( '{{model:local}}', $html );
	}

	public function test_load_button_is_omitted_when_the_pool_is_empty(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( array() );

		$html = $this->render_for_post( 11 );

		$this->assertStringNotContainsString( 'id="ffc_load_template_btn"', $html );
		$this->assertStringContainsString( 'id="ffc_btn_preview"', $html, 'the other actions still render' );
	}

	public function test_a_layout_that_is_an_earlier_default_body_is_offered_the_current_one(): void {
		Functions\when( 'get_posts' )->alias( static fn( $args ) => isset( $args['meta_key'] ) && '_ffc_default_slug' === $args['meta_key'] ? array( 7 ) : array() );
		Functions\when( 'get_the_title' )->justReturn( 'Certificate model 4' );
		$body = (string) file_get_contents( FFC_PLUGIN_DIR . 'templates/certificate-defaults/previous/default_certificate_4-6.44.1.html' );

		$html = $this->render( array( 'pdf_layout' => $body ) );

		$this->assertStringContainsString( 'id="ffc_update_default_layout_btn"', $html );
		$this->assertStringContainsString( 'data-template-id="7"', $html );
		$this->assertStringNotContainsString( 'ffc_update_default_layout_btn', $this->render( array( 'pdf_layout' => '<p>own layout</p>' ) ) );
	}

	public function test_the_qr_mode_row_offers_three_modes_and_keeps_the_saved_one(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );

		$html = $this->render( array( 'qr_mode' => 'full', 'pdf_layout' => '<p>{{qr_code:size=96:margin=2}}</p>' ) );

		$this->assertStringContainsString( 'name="ffc_config[qr_mode]"', $html );
		foreach ( array( 'link', 'compact', 'full' ) as $mode ) {
			$this->assertStringContainsString( 'value="' . $mode . '"', $html );
		}
		$this->assertMatchesRegularExpression( '/value="full"[^>]*checked/', $html );
		$this->assertStringNotContainsString( 'prints the QR code at', $html, 'A 96 px QR fits both signed modes.' );
	}

	public function test_a_small_qr_a_missing_key_and_a_logo_are_said_on_the_signed_modes(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'get_option' )->alias( static fn( $name, $default = false ) => 'ffc_settings' === $name ? array( 'qr_design_logo_id' => 9 ) : $default );

		$html = $this->render( array( 'qr_mode' => 'compact', 'pdf_layout' => '<p>{{qr_code:size=50:margin=0}}</p>' ) );

		$this->assertStringContainsString( 'The layout prints the QR code at 50 px; this mode needs at least', $html );
		$this->assertStringContainsString( 'No signing key exists yet', $html );
		$this->assertStringContainsString( 'The global QR design has a logo', $html );
	}

	public function test_a_link_that_leaves_room_is_said_to_print_above_the_level_asked_for(): void {
		// #2664: the short verification link fits a higher level at the same size.
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );

		$html = $this->render( array( 'qr_mode' => 'link', 'pdf_layout' => '<p>{{qr_code:size=200:margin=2:error=L}}</p>' ) );

		// Measured: this 79-byte link keeps its grid at M, and Q needs a larger one.
		$this->assertStringContainsString( 'printed at error correction M, above the L asked for', $html );
		$this->assertStringNotContainsString( 'below the', $html );
	}

	public function test_a_link_too_long_for_the_level_asked_for_is_said_to_print_below_it(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test/' . str_repeat( 'x', 1400 ) . $path );

		$html = $this->render( array( 'qr_mode' => 'link', 'pdf_layout' => '<p>{{qr_code:size=200:margin=2:error=H}}</p>' ) );

		$this->assertStringContainsString( 'printed at error correction Q, below the H asked for: the link does not fit at H.', $html );
	}

	public function test_a_logo_the_link_cannot_carry_is_said_to_be_left_out(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test/' . str_repeat( 'x', 1400 ) . $path );
		Functions\when( 'get_option' )->alias( static fn( $name, $default = false ) => 'ffc_settings' === $name ? array( 'qr_design_on_certificate' => '1', 'qr_design_logo_id' => 9 ) : $default );

		$html = $this->render( array( 'qr_mode' => 'link', 'pdf_layout' => '<p>{{qr_code:size=200:margin=2:error=M}}</p>' ) );

		$this->assertStringContainsString( 'The logo is left out: the link does not fit at error correction H', $html );
	}

	public function test_a_link_no_level_holds_is_said_to_print_as_text(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test/' . str_repeat( 'x', 4000 ) . $path );

		$html = $this->render( array( 'qr_mode' => 'link', 'pdf_layout' => '<p>{{qr_code:size=200}}</p>' ) );

		$this->assertStringContainsString( 'The link does not fit in a QR code at any error correction', $html );
	}

	public function test_a_layout_with_no_qr_says_the_signature_has_nowhere_to_go(): void {
		$html = $this->render( array( 'qr_mode' => 'full', 'pdf_layout' => '<p>No code here</p>' ) );

		$this->assertStringContainsString( 'The layout prints no QR code', $html );
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
