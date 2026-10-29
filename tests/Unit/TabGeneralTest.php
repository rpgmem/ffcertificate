<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Settings\SettingsTab;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabGeneral;

/**
 * Tests for TabGeneral: general settings tab.
 *
 * Exercises the real render() (including the shipped view partial) and the
 * real enqueue_scripts() so the tab class body is covered end-to-end.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabGeneral
 * @preserveGlobalState disabled
 */
class TabGeneralTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var TabGeneral */
	private $tab;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args = array() ) {
				$ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
				$ffc_cls  = 'notice notice-' . $ffc_type;
				if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
				if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
				$ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
				echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
			}
		);

		// Pre-stub wp_kses_post BEFORE the SettingsTab base autoloads — its
		// file-level guard require_once's wp-includes/formatting.php when the
		// function is undefined, which fatals under the test WP-less ABSPATH.
		Functions\when( 'wp_kses_post' )->returnArg();
		class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabGeneral' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		} );

		$this->tab = new TabGeneral();
	}

	protected function tearDown(): void {
		unset( $_GET['tab'] );
		SettingsTab::set_resolved_active_tab( null );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// init() — tab properties
	// ==================================================================

	public function test_tab_id(): void {
		$this->assertSame( 'general', $this->tab->get_id() );
	}

	public function test_tab_title(): void {
		$this->assertSame( 'General', $this->tab->get_title() );
	}

	public function test_tab_icon(): void {
		$this->assertSame( 'ffc-icon-settings', $this->tab->get_icon() );
	}

	public function test_tab_order(): void {
		$this->assertSame( 10, $this->tab->get_order() );
	}


	// ==================================================================
	// render() — real view partial include (happy path)
	// ==================================================================

	public function test_render_includes_real_view(): void {
		// The shipped view resolves every $ffcertificate_get_option(...) call
		// through the real SettingsReader::get; an empty `ffc_settings` makes
		// every key fall back to the default the view passes. The WP core
		// date/time formats (divergence notice) stay aligned with the plugin
		// defaults.
		$this->stub_options(
			array(),
			array(
				'date_format' => 'Y-m-d',
				'time_format' => 'H:i',
			)
		);

		Functions\when( 'date_i18n' )->alias(
			function ( $fmt, $ts = null ) {
				return gmdate( (string) $fmt, null === $ts ? time() : (int) $ts );
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );

		ob_start();
		$this->tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ffc-settings-wrap', $output );
		$this->assertStringContainsString( 'ffc_date_format', $output );
		// The QR defaults moved to their own tab (#1563): one autosave key on
		// two tabs would drift.
		$this->assertStringNotContainsString( 'qr_default_size', $output );

		// The module-settings jump list moved from this view into the
		// settings nav itself (Settings::render_module_settings_links()).
		$this->assertStringNotContainsString( 'ffc-module-settings-index', $output );
	}

	// ==================================================================
	// render() — happy path with divergence notice shown
	// ==================================================================

	public function test_render_shows_divergence_notice(): void {
		// The plugin's stored formats differ from WordPress's global ones ->
		// divergence banner.
		$this->stub_options(
			array(
				'date_format' => 'd/m/Y',
				'time_format' => 'g:i a',
			),
			array(
				'date_format' => 'Y-m-d',
				'time_format' => 'H:i',
			)
		);

		Functions\when( 'date_i18n' )->alias(
			function ( $fmt, $ts = null ) {
				return gmdate( (string) $fmt, null === $ts ? time() : (int) $ts );
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );

		ob_start();
		$this->tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ffc-settings-divergence-notice', $output );
	}

	// ==================================================================
	// render() — smart-match legacy combined date format -> custom
	// ==================================================================

	public function test_render_smart_matches_legacy_date_format(): void {
		// A pre-#248 combined date+time value that strips to a preset; the
		// WordPress core formats read back empty.
		$this->stub_options(
			array( 'date_format' => 'Y-m-d H:i' ),
			array(
				'date_format' => '',
				'time_format' => '',
			)
		);
		Functions\when( 'date_i18n' )->alias(
			function ( $fmt, $ts = null ) {
				return gmdate( (string) $fmt, null === $ts ? time() : (int) $ts );
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );

		ob_start();
		$this->tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ffc-settings-wrap', $output );
	}

	// ==================================================================
	// render() — error branch (view file missing)
	// ==================================================================

	public function test_render_error_when_view_missing(): void {
		// Point the view path at a file that does not exist, so the real
		// render()'s file_exists() guard fails and its error branch (the
		// "view file not found" notice) executes.
		$tab = new class() extends TabGeneral {
			/**
			 * A view path that never exists.
			 *
			 * @param string $file View file name.
			 * @return string
			 */
			protected function view_path( string $file ): string {
				return FFC_PLUGIN_DIR . 'includes/settings/views/missing-' . $file;
			}
		};

		ob_start();
		$tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-error', $output );
		$this->assertStringContainsString( 'not found', $output );
	}

	// ==================================================================
	// enqueue_scripts()
	// ==================================================================

	public function test_enqueue_scripts_returns_early_for_wrong_hook(): void {
		Functions\expect( 'wp_enqueue_script' )->never();
		$this->tab->enqueue_scripts( 'edit.php' );
	}

	public function test_enqueue_scripts_returns_early_for_wrong_tab(): void {
		$_GET['tab'] = 'other';
		Functions\expect( 'wp_enqueue_script' )->never();
		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );
	}

	public function test_enqueue_scripts_enqueues_autosave_on_general_tab(): void {
		SettingsTab::set_resolved_active_tab( 'general' );

		// The real AssetRegistry registers each shared handle through the
		// real AssetHelper::asset_suffix() before enqueuing it.
		Functions\when( 'plugins_url' )->returnArg();
		Functions\when( 'wp_script_is' )->justReturn( false );

		$handles = array();
		Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$handles ) {
			$handles[] = $h;
		} );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );

		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );

		$this->assertContains( 'ffc-core', $handles );
		$this->assertContains( 'ffc-admin-autosave', $handles );
		$this->assertContains( 'ffc-section-collapse', $handles );
	}

	// ==================================================================
	// Inherited get_option()
	// ==================================================================

	public function test_get_option_returns_default_for_missing_key(): void {
		$this->stub_options( array( 'present' => 'stored' ), array() );

		$this->assertSame( 'fallback', $this->tab->get_option( 'missing', 'fallback' ) );
		$this->assertSame( 'stored', $this->tab->get_option( 'present', 'fallback' ) );
	}

	/**
	 * Answer get_option(): `ffc_settings` with the plugin's stored settings,
	 * any other key from the WordPress core options given, else the default.
	 *
	 * @param array<string, mixed> $ffc_settings Stored plugin settings.
	 * @param array<string, mixed> $core         WordPress core options.
	 */
	private function stub_options( array $ffc_settings, array $core ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( $ffc_settings, $core ) {
				if ( \FreeFormCertificate\Settings\SettingsReader::OPTION_KEY === $key ) {
					return $ffc_settings;
				}
				return array_key_exists( $key, $core ) ? $core[ $key ] : $default;
			}
		);
	}
}
