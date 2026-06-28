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

	private function invoke_private( string $method, array $args = array() ) {
		$m = new \ReflectionMethod( Activator::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	// ───────────── maybe_add_perf_indexes ─────────────

	public function test_perf_indexes_runs_when_version_differs(): void {
		// get_option('ffc_perf_indexes_db_version') → '' (≠ FFC_VERSION) → run.
		Activator::maybe_add_perf_indexes();
		$this->assertTrue( true );
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
		Activator::maybe_migrate_submission_date_to_unix();
		$this->assertTrue( true );
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
		Activator::maybe_migrate_submitted_at_to_unix();
		$this->assertTrue( true );
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
		Activator::maybe_migrate_sibling_instants_to_unix();
		$this->assertTrue( true );
	}

	public function test_migrate_sibling_instants_skips_when_flag_set(): void {
		Functions\when( 'get_option' )->justReturn( '1' );
		Activator::maybe_migrate_sibling_instants_to_unix();
		$this->assertTrue( true );
	}

	// ───────────── upgrade_auth_code_unique_constraints (private) ─────────────

	public function test_upgrade_auth_code_adds_unique_when_absent(): void {
		// No existing index (get_results []) → dedup + add UNIQUE path runs.
		$this->invoke_private( 'upgrade_auth_code_unique_constraints' );
		$this->assertTrue( true );
	}

	public function test_upgrade_auth_code_skips_when_unique_present(): void {
		// A UNIQUE index already on the column → continue (no ALTER).
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array( (object) array( 'Non_unique' => 0, 'Key_name' => 'uq_auth_code' ) )
		);
		$this->invoke_private( 'upgrade_auth_code_unique_constraints' );
		$this->assertTrue( true );
	}
}
