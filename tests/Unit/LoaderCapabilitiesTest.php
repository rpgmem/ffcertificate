<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Loader;

/**
 * Tests for Loader's admin-capability seeding + orchestration helpers that
 * the constructor/asset tests don't reach: ensure_admin_capabilities(),
 * init_rest_api(), and define_admin_hooks().
 *
 * These alias/overload the concrete collaborators (CapabilityManager,
 * UserManager, RestController, …) so they run in isolated processes.
 *
 * @covers \FreeFormCertificate\Loader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LoaderCapabilitiesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Loader' );
		// Constructor wiring — irrelevant here; stub so `new Loader()` is cheap.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'register_activation_hook' )->justReturn( true );
		Functions\when( 'register_deactivation_hook' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Invoke a private Loader method via Reflection. */
	private function invoke_private( Loader $loader, string $method ) {
		$ref = new \ReflectionMethod( Loader::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $loader, array() );
	}

	// ==================================================================
	// ensure_admin_capabilities()
	// ==================================================================

	public function test_ensure_admin_capabilities_early_returns_when_version_matches(): void {
		$loader = new Loader();

		// Flag already at FFC_VERSION → whole body skipped.
		Functions\when( 'get_option' )->justReturn( FFC_VERSION );

		$get_role_called = false;
		Functions\when( 'get_role' )->alias(
			function () use ( &$get_role_called ) {
				$get_role_called = true;
				return null;
			}
		);
		$update_called = false;
		Functions\when( 'update_option' )->alias(
			function () use ( &$update_called ) {
				$update_called = true;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_admin_capabilities' );

		$this->assertFalse( $get_role_called, 'Body must be skipped when version flag already current.' );
		$this->assertFalse( $update_called, 'No option write on the early-return path.' );
	}

	public function test_ensure_admin_capabilities_cleans_overrides_without_granting(): void {
		$loader = new Loader();

		Functions\when( 'get_option' )->justReturn( 'stale-version' );

		// Use the real CapabilityManager (pure static caps source) and the
		// real UserManager (only needs to satisfy the class_exists() gate) —
		// aliasing them would drop the real ADMIN_CAPABILITIES const the
		// production code reads.
		class_exists( '\\FreeFormCertificate\\UserDashboard\\CapabilityManager' );
		class_exists( '\\FreeFormCertificate\\UserDashboard\\UserManager' );

		$all_ffc_caps = \FreeFormCertificate\UserDashboard\CapabilityManager::get_all_capabilities();

		// Administrator role — #739: no longer receives a cap grant here, so
		// add_cap() must never fire (FFC caps live on the ffc_administrator
		// role now). The role is still fetched to drive the cleanup guard.
		$granted     = array();
		$admin_role  = new class( $granted ) {
			/** @var array<int,string> */
			public $granted;
			public function __construct( &$granted ) {
				$this->granted = &$granted;
			}
			public function has_cap( string $cap ): bool {
				return false;
			}
			public function add_cap( string $cap, bool $grant = true ): void {
				$this->granted[] = $cap;
			}
		};

		// Pick two real cap slugs from the canonical set to drive the
		// user-level + role-level `=> false` cleanup branches.
		$user_denial_cap = $all_ffc_caps[0];
		$role_false_cap  = $all_ffc_caps[1] ?? $all_ffc_caps[0];

		// ffc_end_user role: carries a legacy `=> false` cap that must be stripped.
		$ffc_user_removed = array();
		$ffc_user_role    = new class( $ffc_user_removed, $role_false_cap ) {
			/** @var array<string,bool> */
			public $capabilities;
			/** @var array<int,string> */
			public $removed;
			public function __construct( &$removed, string $false_cap ) {
				$this->removed      = &$removed;
				$this->capabilities = array( $false_cap => false );
			}
			public function remove_cap( string $cap ): void {
				$this->removed[] = $cap;
			}
		};

		Functions\when( 'get_role' )->alias(
			function ( $role ) use ( $admin_role, $ffc_user_role ) {
				return 'administrator' === $role ? $admin_role : $ffc_user_role;
			}
		);

		// One admin user carrying an explicit `=> false` user-level denial.
		Functions\when( 'get_users' )->justReturn( array( 7 ) );
		$user_removed = array();
		$user         = new class( $user_removed, $user_denial_cap ) {
			/** @var array<string,bool> */
			public $caps;
			/** @var array<int,string> */
			public $removed;
			public function __construct( &$removed, string $denial_cap ) {
				$this->removed = &$removed;
				$this->caps    = array( $denial_cap => false );
			}
			public function remove_cap( string $cap ): void {
				$this->removed[] = $cap;
			}
		};
		Functions\when( 'get_userdata' )->justReturn( $user );

		$updated = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$updated ) {
				$updated[ $k ] = $v;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_admin_capabilities' );

		// #739: no caps are granted to the native administrator role anymore.
		$this->assertCount( 0, $granted );
		// user-level `=> false` denial stripped.
		$this->assertContains( $user_denial_cap, $user_removed );
		// legacy `=> false` cap stripped from ffc_end_user role.
		$this->assertContains( $role_false_cap, $ffc_user_removed );
		// Version flag written at the end.
		$this->assertSame( FFC_VERSION, $updated['ffc_admin_caps_version_v6'] ?? null );
	}

	public function test_ensure_admin_capabilities_no_admin_role_still_writes_flag(): void {
		$loader = new Loader();

		Functions\when( 'get_option' )->justReturn( 'stale' );
		Functions\when( 'get_role' )->justReturn( null );

		$updated = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$updated ) {
				$updated[ $k ] = $v;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_admin_capabilities' );

		// No admin role → grant block skipped, but flag still advanced.
		$this->assertSame( FFC_VERSION, $updated['ffc_admin_caps_version_v6'] ?? null );
	}

	// ==================================================================
	// ensure_admin_role_assigned() (#739)
	// ==================================================================

	public function test_ensure_admin_role_assigned_early_returns_when_flag_set(): void {
		$loader = new Loader();
		Functions\when( 'get_option' )->justReturn( '1' );
		$updated = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$updated ) {
				$updated[ $k ] = $v;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_admin_role_assigned' );

		$this->assertArrayNotHasKey( 'ffc_admin_role_assigned_v1', $updated );
	}

	public function test_ensure_admin_role_assigned_delegates_and_writes_flag(): void {
		$loader = new Loader();
		Functions\when( 'get_option' )->justReturn( '' );

		Mockery::mock( 'alias:\\FreeFormCertificate\\UserDashboard\\CapabilityMigrator' )
			->shouldReceive( 'migrate_admin_role_assignment' )->once()->andReturn( array() );

		$updated = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$updated ) {
				$updated[ $k ] = $v;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_admin_role_assigned' );

		$this->assertSame( '1', $updated['ffc_admin_role_assigned_v1'] ?? null );
	}

	// ==================================================================
	// ensure_rbac_caps_renamed() (#739)
	// ==================================================================

	public function test_ensure_rbac_caps_renamed_early_returns_when_flag_set(): void {
		$loader = new Loader();
		Functions\when( 'get_option' )->justReturn( '1' );
		$updated = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$updated ) {
				$updated[ $k ] = $v;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_rbac_caps_renamed' );

		$this->assertArrayNotHasKey( 'ffc_rbac_caps_renamed_v1', $updated );
	}

	public function test_ensure_rbac_caps_renamed_delegates_and_writes_flag(): void {
		$loader = new Loader();
		Functions\when( 'get_option' )->justReturn( '' );

		Mockery::mock( 'alias:\\FreeFormCertificate\\UserDashboard\\CapabilityMigrator' )
			->shouldReceive( 'migrate_rbac_cap_renames' )->once()->andReturn( array() );

		$updated = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$updated ) {
				$updated[ $k ] = $v;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_rbac_caps_renamed' );

		$this->assertSame( '1', $updated['ffc_rbac_caps_renamed_v1'] ?? null );
	}

	// ==================================================================
	// ensure_rbac_roles_renamed() (#739)
	// ==================================================================

	public function test_ensure_rbac_roles_renamed_early_returns_when_flag_set(): void {
		$loader = new Loader();
		Functions\when( 'get_option' )->justReturn( '1' );
		$updated = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$updated ) {
				$updated[ $k ] = $v;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_rbac_roles_renamed' );

		$this->assertArrayNotHasKey( 'ffc_rbac_roles_renamed_v1', $updated );
	}

	public function test_ensure_rbac_roles_renamed_delegates_and_writes_flag(): void {
		$loader = new Loader();
		Functions\when( 'get_option' )->justReturn( '' );

		Mockery::mock( 'alias:\\FreeFormCertificate\\UserDashboard\\CapabilityMigrator' )
			->shouldReceive( 'migrate_role_renames' )->once()->andReturn( array() );

		$updated = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$updated ) {
				$updated[ $k ] = $v;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_rbac_roles_renamed' );

		$this->assertSame( '1', $updated['ffc_rbac_roles_renamed_v1'] ?? null );
	}

	// ==================================================================
	// init_rest_api()
	// ==================================================================

	public function test_init_rest_api_instantiates_controller(): void {
		$loader = new Loader();

		// RestController constructor may register REST routes — overload it so
		// the `new RestController()` line runs without touching WP internals.
		$rest = Mockery::mock( 'overload:FreeFormCertificate\API\RestController' );
		$rest->shouldReceive( 'anything' )->zeroOrMoreTimes();

		// Should not throw.
		$this->invoke_private( $loader, 'init_rest_api' );
		$this->assertTrue( true );
	}

	// ==================================================================
	// define_admin_hooks()
	// ==================================================================

	public function test_define_admin_hooks_registers_cleanup_and_expiry_hooks(): void {
		$loader = new Loader();

		$added = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback = null ) use ( &$added ) {
				$added[] = $hook;
				return true;
			}
		);

		$this->invoke_private( $loader, 'define_admin_hooks' );

		$this->assertContains( 'ffcertificate_daily_cleanup_hook', $added );
		$this->assertContains( 'ffcertificate_reregistration_expire_hook', $added );
		// Three daily-cleanup callbacks + two expiry callbacks registered.
		$daily = array_filter( $added, static fn ( $h ) => 'ffcertificate_daily_cleanup_hook' === $h );
		$this->assertCount( 3, $daily, 'submission cleanup + CSV reap + schedule-exception reap.' );
	}

	// ==================================================================
	// init_plugin() — the full orchestration path (frontend context)
	// ==================================================================

	public function test_init_plugin_wires_the_full_module_graph(): void {
		// Overload every collaborator init_plugin news up / calls statically so
		// the orchestration runs end-to-end without touching WP or the real
		// module internals. Frontend context (is_admin() === false) skips the
		// AdminLoader branch.
		Functions\when( 'is_admin' )->justReturn( false );

		// Activator migrations (all one-shot statics).
		$activator = Mockery::mock( 'alias:FreeFormCertificate\Activator' );
		$activator->shouldReceive( 'maybe_add_columns' )->once();
		$activator->shouldReceive( 'maybe_add_perf_indexes' )->once();
		$activator->shouldReceive( 'maybe_migrate_submission_date_to_unix' )->once();
		$activator->shouldReceive( 'maybe_migrate_submitted_at_to_unix' )->once();
		$activator->shouldReceive( 'maybe_migrate_sibling_instants_to_unix' )->once();

		Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimitActivator' )
			->shouldReceive( 'maybe_create_tables' )->zeroOrMoreTimes();
		Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' )
			->shouldReceive( 'maybe_create_table' )->zeroOrMoreTimes();
		Mockery::mock( 'alias:FreeFormCertificate\SelfScheduling\SelfSchedulingActivator' )
			->shouldReceive( 'maybe_migrate' )->zeroOrMoreTimes();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceActivator' )
			->shouldReceive( 'maybe_migrate' )->zeroOrMoreTimes();
		Mockery::mock( 'alias:FreeFormCertificate\UrlShortener\UrlShortenerActivator' )
			->shouldReceive( 'maybe_migrate' )->zeroOrMoreTimes();

		// Shared runtime classes.
		Mockery::mock( 'overload:FreeFormCertificate\Submissions\SubmissionHandler' );
		Mockery::mock( 'overload:FreeFormCertificate\Integrations\EmailHandler' );
		Mockery::mock( 'overload:FreeFormCertificate\Admin\CPT' );
		Mockery::mock( 'overload:FreeFormCertificate\Frontend\Frontend' );

		// Fire-and-forget module bootstraps.
		Mockery::mock( 'alias:FreeFormCertificate\Shortcodes\DashboardShortcode' )
			->shouldReceive( 'init' )->zeroOrMoreTimes();
		Mockery::mock( 'overload:FreeFormCertificate\Reregistration\ReregistrationLoader' )
			->shouldReceive( 'init' )->zeroOrMoreTimes();
		Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\AccessControl' )
			->shouldReceive( 'init' )->zeroOrMoreTimes();
		Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserCleanup' )
			->shouldReceive( 'init' )->zeroOrMoreTimes();
		Mockery::mock( 'alias:FreeFormCertificate\Privacy\PrivacyHandler' )
			->shouldReceive( 'init' )->zeroOrMoreTimes();
		Mockery::mock( 'overload:FreeFormCertificate\SelfScheduling\SelfSchedulingLoader' )
			->shouldReceive( 'init' )->zeroOrMoreTimes();

		// AudienceLoader is a singleton — alias the static, return a mock whose
		// init() is a no-op.
		$audience_instance = Mockery::mock();
		$audience_instance->shouldReceive( 'init' )->zeroOrMoreTimes();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceLoader' )
			->shouldReceive( 'get_instance' )->andReturn( $audience_instance );

		Mockery::mock( 'overload:FreeFormCertificate\UrlShortener\UrlShortenerLoader' )
			->shouldReceive( 'init' )->zeroOrMoreTimes();
		Mockery::mock( 'overload:FreeFormCertificate\Recruitment\RecruitmentLoader' )
			->shouldReceive( 'init' )->zeroOrMoreTimes();
		Mockery::mock( 'overload:FreeFormCertificate\Core\ActivityLogSubscriber' );

		// Cron scheduling — pretend nothing scheduled so wp_schedule_event fires.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

		// The ensure_* helpers: flags already set → migrations skipped, no
		// CapabilityMigrator calls needed. ensure_admin_capabilities early-
		// returns because the version flag matches FFC_VERSION.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return 'ffc_admin_caps_version_v6' === $key ? FFC_VERSION : '1';
			}
		);
		Functions\when( 'update_option' )->justReturn( true );

		// define_admin_hooks() + init() constructor + init hooks all funnel
		// through add_action — accept everything.
		Functions\when( 'add_action' )->justReturn( true );

		// init_rest_api() news up RestController.
		Mockery::mock( 'overload:FreeFormCertificate\API\RestController' );

		$loader = new Loader();
		$loader->init_plugin();

		// If we got here the entire graph wired without throwing.
		$this->assertTrue( true );
	}
}
