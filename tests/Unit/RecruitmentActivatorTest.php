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

		// This activator's chain became guarded by `FFC_VERSION` (#1231), so it
		// reads and writes a version option. The value returned here is
		// DIFFERENT from `FFC_VERSION` on purpose: these tests exercise the
		// chain's body, which only runs when the guard does not match.
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'update_option' )->justReturn( true );
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

	// ==================================================================
	// maybe_migrate() — the whole chain against a stateful schema double
	// ==================================================================

	/**
	 * Wires `$wpdb` to a small in-memory schema.
	 *
	 * `$columns` maps a column name to its SQL type (absent = missing); ALTERs
	 * that add, drop or rename `called_at*` update it, so the v7 step reads
	 * back what it wrote. Every `query()` and `update()` is recorded.
	 *
	 * @param bool                  $tables_exist Whether SHOW TABLES finds each table.
	 * @param array<string, string> $columns      Column name => type.
	 * @param list<object>          $legacy_rows  Rows the v7 batch SELECT returns once.
	 * @return \ArrayObject<string, mixed> Keys: queries, updates, dbdelta.
	 */
	private function stub_schema( bool $tables_exist, array $columns, array $legacy_rows = array() ): \ArrayObject {
		$log = new \ArrayObject(
			array(
				'queries' => array(),
				'updates' => array(),
				'dbdelta' => array(),
				'columns' => $columns,
			)
		);

		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $query ) use ( $tables_exist, $log ) {
				if ( preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $m ) ) {
					return $tables_exist ? $m[1] : null;
				}
				if ( preg_match( "/SHOW COLUMNS FROM `[^`]+` LIKE '([^']+)'/", $query, $m ) ) {
					return isset( $log['columns'][ $m[1] ] ) ? $m[1] : null;
				}
				return null;
			}
		);

		$rows_served = false;
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $query ) use ( $log, $legacy_rows, &$rows_served ) {
				$normalized = str_replace( '\\', '', $query );
				if ( preg_match( "/SHOW COLUMNS FROM '[^']+' LIKE '([^']+)'/", $normalized, $m ) ) {
					$name = $m[1];
					return isset( $log['columns'][ $name ] )
						? array( (object) array( 'Field' => $name, 'Type' => $log['columns'][ $name ] ) )
						: array();
				}
				if ( false !== strpos( $normalized, 'SELECT id, called_at' ) ) {
					if ( $rows_served ) {
						return array();
					}
					$rows_served = true;
					return $legacy_rows;
				}
				return array();
			}
		);

		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $query ) use ( $log ) {
				$queries   = $log['queries'];
				$queries[] = $query;
				$log['queries'] = $queries;
				$columns   = $log['columns'];
				if ( false !== strpos( $query, "ADD COLUMN 'called_at_ts'" ) ) {
					$columns['called_at_ts'] = 'bigint(20) unsigned';
				}
				if ( false !== strpos( $query, "DROP COLUMN 'called_at'" ) ) {
					unset( $columns['called_at'] );
				}
				if ( false !== strpos( $query, "CHANGE 'called_at_ts' 'called_at'" ) ) {
					$columns['called_at'] = $columns['called_at_ts'];
					unset( $columns['called_at_ts'] );
				}
				$log['columns'] = $columns;
				return 1;
			}
		);

		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			static function ( $table, $data, $where ) use ( $log ) {
				$updates   = $log['updates'];
				$updates[] = array( $table, $data, $where );
				$log['updates'] = $updates;
				return 1;
			}
		);

		Functions\when( 'dbDelta' )->alias(
			static function ( $sql ) use ( $log ) {
				$sqls   = $log['dbdelta'];
				$sqls[] = $sql;
				$log['dbdelta'] = $sqls;
				return array();
			}
		);

		return $log;
	}

	/**
	 * @return \ArrayObject<int, int> Every schema-version value written, in order.
	 */
	private function record_schema_versions( $stored ): \ArrayObject {
		$writes = new \ArrayObject();
		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( $writes ) {
				if ( 'ffc_recruitment_schema_version' === $key ) {
					$writes[] = $value;
				}
				return true;
			}
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		return $writes;
	}

	/**
	 * @param \ArrayObject<string, mixed> $log
	 * @return list<string>
	 */
	private function queries_matching( \ArrayObject $log, string $needle ): array {
		return array_values(
			array_filter( $log['queries'], static fn ( $q ) => false !== stripos( $q, $needle ) )
		);
	}

	public function test_maybe_migrate_runs_every_step_on_a_legacy_install(): void {
		$writes = $this->record_schema_versions( 0 );
		$log    = $this->stub_schema(
			true,
			array(
				'called_at' => 'datetime',
				'list_type' => "enum('preview','definitive')",
			),
			array(
				(object) array( 'id' => '1', 'called_at' => '2024-01-02 03:04:05' ),
				(object) array( 'id' => '2', 'called_at' => 'not a date' ),
			)
		);

		RecruitmentActivator::maybe_migrate();

		$this->assertSame( array( 2, 3, 4, 5, 6, 7, 8, 9, 10, 11 ), $writes->getArrayCopy() );

		// v2 + v3: widen, rename, narrow — twice.
		$this->assertCount( 1, $this->queries_matching( $log, "SET status = 'definitive' WHERE status = 'active'" ) );
		$this->assertCount( 1, $this->queries_matching( $log, "SET status = 'definitive' WHERE status = 'final'" ) );
		// v4 adjutancy colour, v5 preview columns + index, v6 CSV extension columns.
		$this->assertCount( 1, $this->queries_matching( $log, 'ADD COLUMN color VARCHAR(9)' ) );
		$this->assertCount( 1, $this->queries_matching( $log, 'ADD COLUMN preview_status' ) );
		$this->assertCount( 1, $this->queries_matching( $log, 'ADD COLUMN preview_reason_id' ) );
		$this->assertCount( 1, $this->queries_matching( $log, 'ADD INDEX idx_preview_reason_id' ) );
		$this->assertCount( 1, $this->queries_matching( $log, 'ADD COLUMN time_points' ) );
		$this->assertCount( 1, $this->queries_matching( $log, 'ADD COLUMN hab_emebs' ) );
		// v7: staged, back-filled (the unparseable row is skipped), dropped, renamed.
		$this->assertCount( 1, $this->queries_matching( $log, "ADD COLUMN 'called_at_ts'" ) );
		$this->assertSame(
			array( array( 'wp_ffc_recruitment_call', array( 'called_at_ts' => 1704164645 ), array( 'id' => 1 ) ) ),
			$log['updates']
		);
		$this->assertCount( 1, $this->queries_matching( $log, "DROP COLUMN 'called_at'" ) );
		$this->assertCount( 1, $this->queries_matching( $log, "CHANGE 'called_at_ts' 'called_at' BIGINT" ) );
		$this->assertSame( 'bigint(20) unsigned', $log['columns']['called_at'] );
		$this->assertArrayNotHasKey( 'called_at_ts', $log['columns'] );
		// v8 withdrew, v9 list_type widened then purged.
		$this->assertCount( 1, $this->queries_matching( $log, "'hired','withdrew'" ) );
		$this->assertCount( 1, $this->queries_matching( $log, 'MODIFY list_type VARCHAR(50)' ) );
		$this->assertCount( 1, $this->queries_matching( $log, "DELETE FROM 'wp_ffc_recruitment_classification'" ) );
		// v11 drops the staging table; every table already exists, so nothing is created.
		$this->assertCount( 1, $this->queries_matching( $log, 'DROP TABLE IF EXISTS `wp_ffc_recruitment_import_staging`' ) );
		$this->assertSame( array(), $log['dbdelta'] );
	}

	public function test_maybe_migrate_on_a_bare_database_only_creates_the_late_tables(): void {
		$writes = $this->record_schema_versions( array() );
		$log    = $this->stub_schema( false, array() );

		RecruitmentActivator::maybe_migrate();

		$this->assertSame( array( 2, 3, 4, 5, 6, 7, 8, 9, 10, 11 ), $writes->getArrayCopy(), 'An unusable stored value reads as 0.' );
		$this->assertSame(
			array( 'DROP TABLE IF EXISTS `wp_ffc_recruitment_import_staging`' ),
			$log['queries'],
			'Every ALTER step returns early when its table is missing.'
		);
		$created = array_map(
			static fn ( $sql ) => preg_match( '/CREATE TABLE (\S+)/', $sql, $m ) ? $m[1] : '',
			$log['dbdelta']
		);
		$this->assertSame(
			array(
				'wp_ffc_recruitment_reason',
				'wp_ffc_recruitment_import_jobs',
				'wp_ffc_recruitment_import_staging',
				'wp_ffc_recruitment_import_staging',
			),
			$created
		);
	}

	public function test_maybe_migrate_skips_columns_that_already_exist_and_an_empty_backfill(): void {
		$this->record_schema_versions( 3 );
		$log = $this->stub_schema(
			true,
			array(
				'color'             => 'varchar(9)',
				'preview_status'    => 'enum',
				'preview_reason_id' => 'bigint',
				'time_points'       => 'decimal',
				'hab_emebs'         => 'tinyint',
				'called_at'         => 'datetime',
				'list_type'         => 'varchar(50)',
			)
		);

		RecruitmentActivator::maybe_migrate();

		$this->assertSame( array(), $this->queries_matching( $log, 'ADD COLUMN color' ) );
		$this->assertSame( array(), $this->queries_matching( $log, 'ADD COLUMN preview' ) );
		$this->assertSame( array(), $this->queries_matching( $log, 'ADD INDEX' ) );
		$this->assertSame( array(), $this->queries_matching( $log, 'ADD COLUMN time_points' ) );
		$this->assertSame( array(), $this->queries_matching( $log, 'ADD COLUMN hab_emebs' ) );
		$this->assertSame( array(), $log['updates'], 'No legacy row, so nothing is back-filled.' );
		$this->assertSame( 'bigint(20) unsigned', $log['columns']['called_at'], 'The swap still completes.' );
	}

	public function test_create_tables_returns_early_when_the_version_gate_matches(): void {
		Functions\when( 'get_option' )->justReturn( FFC_VERSION );
		$log = $this->stub_schema( false, array() );
		Functions\when( 'update_option' )->alias(
			function () {
				$this->fail( 'A matching gate must not rewrite the option.' );
			}
		);

		RecruitmentActivator::create_tables();

		$this->assertSame( array(), $log['dbdelta'] );
	}

	public function test_maybe_migrate_does_nothing_when_already_current(): void {
		$writes = $this->record_schema_versions( '11' );
		$log    = $this->stub_schema( true, array() );

		RecruitmentActivator::maybe_migrate();

		$this->assertCount( 0, $writes );
		$this->assertSame( array(), $log['queries'] );
	}

	public function test_maybe_migrate_v9_only_purges_when_list_type_is_already_varchar(): void {
		$writes = $this->record_schema_versions( 8 );
		$log    = $this->stub_schema( true, array( 'list_type' => 'varchar(50)' ) );

		RecruitmentActivator::maybe_migrate();

		$this->assertSame( array( 9, 10, 11 ), $writes->getArrayCopy() );
		$this->assertCount( 0, $this->queries_matching( $log, 'MODIFY list_type' ) );
		$this->assertCount( 1, $this->queries_matching( $log, "DELETE FROM 'wp_ffc_recruitment_classification'" ) );
	}

	public function test_maybe_migrate_v7_without_either_column_alters_nothing(): void {
		$writes = $this->record_schema_versions( 6 );
		$log    = $this->stub_schema( true, array( 'list_type' => 'varchar(50)' ) );

		RecruitmentActivator::maybe_migrate();

		$this->assertContains( 7, $writes->getArrayCopy() );
		$this->assertSame( array(), $this->queries_matching( $log, 'called_at' ) );
		$this->assertSame( array(), $log['updates'] );
	}

	public function test_maybe_migrate_v7_resumes_a_partial_run_with_both_columns_present(): void {
		$this->record_schema_versions( 6 );
		$log = $this->stub_schema(
			true,
			array(
				'called_at'    => 'datetime',
				'called_at_ts' => 'bigint(20) unsigned',
				'list_type'    => 'varchar(50)',
			),
			array( (object) array( 'id' => '5', 'called_at' => '2024-01-01 00:00:00' ) )
		);

		RecruitmentActivator::maybe_migrate();

		$this->assertCount( 0, $this->queries_matching( $log, "ADD COLUMN 'called_at_ts'" ), 'The staging column is reused.' );
		$this->assertSame( array( array( 'wp_ffc_recruitment_call', array( 'called_at_ts' => 1704067200 ), array( 'id' => 5 ) ) ), $log['updates'] );
		$this->assertSame( 'bigint(20) unsigned', $log['columns']['called_at'] );
		$this->assertArrayNotHasKey( 'called_at_ts', $log['columns'] );
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
