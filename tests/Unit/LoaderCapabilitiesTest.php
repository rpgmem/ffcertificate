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
 * Every test runs the real collaborators (CapabilityMigrator, RoleRegistrar,
 * RestController and, for init_plugin(), the whole module graph) over stubbed
 * WordPress functions and a `$wpdb` double, in the shared process (#2091).
 *
 * @covers \FreeFormCertificate\Loader
 * @preserveGlobalState disabled
 */
class LoaderCapabilitiesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed The `$wpdb` global before the test, restored in tearDown. */
	private $previous_wpdb;

	/** @var bool Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $this->had_wpdb ? $GLOBALS['wpdb'] : null;
		class_exists( '\\FreeFormCertificate\\Loader' );
		// Constructor wiring — irrelevant here; stub so `new Loader()` is cheap.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'register_activation_hook' )->justReturn( true );
		// ReregistrationLoader keeps its per-request group out of a persistent cache (#2242).
		Functions\when( 'wp_cache_add_non_persistent_groups' )->justReturn( null );
		Functions\when( 'register_deactivation_hook' )->justReturn( true );
	}

	protected function tearDown(): void {
		self::reset_audience_loader();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Drop the AudienceLoader singleton a boot builds, so none outlives a test. */
	private static function reset_audience_loader(): void {
		$instance = new \ReflectionProperty( \FreeFormCertificate\Audience\AudienceLoader::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Install a `$wpdb` double that answers only get_blog_prefix(), the one
	 * call the CapabilityMigrator user scan makes; any query fails the test.
	 */
	private function install_blog_prefix_wpdb(): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_blog_prefix' )->andReturn( 'wp_' );
		$GLOBALS['wpdb'] = $wpdb;
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

		$this->assertArrayNotHasKey( 'ffc_admin_role_assigned_v2', $updated );
	}

	public function test_ensure_admin_role_assigned_delegates_and_writes_flag(): void {
		$loader = new Loader();
		Functions\when( 'get_option' )->justReturn( '' );

		// The real CapabilityMigrator::migrate_admin_role_assignment(): the
		// `ffc_administrator` role exists, so it is not re-registered; one
		// administrator without it is back-filled; no native administrator
		// role to strip.
		$ffc_admin_role = new \WP_Role();
		Functions\when( 'get_role' )->alias(
			static function ( $slug ) use ( $ffc_admin_role ) {
				return 'ffc_administrator' === $slug ? $ffc_admin_role : null;
			}
		);
		$admin        = new \WP_User( 5 );
		$admin->roles = array( 'administrator' );
		$queried      = array();
		Functions\when( 'get_users' )->alias(
			static function ( $args ) use ( &$queried ) {
				$queried[] = $args;
				return array( 5 );
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) use ( $admin ) {
				return 5 === (int) $id ? $admin : false;
			}
		);

		$updated = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$updated ) {
				$updated[ $k ] = $v;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_admin_role_assigned' );

		// The migration ran: it asked for the administrators and assigned them
		// the FFC role.
		$this->assertSame( 'administrator', $queried[0]['role'] ?? null );
		$this->assertContains( 'ffc_administrator', $admin->roles );

		// v2 (#1302): the flag was bumped so the back-fill re-runs once, for the
		// administrators created between the v1 run and the continuous sync
		// that now keeps `ffc_administrator` on the role.
		$this->assertSame( '1', $updated['ffc_admin_role_assigned_v2'] ?? null );
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
		$this->install_blog_prefix_wpdb();

		// The real CapabilityMigrator::migrate_rbac_cap_renames(), over one
		// user and one role that each still carry the old slug.
		$renames  = \FreeFormCertificate\UserDashboard\CapabilityMigrator::rbac_cap_renames();
		$old      = (string) array_key_first( $renames );
		$new      = $renames[ $old ];
		$user     = new \WP_User( 8 );
		$user->caps = array( $old => true );
		Functions\when( 'get_users' )->justReturn( array( 8 ) );
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) use ( $user ) {
				return 8 === (int) $id ? $user : false;
			}
		);
		$role               = new \WP_Role();
		$role->capabilities = array( $old => true );
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'roles' => array( 'ffc_custom' => array() ) ) );
		Functions\when( 'get_role' )->alias(
			static function ( $slug ) use ( $role ) {
				return 'ffc_custom' === $slug ? $role : null;
			}
		);

		$updated = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$updated ) {
				$updated[ $k ] = $v;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_rbac_caps_renamed' );

		// The migration ran: the old slug moved to the new one on both.
		$this->assertSame( array( $new => true ), $user->caps );
		$this->assertSame( array( $new => true ), $role->capabilities );
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
		$this->install_blog_prefix_wpdb();

		// The real CapabilityMigrator::migrate_role_renames(): no role exists
		// yet, so RoleRegistrar creates them; one user on an old role slug.
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'add_role' )->justReturn( null );
		$removed = array();
		Functions\when( 'remove_role' )->alias(
			static function ( $slug ) use ( &$removed ) {
				$removed[] = $slug;
			}
		);
		$renames     = \FreeFormCertificate\UserDashboard\CapabilityMigrator::role_renames();
		$old         = (string) array_key_first( $renames );
		$user        = new \WP_User( 9 );
		$user->roles = array( $old );
		Functions\when( 'get_users' )->justReturn( array( 9 ) );
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) use ( $user ) {
				return 9 === (int) $id ? $user : false;
			}
		);

		$updated = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$updated ) {
				$updated[ $k ] = $v;
				return true;
			}
		);

		$this->invoke_private( $loader, 'ensure_rbac_roles_renamed' );

		// The migration ran: the user moved to the new slug and every old
		// role was dropped.
		$this->assertSame( array( $renames[ $old ] ), $user->roles );
		$this->assertSame( array_keys( $renames ), $removed );
		$this->assertSame( '1', $updated['ffc_rbac_roles_renamed_v1'] ?? null );
	}

	// ==================================================================
	// init_rest_api()
	// ==================================================================

	public function test_init_rest_api_instantiates_controller(): void {
		$loader = new Loader();

		// The real RestController builds its two repositories over `$wpdb`
		// (no query at construction) and registers its hooks.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;

		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[] = array( $hook, $callback );
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[] = array( $hook, $callback );
				return true;
			}
		);

		$this->invoke_private( $loader, 'init_rest_api' );

		$registered = array();
		foreach ( $hooks as $hook ) {
			if ( is_array( $hook[1] ) && $hook[1][0] instanceof \FreeFormCertificate\API\RestController ) {
				$registered[] = $hook[0] . ':' . $hook[1][1];
			}
		}
		$this->assertContains( 'rest_api_init:register_routes', $registered, 'A RestController was built and wired its routes.' );
	}

	// ==================================================================
	// define_admin_hooks()
	// ==================================================================

	public function test_define_admin_hooks_registers_cleanup_and_expiry_hooks(): void {
		$loader = new Loader();

		// Every module toggle defaults ON (empty ffc_settings), so the module
		// cron callbacks are registered.
		Functions\when( 'get_option' )->justReturn( array() );

		$added = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback = null ) use ( &$added ) {
				$added[] = $hook;
				return true;
			}
		);

		$this->invoke_private( $loader, 'define_admin_hooks' );

		$this->assertContains( 'ffc_daily_cleanup', $added );
		$this->assertContains( 'ffc_reregistration_expire', $added );
		$this->assertContains( \FreeFormCertificate\SelfScheduling\AppointmentReminderScanner::CRON_HOOK, $added );

		// The expired-tickets scan (#1234). It used to live in `AdminLoader`,
		// which is only built inside `if ( is_admin() )` -- and `wp-cron.php`
		// defines `DOING_CRON`, never `WP_ADMIN`, so the callback was never
		// registered in the context that fires the event. This is the method
		// that runs on EVERY request, which is the fix.
		$this->assertContains(
			\FreeFormCertificate\Admin\ExpiredTicketsCleanup::CRON_HOOK,
			$added,
			'Without this the daily event fires with no callback, as it did since it was written.'
		);

		// Three daily-cleanup callbacks + two expiry callbacks registered.
		$daily = array_filter( $added, static fn ( $h ) => 'ffc_daily_cleanup' === $h );
		$this->assertCount( 3, $daily, 'submission cleanup + CSV reap + schedule-exception reap.' );
	}

	public function test_the_daily_reregistration_run_seeds_late_joiners_before_it_mails(): void {
		$loader = new Loader();
		Functions\when( 'get_option' )->justReturn( array() );

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback = null ) use ( &$callbacks ) {
				if ( 'ffc_reregistration_expire' === $hook && is_array( $callback ) ) {
					$callbacks[] = $callback[1];
				}
				return true;
			}
		);

		$this->invoke_private( $loader, 'define_admin_hooks' );

		// Expire first, so a closed campaign is not seeded; seed before the
		// reminders and the invitations read the table, so a late joiner is
		// reached the same day (#2242).
		$this->assertSame(
			array( 'expire_overdue', 'seed_active_campaigns', 'run_automated_reminders', 'run_pending_invitations' ),
			$callbacks
		);
	}

	public function test_define_admin_hooks_skips_disabled_module_crons(): void {
		$loader = new Loader();

		// Reregistration + Self-Scheduling toggled OFF: their cron callbacks must
		// not be registered, so a disabled module stops its scheduled work.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( \FreeFormCertificate\Settings\SettingsReader::OPTION_KEY === $key ) {
					return array(
						'module_reregistration_enabled'  => 0,
						'module_self_scheduling_enabled' => 0,
					);
				}
				return $default;
			}
		);

		$added = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback = null ) use ( &$added ) {
				$added[] = $hook;
				return true;
			}
		);

		$this->invoke_private( $loader, 'define_admin_hooks' );

		// The core daily cleanup stays wired; the disabled modules' crons don't.
		$this->assertContains( 'ffc_daily_cleanup', $added );
		$this->assertNotContains( 'ffc_reregistration_expire', $added );
		$this->assertNotContains( \FreeFormCertificate\SelfScheduling\AppointmentReminderScanner::CRON_HOOK, $added );
		// Certificates is still on in this scenario, so the scan of
		// tickets is still composed -- which separates the MODULE gate
		// (deliberate) from the CONTEXT defect #1234 fixed.
		$this->assertContains( \FreeFormCertificate\Admin\ExpiredTicketsCleanup::CRON_HOOK, $added );
	}

	/**
	 * Certificates off for the ticket scan -- the deliberate gate.
	 *
	 * Separate from the test above on purpose: there the module is on and what it
	 * proves is that registration HAPPENS outside `is_admin()`; here what is proved is
	 * that the module gate still holds after the move. Conflating the two would
	 * make #1234's fix look as though it had switched the gate off.
	 */
	public function test_define_admin_hooks_skips_the_expired_tickets_cron_when_certificates_disabled(): void {
		$loader = new Loader();

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( \FreeFormCertificate\Settings\SettingsReader::OPTION_KEY === $key ) {
					return array( 'module_certificates_enabled' => 0 );
				}
				return $default;
			}
		);

		$added = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback = null ) use ( &$added ) {
				$added[] = $hook;
				return true;
			}
		);

		$this->invoke_private( $loader, 'define_admin_hooks' );

		$this->assertNotContains( \FreeFormCertificate\Admin\ExpiredTicketsCleanup::CRON_HOOK, $added );
		$this->assertContains( 'ffc_daily_cleanup', $added, 'The core daily cleanup does not depend on the module.' );
	}

	// ==================================================================
	// init_plugin() — the full orchestration path (frontend context)
	// ==================================================================

	/**
	 * Hooks registered during a boot, as `hook|Class::method` for every
	 * array callback (the class of an object, or the class string).
	 *
	 * @var array<int, string>
	 */
	private array $boot_hooks = array();

	/**
	 * Option names read during a boot, in order.
	 *
	 * @var array<int, string>
	 */
	private array $option_reads = array();

	/**
	 * Hooks `wp_next_scheduled()` was asked about during a boot.
	 *
	 * @var array<int, string>
	 */
	private array $cron_probes = array();

	/**
	 * Every SQL string the boot's `$wpdb` ran or prepared, the table probes
	 * excepted.
	 *
	 * @var array<int, string>
	 */
	private array $boot_queries = array();

	/**
	 * Boot the REAL module graph over a recorded WordPress boundary.
	 *
	 * The install is established and current: every table answers the
	 * existence probe, every schema version the activators gate on is the
	 * running one, and every one-shot `_vN` flag reads as done -- so each
	 * activator and migration runs its gate and stops there, which is what
	 * lets `boot_queries` be asserted empty. What a test proves is read off the
	 * recordings: the options read (an activator's gate is its only trace on a
	 * current install), the cron probes, and the hooks each constructed class
	 * registered.
	 *
	 * @param array<string, mixed> $ffc_settings The stored `ffc_settings`.
	 */
	private function boot( array $ffc_settings ): void {
		$this->boot_hooks   = array();
		$this->option_reads = array();
		$this->cron_probes  = array();
		$this->boot_queries = array();

		// AudienceLoader is a singleton; a boot must build its own.
		self::reset_audience_loader();

		$queries         = &$this->boot_queries;
		$GLOBALS['wpdb'] = new class( $queries ) extends \wpdb {
			/** @var array<int, string> */
			public $ran;

			/**
			 * @param array<int, string> $ran Where every query is recorded.
			 */
			public function __construct( array &$ran ) {
				$this->prefix = 'wp_';
				$this->ran    = &$ran;
			}

			/**
			 * Substitutes the table name into the existence probe only.
			 *
			 * @param string $query   Query with placeholders.
			 * @param mixed  ...$args Placeholder values.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				if ( 'SHOW TABLES LIKE %s' === $query && isset( $args[0] ) ) {
					return "SHOW TABLES LIKE '" . $args[0] . "'";
				}
				$this->ran[] = (string) $query;
				return (string) $query;
			}

			/**
			 * Answers the existence probe with the table name.
			 *
			 * @param string|null $query Query.
			 * @param int         $x     Column offset.
			 * @param int         $y     Row offset.
			 * @return mixed
			 */
			public function get_var( $query = null, $x = 0, $y = 0 ) {
				if ( is_string( $query ) && 1 === preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $query, $m ) ) {
					return $m[1];
				}
				$this->ran[] = (string) $query;
				return null;
			}

			/**
			 * @param string|null $query  Query.
			 * @param string      $output Output shape.
			 * @param int         $y      Row offset.
			 * @return mixed
			 */
			public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
				$this->ran[] = (string) $query;
				return null;
			}

			/**
			 * @param string|null $query Query.
			 * @param int         $x     Column offset.
			 * @return array<int, mixed>
			 */
			public function get_col( $query = null, $x = 0 ) {
				$this->ran[] = (string) $query;
				return array();
			}

			/**
			 * @param string|null $query  Query.
			 * @param string      $output Output shape.
			 * @return array<int, mixed>
			 */
			public function get_results( $query = null, $output = OBJECT ) {
				$this->ran[] = (string) $query;
				return array();
			}

			/**
			 * @param string $query Query.
			 * @return int
			 */
			public function query( $query ) {
				$this->ran[] = (string) $query;
				return 0;
			}
		};

		$options = array(
			\FreeFormCertificate\Settings\SettingsReader::OPTION_KEY => $ffc_settings,
			'ffc_admin_caps_version_v6'                             => FFC_VERSION,
			'ffc_submissions_db_version'                            => ( new \ReflectionMethod( \FreeFormCertificate\Activator::class, 'submissions_schema_fingerprint' ) )->invoke( null ),
			'ffc_perf_indexes_db_version'                           => FFC_VERSION,
			'ffc_submission_date_unix_migrated'                     => '1',
			'ffc_submitted_at_unix_migrated'                        => '1',
			'ffc_sibling_instants_unix_migrated'                    => '1',
			'ffc_rate_limit_db_version'                             => \FreeFormCertificate\Security\RateLimitActivator::DB_VERSION,
			'ffc_activity_log_db_version'                           => ( new \ReflectionClassConstant( \FreeFormCertificate\Core\ActivityLog::class, 'DB_VERSION' ) )->getValue(),
			'ffc_bulk_issue_db_version'                             => \FreeFormCertificate\Submissions\BulkIssueActivator::DB_VERSION,
			'ffc_reregistration_schema_version'                     => FFC_VERSION,
			'ffc_user_dashboard_schema_version'                     => FFC_VERSION,
			'ffc_recruitment_tables_version'                        => FFC_VERSION,
			\FreeFormCertificate\DateMessages\DateMessagesActivator::SCHEMA_OPTION => FFC_VERSION,
			\FreeFormCertificate\DateMessages\DateMessagesActivator::AUDIENCE_IDS_OPTION => '1',
			\FreeFormCertificate\DateMessages\DateMessagesActivator::APPEARANCE_OPTION => '1',
			\FreeFormCertificate\DateMessages\DateMessagesActivator::ANNOUNCEMENTS_OPTION => '1',
			'ffc_self_scheduling_schema_version'                    => FFC_VERSION,
			\FreeFormCertificate\Audience\AudienceActivator::SCHEMA_REVISION_OPTION => \FreeFormCertificate\Audience\AudienceActivator::SCHEMA_REVISION,
			'ffc_audience_schema_version'                           => FFC_VERSION,
			'ffc_url_shortener_schema_version'                      => FFC_VERSION,
			// Every numbered recruitment step already applied.
			'ffc_recruitment_schema_version'                        => PHP_INT_MAX,
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $options ) {
				$this->option_reads[] = (string) $name;
				if ( array_key_exists( $name, $options ) ) {
					return $options[ $name ];
				}
				// Every one-shot migration flag reads as already done.
				return 1 === preg_match( '/_v\d+$/', (string) $name ) ? '1' : $default;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );

		$record = function ( $hook, $callback = null ) {
			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$class              = is_object( $callback[0] ) ? get_class( $callback[0] ) : ltrim( (string) $callback[0], '\\' );
				$this->boot_hooks[] = $hook . '|' . $class . '::' . $callback[1];
			}
			return true;
		};
		Functions\when( 'add_action' )->alias( $record );
		Functions\when( 'add_filter' )->alias( $record );
		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook ) {
				$this->cron_probes[] = (string) $hook;
				return false;
			}
		);
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'wp_get_schedules' )->justReturn( array() );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'register_post_type' )->justReturn( null );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );
		Functions\stubs( array( '__', '_x', 'esc_html__', 'esc_html', 'esc_attr', 'esc_url', 'sanitize_key', 'sanitize_text_field', 'wp_unslash', 'trailingslashit', 'untrailingslashit' ) );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ) );

		( new Loader() )->init_plugin();
	}

	/**
	 * One hook registered by each module bootstrap, `hook|Class::method`.
	 *
	 * @return array<string, string>
	 */
	private static function module_signatures(): array {
		return array(
			'certificates (CPT)'      => 'init|FreeFormCertificate\Admin\CPT::register_form_cpt',
			'certificates (Frontend)' => 'wp_enqueue_scripts|FreeFormCertificate\Frontend\Frontend::frontend_assets',
			'reregistration'          => 'wp_ajax_ffc_get_reregistration_form|FreeFormCertificate\Reregistration\ReregistrationFrontend::ajax_get_form',
			'self_scheduling'         => 'init|FreeFormCertificate\SelfScheduling\SelfSchedulingCPT::register_calendar_cpt',
			'audiences'               => 'init|FreeFormCertificate\Audience\AudienceLoader::register_capabilities',
			'url_shortener'           => 'parse_request|FreeFormCertificate\UrlShortener\UrlShortenerLoader::intercept_short_url',
			'recruitment'             => 'init|FreeFormCertificate\Recruitment\RecruitmentLoader::register_shortcode',
			'date_messages (cron)'    => \FreeFormCertificate\DateMessages\DateMessagesCron::CRON_HOOK . '|FreeFormCertificate\DateMessages\Runner::run_daily',
		);
	}

	public function test_init_plugin_wires_the_full_module_graph(): void {
		// Every module on, Date Messages included (it defaults off), so each
		// module bootstrap is constructed for real and leaves its signature.
		$this->boot( array( 'module_date_messages_enabled' => 1 ) );

		foreach ( self::module_signatures() as $module => $signature ) {
			$this->assertContains( $signature, $this->boot_hooks, "The {$module} bootstrap was not wired." );
		}

		// The schema heals and one-shot migrations ran their gates. On a
		// current install the option read IS the call: deleting a line from
		// init_plugin() drops its read, which is what the five Activator
		// statics and the #1311 / #1538 wiring are pinned by.
		foreach ( array(
			'ffc_submissions_db_version',
			'ffc_perf_indexes_db_version',
			'ffc_submission_date_unix_migrated',
			'ffc_submitted_at_unix_migrated',
			'ffc_sibling_instants_unix_migrated',
			'ffc_reregistration_schema_version',
			'ffc_user_dashboard_schema_version',
			'ffc_recruitment_tables_version',
			'ffc_recruitment_schema_version',
			\FreeFormCertificate\DateMessages\DateMessagesActivator::SCHEMA_OPTION,
		) as $gate ) {
			$this->assertContains( $gate, $this->option_reads, "{$gate} was never read: its migration is not wired." );
		}
		$this->assertContains( \FreeFormCertificate\DateMessages\DateMessagesCron::CRON_HOOK, $this->cron_probes, 'The date-messages daily schedule is not wired.' );

		// The REST controller is built last.
		$this->assertContains( 'rest_api_init|FreeFormCertificate\API\RestController::register_routes', $this->boot_hooks );
		$this->assertSame( array(), $this->boot_queries, 'A current install must boot without a query.' );
	}

	// ==================================================================
	// init_plugin() — module toggles skip disabled bootstraps
	// ==================================================================

	public function test_init_plugin_skips_disabled_module_bootstraps(): void {
		// Every feature module toggled OFF in `ffc_settings`: init_plugin() must
		// skip each gated bootstrap. The orchestrator-level lifecycle (activator
		// schema self-heal, the date-messages schedule) still runs regardless of
		// the toggles (#1538).
		$this->boot(
			array(
				'module_certificates_enabled'    => 0,
				'module_audiences_enabled'       => 0,
				'module_self_scheduling_enabled' => 0,
				'module_reregistration_enabled'  => 0,
				'module_recruitment_enabled'     => 0,
				'module_date_messages_enabled'   => 0,
				'url_shortener_enabled'          => 0,
			)
		);

		foreach ( self::module_signatures() as $module => $signature ) {
			$this->assertNotContains( $signature, $this->boot_hooks, "The disabled {$module} bootstrap still ran." );
		}

		// Schema heal and the daily schedule are not behind the toggles.
		$this->assertContains( 'ffc_recruitment_tables_version', $this->option_reads, 'Recruitment table creation must run with the module off.' );
		$this->assertContains( 'ffc_recruitment_schema_version', $this->option_reads, 'Recruitment schema migration must run with the module off.' );
		$this->assertContains( \FreeFormCertificate\DateMessages\DateMessagesActivator::SCHEMA_OPTION, $this->option_reads, 'Date-messages schema heal must run with the module off.' );
		$this->assertContains( \FreeFormCertificate\DateMessages\DateMessagesCron::CRON_HOOK, $this->cron_probes, 'The date-messages schedule must run with the module off.' );
		$this->assertSame( array(), $this->boot_queries );
	}
}
