<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Loader;
use FreeFormCertificate\UserDashboard\RoleRegistrar;

/**
 * Tests for Loader's one-shot capability migrations (the `ensure_*` helpers)
 * and `register_ffc_roles_safe()`.
 *
 * Each helper is version-flagged: it reads an option flag, runs a
 * CapabilityMigrator migration (or a RoleRegistrar registration) once, then
 * writes the flag. The real CapabilityMigrator and RoleRegistrar run here,
 * over an in-memory role store (`get_role` / `add_role` / `wp_roles` answer
 * from `$this->roles`) and an option store (`get_option` / `update_option`
 * read and write `$this->options`), so "the migration ran" is proven by what
 * it did to a role, and "it was skipped" by the role being left untouched.
 *
 * @covers \FreeFormCertificate\Loader
 */
class LoaderMigrationsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** A role that is not FFC-managed, so only the migration under test moves its caps. */
	private const PROBE = 'ffc_probe_role';

	/** @var array<string, \WP_Role> Stored roles by slug. */
	private array $roles = array();

	/** @var array<string, mixed> Stored options by key. */
	private array $options = array();

	/** @var array<string, mixed> Every update_option() call, key => value. */
	private array $updated = array();

	/** @var list<string> Slugs passed to add_role(), in order. */
	private array $added_roles = array();

	/** @var int How many times wp_roles() was asked for the roles registry. */
	private int $wp_roles_calls = 0;

	/** @var object The registry wp_roles() returns. */
	private $wp_roles;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		// get_users() is built with the capabilities meta key; nothing else may
		// reach the database.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_blog_prefix' )->andReturn( 'wp_' );
		$GLOBALS['wpdb'] = $wpdb;

		$this->roles          = array();
		$this->options        = array();
		$this->updated        = array();
		$this->added_roles    = array();
		$this->wp_roles_calls = 0;

		$this->wp_roles             = Mockery::mock( 'WP_Roles' );
		$this->wp_roles->roles      = array();
		$this->wp_roles->role_names = array();
		$this->wp_roles->role_key   = 'wp_user_roles';

		// Constructor wiring — irrelevant here, stub so `new Loader()` is cheap.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'register_activation_hook' )->justReturn( true );
		Functions\when( 'register_deactivation_hook' )->justReturn( true );

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				$this->updated[ $key ] = $value;
				return true;
			}
		);
		// No user carries a personal grant: the role definitions are what is measured.
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_role' )->alias(
			function ( $slug ) {
				return $this->roles[ $slug ] ?? null;
			}
		);
		Functions\when( 'add_role' )->alias(
			function ( $slug, $label, $caps = array() ) {
				$role               = new \WP_Role();
				$role->capabilities = $caps;
				$this->roles[ $slug ] = $role;
				$this->added_roles[]  = $slug;
				return $role;
			}
		);
		Functions\when( 'wp_roles' )->alias(
			function () {
				++$this->wp_roles_calls;
				$this->wp_roles->roles = array_merge(
					array_fill_keys( array_keys( $this->roles ), array() ),
					$this->wp_roles->roles
				);
				return $this->wp_roles;
			}
		);
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Invoke a private Loader method via Reflection. */
	private function invoke_private( Loader $loader, string $method ) {
		$ref = new \ReflectionMethod( Loader::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $loader, array() );
	}

	/**
	 * Store a role holding the given caps.
	 *
	 * @param string              $slug Role slug.
	 * @param array<string, bool> $caps Capability map.
	 */
	private function store_role( string $slug, array $caps ): \WP_Role {
		$role                 = new \WP_Role();
		$role->capabilities   = $caps;
		$this->roles[ $slug ] = $role;
		return $role;
	}

	/**
	 * @dataProvider migration_method_provider
	 */
	public function test_ensure_migration_skips_when_flag_already_set( string $method, string $flag, string $source, string $gained, bool $source_kept ): void {
		$loader = new Loader();

		$this->options[ $flag ] = '1';
		$role                   = $this->store_role( self::PROBE, array( $source => true ) );

		$this->invoke_private( $loader, $method );

		$this->assertArrayNotHasKey( $flag, $this->updated, 'No re-write when already migrated.' );
		$this->assertSame( array( $source => true ), $role->capabilities, 'An already-migrated install must not have its roles touched again.' );
		$this->assertSame( 0, $this->wp_roles_calls, 'The migrator must not even walk the roles when the flag is set.' );
	}

	/**
	 * @dataProvider migration_method_provider
	 */
	public function test_ensure_migration_runs_and_sets_flag( string $method, string $flag, string $source, string $gained, bool $source_kept ): void {
		$loader = new Loader();

		$role = $this->store_role( self::PROBE, array( $source => true ) );

		$this->invoke_private( $loader, $method );

		$this->assertSame( '1', $this->updated[ $flag ] ?? null, 'Flag must be set after migration.' );
		$this->assertTrue( $role->capabilities[ $gained ] ?? null, "The migration did not run: the role holding '{$source}' did not gain '{$gained}'." );
		$this->assertSame( $source_kept, isset( $role->capabilities[ $source ] ), "'{$source}' is not where the migration leaves it." );
	}

	/**
	 * Method, flag, a cap the probe role holds, the cap the migration gives it
	 * for that, and whether the held cap survives (a seed keeps it, a rename
	 * replaces it).
	 *
	 * @return array<string, array{0:string,1:string,2:string,3:string,4:bool}>
	 */
	public static function migration_method_provider(): array {
		return array(
			'taxonomy'     => array( 'ensure_taxonomy_renamed', 'ffc_taxonomy_caps_renamed_v1', 'ffc_view_certificate_history', 'ffc_view_own_certificate_history', false ),
			'delete caps'  => array( 'ensure_delete_caps_granted', 'ffc_delete_caps_granted_v1', 'ffc_manage_certificates', 'ffc_delete_certificates', true ),
			'export caps'  => array( 'ensure_export_caps_granted', 'ffc_export_caps_granted_v1', 'ffc_manage_appointments', 'ffc_export_appointments', true ),
			// _v2 since #1214: the map gained `ffc_import_reregistration`, and a
			// flagged one-shot never revisits an install that recorded _v1.
			'import caps'  => array( 'ensure_import_caps_granted', 'ffc_import_caps_granted_v2', 'ffc_manage_reregistration', 'ffc_import_reregistration', true ),
			'reasons caps' => array( 'ensure_reasons_caps_wired', 'ffc_reasons_caps_wired_v1', 'ffc_view_recruitment', 'ffc_view_recruitment_reasons', true ),
			'settings'     => array( 'ensure_settings_split_caps_granted', 'ffc_settings_split_caps_v1', 'ffc_manage_settings', 'ffc_manage_settings_smtp', true ),
			'activity log' => array( 'ensure_activity_log_export_cap_granted', 'ffc_activity_log_export_cap_v1', 'ffc_view_activity_log', 'ffc_export_activity_log', true ),
			'url shortener export' => array( 'ensure_url_shortener_export_cap_granted', 'ffc_url_shortener_export_cap_v1', 'ffc_manage_url_shortener', 'ffc_export_url_shortener', true ),
			'date messages send' => array( 'ensure_date_messages_send_cap_granted', 'ffc_date_messages_send_cap_v1', 'ffc_manage_date_messages', 'ffc_send_date_messages', true ),
			'reregistration pii'   => array( 'ensure_reregistration_pii_cap_granted', 'ffc_reregistration_pii_cap_v1', 'ffc_manage_reregistration', 'ffc_view_reregistration_pii', true ),
			'audiences pii'        => array( 'ensure_audiences_pii_cap_granted', 'ffc_audiences_pii_cap_v1', 'ffc_manage_audiences', 'ffc_view_audiences_pii', true ),
			'ip pii'               => array( 'ensure_ip_pii_caps_granted', 'ffc_ip_pii_caps_v1', 'ffc_view_activity_log', 'ffc_view_activity_log_pii', true ),
			'forms api submit'     => array( 'ensure_forms_api_submit_cap_granted', 'ffc_forms_api_submit_cap_v1', 'ffc_view_forms_api', 'ffc_submit_forms_api', true ),
		);
	}

	// ==================================================================
	// ensure_role_tiers_applied() / ensure_module_role_labels_applied()
	// #1860: run from register_ffc_roles_safe() on init, after the seeds.
	// ==================================================================

	public function test_ensure_role_tiers_applied_skips_when_flag_already_set(): void {
		$loader = new Loader();

		$this->options['ffc_role_tiers_v1'] = '1';
		$readonly                           = $this->store_role( 'ffc_readonly', array( 'ffc_view_activity_log' => true ) );

		$this->invoke_private( $loader, 'ensure_role_tiers_applied' );

		$this->assertArrayNotHasKey( 'ffc_role_tiers_v1', $this->updated, 'No re-write when already aligned.' );
		$this->assertSame( array( 'ffc_view_activity_log' => true ), $readonly->capabilities, 'An aligned install must not have a tier cap removed again.' );
		$this->assertSame( array(), $this->added_roles, 'An aligned install must not have its roles re-registered by the tier step.' );
	}

	public function test_ensure_role_tiers_applied_runs_and_sets_flag(): void {
		$loader = new Loader();

		// The activity log is administrator-only (#1860): the read-only tier loses it.
		$readonly = $this->store_role( 'ffc_readonly', array( 'ffc_view_activity_log' => true ) );

		$this->invoke_private( $loader, 'ensure_role_tiers_applied' );

		$this->assertSame( '1', $this->updated['ffc_role_tiers_v1'] ?? null, 'Flag must be set after migration.' );
		$this->assertArrayNotHasKey( 'ffc_view_activity_log', $readonly->capabilities, 'The tier step did not run: the read-only role still holds an administrator-only cap.' );
		$this->assertContains( 'ffc_administrator', $this->added_roles, 'The tier step registers the module roles before aligning them.' );
	}

	public function test_ensure_module_role_labels_applied_skips_when_flag_already_set(): void {
		$loader = new Loader();

		$this->options['ffc_module_role_labels_v1'] = '1';
		$this->options['wp_user_roles']             = array(
			'ffc_end_user' => array(
				'name'         => 'A stale label',
				'capabilities' => array(),
			),
		);

		$this->invoke_private( $loader, 'ensure_module_role_labels_applied' );

		$this->assertArrayNotHasKey( 'ffc_module_role_labels_v1', $this->updated, 'No re-write when already relabelled.' );
		$this->assertArrayNotHasKey( 'wp_user_roles', $this->updated, 'A relabelled install must not have its stored role names rewritten again.' );
	}

	public function test_ensure_module_role_labels_applied_runs_and_sets_flag(): void {
		$loader = new Loader();

		$this->options['wp_user_roles'] = array(
			'ffc_end_user' => array(
				'name'         => 'A stale label',
				'capabilities' => array(),
			),
		);

		$this->invoke_private( $loader, 'ensure_module_role_labels_applied' );

		$this->assertSame( '1', $this->updated['ffc_module_role_labels_v1'] ?? null, 'Flag must be set after migration.' );
		$this->assertSame( 'FFC End User', $this->updated['wp_user_roles']['ffc_end_user']['name'] ?? null, 'The relabel did not run: the stored role name was not rewritten.' );
	}

	// ==================================================================
	// ensure_false_ffc_caps_stripped() — RoleRegistrar, not CapabilityMigrator
	// ==================================================================

	public function test_ensure_false_ffc_caps_stripped_skips_when_flag_already_set(): void {
		$loader = new Loader();

		$this->options['ffc_false_caps_stripped_v1'] = '1';
		$end_user                                    = $this->store_role( 'ffc_end_user', array( 'ffc_view_forms' => false ) );

		$this->invoke_private( $loader, 'ensure_false_ffc_caps_stripped' );

		$this->assertArrayNotHasKey( 'ffc_false_caps_stripped_v1', $this->updated, 'No re-write when already stripped.' );
		$this->assertSame( array( 'ffc_view_forms' => false ), $end_user->capabilities, 'An already-stripped install must not be stripped again.' );
	}

	public function test_ensure_false_ffc_caps_stripped_runs_and_sets_flag(): void {
		$loader = new Loader();

		$end_user = $this->store_role( 'ffc_end_user', array( 'read' => true, 'ffc_view_forms' => false ) );

		$this->invoke_private( $loader, 'ensure_false_ffc_caps_stripped' );

		$this->assertSame( '1', $this->updated['ffc_false_caps_stripped_v1'] ?? null, 'Flag must be set after stripping.' );
		$this->assertSame( array( 'read' => true ), $end_user->capabilities, 'The strip did not run: the stale denial is still on the role.' );
	}

	// ==================================================================
	// register_ffc_roles_safe()
	// ==================================================================

	public function test_register_ffc_roles_safe_registers_roles_and_relabel_hook(): void {
		$loader = new Loader();

		// Both init-time one-shots already ran on this install.
		$this->options['ffc_role_tiers_v1']         = '1';
		$this->options['ffc_module_role_labels_v1'] = '1';

		// A cap the tier step would remove: with the flag set it must survive.
		$readonly = $this->store_role( 'ffc_readonly', array( 'ffc_view_activity_log' => true ) );

		// Labels frozen in English by an early wp_roles_init.
		$this->wp_roles->roles['ffc_end_user']      = array( 'name' => 'stale' );
		$this->wp_roles->role_names['ffc_end_user'] = 'stale';

		$added = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook ) use ( &$added ) {
				$added[] = $hook;
			}
		);

		$loader->register_ffc_roles_safe();

		$this->assertContains( 'ffc_end_user', $this->added_roles, 'register_role() must create the end-user role.' );
		foreach ( array_keys( RoleRegistrar::ffc_managed_role_labels() ) as $slug ) {
			$this->assertArrayHasKey( $slug, $this->roles, "register_module_roles() did not leave '{$slug}' registered." );
		}
		// Relabel is applied directly too (not only via the wp_roles_init hook)
		// so it runs even when wp_roles_init already fired during early auth.
		$this->assertSame( 'FFC End User', $this->wp_roles->roles['ffc_end_user']['name'], 'The relabel was not applied directly.' );
		$this->assertSame( 'FFC End User', $this->wp_roles->role_names['ffc_end_user'], 'The relabel was not applied directly.' );
		// Recruitment-manager role self-heal was relocated here from
		// RecruitmentLoader (so a disabled recruitment module keeps its role).
		$this->assertTrue( $this->roles['ffc_recruitment_manager']->capabilities['ffc_manage_recruitment'] ?? null, 'The recruitment manager role must hold its cap.' );
		// The #1860 tier alignment is a flagged one-shot run from here; with
		// the flag set it must not touch the roles.
		$this->assertTrue( $readonly->capabilities['ffc_view_activity_log'] ?? null, 'The tier step ran although its flag was set.' );
		$this->assertSame( array(), $this->updated, 'No flag is rewritten when both one-shots already ran.' );

		$this->assertContains( 'wp_roles_init', $added, 'Should hook relabel_ffc_roles onto wp_roles_init.' );
	}
}
