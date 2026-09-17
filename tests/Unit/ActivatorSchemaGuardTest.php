<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Every activator chain `Loader` calls on `plugins_loaded` probes the schema at
 * most once per `FFC_VERSION` (#1231).
 *
 * WHAT WAS WRONG
 *
 * `table_exists()` is an uncached `SHOW TABLES LIKE`, and every
 * `add_column_if_missing()` fires a `SHOW COLUMNS` before deciding to do
 * nothing. Together, the chains then wired cost 48 DDL queries per HTTP request --
 * anonymous frontend included -- on an install where there was nothing to
 * migrate.
 *
 * WHY THE GUARD IS `FFC_VERSION` AND NOT A ONE-SHOT MARKER
 *
 * These calls exist because an in-place plugin update does NOT fire
 * `register_activation_hook`. The property to preserve is "the schema heals
 * after an update", not "it runs on every request" -- and only the per-version
 * guard preserves both halves. So this test charges BOTH directions: the second
 * call on the same version probes nothing, and a different version re-arms. A
 * test that only checked the first would pass with a one-shot boolean, which is
 * precisely the wrong implementation.
 *
 * HOW THE PROBING IS OBSERVED
 *
 * With no database, what can be observed are the calls to `$wpdb`. The double
 * below counts every query that reaches it; the guarded chain must emit none,
 * and the unguarded one must emit at least one -- otherwise the test would be
 * green over a chain that never probed anything at all, which is the
 * measurement defect CLAUDE.md records (an empty scan must never read as
 * "clean").
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingActivator
 * @covers \FreeFormCertificate\Audience\AudienceActivator
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerActivator
 * @covers \FreeFormCertificate\Recruitment\RecruitmentActivator
 * @covers \FreeFormCertificate\Reregistration\ReregistrationActivator
 * @covers \FreeFormCertificate\UserDashboard\UserDashboardActivator
 */
class ActivatorSchemaGuardTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Chain => the version option that guards it.
	 *
	 * Frozen on purpose: a new chain called from `plugins_loaded` without a guard
	 * does not appear here on its own, but the manifest test below charges that
	 * every listed option is declared in `uninstall.php`.
	 *
	 * @var array<string, array{0: class-string, 1: string, 2: string}>
	 */
	private const GUARDED_CHAINS = array(
		'self-scheduling' => array( '\FreeFormCertificate\SelfScheduling\SelfSchedulingActivator', 'maybe_migrate', 'ffc_self_scheduling_schema_version' ),
		'audience'        => array( '\FreeFormCertificate\Audience\AudienceActivator', 'maybe_migrate', 'ffc_audience_schema_version' ),
		'url-shortener'   => array( '\FreeFormCertificate\UrlShortener\UrlShortenerActivator', 'maybe_migrate', 'ffc_url_shortener_schema_version' ),
		'recruitment'     => array( '\FreeFormCertificate\Recruitment\RecruitmentActivator', 'create_tables', 'ffc_recruitment_tables_version' ),
		// The two chains #1311 added. They were not unguarded -- they were not
		// called from `plugins_loaded` AT ALL, so their schema reached a fresh
		// install and no upgraded one. Same guard, same both-directions charge.
		'reregistration'  => array( '\FreeFormCertificate\Reregistration\ReregistrationActivator', 'maybe_migrate', 'ffc_reregistration_schema_version' ),
		'user-dashboard'  => array( '\FreeFormCertificate\UserDashboard\UserDashboardActivator', 'maybe_migrate', 'ffc_user_dashboard_schema_version' ),
	);

	/**
	 * Count of queries that reached the `$wpdb` double.
	 *
	 * @var int
	 */
	private int $queries = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		foreach ( self::GUARDED_CHAINS as $chain ) {
			class_exists( $chain[0] );
		}

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->install_wpdb_double();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` that only COUNTS — it simulates no schema at all. What this test
	 * measures is whether the chain spoke to the database, not what it asked.
	 */
	private function install_wpdb_double(): void {
		$counter = function (): void {
			++$this->queries;
		};

		$wpdb = new class( $counter ) {
			// phpcs:ignore Squiz.Commenting.VariableComment.Missing
			public string $prefix = 'wp_';

			/** @var callable */
			private $counter;

			/**
			 * @param callable $counter Increments the count.
			 */
			public function __construct( callable $counter ) {
				$this->counter = $counter;
			}

			/**
			 * @param mixed ...$args Ignored.
			 * @return string
			 */
			public function prepare( ...$args ): string {
				$this->sql   = is_string( $args[0] ?? '' ) ? (string) $args[0] : '';
				$bound       = $args[1] ?? null;
				$bound       = is_array( $bound ) ? ( $bound[0] ?? null ) : $bound;
				$this->bound = is_string( $bound ) ? $bound : '';

				return $this->sql;
			}

			/**
			 * The last table bound in `prepare()`, so that a `SHOW TABLES LIKE`
			 * can answer that it exists.
			 *
			 * @var string
			 */
			private string $bound = '';

			/**
			 * @var string
			 */
			private string $sql = '';

			/**
			 * Answers the `SHOW TABLES LIKE` saying the table EXISTS.
			 *
			 * Not a detail: with the table absent, the chains would create schema
			 * and call `dbDelta()`, which only exists in wp-admin. Stubbing
			 * `dbDelta` here would define the function through Patchwork for the
			 * rest of the PROCESS, and every test running afterwards that reaches
			 * a `dbDelta()` without an expectation of its own starts failing with
			 * "is not defined nor mocked" -- the blast radius CLAUDE.md records.
			 * That is how this file broke `ActivityLogTest`.
			 *
			 * Reporting the table as existing avoids the stub AND measures the
			 * case that matters: an established install, where there is nothing
			 * to create and the 48 queries were pure waste.
			 *
			 * @param mixed ...$args Ignored.
			 * @return string|null
			 */
			public function get_var( ...$args ) {
				( $this->counter )();
				return str_contains( $this->sql, 'SHOW TABLES' ) ? $this->bound : null;
			}

			/**
			 * @param mixed ...$args Ignored.
			 * @return array<int, mixed>
			 */
			public function get_results( ...$args ): array {
				( $this->counter )();
				return array();
			}

			/**
			 * @param mixed ...$args Ignored.
			 * @return int
			 */
			public function query( ...$args ): int {
				( $this->counter )();
				return 0;
			}

			/**
			 * @return string
			 */
			public function get_charset_collate(): string {
				return '';
			}

			/**
			 * @param string $value The value.
			 * @return string
			 */
			public function esc_like( string $value ): string {
				return $value;
			}

			/**
			 * @param bool $suppress Whether to suppress.
			 * @return bool
			 */
			public function suppress_errors( bool $suppress = true ): bool {
				return ! $suppress;
			}

			/**
			 * @param string $message The message.
			 * @return void
			 */
			public function print_error( string $message = '' ): void {
			}
		};

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Runs the chain with the option at a given value and returns how many
	 * queries reached the database.
	 *
	 * @param string $class    The activator class.
	 * @param string $method   The chain's method.
	 * @param string $option   The version option.
	 * @param string $stored   The value the option already holds.
	 * @return int
	 */
	private function run_chain( string $class, string $method, string $option, string $stored ): int {
		$this->queries = 0;

		// The option under test returns the requested value; ANY OTHER returns '1'.
		// The remaining reads in these chains are one-shot markers (the
		// `AudienceEmailTokenMigration` one, for instance), and on an established
		// install -- the case this test measures -- they have already run.
		// Without that, the audience chain enters a repository whose `db()` is
		// typed `: wpdb`, and the anonymous double here does not satisfy it.
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default_value = false ) use ( $option, $stored ) {
				return $key === $option ? $stored : '1';
			}
		);
		Functions\when( 'update_option' )->justReturn( true );

		call_user_func( array( $class, $method ) );

		return $this->queries;
	}

	/**
	 * @dataProvider guarded_chains
	 *
	 * @param string $class  The activator class.
	 * @param string $method The chain's method.
	 * @param string $option The version option.
	 */
	public function test_chain_does_not_probe_the_schema_on_the_current_version( string $class, string $method, string $option ): void {
		// Self-check: without the guard the chain MUST speak to the database. If
		// it did not, a zero below would mean nothing.
		$unguarded = $this->run_chain( $class, $method, $option, 'an-older-version' );
		$this->assertGreaterThan(
			0,
			$unguarded,
			sprintf( '%s::%s() did not probe the schema even without the guard — the measurement is broken, not the chain.', $class, $method )
		);

		$guarded = $this->run_chain( $class, $method, $option, FFC_VERSION );
		$this->assertSame(
			0,
			$guarded,
			sprintf( '%s::%s() probed the schema with the option already at FFC_VERSION (%d queries).', $class, $method, $guarded )
		);
	}

	/**
	 * The guard's other direction: a different version RE-ARMS the chain.
	 *
	 * This is what separates a per-version guard from a one-shot marker — and
	 * what preserves schema healing after an in-place update, which is the reason
	 * these calls exist at all.
	 *
	 * @dataProvider guarded_chains
	 *
	 * @param string $class  The activator class.
	 * @param string $method The chain's method.
	 * @param string $option The version option.
	 */
	public function test_a_version_bump_rearms_the_chain( string $class, string $method, string $option ): void {
		$after_bump = $this->run_chain( $class, $method, $option, FFC_VERSION . '-previous' );

		$this->assertGreaterThan(
			0,
			$after_bump,
			sprintf(
				'%s::%s() did not re-arm on a different version: an in-place update would stop healing the schema.',
				$class,
				$method
			)
		);
	}

	/**
	 * Every guard option must be declared in `uninstall.php`.
	 *
	 * The `fresh-install` job compares in both directions (#994): an option the
	 * activation writes and the manifest does not declare fails CI. Charging it
	 * here too makes the failure appear in PHPUnit, which is where whoever wrote
	 * the guard is looking.
	 */
	/**
	 * Every declared table must have a declarer the runtime can reach (#1311).
	 *
	 * This is the direction the register above cannot see. `GUARDED_CHAINS` is
	 * frozen by hand, so a module that is not wired at all does not appear in it
	 * and nothing notices -- which is exactly how the two reregistration import
	 * tables shipped. They were declared by `ReregistrationActivator`, whose only
	 * caller was `Activator::activate()`, and plugin activation is not something
	 * an in-place update or an rsync deploy performs. Fresh installs had them;
	 * every upgraded install did not.
	 *
	 * **The rule is about the TABLE, not the class**, and that distinction is
	 * what lets it block at zero with no allowlist. `MigrationDynamicReregFields`
	 * declares `ffc_custom_fields` and `ffc_reregistration_submissions` and is
	 * not named in `Loader` -- correctly, since it is a one-shot migration. Both
	 * of its tables are also declared by a class the runtime reaches, so the
	 * table is creatable and nothing is wrong. A class-level rule would have had
	 * to carve it out by name; a table-level one simply does not fire.
	 *
	 * What it deliberately does NOT prove: that the reachable declarer is called
	 * on the right hook, that its version gate lets it through, or that the DDL
	 * succeeds. Those are the two tests above and the `fresh-install` job. This
	 * one answers a narrower question -- *is there any runtime path to this
	 * table at all* -- which is the one nobody was asking.
	 *
	 * Statements come from `.github/scripts/ffc-create-statements.php`, shared
	 * with `ActivatorSqlTest` and the dbDelta gate for the reason `CLAUDE.md`
	 * gives: two guards measuring the same declarations must not disagree about
	 * what one is.
	 */
	public function test_every_declared_table_has_a_declarer_the_runtime_reaches(): void {
		$root = dirname( __DIR__, 2 );
		require_once $root . '/.github/scripts/ffc-create-statements.php';

		$statements = ffc_create_statements( $root . '/includes' );
		$loader     = (string) file_get_contents( $root . '/includes/class-ffc-loader.php' );

		// Self-check: an empty scan, or one that resolved no table name, must
		// fail rather than read as clean (the #1071 / #1094 rule).
		$this->assertNotEmpty( $statements, 'No CREATE TABLE statement was found — the collector is broken.' );
		$this->assertNotSame( '', $loader, 'Could not read the Loader — the reachability half did not run.' );

		$reachable = array();
		$declared  = array();

		foreach ( $statements as $statement ) {
			$table = $statement['table'];
			if ( null === $table ) {
				continue;
			}

			$declared[ $table ] = true;

			$source = (string) file_get_contents( $statement['file'] );
			if ( 1 !== preg_match( '/^class\s+(\w+)/m', $source, $m ) ) {
				continue;
			}

			if ( false !== strpos( $loader, $m[1] ) ) {
				$reachable[ $table ] = true;
			}
		}

		$this->assertNotEmpty( $declared, 'No statement resolved to a table name — the scan collapsed.' );

		$orphans = array_keys( array_diff_key( $declared, $reachable ) );
		sort( $orphans );

		$this->assertSame(
			array(),
			$orphans,
			'These tables are declared only by classes nothing calls at runtime, so they reach a fresh install and no upgraded one. Wire the declaring activator into Loader behind a version gate, as ReregistrationActivator and UserDashboardActivator are.'
		);
	}

	public function test_every_guard_option_is_declared_in_the_uninstall_manifest(): void {
		$manifest = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertNotSame( '', $manifest, 'Could not read uninstall.php — the check did not run.' );

		foreach ( self::GUARDED_CHAINS as $chain ) {
			$this->assertStringContainsString(
				"'" . $chain[2] . "'",
				$manifest,
				sprintf( 'The option %s is not declared in uninstall.php — the fresh-install gate will fail.', $chain[2] )
			);
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function guarded_chains(): array {
		$cases = array();
		foreach ( self::GUARDED_CHAINS as $name => $chain ) {
			$cases[ $name ] = array( $chain[0], $chain[1], $chain[2] );
		}
		return $cases;
	}
}
