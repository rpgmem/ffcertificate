<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Settings\SettingsTab;
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
		SettingsTab::set_resolved_active_tab( null );
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
		SettingsTab::set_resolved_active_tab( 'captcha' );
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
		Functions\when( 'wp_kses_post' )->returnArg();

		ob_start();
		$this->tab->render();
		$html = (string) ob_get_clean();

		// The HTTPS warning is an in-content callout in the warning tone (#1843).
		$this->assertSame( 1, substr_count( $html, 'class="ffc-notice ffc-notice-warning"' ), 'the HTTPS warning is shown' );
		$this->assertStringContainsString( 'name="_ffc_tab" value="captcha"', $html );
		$this->assertStringContainsString( 'ALTCHA + Math', $html );
		$this->assertStringContainsString( 'This is reach, not strength.', $html );
		// Steps, left to right Math, ALTCHA + Math, ALTCHA only (#1758); without
		// HTTPS the ALTCHA-only stop is drawn but cannot be chosen.
		$this->assertStringContainsString( 'class="ffc-control-steps ffc-control-steps-traffic"', $html );
		$this->assertStringContainsString( 'name="ffc_settings[captcha_provider]" value="altcha" disabled>', $html );
		$this->assertStringContainsString( 'name="ffc_settings[captcha_provider]" value="both" checked>', $html );
		$this->assertLessThan( strpos( $html, 'value="both"' ), strpos( $html, 'value="math"' ) );
		$this->assertLessThan( strpos( $html, 'value="altcha"' ), strpos( $html, 'value="both"' ) );
		// Display: on means shown, so stored hide_* = 0 renders both on.
		$this->assertStringContainsString( 'name="ffc_settings[captcha_altcha_show_logo]" value="1" checked aria-describedby="captcha_altcha_show_logo-description">', $html );
		$this->assertStringContainsString( 'name="ffc_settings[captcha_altcha_show_footer]" value="1" checked aria-describedby="captcha_altcha_show_footer-description">', $html );
	}

	/**
	 * The two Display toggles each say what the visitor sees, and sit in the
	 * shared stack rather than behind a `<br />` (#1816).
	 */
	public function test_the_display_toggles_are_stacked_and_described(): void {
		Functions\when( 'get_option' )->justReturn( array( 'captcha_provider' => 'altcha' ) );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'disabled' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'wp_admin_notice' )->justReturn( null );

		ob_start();
		$this->tab->render();
		$html = (string) ob_get_clean();

		$stack = strpos( $html, '<div class="ffc-control-toggle-stack">' );
		$this->assertNotFalse( $stack, 'the Display cell is a toggle stack' );
		$cell = substr( $html, $stack, (int) strpos( $html, '</td>', $stack ) - $stack );

		$this->assertSame( 2, substr_count( $cell, '<div class="ffc-control-toggle-field">' ) );
		$this->assertStringNotContainsString( '<br', $cell, 'the stack spaces the toggles, not a line break' );
		$this->assertStringContainsString(
			'<p class="description" id="captcha_altcha_show_logo-description">On: the widget on the public form shows the ALTCHA logo beside the challenge. Off: the logo is hidden and only the challenge remains.</p>',
			$cell
		);
		$this->assertStringContainsString(
			'<p class="description" id="captcha_altcha_show_footer-description">On: a "Protected by ALTCHA" line linking to altcha.org appears at the bottom of the widget. Off: the line is hidden. The challenge works the same either way.</p>',
			$cell
		);
	}
}
