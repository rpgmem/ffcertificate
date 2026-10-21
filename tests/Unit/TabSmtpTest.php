<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Settings\SettingsTab;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabSMTP;

/**
 * Tests for TabSMTP: SMTP settings tab.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabSMTP
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabSmtpTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var TabSMTP */
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

		$this->tab = new TabSMTP();
	}

	protected function tearDown(): void {
		SettingsTab::set_resolved_active_tab( null );
		unset( $_GET['tab'], $_POST['ffc_save_email_bodies'], $_POST['ffc_email_bodies'] );
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

	public function test_tab_id_is_smtp(): void {
		$this->assertSame( 'smtp', $this->tab->get_id() );
	}

	public function test_tab_title_is_smtp(): void {
		$this->assertSame( 'SMTP', $this->tab->get_title() );
	}

	public function test_tab_icon_is_email(): void {
		$this->assertSame( 'ffc-icon-send', $this->tab->get_icon() );
	}

	public function test_tab_order_is_20(): void {
		$this->assertSame( 20, $this->tab->get_order() );
	}

	// ==================================================================
	// Inheritance
	// ==================================================================


	// ==================================================================
	// enqueue_scripts() — wrong hook
	// ==================================================================

	public function test_enqueue_scripts_returns_early_for_wrong_hook(): void {
		// wp_enqueue_script should never be called for a non-matching hook
		Functions\expect( 'wp_enqueue_script' )->never();

		$this->tab->enqueue_scripts( 'edit.php' );
	}

	// ==================================================================
	// enqueue_scripts() — correct hook but different tab
	// ==================================================================

	public function test_enqueue_scripts_returns_early_when_tab_is_not_smtp(): void {
		$_GET['tab'] = 'general';
		Functions\when( 'wp_unslash' )->returnArg();

		Functions\expect( 'wp_enqueue_script' )->never();

		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );
	}

	// ==================================================================
	// enqueue_scripts() — correct hook and correct tab
	// ==================================================================

	public function test_enqueue_scripts_enqueues_script_when_tab_is_smtp(): void {
		SettingsTab::set_resolved_active_tab( 'smtp' );
		Functions\when( 'wp_unslash' )->returnArg();

		// Since the #976 split the SMTP tab is transport only: it enqueues its own
		// ffc-smtp-settings plus the autosave infra (ffc-core, ffc-admin-autosave,
		// ffc-section-collapse) for the "enable email sending" toggle. The Email
		// Model + Restore-Default assets moved to their own tabs. The shared
		// scripts are named by handle and declared once in AssetRegistry, which
		// asserts their sources and dependencies (#1931).
		$utils_mock = Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' );
		$utils_mock->shouldReceive( 'asset_suffix' )->andReturn( '.min' );

		Functions\expect( 'wp_enqueue_script' )
			->with( 'ffc-smtp-settings', Mockery::type( 'string' ), array( 'jquery' ), Mockery::type( 'string' ), true )
			->once();
		foreach ( array( 'ffc-core', 'ffc-admin-autosave', 'ffc-section-collapse' ) as $ffc_handle ) {
			Functions\expect( 'wp_enqueue_script' )->with( $ffc_handle )->once();
		}
		Functions\expect( 'wp_localize_script' )
			->with( 'ffc-admin-autosave', 'ffcAdminAutosave', Mockery::on( function ( $arg ) {
				return is_array( $arg ) && isset( $arg['nonce'] ) && is_string( $arg['nonce'] );
			} ) )
			->once();
		Functions\when( 'wp_create_nonce' )->justReturn( 'autosave-nonce' );
		// The Email Model + Restore-Default assets are NOT enqueued by this tab.
		Functions\expect( 'wp_enqueue_media' )->never();

		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );
	}

	// ==================================================================
	// render() — view file missing (error branch)
	// ==================================================================

	/**
	 * The tab under test with only its view directory redirected, through the
	 * `view_path()` seam: render() is the product's own (#1811).
	 *
	 * @param string $views_dir Directory the tab reads its view from.
	 * @return TabSMTP
	 */
	private function tab_with_views_in( string $views_dir ): TabSMTP {
		return new class( $views_dir ) extends TabSMTP {
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
		$this->assertStringContainsString( 'SMTP settings view file not found.', $output );
	}

	// ==================================================================
	// render() — view file exists (happy path via temp file)
	// ==================================================================

	public function test_render_includes_view_when_file_exists(): void {
		$this->tmp_dir = sys_get_temp_dir() . '/ffc_test_views_smtp_' . getmypid();
		if ( ! is_dir( $this->tmp_dir ) ) {
			mkdir( $this->tmp_dir, 0777, true );
		}
		// The view reports what render() handed it: the shipped view reads
		// `$settings->get_option()`, so `$settings` must be the tab itself.
		file_put_contents(
			$this->tmp_dir . '/ffc-tab-smtp.php',
			'<?php echo ( $settings === $this ) ? "view-got-the-tab" : "view-got-something-else";'
		);

		ob_start();
		$this->tab_with_views_in( $this->tmp_dir )->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'view-got-the-tab', $output );
		$this->assertStringNotContainsString( 'not found', $output );
	}

	// ==================================================================
	// Inherited get_option()
	// ==================================================================

	public function test_get_option_returns_value_from_ffc_settings(): void {
		Functions\when( 'get_option' )->justReturn( array( 'smtp_host' => 'mail.example.com' ) );

		$this->assertSame( 'mail.example.com', $this->tab->get_option( 'smtp_host' ) );
	}

	public function test_get_option_returns_default_for_missing_key(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'wp', $this->tab->get_option( 'smtp_mode', 'wp' ) );
	}
}
