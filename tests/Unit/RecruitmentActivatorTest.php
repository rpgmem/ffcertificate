<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentActivator;

/**
 * Tests for RecruitmentActivator — covers the six idempotent CREATE TABLE
 * statements (one per recruitment table) and verifies the schema-level
 * invariants encoded in each statement (UNIQUE constraints, ENGINE=InnoDB,
 * indexes for the documented hot paths).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentActivator
 */
class RecruitmentActivatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$this->wpdb       = $wpdb;

		$this->wpdb->shouldReceive( 'get_charset_collate' )
			->andReturn( 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' )
			->byDefault();

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function () {
					$args = func_get_args();
					$sql  = $args[0];
					for ( $i = 1; $i < count( $args ); $i++ ) {
						$val = is_string( $args[ $i ] ) ? "'{$args[$i]}'" : $args[ $i ];
						$sql = preg_replace( '/%[isdf]/', (string) $val, $sql, 1 );
					}
					return $sql;
				}
			)
			->byDefault();

		Functions\when( 'dbDelta' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Returns a get_var stub that reports every recruitment table as missing.
	 *
	 * Used to force RecruitmentActivator to issue all six CREATE TABLE
	 * statements (idempotency would otherwise short-circuit).
	 */
	private function stub_no_tables_exist(): void {
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing(
				function ( $query ) {
					if ( false !== stripos( $query, 'SHOW TABLES LIKE' ) ) {
						return null;
					}
					return null;
				}
			);
	}

	/**
	 * Returns a get_var stub that reports every recruitment table as present.
	 */
	private function stub_all_tables_exist(): void {
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing(
				function ( $query ) {
					if ( false !== stripos( $query, 'SHOW TABLES LIKE' ) ) {
						if ( preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $m ) ) {
							return $m[1];
						}
						return 'wp_ffc_recruitment_existing';
					}
					return null;
				}
			);
	}

	public function test_create_tables_issues_six_dbdelta_calls_when_none_exist(): void {
		$this->stub_no_tables_exist();

		$delta_sqls = array();
		Functions\when( 'dbDelta' )->alias(
			function ( $sql ) use ( &$delta_sqls ) {
				$delta_sqls[] = $sql;
			}
		);

		RecruitmentActivator::create_tables();

		$this->assertCount( 9, $delta_sqls, 'dbDelta should be called 9 times — one per recruitment table (7 canonical + import_jobs + import_staging)' );
	}

	public function test_create_tables_is_idempotent_when_all_tables_exist(): void {
		$this->stub_all_tables_exist();

		$delta_called = false;
		Functions\when( 'dbDelta' )->alias(
			function () use ( &$delta_called ) {
				$delta_called = true;
			}
		);

		RecruitmentActivator::create_tables();

		$this->assertFalse( $delta_called, 'No CREATE TABLE statements should be issued when every table already exists' );
	}

	public function test_all_tables_use_innodb_engine(): void {
		$this->stub_no_tables_exist();

		$delta_sqls = array();
		Functions\when( 'dbDelta' )->alias(
			function ( $sql ) use ( &$delta_sqls ) {
				$delta_sqls[] = $sql;
			}
		);

		RecruitmentActivator::create_tables();

		$this->assertCount( 9, $delta_sqls );
		foreach ( $delta_sqls as $sql ) {
			$this->assertStringContainsString( 'ENGINE=InnoDB', $sql, 'Every recruitment table must declare ENGINE=InnoDB so the atomic CSV import works' );
		}
	}

	public function test_adjutancy_table_has_unique_slug_constraint(): void {
		$this->stub_no_tables_exist();

		$adjutancy_sql = $this->capture_table_sql( 'ffc_recruitment_adjutancy' );

		$this->assertStringContainsString( 'CREATE TABLE wp_ffc_recruitment_adjutancy', $adjutancy_sql );
		$this->assertStringContainsString( 'UNIQUE KEY uq_slug (slug)', $adjutancy_sql );
	}

	public function test_notice_table_has_unique_code_status_index_and_was_reopened(): void {
		$this->stub_no_tables_exist();

		$notice_sql = $this->capture_table_sql( 'ffc_recruitment_notice' );

		$this->assertStringContainsString( 'UNIQUE KEY uq_code (code)', $notice_sql );
		$this->assertStringContainsString( 'KEY idx_status (status)', $notice_sql );
		$this->assertStringContainsString( 'was_reopened tinyint(1) NOT NULL DEFAULT 0', $notice_sql );
		$this->assertStringContainsString( 'public_columns_config longtext NOT NULL', $notice_sql );
	}

	public function test_candidate_table_has_separate_unique_constraints_on_cpf_and_rf_hashes(): void {
		$this->stub_no_tables_exist();

		$candidate_sql = $this->capture_table_sql( 'ffc_recruitment_candidate' );

		$this->assertStringContainsString( 'UNIQUE KEY uq_cpf_hash (cpf_hash)', $candidate_sql );
		$this->assertStringContainsString( 'UNIQUE KEY uq_rf_hash (rf_hash)', $candidate_sql );
		$this->assertStringContainsString( 'KEY idx_email_hash (email_hash)', $candidate_sql, 'email_hash is indexed but NOT unique (family-shared emails are allowed)' );
		$this->assertStringContainsString( 'pcd_hash varchar(64) NOT NULL', $candidate_sql, 'pcd_hash is NOT NULL — both PCD and non-PCD candidates carry a hash' );
	}

	public function test_classification_table_has_composite_indexes_for_hot_paths(): void {
		$this->stub_no_tables_exist();

		$classification_sql = $this->capture_table_sql( 'ffc_recruitment_classification' );

		$this->assertStringContainsString( 'UNIQUE KEY uq_candidate_adjutancy_notice_list', $classification_sql );
		$this->assertStringContainsString(
			'KEY idx_notice_adjutancy_list_status_rank (notice_id, adjutancy_id, list_type, status, `rank`)',
			$classification_sql,
			'Composite index covering the lowest-rank-empty hot path; status precedes rank'
		);
		$this->assertStringContainsString( 'KEY idx_candidate_id (candidate_id)', $classification_sql );
	}

	public function test_classification_list_type_is_varchar_not_enum(): void {
		// V9 widened list_type from ENUM('preview','definitive') to
		// VARCHAR(50) so the batched-import flow can stash per-job
		// staging markers (`__staging_<uuid>`) alongside the live
		// values. The original ENUM silently coerced every staging
		// marker to '' and every concurrent job collided on the
		// UNIQUE (candidate_id, adjutancy_id, notice_id, list_type)
		// constraint with a misleading "Duplicate entry '1668-1-1-'".
		$this->stub_no_tables_exist();

		$classification_sql = $this->capture_table_sql( 'ffc_recruitment_classification' );

		$this->assertStringContainsString( 'list_type varchar(50) NOT NULL', $classification_sql );
		$this->assertStringNotContainsString( "list_type enum('preview','definitive')", $classification_sql );
	}

	public function test_call_table_has_classification_cancelled_index(): void {
		$this->stub_no_tables_exist();

		$call_sql = $this->capture_table_sql( 'ffc_recruitment_call' );

		$this->assertStringContainsString( 'KEY idx_classification_cancelled (classification_id, cancelled_at)', $call_sql );
		// `cancelled_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
		$this->assertStringContainsString( 'cancelled_at bigint(20) unsigned DEFAULT NULL', $call_sql );
		$this->assertStringContainsString( 'cancellation_reason text DEFAULT NULL', $call_sql );
	}

	public function test_notice_adjutancy_junction_has_composite_pk_and_reverse_lookup_index(): void {
		$this->stub_no_tables_exist();

		$junction_sql = $this->capture_table_sql( 'ffc_recruitment_notice_adjutancy' );

		$this->assertStringContainsString( 'PRIMARY KEY (notice_id, adjutancy_id)', $junction_sql );
		$this->assertStringContainsString( 'KEY idx_adjutancy_id (adjutancy_id)', $junction_sql );
	}

	/**
	 * Regression for #444 — uninstall+reinstall with `delete_data_on_uninstall`
	 * ON wipes `ffc_recruitment_schema_version` and recreates the table
	 * with the 6.6.0+ schema (`called_at` already BIGINT). The legacy v7
	 * code path treated "old column present" as "needs migration",
	 * dropped the BIGINT column and renamed an empty staging column over
	 * it — silently corrupting fresh installs. The hardened path detects
	 * the integer type via `column_type()` and short-circuits before any
	 * ALTER runs.
	 */
	public function test_migrate_called_at_to_unix_skips_when_column_is_already_bigint(): void {
		// Table exists.
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing(
				function ( $query ) {
					if ( false !== stripos( $query, 'SHOW TABLES LIKE' ) ) {
						return 'wp_ffc_recruitment_call';
					}
					return null;
				}
			);

		// SHOW COLUMNS responses: called_at present as BIGINT,
		// called_at_ts absent. The production code wraps the column
		// name with esc_like(), so the rendered query carries
		// `called\_at` / `called\_at\_ts` — normalize before matching.
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturnUsing(
				function ( $query ) {
					$normalized = str_replace( '\\', '', $query );
					if ( false !== stripos( $normalized, "LIKE 'called_at_ts'" ) ) {
						return array();
					}
					if ( false !== stripos( $normalized, "LIKE 'called_at'" ) ) {
						return array(
							(object) array(
								'Field' => 'called_at',
								'Type'  => 'bigint(20) unsigned',
							),
						);
					}
					return array();
				}
			);

		$queries = array();
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $query ) use ( &$queries ) {
					$queries[] = $query;
					return 1;
				}
			);

		// Drive the v7 step by forcing the schema-version gate at 6
		// (one below v7).
		Functions\when( 'get_option' )->justReturn( 6 );
		$version_writes = array();
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$version_writes ) {
				if ( 'ffc_recruitment_schema_version' === $key ) {
					$version_writes[] = $value;
				}
				return true;
			}
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		\FreeFormCertificate\Recruitment\RecruitmentActivator::maybe_migrate();

		$called_at_alters = array_filter(
			$queries,
			function ( $q ) {
				return false !== stripos( $q, 'ALTER TABLE' )
					&& false !== stripos( $q, 'called_at' );
			}
		);

		$this->assertSame( array(), $called_at_alters, 'No ALTER TABLE statements should touch called_at when it is already BIGINT.' );
		$this->assertContains( 7, $version_writes, 'Schema-version marker should still advance past v7.' );
	}

	/**
	 * Capture the CREATE TABLE SQL emitted to dbDelta for a specific table suffix.
	 *
	 * @param string $table_suffix Table-name fragment (without `wp_` prefix).
	 * @return string Full CREATE TABLE SQL.
	 */
	private function capture_table_sql( string $table_suffix ): string {
		$captured = array();
		Functions\when( 'dbDelta' )->alias(
			function ( $sql ) use ( &$captured ) {
				$captured[] = $sql;
			}
		);

		RecruitmentActivator::create_tables();

		foreach ( $captured as $sql ) {
			if ( false !== stripos( $sql, $table_suffix ) ) {
				return $sql;
			}
		}

		$this->fail( "No CREATE TABLE captured for {$table_suffix}" );
	}
}
