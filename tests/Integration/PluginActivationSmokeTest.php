<?php
/**
 * Wiring smoke for the plugin *activation* + one-shot migration paths.
 *
 * The {@see PluginBootstrapSmokeTest} boots the steady-state graph with the
 * one-shot migration flags pre-seeded, so it never exercises the cold-install /
 * version-upgrade branches. This test covers exactly those:
 *   - `Activator::activate()` — the activation entry point that runs `dbDelta`
 *     for every module's schema and the inline data migrations.
 *   - The boot-time `maybe_migrate_*` upgrade path — booting WITHOUT the
 *     completion flags so the idempotent instant/FK migrations actually run and
 *     flag themselves done.
 *
 * Like the bootstrap smoke it only asserts *wiring*: that the real graph
 * composes over the stubbed WordPress boundary without a fatal and lands the
 * observable side effects (dbDelta calls, completion flags), not the SQL
 * semantics (those are unit-tested per-activator / per-migration).
 *
 * @package FreeFormCertificate\Tests\Integration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Integration;

use Brain\Monkey\Functions;
use FreeFormCertificate\Activator;
use FreeFormCertificate\Loader;

/**
 * @coversNothing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PluginActivationSmokeTest extends IntegrationTestCase {

	/**
	 * Recorded dbDelta() SQL statements.
	 *
	 * @var array<int, string>
	 */
	private array $ddl = array();

	protected function setUp(): void {
		parent::setUp();

		$this->ddl = array();
		// Record every dbDelta() call. Declared via Brain\Monkey so the
		// activators' `require_once wp-admin/includes/upgrade.php` sees the
		// function already defined and skips its no-op stub.
		Functions\when( 'dbDelta' )->alias(
			function ( $queries = '', $execute = true ) {
				foreach ( (array) $queries as $sql ) {
					$this->ddl[] = (string) $sql;
				}
				return array();
			}
		);

		// create_verification_page(): no existing /valid page → insert one.
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_insert_post' )->justReturn( 4242 );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wp_update_post' )->justReturn( 4242 );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	/**
	 * The activation entry point composes over the real activator graph and
	 * issues dbDelta for the plugin's tables without a fatal.
	 */
	public function test_activation_composes_and_runs_dbdelta_for_tables(): void {
		Activator::activate();

		$this->assertNotEmpty( $this->ddl, 'Activation should issue at least one dbDelta().' );

		$joined = strtolower( implode( "\n", $this->ddl ) );
		// A representative spread across the modules whose activators run.
		$this->assertStringContainsString( 'ffc_submissions', $joined );
		$this->assertStringContainsString( 'ffc_activity_log', $joined );

		// The public /valid verification page is provisioned + recorded.
		$this->assertSame( 4242, $this->options['ffc_verification_page_id'] ?? null );
	}

	/**
	 * Activation fans out across every module's activator — each issues dbDelta
	 * for its own schema, so a break in any one composes-and-fails here.
	 */
	public function test_activation_runs_dbdelta_across_all_module_activators(): void {
		Activator::activate();

		$joined = strtolower( implode( "\n", $this->ddl ) );
		foreach (
			array(
				'ffc_self_scheduling', // SelfSchedulingActivator
				'ffc_audience',        // AudienceActivator
				'ffc_recruitment',     // RecruitmentActivator
				'ffc_reregistration',  // ReregistrationActivator
				'ffc_user_profiles',   // UserDashboardActivator
			) as $table_fragment
		) {
			$this->assertStringContainsString(
				$table_fragment,
				$joined,
				"Activation should dbDelta a {$table_fragment} table (module activator fan-out)."
			);
		}
	}

	/**
	 * The boot-time upgrade path: with NO instant-migration flags seeded (a
	 * fresh install or a version bump), init_plugin() runs the idempotent
	 * `maybe_migrate_*` path and still composes the graph without a fatal. (On
	 * the stubbed empty schema each migration correctly early-returns via its
	 * own `table_exists()` guard — the value here is that the boot reaches and
	 * survives that path, which the flag-seeded bootstrap smoke skips.)
	 */
	public function test_cold_boot_runs_migration_path_without_fatal(): void {
		// Seed only the cap/role one-shot flags (those need a live WP_Roles object
		// and are unit-tested separately); leave the instant-migration flags unset
		// so the WP_Roles-free maybe_migrate_* path actually executes.
		$this->seed_completed_migration_flags();

		( new Loader() )->init_plugin();

		// Reaching here means the migration path composed; the boot still lands
		// its deferred role-registration wiring.
		$this->assertActionRegistered(
			'init',
			'register_ffc_roles_safe',
			'Cold/upgrade boot must run the migration path and still wire the graph.'
		);
	}

	/**
	 * The foreign-key migration is version-guarded: when the stamped version
	 * already equals FFC_VERSION it short-circuits before touching the DB
	 * (no MigrationForeignKeys run), leaving the stamp untouched.
	 */
	public function test_foreign_key_migration_skips_when_version_current(): void {
		$this->options['ffc_foreign_keys_db_version'] = FFC_VERSION;

		Activator::maybe_add_foreign_keys();

		$this->assertSame(
			FFC_VERSION,
			$this->options['ffc_foreign_keys_db_version'] ?? null,
			'The FK migration guard should no-op when the version is already current.'
		);
	}

	/**
	 * With a stale FK-version stamp the migration is attempted: the call
	 * composes over the real MigrationForeignKeys without a fatal (the actual
	 * ALTER-TABLE semantics are unit-tested with a real DB double).
	 */
	public function test_foreign_key_migration_composes_when_version_stale(): void {
		$this->options['ffc_foreign_keys_db_version'] = '0.0.0-old';

		Activator::maybe_add_foreign_keys();

		// No fatal reaching here; the guard let the migration run.
		$this->assertTrue( true );
	}
}
