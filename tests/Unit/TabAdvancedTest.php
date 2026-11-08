<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Settings\SettingsTab;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabAdvanced;

/**
 * @covers \FreeFormCertificate\Settings\Tabs\TabAdvanced
 * @preserveGlobalState disabled
 */
class TabAdvancedTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var TabAdvanced */
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

		// The SettingsTab base require_once's formatting.php unless wp_kses_post
		// already exists, so pre-stub it BEFORE the class autoloads.
		Functions\when( 'wp_kses_post' )->returnArg();
		class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabAdvanced' );

		// The #839 S7a Encryption Key Health card uses wp_kses() with an explicit
		// allowlist in its warning/advisory branches.
		Functions\when( 'wp_kses' )->returnArg();
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
		SettingsTab::set_resolved_active_tab( null );
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
		$this->assertSame( 'ffc-icon-sliders', $this->tab->get_icon() );
	}

	public function test_tab_order_is_70(): void {
		$this->assertSame( 70, $this->tab->get_order() );
	}


	// ==================================================================
	// Inherited get_option()
	// ==================================================================

	public function test_get_option_delegates_to_settings_reader(): void {
		// The real SettingsReader reads the stored ffc_settings array.
		$read = array();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$read ) {
				$read[] = $key;
				return 'ffc_settings' === $key ? array( 'enable_activity_log' => '1' ) : $default;
			}
		);

		$this->assertSame( '1', $this->tab->get_option( 'enable_activity_log', '0' ) );
		$this->assertSame( array( 'ffc_settings' ), $read );
	}

	// ==================================================================
	// render() — happy path: includes the REAL view file
	// ==================================================================

	public function test_render_includes_real_view(): void {
		// The real SettingsReader over an empty ffc_settings: every read
		// falls back to its default.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return $default;
			}
		);

		// Globals the real view calls.
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'get_posts' )->justReturn( array() );
		// No Danger Zone capability: the purge switch is shown, not armed (#2343).
		Functions\when( 'current_user_can' )->justReturn( false );

		ob_start();
		$this->tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ffc-settings-wrap', $output );
		$this->assertStringContainsString( 'Activity Log', $output );
		$this->assertStringContainsString( 'Danger Zone', $output );
		$this->assertMatchesRegularExpression( '/id="ffc_delete_data_on_uninstall"[^>]*disabled/', $output, 'Without the Danger Zone capability the purge cannot be armed.' );
	}

	public function test_danger_zone_starts_with_no_target_and_counts_each_one(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => number_format( (float) $n ) );
		Functions\when( 'admin_url' )->returnArg();
		$form             = new \stdClass();
		$form->ID         = 7;
		$form->post_title = 'Libras 2026';
		$other            = new \stdClass();
		$other->ID        = 9;
		$other->post_title = 'Semana Pedagógica';
		Functions\when( 'get_posts' )->justReturn( array( $form, $other ) );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_manage_settings_dangerzone' === $cap );

		global $wpdb;
		$saved        = $wpdb;
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => (string) $sql );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static fn( $sql ) => false !== strpos( (string) $sql, 'GROUP BY form_id' )
				? array(
					array( 'form_id' => '7', 'total' => '1200' ),
					array( 'form_id' => '3', 'total' => '84' ),
				)
				: array()
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );

		ob_start();
		$this->tab->render();
		$output = (string) ob_get_clean();
		$wpdb   = $saved;

		$this->assertMatchesRegularExpression( '/<select name="delete_target" id="ffc_delete_target"[^>]*required>\s*<option value="">/', $output, 'No target is chosen for the operator.' );
		$this->assertStringContainsString( 'data-count-label="1,284"', $output, 'All forms count every row, a form no longer listed included.' );
		$this->assertMatchesRegularExpression( '/value="7" data-label="Libras 2026" data-count-label="1,200"/', $output );
		$this->assertMatchesRegularExpression( '/value="9" data-label="Semana Pedagógica" data-count-label="0"/', $output );
		$this->assertMatchesRegularExpression( '/<button type="submit"[^>]*id="ffc-danger-zone-delete"[^>]*disabled/', $output, 'The button waits for a target.' );
		$this->assertStringContainsString( 'name="ffc_delete_confirm"', $output );
		$this->assertStringNotContainsString( 'onclick=', $output, 'The browser confirm() is gone.' );
	}

	// ==================================================================
	// render() — error branch when view file missing
	// ==================================================================

	public function test_render_error_when_view_missing(): void {
		// Point the real render() at a view file that does not exist, so its
		// file_exists() guard fails and the error-notice branch executes.
		$tab = new class() extends TabAdvanced {
			protected function view_path( string $file ): string {
				return sys_get_temp_dir() . '/ffc-missing-view-' . $file;
			}
		};

		ob_start();
		$tab->render();
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
		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );
	}

	public function test_enqueue_scripts_enqueues_autosave_on_tab(): void {
		SettingsTab::set_resolved_active_tab( 'advanced' );
		Functions\when( 'admin_url' )->returnArg();

		$handles = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $h ) use ( &$handles ) {
				$handles[] = $h;
			}
		);
		$localized = array();
		Functions\when( 'wp_localize_script' )->alias(
			function ( $handle, $name, $data ) use ( &$localized ) {
				$localized[ $name ] = $data;
				return true;
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( '_x' )->returnArg();

		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );

		$this->assertContains( 'ffc-core', $handles );
		$this->assertContains( 'ffc-danger-zone', $handles );
		$this->assertSame( 'DELETE', $localized['ffcDangerZone']['word'] ?? null, 'The dialog asks for the word the server checks.' );
		$this->assertSame( 15, $localized['ffcDangerZone']['dialog']['seconds'] ?? null, 'The shared countdown.' );
		$this->assertContains( 'ffc-admin-autosave', $handles );
		$this->assertContains( 'ffc-section-collapse', $handles );
		$this->assertContains( 'ffc-encryption-key-suggest', $handles );
	}
}
