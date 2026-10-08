<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabQrCode;

/**
 * The QR Code settings tab (#1563): properties, the real view, and the assets.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabQrCode
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabQrCodeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var TabQrCode */
	private $tab;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Pre-stub wp_kses_post BEFORE the SettingsTab base autoloads — its
		// file-level guard require_once's wp-includes/formatting.php otherwise.
		Functions\when( 'wp_kses_post' )->returnArg();
		class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabQrCode' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $t ) { echo $t; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		} );
		Functions\when( 'wp_unslash' )->returnArg();

		$this->tab = new TabQrCode();
	}

	protected function tearDown(): void {
		unset( $_GET['tab'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_tab_properties(): void {
		$this->assertSame( 'qr_code', $this->tab->get_id() );
		$this->assertSame( 'QR Code', $this->tab->get_title() );
		$this->assertSame( 'content', $this->tab->get_group() );
	}

	public function test_render_moves_the_defaults_and_shows_the_saved_design(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'qr_default_size'          => 250,
				'qr_design_dots'           => 'fluid',
				'qr_design_color'          => '#123456',
				'qr_design_on_certificate' => 1,
				'qr_design_logo_id'        => 12,
				'qr_design_frame'          => 'bubble',
			)
		);
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/logo-150x150.png' );
		Functions\when( 'get_post_mime_type' )->justReturn( '' );
		Functions\when( 'get_attached_file' )->justReturn( '' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		Functions\when( 'selected' )->alias(
			static function ( $a, $b ) {
				echo $a === $b ? ' selected="selected"' : '';
			}
		);
		Functions\when( 'checked' )->alias(
			static function ( $a, $b = true ) {
				echo (bool) $a === (bool) $b ? ' checked="checked"' : '';
			}
		);

		ob_start();
		$this->tab->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="_ffc_tab" value="qr_code"', $html );
		$this->assertStringContainsString( 'name="ffc_settings[qr_default_size]" id="qr_default_size" value="250"', $html );
		$this->assertStringContainsString( 'data-ffc-autosave-key="qr_default_error_level"', $html );
		// Shapes are tile pickers now (#1570): a checked radio per value.
		$this->assertStringContainsString( 'name="ffc_settings[qr_design_dots]" value="fluid"  checked="checked"', $html );
		$this->assertStringContainsString( '<details class="ffc-section"', $html );
		$this->assertStringContainsString( 'class="ffc-qr-swatch"', $html );
		$this->assertStringContainsString( 'data-ffc-qr-hex-for="qr_design_color"', $html );
		$this->assertStringContainsString( 'id="qr_design_color" value="#123456"', $html );
		$this->assertStringContainsString( 'id="ffc-qr-design-preview"', $html );
		$this->assertStringContainsString( 'id="qr_design_logo_id" value="12"', $html );
		$this->assertStringContainsString( 'src="https://example.com/logo-150x150.png"', $html );
		$this->assertStringContainsString( 'name="ffc_settings[qr_design_frame]" value="bubble"  checked="checked"', $html );
		// Both switches are named fields in the form AND autosave keys.
		$this->assertStringContainsString( 'name="ffc_settings[qr_design_on_certificate]"', $html );
		$this->assertStringContainsString( 'data-ffc-autosave-key="qr_design_on_short_urls"', $html );
	}

	public function test_render_error_when_view_missing(): void {
		Functions\when( 'FreeFormCertificate\\Settings\\Tabs\\file_exists' )->justReturn( false );
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message ) {
				echo '<div class="notice notice-error">' . $message . '</div>';
			}
		);

		ob_start();
		$this->tab->render();
		$this->assertStringContainsString( 'not found', (string) ob_get_clean() );
	}

	public function test_enqueue_scripts_skips_other_tabs(): void {
		$_GET['tab'] = 'general';
		Functions\expect( 'wp_enqueue_script' )->never();

		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );
	}

	public function test_enqueue_scripts_localises_the_preview_endpoint(): void {
		$_GET['tab'] = 'qr_code';

		$utils = Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' );
		$utils->shouldReceive( 'asset_suffix' )->andReturn( '.min' );
		$utils->shouldReceive( 'enqueue_common_style' )->once();
		$utils->shouldReceive( 'enqueue_dark_mode' )->once();

		$styles = array();
		Functions\when( 'wp_enqueue_style' )->alias( function ( $h, $src, $deps ) use ( &$styles ) {
			$styles[ $h ] = $deps;
		} );
		$handles   = array();
		$localized = array();
		Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$handles ) {
			$handles[] = $h;
		} );
		Functions\when( 'wp_localize_script' )->alias( function ( $h, $name, $data ) use ( &$localized ) {
			$localized[ $name ] = $data;
			return true;
		} );
		Functions\when( 'wp_create_nonce' )->alias( static fn( $action ) => 'nonce-' . $action );
		Functions\when( 'admin_url' )->returnArg();

		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );

		$this->assertContains( 'ffc-admin-autosave', $handles );
		$this->assertContains( 'ffc-qr-design', $handles );
		// The shared design sections have their own sheet, on the palette (#1570).
		$this->assertSame( array( 'ffc-common' ), $styles['ffc-qr-design-fields'] ?? null );
		$this->assertSame( 'ffc_qr_design_preview', $localized['ffcQrDesign']['action'] );
		$this->assertSame( 'nonce-ffc_qr_design_preview', $localized['ffcQrDesign']['nonce'] );
	}
}
