<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Settings\SettingsTab;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabUserAccess;

/**
 * Tests for TabUserAccess: user access settings tab.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabUserAccess
 */
class TabUserAccessTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var TabUserAccess */
	private $tab;

	/**
	 * Temporary view directory a test created, removed in tearDown() so a
	 * failing assertion cannot leave it behind.
	 *
	 * @var string|null
	 */
	private $tmp_dir = null;

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

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
		} );

		$this->tab = new TabUserAccess();
	}

	protected function tearDown(): void {
		SettingsTab::set_resolved_active_tab( null );
		unset( $_GET['tab'] );
		unset( $_POST['ffc_user_access_nonce'], $_POST['block_wp_admin'], $_POST['blocked_roles'] );
		unset( $_POST['redirect_url'], $_POST['redirect_message'] );
		unset( $_POST['allow_admin_bar'], $_POST['bypass_for_admins'] );
		if ( null !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			foreach ( (array) glob( $this->tmp_dir . '/*' ) as $file ) {
				unlink( (string) $file );
			}
			rmdir( $this->tmp_dir );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// init() — tab properties
	// ==================================================================

	public function test_tab_id_is_user_access(): void {
		$this->assertSame( 'user_access', $this->tab->get_id() );
	}

	public function test_tab_title_is_user_access(): void {
		$this->assertSame( 'User Access', $this->tab->get_title() );
	}

	public function test_tab_icon_is_users(): void {
		$this->assertSame( 'ffc-icon-key', $this->tab->get_icon() );
	}

	public function test_tab_order_is_60(): void {
		$this->assertSame( 60, $this->tab->get_order() );
	}

	// ==================================================================
	// Inheritance
	// ==================================================================


	// ==================================================================
	// enqueue_styles() — wrong hook
	// ==================================================================

	public function test_enqueue_styles_returns_early_for_wrong_hook(): void {
		// Returning early means enqueueing nothing — that is the observable
		// effect, and shipping the autosave bundle on every admin screen is the
		// regression this guards against.
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$enqueued ) {
				$enqueued[] = (string) $handle;
			}
		);

		$this->tab->enqueue_styles( 'edit.php' );

		$this->assertSame( array(), $enqueued );
	}

	// ==================================================================
	// enqueue_styles() — correct hook
	// ==================================================================

	public function test_enqueue_styles_runs_for_correct_hook(): void {
		SettingsTab::set_resolved_active_tab( 'user_access' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( null );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->justReturn( '' );

		// The comment below stated what this does; assert it. Without the
		// autosave bundle every toggle on the tab silently stops saving.
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$enqueued ) {
				$enqueued[] = (string) $handle;
			}
		);

		$this->tab->enqueue_styles( 'toplevel_page_ffc-settings' );

		$this->assertContains( 'ffc-admin-autosave', $enqueued );
	}

	// ==================================================================
	// render() — view file missing (error branch)
	// ==================================================================

	/**
	 * The tab under test with only its view directory redirected, through the
	 * `view_path()` seam: render() is the product's own (#1811).
	 *
	 * @param string $views_dir Directory the tab reads its view from.
	 * @return TabUserAccess
	 */
	private function tab_with_views_in( string $views_dir ): TabUserAccess {
		return new class( $views_dir ) extends TabUserAccess {
			private string $views_dir;
			public function __construct( string $views_dir ) {
				$this->views_dir = $views_dir;
				parent::__construct();
			}
			protected function view_path( string $file ): string {
				return $this->views_dir . '/' . $file;
			}
		};
	}

	public function test_render_outputs_error_when_view_file_missing(): void {
		$tab = $this->tab_with_views_in( sys_get_temp_dir() . '/ffc_test_no_such_view_dir_' . getmypid() );

		ob_start();
		$tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-error', $output );
		$this->assertStringContainsString( 'User Access settings view file not found.', $output );
	}

	// ==================================================================
	// render() — view file exists (happy path via temp file)
	// ==================================================================

	public function test_render_includes_view_when_file_exists(): void {
		$this->tmp_dir = sys_get_temp_dir() . '/ffc_test_views_ua_' . getmypid();
		if ( ! is_dir( $this->tmp_dir ) ) {
			mkdir( $this->tmp_dir, 0777, true );
		}
		// The view reports what render() handed it: the shipped view reads
		// `$settings->get_option()`, so `$settings` must be the tab itself.
		file_put_contents(
			$this->tmp_dir . '/ffc-tab-user-access.php',
			'<?php echo ( $settings === $this ) ? "view-got-the-tab" : "view-got-something-else";'
		);

		ob_start();
		$this->tab_with_views_in( $this->tmp_dir )->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'view-got-the-tab', $output );
		$this->assertStringNotContainsString( 'not found', $output );
	}

	// ==================================================================
	// get_option() — overridden to use ffc_user_access_settings
	// ==================================================================

	public function test_get_option_returns_value_from_user_access_settings(): void {
		Functions\when( 'get_option' )->justReturn( array( 'block_wp_admin' => '1' ) );

		$this->assertSame( '1', $this->tab->get_option( 'block_wp_admin' ) );
	}

	public function test_get_option_returns_default_for_missing_key(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'no', $this->tab->get_option( 'block_wp_admin', 'no' ) );
	}

	public function test_get_option_returns_empty_string_by_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '', $this->tab->get_option( 'nonexistent' ) );
	}

	// ==================================================================
	// save_settings() — without nonce
	// ==================================================================

	public function test_save_settings_returns_early_without_nonce(): void {
		// No nonce in POST — update_option should never be called
		Functions\expect( 'update_option' )->never();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$this->tab->save_settings();

		// If we reach here, early return worked
		$this->assertTrue( true );
	}

	// ==================================================================
	// save_settings() — invalid nonce
	// ==================================================================

	public function test_save_settings_returns_early_with_invalid_nonce(): void {
		$_POST['ffc_user_access_nonce'] = 'bad_nonce';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\expect( 'update_option' )->never();

		$this->tab->save_settings();

		$this->assertTrue( true );
	}

	// ==================================================================
	// save_settings() — valid nonce saves settings
	// ==================================================================

	public function test_save_settings_saves_with_valid_nonce(): void {
		$_POST['ffc_user_access_nonce'] = 'valid_nonce';
		$_POST['block_wp_admin'] = '1';
		$_POST['blocked_roles'] = array( 'ffc_end_user', 'subscriber' );
		$_POST['redirect_url'] = 'https://example.com/dashboard';
		$_POST['redirect_message'] = 'You cannot access admin.';
		$_POST['allow_admin_bar'] = '1';
		$_POST['bypass_for_admins'] = '1';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
		Functions\when( 'add_settings_error' )->justReturn( true );

		$captured_settings = null;
		Functions\when( 'update_option' )->alias( function ( $option_name, $value ) use ( &$captured_settings ) {
			$captured_settings = $value;
			return true;
		} );

		$this->tab->save_settings();

		$this->assertNotNull( $captured_settings );
		$this->assertTrue( $captured_settings['block_wp_admin'] );
		$this->assertSame( array( 'ffc_end_user', 'subscriber' ), $captured_settings['blocked_roles'] );
		$this->assertSame( 'https://example.com/dashboard', $captured_settings['redirect_url'] );
		$this->assertSame( 'You cannot access admin.', $captured_settings['redirect_message'] );
		$this->assertTrue( $captured_settings['allow_admin_bar'] );
		$this->assertTrue( $captured_settings['bypass_for_admins'] );
	}

	// ==================================================================
	// save_settings() — defaults when POST fields missing
	// ==================================================================

	public function test_save_settings_uses_defaults_when_fields_missing(): void {
		$_POST['ffc_user_access_nonce'] = 'valid_nonce';
		// No other POST fields set

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
		Functions\when( 'add_settings_error' )->justReturn( true );

		$captured_settings = null;
		Functions\when( 'update_option' )->alias( function ( $option_name, $value ) use ( &$captured_settings ) {
			$captured_settings = $value;
			return true;
		} );

		$this->tab->save_settings();

		$this->assertNotNull( $captured_settings );
		$this->assertFalse( $captured_settings['block_wp_admin'] );
		$this->assertSame( array( 'ffc_end_user' ), $captured_settings['blocked_roles'] );
		$this->assertSame( 'https://example.com/dashboard', $captured_settings['redirect_url'] );
		$this->assertFalse( $captured_settings['allow_admin_bar'] );
		$this->assertFalse( $captured_settings['bypass_for_admins'] );
	}
}
