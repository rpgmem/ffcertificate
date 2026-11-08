<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
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
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );
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
			// Everything before the LAST " - " (the tier): a module's part
			// stays in it, so "Certificates - Forms" sorts as one block (#1860).
			$label  = $labels[ $slug ];
			$cut    = strrpos( $label, ' - ' );
			$module = (string) preg_replace( '/^FFC\s+/u', '', false === $cut ? $label : substr( $label, 0, $cut ) );
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
		$user->ID    = 7;
		$user->roles = $roles;
		return $user;
	}

	/**
	 * Stub the per-user sync flag, recording what is written.
	 *
	 * @param string $stored Flag value already on the user.
	 * @return array<int, array{0: string, 1: string}> Writes, by reference.
	 */
	private function &flag_store( string $stored = '' ): array {
		$writes = array();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_user_meta' )->justReturn( $stored );
		Functions\when( 'update_user_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$writes ) {
				$writes[] = array( $key, $value );
				return true;
			}
		);
		return $writes;
	}

	public function test_an_administrator_without_the_ffc_role_receives_it(): void {
		$writes = &$this->flag_store();
		$user   = $this->user_with_roles( array( 'administrator' ) );
		$user->shouldReceive( 'add_role' )->once()->with( 'ffc_administrator' );

		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_role' )->justReturn( \Mockery::mock( '\WP_Role' ) );

		RoleRegistrar::sync_admin_role( 7 );

		$this->assertSame( array( array( 'ffc_admin_role_synced', '1' ) ), $writes );
	}

	public function test_an_administrator_who_already_has_it_is_left_alone(): void {
		$writes = &$this->flag_store();
		$user   = $this->user_with_roles( array( 'administrator', 'ffc_administrator' ) );
		$user->shouldNotReceive( 'add_role' );

		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_role' )->justReturn( \Mockery::mock( '\WP_Role' ) );

		RoleRegistrar::sync_admin_role( 7 );

		// Recorded, so taking the role off later holds.
		$this->assertSame( array( array( 'ffc_admin_role_synced', '1' ) ), $writes );
	}

	public function test_an_administrator_whose_ffc_role_was_taken_off_does_not_get_it_back(): void {
		$writes = &$this->flag_store( '1' );
		$user   = $this->user_with_roles( array( 'administrator' ) );
		$user->shouldNotReceive( 'add_role' );

		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_role' )->justReturn( \Mockery::mock( '\WP_Role' ) );

		RoleRegistrar::sync_admin_role( 7 );

		$this->assertSame( array(), $writes );
	}

	public function test_on_a_network_the_flag_is_per_site(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 3 );

		$this->assertSame( 'ffc_admin_role_synced_3', RoleRegistrar::admin_role_synced_key() );
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
		$writes = &$this->flag_store();
		$user   = $this->user_with_roles( array( 'administrator' ) );
		$user->shouldNotReceive( 'add_role' );

		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_role' )->justReturn( null );

		RoleRegistrar::sync_admin_role( 7 );

		// Not recorded either: the role must still arrive once it exists.
		$this->assertSame( array(), $writes );
	}

	/**
	 * A role holding the given capability map.
	 *
	 * @param array<string, bool> $caps Capability map.
	 * @return \WP_Role
	 */
	private function role_with( array $caps ): \WP_Role {
		$role               = new \WP_Role();
		$role->capabilities = $caps;
		return $role;
	}

	public function test_the_recruitment_manager_role_is_created_with_its_two_caps(): void {
		Functions\when( 'get_role' )->justReturn( null );
		Functions\expect( 'add_role' )
			->once()
			->with(
				\FreeFormCertificate\UserDashboard\CapabilityManager::RECRUITMENT_MANAGER_ROLE,
				'FFC Recruitment - Manager',
				array(
					'read'                   => true,
					'ffc_manage_recruitment' => true,
				)
			);

		RoleRegistrar::register_recruitment_manager_role();
	}

	public function test_an_existing_recruitment_manager_role_gains_missing_caps_and_keeps_custom_ones(): void {
		// No role was edited, so nothing was removed.
		Functions\when( 'get_option' )->justReturn( array() );
		$role = $this->role_with( array( 'custom_cap' => true ) );
		Functions\when( 'get_role' )->justReturn( $role );
		Functions\expect( 'add_role' )->never();

		RoleRegistrar::register_recruitment_manager_role();

		$this->assertSame(
			array(
				'custom_cap'             => true,
				'read'                   => true,
				'ffc_manage_recruitment' => true,
			),
			$role->capabilities
		);
	}

	public function test_a_recruitment_manager_cap_removed_in_the_editor_is_not_put_back(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				\FreeFormCertificate\UserDashboard\CapabilityManager::RECRUITMENT_MANAGER_ROLE => array(
					'added'   => array(),
					'removed' => array( 'ffc_manage_recruitment' ),
				),
			)
		);
		$role = $this->role_with( array( 'read' => true ) );
		Functions\when( 'get_role' )->justReturn( $role );

		RoleRegistrar::register_recruitment_manager_role();

		$this->assertSame( array( 'read' => true ), $role->capabilities );
	}

	public function test_a_complete_recruitment_manager_role_is_not_rewritten(): void {
		$role = Mockery::mock( \WP_Role::class );
		$role->capabilities = array(
			'read'                   => true,
			'ffc_manage_recruitment' => false,
		);
		$role->shouldNotReceive( 'add_cap' );
		Functions\when( 'get_role' )->justReturn( $role );

		RoleRegistrar::register_recruitment_manager_role();

		$this->assertFalse( $role->capabilities['ffc_manage_recruitment'], 'An operator\'s explicit choice is not overwritten.' );
	}

	public function test_module_roles_are_created_with_read_and_their_declared_caps(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$added = array();
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'add_role' )->alias(
			static function ( $slug, $label, $caps ) use ( &$added ) {
				$added[ $slug ] = array( $label, $caps );
			}
		);

		RoleRegistrar::register_module_roles();

		$definition = \FreeFormCertificate\UserDashboard\CapabilityManager::module_roles_definition();
		$this->assertSame( array_keys( $definition ), array_keys( $added ) );

		foreach ( $definition as $slug => $def ) {
			$this->assertSame( $def['label'], $added[ $slug ][0] );
			$this->assertTrue( $added[ $slug ][1]['read'], $slug . ' must carry read.' );
			foreach ( $def['caps'] as $cap ) {
				$this->assertTrue( $added[ $slug ][1][ $cap ], $slug . ' must carry ' . $cap );
			}
		}
	}

	public function test_existing_module_roles_are_topped_up_and_the_manager_gains_its_tier(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$roles = array();
		Functions\when( 'get_role' )->alias(
			function ( $slug ) use ( &$roles ) {
				if ( ! isset( $roles[ $slug ] ) ) {
					$roles[ $slug ] = $this->role_with( array( 'operator_extra' => true ) );
				}
				return $roles[ $slug ];
			}
		);
		Functions\expect( 'add_role' )->never();

		RoleRegistrar::register_module_roles();

		foreach ( \FreeFormCertificate\UserDashboard\CapabilityManager::module_roles_definition() as $slug => $def ) {
			$this->assertTrue( $roles[ $slug ]->capabilities['read'] );
			$this->assertTrue( $roles[ $slug ]->capabilities['operator_extra'], 'A manually granted cap must survive.' );
			foreach ( $def['caps'] as $cap ) {
				$this->assertArrayHasKey( $cap, $roles[ $slug ]->capabilities );
			}
		}

		$manager = $roles[ \FreeFormCertificate\UserDashboard\CapabilityManager::RECRUITMENT_MANAGER_ROLE ];
		foreach ( array( 'ffc_view_recruitment', 'ffc_call_recruitment', 'ffc_import_recruitment', 'ffc_view_recruitment_pii', 'ffc_delete_recruitment', 'ffc_view_recruitment_reasons', 'ffc_manage_recruitment_reasons' ) as $cap ) {
			$this->assertTrue( $manager->capabilities[ $cap ] ?? false, 'The 6.0.0 manager must be upgraded with ' . $cap );
		}
	}

	/**
	 * A module role and one capability its definition ships.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function shipped_pair(): array {
		foreach ( \FreeFormCertificate\UserDashboard\CapabilityManager::module_roles_definition() as $slug => $def ) {
			if ( array() !== $def['caps'] ) {
				return array( $slug, $def['caps'][0] );
			}
		}
		$this->fail( 'No module role ships a capability.' );
	}

	public function test_a_capability_removed_in_the_editor_is_not_put_back(): void {
		list( $slug, $cap ) = $this->shipped_pair();
		Functions\when( 'get_option' )->justReturn(
			array( $slug => array( 'added' => array(), 'removed' => array( $cap ) ) )
		);
		$roles = array();
		Functions\when( 'get_role' )->alias(
			function ( $s ) use ( &$roles ) {
				if ( ! isset( $roles[ $s ] ) ) {
					$roles[ $s ] = $this->role_with( array( 'read' => true ) );
				}
				return $roles[ $s ];
			}
		);

		RoleRegistrar::register_module_roles();

		$this->assertArrayNotHasKey( $cap, $roles[ $slug ]->capabilities );
		// Every other role is still topped up.
		foreach ( \FreeFormCertificate\UserDashboard\CapabilityManager::module_roles_definition() as $other => $def ) {
			if ( $other === $slug ) {
				continue;
			}
			foreach ( $def['caps'] as $c ) {
				$this->assertArrayHasKey( $c, $roles[ $other ]->capabilities );
			}
		}
	}

	public function test_a_recreated_role_keeps_the_operator_changes_both_ways(): void {
		list( $slug, $cap ) = $this->shipped_pair();
		Functions\when( 'get_option' )->justReturn(
			array( $slug => array( 'added' => array( 'ffc_extra_cap' ), 'removed' => array( $cap ) ) )
		);
		Functions\when( 'get_role' )->justReturn( null );
		$added = array();
		Functions\when( 'add_role' )->alias(
			static function ( $s, $label, $caps ) use ( &$added ) {
				$added[ $s ] = $caps;
			}
		);

		RoleRegistrar::register_module_roles();

		$this->assertArrayNotHasKey( $cap, $added[ $slug ] );
		$this->assertTrue( $added[ $slug ]['ffc_extra_cap'] );
	}

	public function test_record_override_tracks_changes_against_the_definition(): void {
		list( $slug, $cap ) = $this->shipped_pair();
		$stored = array();
		Functions\when( 'get_option' )->alias(
			static function () use ( &$stored ) {
				return $stored;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		RoleRegistrar::record_override( $slug, $cap, false );
		RoleRegistrar::record_override( $slug, 'ffc_extra_cap', true );
		$this->assertSame(
			array( $slug => array( 'added' => array( 'ffc_extra_cap' ), 'removed' => array( $cap ) ) ),
			RoleRegistrar::overrides()
		);

		// Putting a shipped cap back, or taking off one the definition never
		// had, cancels the earlier change; an empty entry leaves the option.
		RoleRegistrar::record_override( $slug, $cap, true );
		RoleRegistrar::record_override( $slug, 'ffc_extra_cap', false );
		$this->assertSame( array(), $stored );

		// Granting a shipped cap that was never removed records nothing.
		RoleRegistrar::record_override( $slug, $cap, true );
		$this->assertSame( array(), $stored );
	}

	public function test_clear_overrides_forgets_one_role_only(): void {
		$stored = array(
			'ffc_a' => array( 'added' => array( 'x' ), 'removed' => array() ),
			'ffc_b' => array( 'added' => array(), 'removed' => array( 'y' ) ),
		);
		$writes = 0;
		Functions\when( 'get_option' )->alias(
			static function () use ( &$stored ) {
				return $stored;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$stored, &$writes ) {
				$stored = $value;
				++$writes;
				return true;
			}
		);

		RoleRegistrar::clear_overrides( 'ffc_a' );
		$this->assertSame( array( 'ffc_b' ), array_keys( $stored ) );

		// A role with nothing recorded writes nothing.
		RoleRegistrar::clear_overrides( 'ffc_c' );
		$this->assertSame( 1, $writes );
	}

	public function test_overrides_drop_a_malformed_option(): void {
		Functions\when( 'get_option' )->justReturn( 'garbage' );
		$this->assertSame( array(), RoleRegistrar::overrides() );

		Functions\when( 'get_option' )->justReturn(
			array(
				'ffc_x' => array( 'added' => array( 'a', 'a', 7 ), 'removed' => 'b' ),
				3       => array(),
				'ffc_y' => 'nope',
			)
		);
		$this->assertSame(
			array( 'ffc_x' => array( 'added' => array( 'a' ), 'removed' => array() ) ),
			RoleRegistrar::overrides()
		);
	}

	public function test_relabel_rewrites_both_label_maps_for_ffc_roles_only(): void {
		$wp_roles             = Mockery::mock( \WP_Roles::class );
		$wp_roles->roles      = array(
			'ffc_end_user' => array( 'name' => 'stale' ),
			'editor'       => array( 'name' => 'Editor' ),
		);
		$wp_roles->role_names = array(
			'ffc_end_user' => 'stale',
			'editor'       => 'Editor',
		);

		RoleRegistrar::relabel_ffc_roles( $wp_roles );

		$label = RoleRegistrar::ffc_managed_role_labels()['ffc_end_user'];
		$this->assertSame( $label, $wp_roles->roles['ffc_end_user']['name'] );
		$this->assertSame( $label, $wp_roles->role_names['ffc_end_user'] );
		$this->assertSame( 'Editor', $wp_roles->roles['editor']['name'] );
		$this->assertSame( 'Editor', $wp_roles->role_names['editor'] );
	}
}
