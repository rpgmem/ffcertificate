<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin;

/**
 * The render tests run the real Capabilities, AdminNotice and Debug: Debug's
 * area toggle is read through a stubbed get_option, and its sink is pointed at
 * a scratch file through the error_log ini setting.
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin
 */
class SelfSchedulingAdminTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> Scratch files and directories a test created, removed in tearDown. */
	private array $scratch = array();

	/** @var string|false The error_log ini value before a test changed it. */
	private $error_log_backup = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->scratch          = array();
		$this->error_log_backup = ini_get( 'error_log' );
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
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_submenu_page' )->justReturn( 'hook' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/wp-content/plugins/ffcertificate/' );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', false === $this->error_log_backup ? '' : $this->error_log_backup );
		foreach ( array_reverse( $this->scratch ) as $path ) {
			if ( is_dir( $path ) ) {
				rmdir( $path );
			} elseif ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A scratch plugin dir holding views/appointments-list.php with this body.
	 *
	 * @return string The directory, with a trailing slash, as plugin_dir_path() returns it.
	 */
	private function scratch_view( string $prefix, string $body ): string {
		$dir = sys_get_temp_dir() . '/' . $prefix . uniqid( '', true );
		mkdir( $dir . '/views', 0777, true );
		file_put_contents( $dir . '/views/appointments-list.php', $body );
		$this->scratch[] = $dir;
		$this->scratch[] = $dir . '/views';
		$this->scratch[] = $dir . '/views/appointments-list.php';
		return $dir . '/';
	}

	/**
	 * Switch the self-scheduling debug area on or off and send Debug's
	 * error_log() sink to a scratch file.
	 *
	 * @return string The log file path.
	 */
	private function debug_log_to_scratch( bool $enabled ): string {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( $enabled ) {
				if ( 'ffc_settings' === $key ) {
					return $enabled ? array( \FreeFormCertificate\Core\Debug::AREA_SELF_SCHEDULING => 1 ) : array();
				}
				return $default;
			}
		);
		$log             = tempnam( sys_get_temp_dir(), 'ffc_ss_log_' );
		$this->scratch[] = $log;
		ini_set( 'error_log', $log );
		return $log;
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_creates_instance(): void {
		$admin = new SelfSchedulingAdmin();
		$this->assertInstanceOf( SelfSchedulingAdmin::class, $admin );
	}

	// ==================================================================
	// add_submenu_pages()
	// ==================================================================

	public function test_add_submenu_pages_registers_menu(): void {
		// #1030: the name claims pages are registered; nothing checked it.
		$slugs = array();
		Functions\when( 'add_submenu_page' )->alias(
			static function () use ( &$slugs ) {
				$slugs[] = func_get_arg( 4 );
				return 'admin_page_' . func_get_arg( 4 );
			}
		);

		$admin = new SelfSchedulingAdmin();
		$admin->add_submenu_pages();

		$this->assertNotEmpty( $slugs, 'add_submenu_pages() registered no page' );
	}

	// ==================================================================
	// enqueue_admin_assets() — no screen
	// ==================================================================

	public function test_enqueue_admin_assets_returns_early_without_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );

		// #1030: this asserted nothing, so the guard could be deleted and it
		// would still pass. Collect what gets enqueued and state that nothing
		// did — that is the whole claim in the test's name.
		$enqueued = array();
		$collect  = static function ( $handle ) use ( &$enqueued ) {
			$enqueued[] = $handle;
		};
		Functions\when( 'wp_enqueue_script' )->alias( $collect );
		Functions\when( 'wp_enqueue_style' )->alias( $collect );

		$admin = new SelfSchedulingAdmin();
		$admin->enqueue_admin_assets( 'edit.php' );
		$this->assertSame( array(), $enqueued );
	}

	// ==================================================================
	// enqueue_admin_assets() — wrong screen
	// ==================================================================

	public function test_enqueue_admin_assets_returns_early_on_wrong_screen(): void {
		$screen = (object) array( 'post_type' => 'post', 'id' => 'edit-post' );
		Functions\when( 'get_current_screen' )->justReturn( $screen );

		// #1030: this asserted nothing, so the guard could be deleted and it
		// would still pass. Collect what gets enqueued and state that nothing
		// did — that is the whole claim in the test's name.
		$enqueued = array();
		$collect  = static function ( $handle ) use ( &$enqueued ) {
			$enqueued[] = $handle;
		};
		Functions\when( 'wp_enqueue_script' )->alias( $collect );
		Functions\when( 'wp_enqueue_style' )->alias( $collect );

		$admin = new SelfSchedulingAdmin();
		$admin->enqueue_admin_assets( 'edit.php' );
		$this->assertSame( array(), $enqueued );
	}

	// ==================================================================
	// enqueue_admin_assets() — correct screen
	// ==================================================================

	public function test_enqueue_admin_assets_enqueues_on_self_scheduling_screen(): void {
		$screen = (object) array( 'post_type' => 'ffc_self_scheduling', 'id' => 'ffc_self_scheduling' );
		Functions\when( 'get_current_screen' )->justReturn( $screen );

		$enqueued_styles = array();
		Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued_styles ) {
			$enqueued_styles[] = func_get_arg( 0 );
		} );

		$enqueued_scripts = array();
		Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued_scripts ) {
			$enqueued_scripts[] = func_get_arg( 0 );
		} );
		Functions\when( 'get_option' )->justReturn( array( 'dark_mode' => 'on' ) );

		$admin = new SelfSchedulingAdmin();
		$admin->enqueue_admin_assets( 'edit.php' );

		// The calendar list and editor are post screens AdminAssetsManager
		// never reaches, so the dark-mode toggle is enqueued here.
		$this->assertContains( 'ffc-dark-mode', $enqueued_scripts );

		// The screen now loads only the status-badge stylesheet — the empty
		// ffc-calendar-admin.js stub + its dead localize were removed in the
		// frontend-audit Item 4 cleanup.
		$this->assertContains( 'ffc-calendar-admin', $enqueued_styles );
	}

	// ==================================================================
	// enqueue_admin_assets() — appointments page
	// ==================================================================

	public function test_enqueue_admin_assets_enqueues_on_appointments_page(): void {
		$screen = (object) array( 'post_type' => '', 'id' => 'ffc-scheduling_page_ffc-appointments' );
		Functions\when( 'get_current_screen' )->justReturn( $screen );

		$enqueued_styles = array();
		Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued_styles ) {
			$enqueued_styles[] = func_get_arg( 0 );
		} );

		Functions\when( 'get_option' )->justReturn( array() );

		$admin = new SelfSchedulingAdmin();
		$admin->enqueue_admin_assets( 'admin_page_ffc-appointments' );

		$this->assertContains( 'ffc-calendar-admin', $enqueued_styles );
	}

	// ==================================================================
	// render_appointments_page() — no permission
	// ==================================================================

	public function test_render_appointments_page_dies_without_permission(): void {
		$checked = array();
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) use ( &$checked ) {
				$checked[] = $cap;
				return false;
			}
		);
		Functions\when( 'wp_die' )->alias( function ( $msg ) {
			throw new \RuntimeException( $msg );
		} );

		$admin = new SelfSchedulingAdmin();
		try {
			$admin->render_appointments_page();
			$this->fail( 'Expected wp_die() to stop the render.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'You do not have permission to access this page.', $e->getMessage() );
		}
		// The real Capabilities asked the admin cap first, then the granular one.
		$this->assertSame( array( 'manage_options', 'ffc_view_appointments' ), $checked );
	}

	public function test_render_appointments_page_renders_view_and_registers_shutdown_handler(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$log = $this->debug_log_to_scratch( true );
		class_exists( '\FreeFormCertificate\Core\Utils' ); // so the shutdown guard's class_exists(...,false) is true.

		// Point plugin_dir_path at a scratch dir holding a benign stub view.
		Functions\when( 'plugin_dir_path' )->justReturn( $this->scratch_view( 'ffc_ss_ok_', "<?php /* stub view */\n" ) );

		$shutdown = null;
		Functions\when( 'register_shutdown_function' )->alias( function ( $cb ) use ( &$shutdown ) {
			$shutdown = $cb;
		} );

		( new SelfSchedulingAdmin() )->render_appointments_page();

		$this->assertIsCallable( $shutdown );

		// Invoke the registered shutdown closure — error_get_last() is a PHP
		// internal Patchwork can't redefine. It is cleared first, so the guard
		// takes its no-fatal path: with the debug area on, a log line would be
		// the proof it took the other one.
		error_clear_last();
		$shutdown();

		$this->assertSame( '', (string) file_get_contents( $log ), 'No fatal pending, so nothing is logged.' );
	}

	public function test_render_appointments_page_catches_view_throwable(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'register_shutdown_function' )->justReturn( null );
		$log = $this->debug_log_to_scratch( true );

		// A view that throws → the catch renders an error notice and logs it.
		Functions\when( 'plugin_dir_path' )->justReturn(
			$this->scratch_view( 'ffc_ss_err_', "<?php throw new \\RuntimeException( 'view boom' );\n" )
		);

		ob_start();
		( new SelfSchedulingAdmin() )->render_appointments_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Error:', $html );
		$this->assertStringContainsString( 'view boom', $html );
		$this->assertStringContainsString( '[FFC Debug] Appointments page error', (string) file_get_contents( $log ) );
	}
}
