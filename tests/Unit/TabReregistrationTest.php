<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabReregistration;
use FreeFormCertificate\Admin\CertTemplateFichaResolver;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * @covers \FreeFormCertificate\Settings\Tabs\TabReregistration
 */
class TabReregistrationTest extends TestCase {

	private TabReregistration $tab;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabReregistration' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );

		$this->tab = new TabReregistration();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_tab_identity(): void {
		$this->assertSame( 'reregistration', $this->tab->get_id() );
		$this->assertSame( 'Reregistration', $this->tab->get_title() );
	}

	public function test_gated_by_reregistration_caps(): void {
		$this->assertSame( 'ffc_view_reregistration', $this->tab->get_view_cap() );
		$this->assertSame( 'ffc_manage_reregistration', $this->tab->get_manage_cap() );
	}

	public function test_extends_settings_tab(): void {
		$this->assertInstanceOf( \FreeFormCertificate\Settings\SettingsTab::class, $this->tab );
	}

	public function test_render_shows_the_selector_and_hub_links(): void {
		Functions\when( 'get_option' )->justReturn( 0 );        // nothing selected.
		Functions\when( 'get_posts' )->justReturn( array() );   // no ficha templates yet.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );

		ob_start();
		$this->tab->render();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'name="ffc_ficha_template"', $out );
		$this->assertStringContainsString( 'ffc_kind=' . CertTemplateCpt::KIND_FICHA, $out );
		$this->assertStringContainsString( 'post-new.php?post_type=' . CertTemplateCpt::POST_TYPE, $out );
	}

	public function test_handle_save_persists_a_valid_ficha_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( CertTemplateCpt::KIND_FICHA );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new \RuntimeException( 'redirected' );
			}
		);
		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved = array( $key, $value );
				return true;
			}
		);

		$_POST['ffc_ficha_template'] = '42';
		try {
			$this->tab->handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( CertTemplateFichaResolver::OPTION, $saved[0] );
		$this->assertSame( 42, $saved[1] );

		unset( $_POST['ffc_ficha_template'] );
	}

	public function test_handle_save_drops_an_id_of_the_wrong_kind(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( CertTemplateCpt::KIND_CERTIFICATE );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new \RuntimeException( 'redirected' );
			}
		);
		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved = array( $key, $value );
				return true;
			}
		);

		$_POST['ffc_ficha_template'] = '42';
		try {
			$this->tab->handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( 0, $saved[1] );

		unset( $_POST['ffc_ficha_template'] );
	}
}
