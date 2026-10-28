<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\AssetHelper;
use FreeFormCertificate\Settings\SettingsTab;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabUrlShortener;

/**
 * Tests for TabUrlShortener: URL shortener settings tab.
 *
 * Exercises the real render() (including the shipped view partial) and the
 * real enqueue_scripts() so the tab class body is covered end-to-end.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabUrlShortener
 * @preserveGlobalState disabled
 */
class TabUrlShortenerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var TabUrlShortener */
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
		class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabUrlShortener' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		} );
		// The enqueue gate now routes through SettingsTab::is_active(), which
		// wp_unslash()es the tab param before sanitize_key().
		Functions\when( 'wp_unslash' )->returnArg();

		$this->tab = new TabUrlShortener();
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
		$this->assertSame( 'url_shortener', $this->tab->get_id() );
	}

	public function test_tab_title(): void {
		$this->assertSame( 'Short URLs', $this->tab->get_title() );
	}

	public function test_tab_icon(): void {
		$this->assertSame( 'ffc-icon-link', $this->tab->get_icon() );
	}

	public function test_tab_order(): void {
		$this->assertSame( 35, $this->tab->get_order() );
	}


	// ==================================================================
	// render() — real view partial include (happy path)
	// ==================================================================

	public function test_render_includes_real_view(): void {
		// The shipped view reads the real SettingsReader::all(), which reads
		// the option; the stored array is what the view draws.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'ffc_settings' !== $key ) {
					return $default;
				}
				return array(
					'url_shortener_enabled'       => 1,
					'url_shortener_prefix'        => 'go',
					'url_shortener_code_length'   => 6,
					'url_shortener_auto_create'   => 1,
					'url_shortener_redirect_type' => 302,
					'url_shortener_post_types'    => array( 'post', 'page' ),
				);
			}
		);

		$pt       = new \stdClass();
		$pt->name = 'post';
		$labels   = new \stdClass();
		$labels->singular_name = 'Post';
		$pt->labels            = $labels;

		Functions\when( 'get_post_types' )->justReturn( array( 'post' => $pt ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'disabled' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );

		ob_start();
		$this->tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ffc-settings-wrap', $output );
		$this->assertStringContainsString( 'url_shortener_prefix', $output );
	}

	// ==================================================================
	// render() — error branch (view file missing)
	// ==================================================================

	public function test_render_error_when_view_missing(): void {
		// Point the real render() at a view that does not exist, through the
		// `view_path()` seam (#1811), so its error branch (the "view file not
		// found" notice) executes.
		$tab = new class() extends TabUrlShortener {
			protected function view_path( string $file ): string {
				return __DIR__ . '/no-such-view-directory/' . $file;
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
		$_GET['tab'] = 'general';
		Functions\expect( 'wp_enqueue_script' )->never();
		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );
	}

	public function test_enqueue_scripts_enqueues_autosave_on_url_shortener_tab(): void {
		SettingsTab::set_resolved_active_tab( 'url_shortener' );

		// The real AssetHelper and AssetRegistry run. The suffix depends on
		// SCRIPT_DEBUG, which an earlier test in the process may have
		// defined, so it is read rather than assumed.
		$handles = array();
		$sources = array();
		Functions\when( 'wp_enqueue_script' )->alias( function ( $h, $src = '' ) use ( &$handles, &$sources ) {
			$handles[]     = $h;
			$sources[ $h ] = $src;
		} );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->returnArg();

		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );

		$this->assertContains( 'ffc-core', $handles );
		$this->assertContains( 'ffc-admin-autosave', $handles );
		$this->assertContains( 'ffc-section-collapse', $handles );
		$this->assertContains( 'ffc-url-shortener-settings', $handles );
		$this->assertSame( FFC_PLUGIN_URL . 'assets/js/ffc-url-shortener-settings' . AssetHelper::asset_suffix() . '.js', $sources['ffc-url-shortener-settings'] );
	}

	// ==================================================================
	// Inherited get_option()
	// ==================================================================

	public function test_get_option_returns_default_for_missing_key(): void {
		// The real SettingsReader reads a stored array without the key.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return 'ffc_settings' === $key ? array( 'url_shortener_prefix' => 'go' ) : $default;
			}
		);

		$this->assertSame( 'fallback', $this->tab->get_option( 'missing', 'fallback' ) );
	}
}
