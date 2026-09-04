<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin;

/**
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class SelfSchedulingAdminTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
		if ( ! defined( 'FFC_VERSION' ) ) {
			define( 'FFC_VERSION', '4.12.0' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
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

		$admin = new SelfSchedulingAdmin();
		$admin->enqueue_admin_assets( 'edit.php' );

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

		$admin = new SelfSchedulingAdmin();
		$admin->enqueue_admin_assets( 'admin_page_ffc-appointments' );

		$this->assertContains( 'ffc-calendar-admin', $enqueued_styles );
	}

	// ==================================================================
	// render_appointments_page() — no permission
	// ==================================================================

	/**
	 * Runs in a separate process because other tests in the suite leave a
	 * Mockery alias for Utils loaded, which makes the permission check
	 * resolve to a null mock in full-suite runs.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_appointments_page_dies_without_permission(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias( function ( $msg ) {
			throw new \RuntimeException( $msg );
		} );

		$admin = new SelfSchedulingAdmin();
		$this->expectException( \RuntimeException::class );
		$admin->render_appointments_page();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_appointments_page_renders_view_and_registers_shutdown_handler(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' )
			->shouldReceive( 'log_self_scheduling' )->andReturnNull()->byDefault();
		class_exists( '\FreeFormCertificate\Core\Utils' ); // so the shutdown guard's class_exists(...,false) is true.

		// Point plugin_dir_path at a scratch dir holding a benign stub view.
		$dir = sys_get_temp_dir() . '/ffc_ss_ok_' . uniqid();
		mkdir( $dir . '/views', 0777, true );
		file_put_contents( $dir . '/views/appointments-list.php', "<?php /* stub view */\n" );
		Functions\when( 'plugin_dir_path' )->justReturn( $dir . '/' );

		$shutdown = null;
		Functions\when( 'register_shutdown_function' )->alias( function ( $cb ) use ( &$shutdown ) {
			$shutdown = $cb;
		} );

		( new SelfSchedulingAdmin() )->render_appointments_page();

		$this->assertIsCallable( $shutdown );

		// Invoke the registered shutdown closure — error_get_last() is a PHP
		// internal Patchwork can't redefine, so it returns the real last error
		// (none pending), exercising the guard's no-fatal path without a stub.
		$shutdown();

		$this->assertTrue( true );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_appointments_page_catches_view_throwable(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'register_shutdown_function' )->justReturn( null );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' )
			->shouldReceive( 'log_self_scheduling' )->andReturnNull()->byDefault();

		// A view that throws → the catch renders an error notice and logs it.
		$dir = sys_get_temp_dir() . '/ffc_ss_err_' . uniqid();
		mkdir( $dir . '/views', 0777, true );
		file_put_contents( $dir . '/views/appointments-list.php', "<?php throw new \\RuntimeException( 'view boom' );\n" );
		Functions\when( 'plugin_dir_path' )->justReturn( $dir . '/' );

		ob_start();
		( new SelfSchedulingAdmin() )->render_appointments_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Error:', $html );
		$this->assertStringContainsString( 'view boom', $html );
	}
}
