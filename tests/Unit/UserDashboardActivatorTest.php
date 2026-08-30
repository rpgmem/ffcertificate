<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\UserDashboardActivator;

/**
 * Tests for UserDashboardActivator: the user-dashboard module's activation
 * work — the ffc_end_user role + admin-cap grant, the user-profiles and
 * custom-fields tables, and the front-end dashboard page.
 *
 * The class is exercised indirectly by ActivatorTest (via
 * Activator::activate()), but pcov does not attribute coverage to a class
 * first autoloaded during a test method, so it needs a dedicated test with
 * a setUp() preload to record the coverage (the #563 pcov gotcha).
 *
 * @covers \FreeFormCertificate\UserDashboard\UserDashboardActivator
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UserDashboardActivatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution: preload the class under test (and the real
		// CapabilityManager, whose ADMIN_CAPABILITIES const the role grant
		// iterates) before any test method autoloads them.
		class_exists( '\FreeFormCertificate\UserDashboard\UserDashboardActivator' );
		class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' );

		// ABSPATH + a stub upgrade.php for the require_once in each table method.
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/wordpress/' );
		}
		$upgrade_dir = ABSPATH . 'wp-admin/includes';
		if ( ! is_dir( $upgrade_dir ) ) {
			mkdir( $upgrade_dir, 0755, true );
		}
		$upgrade_file = $upgrade_dir . '/upgrade.php';
		if ( ! file_exists( $upgrade_file ) ) {
			file_put_contents( $upgrade_file, "<?php\n// Stub for unit tests.\n" );
		}

		global $wpdb;
		$wpdb                   = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix           = 'wp_';
		$wpdb->last_error       = '';
		$this->wpdb             = $wpdb;
		$this->wpdb->shouldReceive( 'get_charset_collate' )
			->andReturn( 'DEFAULT CHARSET utf8mb4' )
			->byDefault();
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function () {
					$args = func_get_args();
					$sql  = $args[0];
					for ( $i = 1; $i < count( $args ); $i++ ) {
						$val = is_string( $args[ $i ] ) ? "'{$args[$i]}'" : $args[ $i ];
						$sql = preg_replace( '/%[sidf]/', $val, $sql, 1 );
					}
					return $sql;
				}
			)
			->byDefault();
		// Default: tables already exist (SHOW TABLES LIKE returns the name).
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing(
				function ( $query ) {
					if ( preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $m ) ) {
						return $m[1];
					}
					return null;
				}
			)
			->byDefault();

		// Default WP function stubs (happy path: page already exists).
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'wp_insert_post' )->justReturn( 42 );
		Functions\when( 'get_role' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Alias-mock the role deps so the register_user_role() block runs. */
	private function stub_role_deps(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\UserDashboard\UserManager' );
		Mockery::mock( 'alias:\FreeFormCertificate\UserDashboard\RoleRegistrar' )
			->shouldReceive( 'register_role' )
			->andReturnNull();
	}

	public function test_full_activation_registers_role_grants_caps_and_creates_tables(): void {
		$this->stub_role_deps();

		// An administrator role that records each granted cap.
		$granted     = array();
		$admin_role  = Mockery::mock();
		$admin_role->shouldReceive( 'add_cap' )
			->andReturnUsing(
				function ( $cap, $grant ) use ( &$granted ) {
					$granted[] = $cap;
				}
			);
		Functions\when( 'get_role' )->alias(
			function ( $role ) use ( $admin_role ) {
				return 'administrator' === $role ? $admin_role : null;
			}
		);

		// Tables absent → dbDelta runs for each; capture the DDL.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$ddl = array();
		Functions\when( 'dbDelta' )->alias(
			function ( $sql ) use ( &$ddl ) {
				$ddl[] = $sql;
				return array();
			}
		);

		// Page absent → insert path.
		Functions\when( 'get_page_by_path' )->justReturn( null );
		$inserted = null;
		Functions\when( 'wp_insert_post' )->alias(
			function ( $data ) use ( &$inserted ) {
				$inserted = $data;
				return 77;
			}
		);

		UserDashboardActivator::create_tables();

		$this->assertNotEmpty( $granted, 'admin caps should be granted from ADMIN_CAPABILITIES' );
		$this->assertCount( 2, $ddl, 'dbDelta should run for the two tables' );
		$this->assertStringContainsString( 'ffc_user_profiles', $ddl[0] );
		$this->assertStringContainsString( 'ffc_custom_fields', $ddl[1] );
		$this->assertIsArray( $inserted );
		$this->assertSame( '[user_dashboard_personal]', $inserted['post_content'] );
		$this->assertSame( 'dashboard', $inserted['post_name'] );
	}

	public function test_tables_and_page_skipped_when_already_present(): void {
		$this->stub_role_deps();

		// Tables exist (default get_var) → dbDelta must not run.
		$delta_called = false;
		Functions\when( 'dbDelta' )->alias(
			function () use ( &$delta_called ) {
				$delta_called = true;
			}
		);

		// Existing dashboard page → reuse its ID, no insert.
		$existing      = new \stdClass();
		$existing->ID  = 555;
		Functions\when( 'get_page_by_path' )->justReturn( $existing );
		$saved_option = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$saved_option ) {
				if ( 'ffc_dashboard_page_id' === $key ) {
					$saved_option = $value;
				}
				return true;
			}
		);
		$insert_called = false;
		Functions\when( 'wp_insert_post' )->alias(
			function () use ( &$insert_called ) {
				$insert_called = true;
				return 0;
			}
		);

		UserDashboardActivator::create_tables();

		$this->assertFalse( $delta_called, 'dbDelta should not run when tables exist' );
		$this->assertSame( 555, $saved_option, 'existing page ID should be stored' );
		$this->assertFalse( $insert_called, 'no page insert when the page already exists' );
	}

	public function test_admin_caps_skipped_when_no_administrator_role(): void {
		$this->stub_role_deps();
		Functions\when( 'get_role' )->justReturn( null ); // no admin role → cap loop skipped.
		Functions\when( 'get_page_by_path' )->justReturn( null );

		$consulted = array();
		Functions\when( 'get_role' )->alias(
			static function ( $slug ) use ( &$consulted ) {
				$consulted[] = (string) $slug;
				return null;
			}
		);

		UserDashboardActivator::create_tables();

		$this->assertContains(
			'administrator',
			$consulted,
			'The cap block must be reached and skipped, not bypassed before it.'
		);
	}

	public function test_dashboard_page_insert_error_skips_meta(): void {
		$this->stub_role_deps();
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_insert_post' )->justReturn( new \WP_Error( 'x', 'boom' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );
		$meta_called = false;
		Functions\when( 'update_post_meta' )->alias(
			function () use ( &$meta_called ) {
				$meta_called = true;
				return true;
			}
		);

		UserDashboardActivator::create_tables();

		$this->assertFalse( $meta_called, 'no _ffc_managed_page meta when the insert errors' );
	}
}
