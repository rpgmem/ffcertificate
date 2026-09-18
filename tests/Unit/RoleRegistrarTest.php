<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\RoleRegistrar;

/**
 * Tests for RoleRegistrar's managed-role label map and its canonical ordering.
 *
 * The order is consumed by both admin surfaces that list FFC roles (the
 * Blocked Roles checkboxes and the role → capability editor dropdown), so it is
 * pinned here: cross-cutting roles first, then module ladders alphabetically by
 * module name, each from least to most powerful.
 *
 * @covers \FreeFormCertificate\UserDashboard\RoleRegistrar
 */
class RoleRegistrarTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// pcov: preload so coverage attributes to the exercised classes.
		class_exists( '\FreeFormCertificate\UserDashboard\RoleRegistrar' );
		class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' );
		// Translations resolve to the msgid in tests, so labels are English.
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_managed_role_labels_pins_cross_cutting_roles_first(): void {
		$slugs = array_keys( RoleRegistrar::ffc_managed_role_labels() );

		$this->assertSame(
			array( 'ffc_administrator', 'ffc_readonly', 'ffc_end_user' ),
			array_slice( $slugs, 0, 3 ),
			'Cross-cutting roles must lead: administrator → readonly → end_user.'
		);
	}

	public function test_managed_role_labels_sorts_modules_alphabetically_then_by_tier(): void {
		$labels = RoleRegistrar::ffc_managed_role_labels();
		$slugs  = array_keys( $labels );

		$tier_suffix = array(
			'_viewer'   => 0,
			'_operator' => 1,
			'_manager'  => 2,
			'_admin'    => 3,
		);

		$prev = null;
		foreach ( array_slice( $slugs, 3 ) as $slug ) {
			$module = (string) preg_replace( '/^FFC\s+| - .*$/u', '', $labels[ $slug ] );
			$tier   = 99;
			foreach ( $tier_suffix as $suffix => $rank ) {
				if ( str_ends_with( $slug, $suffix ) ) {
					$tier = $rank;
					break;
				}
			}
			$key = array( strtolower( $module ), $tier );
			if ( null !== $prev ) {
				$this->assertLessThanOrEqual(
					0,
					$prev <=> $key,
					"Role {$slug} breaks the (module, tier) ordering."
				);
			}
			$prev = $key;
		}
	}

	public function test_recruitment_manager_slots_into_the_recruitment_ladder(): void {
		$labels = RoleRegistrar::ffc_managed_role_labels();
		$slugs  = array_keys( $labels );

		$vi = (int) array_search( 'ffc_recruitment_viewer', $slugs, true );
		$op = (int) array_search( 'ffc_recruitment_operator', $slugs, true );
		$mg = (int) array_search( 'ffc_recruitment_manager', $slugs, true );
		$ad = (int) array_search( 'ffc_recruitment_admin', $slugs, true );

		$this->assertTrue(
			$vi < $op && $op < $mg && $mg < $ad,
			'Recruitment ladder order: viewer < operator < manager < admin.'
		);
		$this->assertSame( 'FFC Recruitment - Manager', $labels['ffc_recruitment_manager'] );
	}

	/**
	 * `ffc_administrator` must follow the native `administrator` role
	 * CONTINUOUSLY, not once (#1302).
	 *
	 * #747 moved the admin tier onto `ffc_administrator` and stripped the FFC
	 * capabilities from the core role. Both halves are right; the back-fill that
	 * carried them is one-shot behind an option flag, so an administrator
	 * created afterwards got neither — and every FFC top-level menu registers
	 * against a raw `ffc_view_*` slug, with only Settings holding a
	 * `manage_options` escape. That administrator saw FFC Settings and none of
	 * the six feature menus.
	 *
	 * These pin the decision, not the plumbing: WHO gets the role and who
	 * deliberately does not. Each was mutation-checked — dropping a hook or any
	 * of the three guards turns one of them red.
	 *
	 * The unknown-user guard in `sync_admin_role()` is deliberately NOT tested.
	 * It is defensive rather than decisive: with it removed the method still
	 * does nothing, because a non-user yields no roles — the only difference is
	 * a PHP warning. A test no mutation can fail asserts nothing, so the guard
	 * stays in the code and this sentence is its record.
	 */
	public function test_admin_role_sync_is_registered_on_the_three_role_events(): void {
		RoleRegistrar::init_admin_role_sync();

		foreach ( array( 'user_register', 'set_user_role', 'add_user_role' ) as $hook ) {
			$this->assertTrue(
				has_action( $hook, 'FreeFormCertificate\\UserDashboard\\RoleRegistrar::sync_admin_role' ) !== false,
				sprintf( 'A user can gain the administrator role through %s, so the sync must be wired there.', $hook )
			);
		}
	}

	/**
	 * Build a `WP_User` double whose `roles` are $roles.
	 *
	 * @param list<string> $roles Role slugs.
	 * @return \Mockery\MockInterface&\WP_User
	 */
	private function user_with_roles( array $roles ) {
		$user        = \Mockery::mock( '\WP_User' );
		$user->roles = $roles;
		return $user;
	}

	public function test_an_administrator_without_the_ffc_role_receives_it(): void {
		$user = $this->user_with_roles( array( 'administrator' ) );
		$user->shouldReceive( 'add_role' )->once()->with( 'ffc_administrator' );

		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_role' )->justReturn( \Mockery::mock( '\WP_Role' ) );

		RoleRegistrar::sync_admin_role( 7 );
	}

	public function test_an_administrator_who_already_has_it_is_left_alone(): void {
		$user = $this->user_with_roles( array( 'administrator', 'ffc_administrator' ) );
		$user->shouldNotReceive( 'add_role' );

		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_role' )->justReturn( \Mockery::mock( '\WP_Role' ) );

		// The re-entry this proves terminates: `add_role()` fires
		// `add_user_role`, which calls this method again.
		RoleRegistrar::sync_admin_role( 7 );
	}

	public function test_a_non_administrator_never_receives_the_ffc_admin_role(): void {
		$user = $this->user_with_roles( array( 'editor', 'ffc_end_user' ) );
		$user->shouldNotReceive( 'add_role' );

		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_role' )->justReturn( \Mockery::mock( '\WP_Role' ) );

		RoleRegistrar::sync_admin_role( 7 );
	}

	/**
	 * A membership row pointing at a role that does not exist is worse than
	 * doing nothing: the one-shot back-fill would then skip the user as already
	 * handled, and the role would never arrive.
	 */
	public function test_nothing_is_assigned_before_the_role_is_registered(): void {
		$user = $this->user_with_roles( array( 'administrator' ) );
		$user->shouldNotReceive( 'add_role' );

		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_role' )->justReturn( null );

		RoleRegistrar::sync_admin_role( 7 );
	}
}
