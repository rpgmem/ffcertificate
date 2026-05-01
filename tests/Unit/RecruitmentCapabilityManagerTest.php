<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\CapabilityManager;

/**
 * Tests for the recruitment-specific extension of CapabilityManager.
 *
 * Sprint 3 adds:
 *
 *   - `CONTEXT_RECRUITMENT` constant for `UserCreator::get_or_create_user()`.
 *   - `ffc_manage_recruitment` cap added to `ADMIN_CAPABILITIES`, which is
 *     auto-granted to the `administrator` role on activation by
 *     `Activator::activate()`.
 *   - `RECRUITMENT_MANAGER_ROLE` slug + `register_recruitment_manager_role()`
 *     creator (idempotent / upgrade-safe) + `remove_recruitment_manager_role()`
 *     teardown.
 *   - `grant_context_capabilities(CONTEXT_RECRUITMENT)` is an explicit no-op.
 *
 * @covers \FreeFormCertificate\UserDashboard\CapabilityManager
 */
class RecruitmentCapabilityManagerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_userdata' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_context_recruitment_constant_exists(): void {
		$this->assertSame( 'recruitment', CapabilityManager::CONTEXT_RECRUITMENT );
	}

	public function test_recruitment_manager_role_slug(): void {
		$this->assertSame( 'ffc_recruitment_manager', CapabilityManager::RECRUITMENT_MANAGER_ROLE );
	}

	public function test_admin_capabilities_includes_ffc_manage_recruitment(): void {
		$this->assertContains(
			'ffc_manage_recruitment',
			CapabilityManager::ADMIN_CAPABILITIES,
			'`ffc_manage_recruitment` must be in ADMIN_CAPABILITIES so Activator::activate() grants it to the administrator role'
		);
	}

	public function test_get_all_capabilities_includes_ffc_manage_recruitment(): void {
		$this->assertContains( 'ffc_manage_recruitment', CapabilityManager::get_all_capabilities() );
	}

	public function test_grant_context_capabilities_recruitment_is_a_no_op(): void {
		// CONTEXT_RECRUITMENT is intentionally a no-op: candidates rely on
		// the `ffc_user` role's baseline `read` cap. We verify the call
		// completes without invoking any of the per-cap grant helpers
		// (no get_userdata, no add_cap on the user).
		$get_userdata_called = false;
		Functions\when( 'get_userdata' )->alias(
			function () use ( &$get_userdata_called ) {
				$get_userdata_called = true;
				return false;
			}
		);

		CapabilityManager::grant_context_capabilities( 100, CapabilityManager::CONTEXT_RECRUITMENT );

		$this->assertFalse(
			$get_userdata_called,
			'CONTEXT_RECRUITMENT should not invoke any grant helper — no per-user caps are granted on candidate promotion'
		);
	}

	public function test_register_recruitment_manager_role_creates_new_role(): void {
		$captured_role = null;
		$captured_caps = null;

		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'add_role' )->alias(
			function ( $role, $label, $caps ) use ( &$captured_role, &$captured_caps ) {
				$captured_role = $role;
				$captured_caps = $caps;
				return new \WP_Role();
			}
		);

		CapabilityManager::register_recruitment_manager_role();

		$this->assertSame( 'ffc_recruitment_manager', $captured_role );
		$this->assertTrue( $captured_caps['read'] );
		$this->assertTrue( $captured_caps['ffc_manage_recruitment'] );
	}

	public function test_register_recruitment_manager_role_upgrades_existing_role_idempotently(): void {
		$role = new \WP_Role();
		// Simulate a partial role: only `read` set; missing the recruitment cap.
		$role->capabilities = array( 'read' => true );

		Functions\when( 'get_role' )->alias(
			function ( $slug ) use ( $role ) {
				return 'ffc_recruitment_manager' === $slug ? $role : null;
			}
		);

		// add_role MUST NOT be called when role already exists.
		$add_role_called = false;
		Functions\when( 'add_role' )->alias(
			function () use ( &$add_role_called ) {
				$add_role_called = true;
				return new \WP_Role();
			}
		);

		CapabilityManager::register_recruitment_manager_role();

		$this->assertFalse( $add_role_called, 'Existing role takes the upgrade path, not add_role' );
		$this->assertTrue( $role->capabilities['ffc_manage_recruitment'], 'Missing cap is added' );
		$this->assertTrue( $role->capabilities['read'], 'Existing cap is preserved' );
	}

	public function test_register_recruitment_manager_role_does_not_overwrite_admin_customizations(): void {
		$role               = new \WP_Role();
		$role->capabilities = array(
			'read'                   => true,
			'ffc_manage_recruitment' => true,
			'admin_custom_extra'     => true,
		);

		Functions\when( 'get_role' )->alias(
			function ( $slug ) use ( $role ) {
				return 'ffc_recruitment_manager' === $slug ? $role : null;
			}
		);

		CapabilityManager::register_recruitment_manager_role();

		$this->assertTrue(
			$role->capabilities['admin_custom_extra'],
			'Custom caps the admin added manually must survive role re-registration'
		);
	}

	public function test_remove_recruitment_manager_role_calls_wp_remove_role(): void {
		$captured_slug = null;

		Functions\when( 'remove_role' )->alias(
			function ( $slug ) use ( &$captured_slug ) {
				$captured_slug = $slug;
			}
		);

		CapabilityManager::remove_recruitment_manager_role();

		$this->assertSame( 'ffc_recruitment_manager', $captured_slug );
	}
}
