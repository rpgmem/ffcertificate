<?php
/**
 * Tests for DatabaseHelperTrait.
 *
 * The trait carries the schema helpers Activator / SelfSchedulingActivator
 * / AudienceActivator / RateLimitActivator all share — table_exists,
 * column_exists, column_type, add_column_if_missing, index_exists,
 * add_index_if_missing, add_columns_if_missing,
 * migrate_datetime_column_to_unix and add_indexes_if_missing.
 *
 * `column_exists` is already exercised indirectly by QRCodeGeneratorTest
 * (via cache_column_exists). This file covers the rest — most pin a SQL
 * shape rather than a return value, since the trait's value is the SQL
 * it constructs against an idempotency check.
 *
 * Access pattern: the trait is `protected`, so we mount it on a tiny
 * test subject class (`TraitHost`) and re-expose every method as a
 * public passthrough. Same pattern the migration tests use elsewhere.
 *
 * @covers \FreeFormCertificate\Core\DatabaseHelperTrait
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\DatabaseHelperTrait;

/**
 * Test subject — applies the trait under test and re-exposes every
 * protected helper as a public passthrough so the test can call them
 * directly without reflection.
 */
class DatabaseHelperTraitHost {
	use DatabaseHelperTrait;

	public static function pub_table_exists( string $t ): bool {
		return self::table_exists( $t );
	}
	public static function pub_column_type( string $t, string $c ): ?string {
		return self::column_type( $t, $c );
	}
	public static function pub_add_column_if_missing( string $t, string $c, string $type, ?string $after = null, ?string $idx = null ): bool {
		return self::add_column_if_missing( $t, $c, $type, $after, $idx );
	}
	public static function pub_index_exists( string $t, string $i ): bool {
		return self::index_exists( $t, $i );
	}
	public static function pub_add_index_if_missing( string $t, string $i, string $cols ): bool {
		return self::add_index_if_missing( $t, $i, $cols );
	}
	public static function pub_add_columns_if_missing( string $t, array $cols ): int {
		return self::add_columns_if_missing( $t, $cols );
	}
	public static function pub_add_indexes_if_missing( string $t, array $idx ): int {
		return self::add_indexes_if_missing( $t, $idx );
	}
	public static function pub_migrate_datetime_column_to_unix( string $t, string $c, bool $nullable = true, array $drop = array(), array $recreate = array() ): void {
		self::migrate_datetime_column_to_unix( $t, $c, $nullable, $drop, $recreate );
	}
}

class DatabaseHelperTraitTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb               = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix       = 'wp_';
		$wpdb->last_error   = '';
		$this->wpdb         = $wpdb;

		// `prepare()` returns the SQL string in WP < 6.2; the trait uses
		// `%i` (since 6.2) for identifiers. Returning the first arg keeps
		// SQL inspection trivial without an extra %i resolver.
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing( static fn() => func_get_args()[0] )
			->byDefault();
		$this->wpdb->shouldReceive( 'esc_like' )
			->andReturnUsing( static fn( $s ) => $s )
			->byDefault();
		$this->wpdb->shouldReceive( 'suppress_errors' )->andReturn( false )->byDefault();
		$this->wpdb->shouldReceive( 'print_error' )->andReturn( null )->byDefault();

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ──────────────────────────────────────────────────────────────.
	// table_exists()
	// ──────────────────────────────────────────────────────────────.

	public function test_table_exists_true_when_show_tables_returns_name(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_submissions' );

		$this->assertTrue( DatabaseHelperTraitHost::pub_table_exists( 'wp_ffc_submissions' ) );
	}

	public function test_table_exists_false_when_show_tables_returns_null(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$this->assertFalse( DatabaseHelperTraitHost::pub_table_exists( 'wp_nonexistent' ) );
	}

	public function test_table_exists_false_on_wrong_name_match(): void {
		// Safety: get_var returning a different name (theoretically
		// impossible under SHOW TABLES LIKE but worth pinning) must not
		// be treated as a match — the trait does a strict ===.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_other' );

		$this->assertFalse( DatabaseHelperTraitHost::pub_table_exists( 'wp_ffc_submissions' ) );
	}

	// ──────────────────────────────────────────────────────────────.
	// column_type()
	// ──────────────────────────────────────────────────────────────.

	public function test_column_type_returns_type_string_when_column_present(): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array( (object) array( 'Field' => 'created_at', 'Type' => 'datetime' ) )
		);

		$this->assertSame( 'datetime', DatabaseHelperTraitHost::pub_column_type( 'wp_foo', 'created_at' ) );
	}

	public function test_column_type_returns_null_when_column_missing(): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$this->assertNull( DatabaseHelperTraitHost::pub_column_type( 'wp_foo', 'missing' ) );
	}

	public function test_column_type_returns_null_when_row_lacks_field_match(): void {
		// SHOW COLUMNS LIKE can return rows for similarly-named columns
		// even with esc_like applied (edge case in old MySQL). The trait
		// explicitly compares Field === column_name, so a "wrong row"
		// payload must yield null.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array( (object) array( 'Field' => 'other_column', 'Type' => 'varchar(50)' ) )
		);

		$this->assertNull( DatabaseHelperTraitHost::pub_column_type( 'wp_foo', 'created_at' ) );
	}

	// ──────────────────────────────────────────────────────────────.
	// add_column_if_missing()
	// ──────────────────────────────────────────────────────────────.

	public function test_add_column_if_missing_skips_when_column_already_present(): void {
		// column_exists() → true (returns one row).
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array( (object) array( 'Field' => 'extra' ) )
		);
		// ALTER must NOT fire.
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertFalse(
			DatabaseHelperTraitHost::pub_add_column_if_missing( 'wp_foo', 'extra', 'VARCHAR(50)' )
		);
	}

	public function test_add_column_if_missing_runs_alter_and_returns_true(): void {
		// column_exists() → false then for any nested index_exists → false.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$captured = null;
		$this->wpdb->shouldReceive( 'query' )
			->once()
			->andReturnUsing(
				function ( $sql ) use ( &$captured ) {
					$captured = $sql;
					return 1;
				}
			);

		$this->assertTrue(
			DatabaseHelperTraitHost::pub_add_column_if_missing( 'wp_foo', 'extra', 'VARCHAR(50) DEFAULT NULL' )
		);
		$this->assertStringContainsString( 'ALTER TABLE', (string) $captured );
		$this->assertStringContainsString( 'ADD COLUMN', (string) $captured );
		$this->assertStringContainsString( 'VARCHAR(50) DEFAULT NULL', (string) $captured );
	}

	public function test_add_column_if_missing_treats_duplicate_column_error_as_benign_noop(): void {
		// Race-window guard: even with the pre-check, a concurrent
		// activation can already have added the column. The duplicate-
		// column error string must NOT bubble up to print_error().
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturnUsing(
			function () {
				$GLOBALS['wpdb']->last_error = "Duplicate column name 'extra'";
				return false;
			}
		);
		// print_error must NOT be called — the dup is treated as benign.
		$this->wpdb->shouldNotReceive( 'print_error' );

		$this->assertFalse(
			DatabaseHelperTraitHost::pub_add_column_if_missing( 'wp_foo', 'extra', 'VARCHAR(50)' )
		);
	}

	public function test_add_column_if_missing_surfaces_unexpected_errors(): void {
		// A real ALTER failure (syntax error, missing table, etc.) must
		// reach print_error() so it lands in debug.log.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturnUsing(
			function () {
				$GLOBALS['wpdb']->last_error = 'Table doesn\'t exist';
				return false;
			}
		);
		$this->wpdb->shouldReceive( 'print_error' )->once();

		$this->assertFalse(
			DatabaseHelperTraitHost::pub_add_column_if_missing( 'wp_foo', 'extra', 'VARCHAR(50)' )
		);
	}

	public function test_add_column_if_missing_creates_index_when_requested(): void {
		// column_exists → false, then index_exists → false, then both
		// queries (ADD COLUMN + ADD INDEX) fire successfully.
		$call = 0;
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function () use ( &$call ) {
				++$call;
				return array(); // never present, so both checks return false.
			}
		);
		$queries = array();
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) use ( &$queries ) {
				$queries[] = $sql;
				return 1;
			}
		);

		$ok = DatabaseHelperTraitHost::pub_add_column_if_missing(
			'wp_foo',
			'job_id',
			'VARCHAR(40) DEFAULT NULL',
			null,
			'idx_job'
		);

		$this->assertTrue( $ok );
		$this->assertCount( 2, $queries, 'ADD COLUMN + ADD INDEX must both fire' );
		$this->assertStringContainsString( 'ADD COLUMN', $queries[0] );
		$this->assertStringContainsString( 'ADD INDEX', $queries[1] );
	}

	// ──────────────────────────────────────────────────────────────.
	// index_exists() + add_index_if_missing()
	// ──────────────────────────────────────────────────────────────.

	public function test_index_exists_true_when_show_index_returns_row(): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array( (object) array( 'Key_name' => 'idx_form' ) )
		);

		$this->assertTrue( DatabaseHelperTraitHost::pub_index_exists( 'wp_foo', 'idx_form' ) );
	}

	public function test_index_exists_false_when_show_index_returns_empty(): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$this->assertFalse( DatabaseHelperTraitHost::pub_index_exists( 'wp_foo', 'idx_missing' ) );
	}

	public function test_add_index_if_missing_skips_when_index_already_present(): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array( (object) array( 'Key_name' => 'idx_form' ) )
		);
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertFalse(
			DatabaseHelperTraitHost::pub_add_index_if_missing( 'wp_foo', 'idx_form', '(form_id)' )
		);
	}

	public function test_add_index_if_missing_runs_alter_when_missing(): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$captured = null;
		$this->wpdb->shouldReceive( 'query' )
			->once()
			->andReturnUsing(
				function ( $sql ) use ( &$captured ) {
					$captured = $sql;
					return 1;
				}
			);

		$this->assertTrue(
			DatabaseHelperTraitHost::pub_add_index_if_missing( 'wp_foo', 'idx_form', '(form_id)' )
		);
		$this->assertStringContainsString( 'ADD INDEX', (string) $captured );
		$this->assertStringContainsString( '(form_id)', (string) $captured );
	}

	// ──────────────────────────────────────────────────────────────.
	// add_columns_if_missing() + add_indexes_if_missing() — aggregates
	// ──────────────────────────────────────────────────────────────.

	public function test_add_columns_if_missing_counts_only_real_additions(): void {
		// Two columns. Call sequence:
		//   1. column_exists('existing')  → row (present, skipped)
		//   2. column_exists('fresh')     → empty (added)
		$call = 0;
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function () use ( &$call ) {
				++$call;
				return ( 1 === $call )
					? array( (object) array( 'Field' => 'existing' ) )
					: array();
			}
		);
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$added = DatabaseHelperTraitHost::pub_add_columns_if_missing(
			'wp_foo',
			array(
				'existing' => array( 'type' => 'VARCHAR(50)' ),
				'fresh'    => array( 'type' => 'TEXT' ),
			)
		);

		$this->assertSame( 1, $added, 'only the missing column counts as added' );
	}

	public function test_add_indexes_if_missing_counts_only_real_additions(): void {
		// Call sequence:
		//   1. index_exists('idx_present') → row (skipped)
		//   2. index_exists('idx_new')     → empty (added)
		$call = 0;
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function () use ( &$call ) {
				++$call;
				return ( 1 === $call )
					? array( (object) array( 'Key_name' => 'idx_present' ) )
					: array();
			}
		);
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$added = DatabaseHelperTraitHost::pub_add_indexes_if_missing(
			'wp_foo',
			array(
				'idx_present' => '(form_id)',
				'idx_new'     => '(form_id, status)',
			)
		);

		$this->assertSame( 1, $added );
	}

	// ──────────────────────────────────────────────────────────────.
	// migrate_datetime_column_to_unix() — fast-path guards.
	//
	// The full migration is exercised by the per-column unit tests in
	// includes/migrations/. Here we pin the early-exit branches that
	// keep idempotency: missing table, fast-path "already migrated",
	// and missing source column on a fresh install.
	// ──────────────────────────────────────────────────────────────.

	public function test_migrate_datetime_returns_early_when_table_missing(): void {
		// table_exists → false (get_var returns null).
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
		// Must NOT issue any ALTER or SELECT once the table is missing.
		$this->wpdb->shouldNotReceive( 'get_results' );
		$this->wpdb->shouldNotReceive( 'query' );

		DatabaseHelperTraitHost::pub_migrate_datetime_column_to_unix( 'wp_missing', 'called_at' );

		$this->assertTrue( true ); // assertion is the shouldNotReceive above.
	}

	public function test_migrate_datetime_fast_path_when_column_already_bigint(): void {
		// table_exists → true.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_foo' );

		// Sequence the three column_exists / column_type calls by call
		// order so the test stays deterministic (the `prepare` stub
		// doesn't actually substitute placeholders, so we can't dispatch
		// off the SQL string).
		//   1. column_exists('called_at')      → row (present)
		//   2. column_exists('called_at_ts')   → empty (staging absent)
		//   3. column_type('called_at')        → 'bigint unsigned' row
		$call = 0;
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function () use ( &$call ) {
				++$call;
				if ( 2 === $call ) {
					return array();
				}
				return array( (object) array( 'Field' => 'called_at', 'Type' => 'bigint unsigned' ) );
			}
		);
		// No destructive query allowed in the fast-path.
		$this->wpdb->shouldNotReceive( 'query' );
		$this->wpdb->shouldNotReceive( 'update' );

		DatabaseHelperTraitHost::pub_migrate_datetime_column_to_unix( 'wp_foo', 'called_at' );

		$this->assertSame( 3, $call, 'fast-path consults the column exactly three times' );
	}

	public function test_migrate_datetime_no_op_on_fresh_schema_without_either_column(): void {
		// Fresh-install 6.6+ — table exists but neither the old DATETIME
		// nor the staging _ts column is present (the int column already
		// has the final name). The routine must early-exit silently.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_foo' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$this->wpdb->shouldNotReceive( 'query' );

		DatabaseHelperTraitHost::pub_migrate_datetime_column_to_unix( 'wp_foo', 'called_at' );

		$this->assertTrue( true );
	}
}
