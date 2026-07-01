<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabAdvanced;

/**
 * @covers \FreeFormCertificate\Settings\Tabs\TabAdvanced
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabAdvancedTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var TabAdvanced */
	private $tab;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The SettingsTab base require_once's formatting.php unless wp_kses_post
		// already exists, so pre-stub it BEFORE the class autoloads.
		Functions\when( 'wp_kses_post' )->returnArg();
		class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabAdvanced' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias( function ( $t ) { echo $t; } );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		} );

		$this->tab = new TabAdvanced();
	}

	protected function tearDown(): void {
		unset( $_GET['tab'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// init() — tab properties
	// ==================================================================

	public function test_tab_id_is_advanced(): void {
		$this->assertSame( 'advanced', $this->tab->get_id() );
	}

	public function test_tab_title_is_advanced(): void {
		$this->assertSame( 'Advanced', $this->tab->get_title() );
	}

	public function test_tab_icon_is_settings(): void {
		$this->assertSame( 'ffc-icon-settings', $this->tab->get_icon() );
	}

	public function test_tab_order_is_70(): void {
		$this->assertSame( 70, $this->tab->get_order() );
	}

	public function test_extends_settings_tab(): void {
		$this->assertInstanceOf(
			\FreeFormCertificate\Settings\SettingsTab::class,
			$this->tab
		);
	}

	// ==================================================================
	// Inherited get_option()
	// ==================================================================

	public function test_get_option_delegates_to_settings_reader(): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
		$reader->shouldReceive( 'get' )->with( 'enable_activity_log', '0' )->andReturn( '1' );

		$this->assertSame( '1', $this->tab->get_option( 'enable_activity_log', '0' ) );
	}

	// ==================================================================
	// render() — happy path: includes the REAL view file
	// ==================================================================

	public function test_render_includes_real_view(): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
		$reader->shouldReceive( 'get' )->andReturnUsing(
			function ( $key, $default = '' ) {
				return $default;
			}
		);
		$reader->shouldReceive( 'activity_log_min_level' )->andReturn( 'debug' );
		$reader->shouldReceive( 'activity_log_category_enabled' )->andReturn( true );
		$reader->shouldReceive( 'required_certificate_tags' )->andReturn( array() );

		// Globals the real view calls.
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'get_posts' )->justReturn( array() );

		ob_start();
		$this->tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ffc-settings-wrap', $output );
		$this->assertStringContainsString( 'Activity Log', $output );
		$this->assertStringContainsString( 'Danger Zone', $output );
	}

	// ==================================================================
	// render() — error branch when view file missing
	// ==================================================================

	public function test_render_error_when_view_missing(): void {
		// Safe under @runTestsInSeparateProcesses — the namespaced stub does
		// not leak across tests.
		Functions\when( 'FreeFormCertificate\\Settings\\Tabs\\file_exists' )->justReturn( false );

		ob_start();
		$this->tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-error', $output );
		$this->assertStringContainsString( 'Advanced settings view file not found.', $output );
	}

	// ==================================================================
	// enqueue_scripts() — three branches
	// ==================================================================

	public function test_enqueue_scripts_returns_early_for_wrong_hook(): void {
		Functions\expect( 'wp_enqueue_script' )->never();
		$this->tab->enqueue_scripts( 'edit.php' );
	}

	public function test_enqueue_scripts_returns_early_for_wrong_tab(): void {
		$_GET['tab'] = 'general';
		Functions\expect( 'wp_enqueue_script' )->never();
		$this->tab->enqueue_scripts( 'ffc_form_page_ffc-settings' );
	}

	public function test_enqueue_scripts_enqueues_autosave_on_tab(): void {
		$_GET['tab'] = 'advanced';

		$assets = Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' );
		$assets->shouldReceive( 'asset_suffix' )->andReturn( '.min' );

		$handles = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $h ) use ( &$handles ) {
				$handles[] = $h;
			}
		);
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );

		$this->tab->enqueue_scripts( 'ffc_form_page_ffc-settings' );

		$this->assertContains( 'ffc-core', $handles );
		$this->assertContains( 'ffc-admin-autosave', $handles );
		$this->assertContains( 'ffc-section-collapse', $handles );
	}
}
