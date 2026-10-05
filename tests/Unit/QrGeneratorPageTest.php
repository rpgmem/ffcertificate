<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\QrGeneratorPage;

/**
 * The manual generator's page (#1563): a submenu gated on the manage tier,
 * assets on its own screen only, and the real template.
 *
 * @covers \FreeFormCertificate\UrlShortener\QrGeneratorPage
 */
class QrGeneratorPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_submenu_hangs_from_short_urls_on_the_manage_tier(): void {
		Functions\expect( 'add_submenu_page' )->once()
			->with( 'ffc-short-urls', 'QR Code Generator', 'QR Code Generator', 'ffc_manage_url_shortener', 'ffc-qr-generator', \Mockery::type( 'array' ) )
			->andReturn( 'short-urls_page_ffc-qr-generator' );

		( new QrGeneratorPage() )->register_menu();
	}

	public function test_assets_load_on_its_screen_only(): void {
		Functions\when( 'add_submenu_page' )->justReturn( 'short-urls_page_ffc-qr-generator' );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->alias( static fn( $a ) => 'nonce-' . $a );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_media' )->justReturn( null );
		Functions\when( 'wp_add_inline_script' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );

		$scripts   = array();
		$localized = array();
		Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$scripts ) {
			$scripts[] = $h;
		} );
		Functions\when( 'wp_localize_script' )->alias( function ( $h, $name, $data ) use ( &$localized ) {
			$localized[ $name ] = $data;
			return true;
		} );

		$page = new QrGeneratorPage();
		$page->enqueue_assets( 'short-urls_page_ffc-qr-generator' );
		$this->assertSame( array(), $scripts, 'Before the menu is registered the hook is unknown.' );

		$page->register_menu();
		$page->enqueue_assets( 'toplevel_page_ffc-short-urls' );
		$this->assertSame( array(), $scripts );

		$page->enqueue_assets( 'short-urls_page_ffc-qr-generator' );
		$this->assertContains( 'ffc-qr-generator', $scripts );
		$this->assertContains( 'ffc-qr-raster', $scripts );
		$this->assertContains( 'ffc-qr-design', $scripts );
		$this->assertSame( 'nonce-ffc_qr_generate', $localized['ffcQrGenerator']['generateNonce'] );
		$this->assertSame( 'nonce-ffc_qr_shorten', $localized['ffcQrGenerator']['shortenNonce'] );
	}

	public function test_render_refuses_without_the_manage_tier(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\expect( 'wp_die' )->once()->andThrow( new \RuntimeException( 'died' ) );

		$this->expectExceptionMessage( 'died' );
		( new QrGeneratorPage() )->render_page();
	}

	public function test_render_prints_the_types_and_the_shared_design_fields(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		foreach ( array( 'esc_html', 'esc_attr', 'esc_url', 'esc_html__', 'esc_attr__' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) {
			echo $t;
		} );
		Functions\when( 'esc_attr_e' )->alias( static function ( $t ) {
			echo $t;
		} );
		Functions\when( 'checked' )->alias( static function ( $a, $b = true ) {
			echo $a === $b ? ' checked="checked"' : '';
		} );
		Functions\when( 'selected' )->alias( static function ( $a, $b ) {
			echo $a === $b ? ' selected="selected"' : '';
		} );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );

		ob_start();
		( new QrGeneratorPage() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="wrap ffc-admin-page ffc-page-qr-generator"', $html );
		foreach ( array( 'url', 'text', 'wifi', 'email', 'phone', 'sms', 'whatsapp' ) as $type ) {
			$this->assertStringContainsString( 'data-ffc-qr-type="' . $type . '"', $html, $type );
		}
		$this->assertStringContainsString( 'value="url"  checked="checked"', $html );
		// The design rows come from the shared partial, named for this form.
		$this->assertStringContainsString( 'name="design[qr_design_dots]"', $html );
		$this->assertStringContainsString( 'data-ffc-qr-design="qr_design_frame"', $html );
		$this->assertStringContainsString( 'id="ffc-qr-download-png"', $html );
	}
}
