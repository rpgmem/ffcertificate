<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Settings\Tabs\TabCaptcha;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TabCaptcha: the captcha settings tab (#1053).
 *
 * Runs in separate processes for the AssetHelper alias mock, like the other
 * settings-tab tests.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabCaptcha
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabCaptchaTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var TabCaptcha */
	private $tab;

	/**
	 * Hooks registered while the tab was built.
	 *
	 * @var array<int, string>
	 */
	private array $hooks = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Pre-stub wp_kses_post before the SettingsTab base autoloads (its file
		// guard otherwise require_once's WordPress's formatting.php).
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_action' )->alias(
			function ( $hook ) {
				$this->hooks[] = $hook;
				return true;
			}
		);

		$this->tab = new TabCaptcha();
	}

	protected function tearDown(): void {
		unset( $_GET['tab'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_tab_sits_in_the_security_group_with_its_own_icon(): void {
		$this->assertSame( 'captcha', $this->tab->get_id() );
		$this->assertSame( 'Captcha', $this->tab->get_title() );
		$this->assertSame( 'ffc-icon-robot', $this->tab->get_icon() );
		$this->assertSame( 35, $this->tab->get_order() );
		$this->assertContains( 'admin_enqueue_scripts', $this->hooks );
	}

	public function test_the_script_stays_off_other_screens_and_tabs(): void {
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$enqueued ) {
				$enqueued[] = $handle;
			}
		);

		$this->tab->enqueue_scripts( 'edit.php' );
		$_GET['tab'] = 'general';
		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );

		$this->assertSame( array(), $enqueued );
	}

	public function test_the_script_loads_on_the_captcha_tab(): void {
		$_GET['tab'] = 'captcha';
		Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' )->shouldReceive( 'asset_suffix' )->andReturn( '.min' );
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src ) use ( &$enqueued ) {
				$enqueued[ $handle ] = $src;
			}
		);

		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );

		$this->assertSame(
			array( 'ffc-admin-captcha-settings' => FFC_PLUGIN_URL . 'assets/js/ffc-admin-captcha-settings.min.js' ),
			$enqueued
		);
	}

	public function test_render_draws_the_mode_choice_and_warns_without_https(): void {
		Functions\when( 'get_option' )->justReturn( array( 'captcha_provider' => 'both' ) );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->alias( static fn( $a, $b = true ) => print( (string) $a === (string) $b ? ' checked' : '' ) );
		Functions\when( 'disabled' )->alias( static fn( $a, $b = true ) => print( (string) $a === (string) $b ? ' disabled' : '' ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		$notices = array();
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args ) use ( &$notices ) {
				$notices[] = $args['type'];
			}
		);

		ob_start();
		$this->tab->render();
		$html = (string) ob_get_clean();

		$this->assertSame( array( 'warning' ), $notices, 'the HTTPS warning is shown' );
		$this->assertStringContainsString( 'name="_ffc_tab" value="captcha"', $html );
		$this->assertStringContainsString( 'ALTCHA, with the math challenge as fallback', $html );
		$this->assertStringContainsString( 'This is reach, not strength.', $html );
	}
}
