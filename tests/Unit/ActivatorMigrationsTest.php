<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Activator;

/**
 * Tests for Activator's #249 instant-column migrations + perf-index / auth-code
 * upgrade helpers. These are idempotent, option-flagged schema routines guarded
 * by table/column existence checks (DatabaseHelperTrait over $wpdb). With the
 * default mocks (tables present, columns absent) each routine runs its
 * early-completion path; flag-set variants exercise the short-circuits.
 *
 * @covers \FreeFormCertificate\Activator
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ActivatorMigrationsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Activator' );

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function () {
				$args = func_get_args();
				$sql  = (string) $args[0];
				for ( $i = 1; $i < count( $args ); $i++ ) {
					$val = is_string( $args[ $i ] ) ? "'{$args[$i]}'" : $args[ $i ];
					$sql = preg_replace( '/%[sidf]/', (string) $val, $sql, 1 );
				}
				return $sql;
			}
		)->byDefault();
		// table_exists() → SHOW TABLES LIKE 'x' returns the name (table present).
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $query ) {
				if ( preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", (string) $query, $m ) ) {
					return $m[1];
				}
				return null;
			}
		)->byDefault();
		// column_exists()/index_exists()/SHOW INDEX → empty (column/index absent).
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'update' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static function ( $v ) {
				return (string) $v;
			}
		)->byDefault();
		$wpdb->shouldReceive( 'suppress_errors' )->andReturn( false )->byDefault();
		$this->wpdb = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'absint' )->alias(
			static function ( $v ) {
				return abs( (int) $v );
			}
		);

		Mockery::mock( 'alias:FreeFormCertificate\Repositories\SubmissionRepository' )
			->shouldReceive( 'get_submissions_table' )->andReturn( 'wp_ffc_submissions' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Record every option this test's subject writes.
	 *
	 * `Functions\when()->alias()` rather than `Functions\expect()`: setUp
	 * already defines `update_option` with `when()`, and redefining the same
	 * function through the other API is not a supported mix.
	 *
	 * @param array<string, mixed> $written Filled by reference.
	 */
	private function capture_option_writes( array &$written ): void {
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value = null, $autoload = null ) use ( &$written ) {
				$written[ (string) $key ] = $value;
				return true;
			}
		);
	}

	/**
	 * Record every SQL statement the subject sends to $wpdb::query().
	 *
	 * @param array<int, string> $statements Filled by reference.
	 */
	private function capture_queries( array &$statements ): void {
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $sql ) use ( &$statements ) {
				$statements[] = (string) $sql;
				return 1;
			}
		);
	}

	private function invoke_private( string $method, array $args = array() ) {
		$m = new \ReflectionMethod( Activator::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	// ───────────── maybe_add_perf_indexes ─────────────

	public function test_perf_indexes_runs_when_version_differs(): void {
		// get_option('ffc_perf_indexes_db_version') → '' (≠ FFC_VERSION) → run.
		// The contract is a pair: index each table that exists, then pin the
		// version so the next activation short-circuits. Pinning is what makes
		// the sibling "skips when version matches" test mean anything, so it is
		// asserted here rather than assumed.
		$written    = array();
		$statements = array();
		$this->capture_option_writes( $written );
		$this->capture_queries( $statements );

		Activator::maybe_add_perf_indexes();

		$indexed = array();
		foreach ( $statements as $sql ) {
			if ( preg_match( "/ALTER TABLE '?([a-z_]+)'? ADD INDEX/", $sql, $m ) ) {
				$indexed[] = $m[1];
			}
		}
		$this->assertSame(
			array( 'wp_ffc_recruitment_candidate', 'wp_ffc_recruitment_notice', 'wp_ffc_reregistration_submissions' ),
			$indexed,
			'Every table that carries idx_created should have been indexed.'
		);
		$this->assertSame(
			FFC_VERSION,
			$written['ffc_perf_indexes_db_version'] ?? null,
			'The version marker must be pinned, or this runs again on every activation.'
		);
	}

	public function test_perf_indexes_skips_when_version_matches(): void {
		Functions\when( 'get_option' )->justReturn( FFC_VERSION );
		$this->wpdb->shouldNotReceive( 'get_var' );
		Activator::maybe_add_perf_indexes();
		$this->assertTrue( true );
	}

	// ───────────── maybe_migrate_submission_date_to_unix ─────────────

	public function test_migrate_submission_date_runs_to_completion(): void {
		// Default: table present, columns absent → fresh-table completion path.
		// Reaching completion means writing the one-shot flag; without it the
		// migration re-runs on every activation forever.
		$written = array();
		$this->capture_option_writes( $written );

		Activator::maybe_migrate_submission_date_to_unix();

		$this->assertSame( '1', $written['ffc_submission_date_unix_migrated'] ?? null );
	}

	public function test_migrate_submission_date_skips_when_flag_set(): void {
		Functions\when( 'get_option' )->justReturn( '1' );
		$this->wpdb->shouldNotReceive( 'get_var' );
		Activator::maybe_migrate_submission_date_to_unix();
		$this->assertTrue( true );
	}

	public function test_migrate_submission_date_full_destructive_path(): void {
		// old column present (datetime), new staging column absent, no rows to
		// backfill → drops indexes/column, renames staging, recreates indexes.
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $query ) {
				$q = (string) $query;
				if ( false !== strpos( $q, 'SHOW COLUMNS' ) && false !== strpos( $q, 'submission_date_ts' ) ) {
					return array(); // staging column absent
				}
				if ( false !== strpos( $q, 'SHOW COLUMNS' ) && false !== strpos( $q, 'submission_date' ) ) {
					return array( (object) array( 'Field' => 'submission_date', 'Type' => 'datetime' ) );
				}
				if ( false !== strpos( $q, 'SELECT id, submission_date' ) ) {
					return array(); // nothing to backfill → loop breaks immediately
				}
				return array(); // SHOW INDEX etc.
			}
		);
		Activator::maybe_migrate_submission_date_to_unix();
		$this->assertTrue( true );
	}

	// ───────────── maybe_migrate_submitted_at_to_unix ─────────────

	public function test_migrate_submitted_at_runs_to_completion(): void {
		$written = array();
		$this->capture_option_writes( $written );

		Activator::maybe_migrate_submitted_at_to_unix();

		$this->assertSame( '1', $written['ffc_submitted_at_unix_migrated'] ?? null );
	}

	public function test_migrate_submitted_at_skips_when_flag_set(): void {
		Functions\when( 'get_option' )->justReturn( '1' );
		$this->wpdb->shouldNotReceive( 'get_var' );
		Activator::maybe_migrate_submitted_at_to_unix();
		$this->assertTrue( true );
	}

	// ───────────── maybe_migrate_sibling_instants_to_unix ─────────────

	public function test_migrate_sibling_instants_runs_to_completion(): void {
		// Exercises the orchestration + migrate_datetime_column_to_unix guard
		// for each (table, column) pair (all columns absent → per-column no-op).
		// Every pair being a no-op must still end in the flag being written.
		$written = array();
		$this->capture_option_writes( $written );

		Activator::maybe_migrate_sibling_instants_to_unix();

		$this->assertSame( '1', $written['ffc_sibling_instants_unix_migrated'] ?? null );
	}

	public function test_migrate_sibling_instants_skips_when_flag_set(): void {
		Functions\when( 'get_option' )->justReturn( '1' );
		// Short-circuit: no schema inspection, and no second write of the flag —
		// the routine must be completely inert once it has completed.
		$written = array();
		$this->capture_option_writes( $written );
		$this->wpdb->shouldNotReceive( 'query' );

		Activator::maybe_migrate_sibling_instants_to_unix();

		$this->assertSame( array(), $written );
	}

	// ───────────── upgrade_auth_code_unique_constraints (private) ─────────────

	public function test_upgrade_auth_code_adds_unique_when_absent(): void {
		// No existing index (get_results []) → dedup + add UNIQUE path runs.
		// The point of the routine is the UNIQUE constraint, so assert the
		// statement that creates it rather than that nothing fatalled.
		$statements = array();
		$this->capture_queries( $statements );

		$this->invoke_private( 'upgrade_auth_code_unique_constraints' );

		$unique = array_values(
			array_filter(
				$statements,
				static function ( $sql ) {
					return strpos( $sql, 'ADD UNIQUE INDEX' ) !== false;
				}
			)
		);
		$this->assertNotEmpty( $unique, 'The routine must add the UNIQUE index when none exists.' );
	}

	public function test_upgrade_auth_code_skips_when_unique_present(): void {
		// A UNIQUE index already on the column → continue (no ALTER).
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array( (object) array( 'Non_unique' => 0, 'Key_name' => 'uq_auth_code' ) )
		);
		$this->invoke_private( 'upgrade_auth_code_unique_constraints' );
		$this->assertTrue( true );
	}

	// ───────────── maybe_add_foreign_keys (flag-guard) ─────────────

	public function test_foreign_keys_skips_when_version_matches(): void {
		Functions\when( 'get_option' )->justReturn( FFC_VERSION );
		$fk = Mockery::mock( 'alias:FreeFormCertificate\Migrations\MigrationForeignKeys' );
		$fk->shouldNotReceive( 'run' );

		Activator::maybe_add_foreign_keys();

		$this->assertTrue( true );
	}

	public function test_foreign_keys_pins_version_once_complete(): void {
		// get_option default '' (≠ FFC_VERSION) → runs.
		$recorded = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$recorded ) {
				$recorded[ $key ] = $value;
				return true;
			}
		);
		$fk = Mockery::mock( 'alias:FreeFormCertificate\Migrations\MigrationForeignKeys' );
		$fk->shouldReceive( 'run' )->once();
		$fk->shouldReceive( 'get_status' )->once()->andReturn( array( 'is_complete' => true ) );

		Activator::maybe_add_foreign_keys();

		$this->assertSame( FFC_VERSION, $recorded['ffc_foreign_keys_db_version'] ?? null );
	}

	public function test_foreign_keys_does_not_pin_when_incomplete(): void {
		// A MyISAM host / missing table leaves is_complete false → keep retrying.
		$recorded = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$recorded ) {
				$recorded[ $key ] = $value;
				return true;
			}
		);
		$fk = Mockery::mock( 'alias:FreeFormCertificate\Migrations\MigrationForeignKeys' );
		$fk->shouldReceive( 'run' )->once();
		$fk->shouldReceive( 'get_status' )->once()->andReturn( array( 'is_complete' => false ) );

		Activator::maybe_add_foreign_keys();

		$this->assertArrayNotHasKey( 'ffc_foreign_keys_db_version', $recorded );
	}
}
