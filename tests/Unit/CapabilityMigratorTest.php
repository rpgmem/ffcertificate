<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\CapabilityManager;
use FreeFormCertificate\UserDashboard\CapabilityMigrator;

/**
 * Tests for the #739 admin role-assignment migration.
 *
 * @covers \FreeFormCertificate\UserDashboard\CapabilityMigrator
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CapabilityMigratorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\UserDashboard\\CapabilityMigrator' );
		class_exists( '\\FreeFormCertificate\\UserDashboard\\CapabilityManager' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_admin_role_assignment_backfills_role_and_strips_caps(): void {
		$strip_cap = CapabilityManager::ADMIN_CAPABILITIES[0];

		// Administrator role carries one FFC cap to be stripped.
		$stripped   = array();
		$admin_role = new class( $stripped, $strip_cap ) {
			/** @var array<string, bool> */
			public $capabilities;
			/** @var array<int, string> */
			public $stripped;
			public function __construct( &$stripped, string $cap ) {
				$this->stripped     = &$stripped;
				$this->capabilities = array( $cap => true );
			}
			public function remove_cap( string $cap ): void {
				$this->stripped[] = $cap;
			}
		};

		// ffc_administrator already exists (truthy) → no self-heal.
		Functions\when( 'get_role' )->alias(
			static function ( $role ) use ( $admin_role ) {
				return 'administrator' === $role ? $admin_role : (object) array();
			}
		);

		Functions\when( 'get_users' )->justReturn( array( 7, 8 ) );

		$added7 = array();
		$user7  = new class( $added7 ) {
			/** @var array<int, string> */
			public $roles = array();
			/** @var array<int, string> */
			public $added;
			public function __construct( &$added ) {
				$this->added = &$added;
			}
			public function add_role( string $role ): void {
				$this->added[] = $role;
				$this->roles[] = $role;
			}
		};
		$user8 = new class() {
			/** @var array<int, string> */
			public $roles = array( 'ffc_administrator' );
			/** @var array<int, string> */
			public $added = array();
			public function add_role( string $role ): void {
				$this->added[] = $role;
			}
		};
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) use ( $user7, $user8 ) {
				return 7 === $id ? $user7 : $user8;
			}
		);

		$counts = CapabilityMigrator::migrate_admin_role_assignment();

		// Back-fill: user7 gets the role, user8 (already has it) is skipped.
		$this->assertContains( 'ffc_administrator', $user7->added );
		$this->assertNotContains( 'ffc_administrator', $user8->added );
		$this->assertSame( 1, $counts['roles_assigned'] );
		// Strip: the FFC cap is removed from the native administrator role.
		$this->assertContains( $strip_cap, $stripped );
		$this->assertGreaterThanOrEqual( 1, $counts['caps_stripped'] );
	}

	public function test_admin_role_assignment_self_heals_missing_role(): void {
		// ffc_administrator missing → the role registry is re-run once.
		Mockery::mock( 'alias:\\FreeFormCertificate\\UserDashboard\\RoleRegistrar' )
			->shouldReceive( 'register_module_roles' )->atLeast()->once();

		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'get_users' )->justReturn( array() );

		$counts = CapabilityMigrator::migrate_admin_role_assignment();

		$this->assertSame( 0, $counts['roles_assigned'] );
		$this->assertSame( 0, $counts['caps_stripped'] );
	}

	public function test_rbac_cap_renames_rewrites_user_meta_and_roles(): void {
		Functions\when( 'get_users' )->justReturn( array( 7 ) );

		$user = new class() {
			/** @var array<string, bool> */
			public $caps = array( 'ffc_scheduling_bypass' => true );
			/** @var array<string, bool> */
			public $added = array();
			/** @var array<int, string> */
			public $removed = array();
			public function add_cap( string $cap, bool $grant = true ): void {
				$this->added[ $cap ] = $grant;
			}
			public function remove_cap( string $cap ): void {
				$this->removed[] = $cap;
			}
		};
		Functions\when( 'get_userdata' )->justReturn( $user );

		$role = new class() {
			/** @var array<string, bool> */
			public $capabilities = array( 'ffc_scheduling_bypass' => true );
			/** @var array<string, bool> */
			public $added = array();
			/** @var array<int, string> */
			public $removed = array();
			public function add_cap( string $cap, bool $grant = true ): void {
				$this->added[ $cap ] = $grant;
			}
			public function remove_cap( string $cap ): void {
				$this->removed[] = $cap;
			}
		};
		$roles_obj = new class() {
			/** @var array<string, array<string, mixed>> */
			public $roles = array( 'ffc_appointments_manager' => array() );
		};
		Functions\when( 'wp_roles' )->justReturn( $roles_obj );
		Functions\when( 'get_role' )->justReturn( $role );

		$counts = CapabilityMigrator::migrate_rbac_cap_renames();

		// User-meta: old cap rewritten to the new slug.
		$this->assertArrayHasKey( 'ffc_bypass_appointments', $user->added );
		$this->assertContains( 'ffc_scheduling_bypass', $user->removed );
		// Role definition: same rewrite.
		$this->assertArrayHasKey( 'ffc_bypass_appointments', $role->added );
		$this->assertContains( 'ffc_scheduling_bypass', $role->removed );
		$this->assertSame( 1, $counts['ffc_scheduling_bypass'] );
	}
}
