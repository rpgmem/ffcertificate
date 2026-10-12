<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\MigrationForeignKeys;

/**
 * @covers \FreeFormCertificate\Migrations\MigrationForeignKeys
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class MigrationForeignKeysTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		if ( ! defined( 'DB_NAME' ) ) {
			define( 'DB_NAME', 'test_db' );
		}

		// Namespaced stubs for ActivityLog
		Functions\when( 'FreeFormCertificate\Core\get_option' )->justReturn( array() );
		Functions\when( 'FreeFormCertificate\Settings\get_option' )->justReturn( array() );
		Functions\when( 'FreeFormCertificate\Core\absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->users = 'wp_users';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();
		$wpdb->last_error = '';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// run() — wp_users not InnoDB
	// ==================================================================

	public function test_run_returns_error_when_users_table_not_innodb(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_var' )->andReturn( 'MyISAM' );

		$result = MigrationForeignKeys::run();

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'MyISAM', $result['errors'][0] );
	}

	// ==================================================================
	// run() — tables don't exist (skipped)
	// ==================================================================

	public function test_run_skips_when_tables_do_not_exist(): void {
		global $wpdb;
		// get_var for engine check: InnoDB for wp_users, null for all others
		$call_count = 0;
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( function () use ( &$call_count ) {
			$call_count++;
			return $call_count === 1 ? 'InnoDB' : null;
		} );

		$result = MigrationForeignKeys::run();

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['skipped'] );
		$this->assertEmpty( $result['errors'] );
	}

	// ==================================================================
	// run() — FK already exists (skipped)
	// ==================================================================

	public function test_run_skips_when_fk_already_exists(): void {
		global $wpdb;
		// get_var returns InnoDB for engine checks
		$wpdb->shouldReceive( 'get_var' )->andReturn( 'InnoDB' );
		// table_exists returns true (get_var for table existence)
		// column_exists returns true
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array( (object) array( 'CONSTRAINT_NAME' => 'existing_fk' ) )
		);

		$result = MigrationForeignKeys::run();

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['skipped'] );
	}

	// ==================================================================
	// get_status() — no constraints exist
	// ==================================================================

	public function test_get_status_returns_incomplete_when_no_fks(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );

		$status = MigrationForeignKeys::get_status();

		$this->assertTrue( $status['available'] );
		$this->assertSame( 7, $status['total_constraints'] );
		$this->assertSame( 0, $status['existing_constraints'] );
		$this->assertFalse( $status['is_complete'] );
	}

	// ==================================================================
	// get_status() — all constraints exist
	// ==================================================================

	public function test_get_status_returns_complete_when_all_fks_exist(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_col' )->andReturn( array(
			'fk_ffc_submissions_user',
			'fk_ffc_appointments_user',
			'fk_ffc_activity_log_user',
			'fk_ffc_audience_members_user',
			'fk_ffc_booking_users_user',
			'fk_ffc_schedule_perms_user',
			'fk_ffc_user_profiles_user',
		) );

		$status = MigrationForeignKeys::get_status();

		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( 7, $status['existing_constraints'] );
	}

	// ==================================================================
	// run() — query failure returns error
	// ==================================================================

	public function test_run_message_includes_counts(): void {
		global $wpdb;
		// All engines InnoDB, but tables don't exist
		$call_count = 0;
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( function () use ( &$call_count ) {
			$call_count++;
			return $call_count === 1 ? 'InnoDB' : null;
		} );

		$result = MigrationForeignKeys::run();

		$this->assertArrayHasKey( 'message', $result );
		$this->assertStringContainsString( 'Foreign keys', $result['message'] );
	}

	// ==================================================================
	// run() — a schema that answers, table by table
	// ==================================================================

	/**
	 * Teach the double to answer the introspection statements by table.
	 *
	 * @param array<string, string> $engines    Table => engine (InnoDB when absent).
	 * @param array<int, string>    $no_user_id Tables without a user_id column.
	 * @param bool|string           $alter      What `ALTER … ADD CONSTRAINT` returns: true for 1, or an error string.
	 * @param array<int, string>    $existing   Constraints that already exist.
	 * @return \ArrayObject The executed statements, filled during the run.
	 */
	private function schema( array $engines, array $no_user_id, $alter, array $existing = array() ): \ArrayObject {
		global $wpdb;

		$queries = new \ArrayObject();

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				foreach ( $args as $arg ) {
					$sql = preg_replace( '/%[isd]/', (string) $arg, (string) $sql, 1 );
				}
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $sql ) use ( $engines ) {
				if ( 1 === preg_match( '/SHOW TABLES LIKE (\S+)/', (string) $sql, $m ) ) {
					return $m[1];
				}
				if ( 1 === preg_match( '/TABLE_NAME = (\S+)/', (string) $sql, $m ) ) {
					return $engines[ $m[1] ] ?? 'InnoDB';
				}
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $sql ) use ( $no_user_id, $existing ) {
				if ( 1 === preg_match( '/SHOW COLUMNS FROM (\S+) LIKE user_id/', (string) $sql, $m ) ) {
					return in_array( $m[1], $no_user_id, true ) ? array() : array( (object) array( 'Field' => 'user_id' ) );
				}
				if ( 1 === preg_match( '/CONSTRAINT_NAME = (\S+)/', (string) $sql, $m ) && in_array( $m[1], $existing, true ) ) {
					return array( (object) array( 'CONSTRAINT_NAME' => $m[1] ) );
				}
				return array();
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'Null' => 'NO' ) );
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $sql ) use ( $queries, $alter, $wpdb ) {
				$queries[] = (string) $sql;
				if ( false !== strpos( (string) $sql, 'ADD CONSTRAINT' ) && true !== $alter ) {
					$wpdb->last_error = (string) $alter;
					return false;
				}
				return 1;
			}
		);

		return $queries;
	}

	public function test_run_adds_every_constraint_with_its_delete_rule(): void {
		$queries = $this->schema( array(), array(), true );

		$result = MigrationForeignKeys::run();

		$this->assertTrue( $result['success'] );
		$this->assertCount( 7, $result['added'] );
		$this->assertSame( array(), $result['skipped'] );
		$this->assertStringContainsString( '7 added, 0 skipped, 0 errors', $result['message'] );

		$alters = array_values( array_filter( $queries->getArrayCopy(), static fn( $q ) => false !== strpos( $q, 'ADD CONSTRAINT' ) ) );
		$this->assertCount( 7, $alters );
		$this->assertStringContainsString( 'wp_ffc_submissions ADD CONSTRAINT fk_ffc_submissions_user', $alters[0] );
		$this->assertStringEndsWith( 'ON DELETE SET NULL', $alters[0] );
		$this->assertStringEndsWith( 'ON DELETE CASCADE', $alters[6] );

		$modified = array_filter( $queries->getArrayCopy(), static fn( $q ) => false !== strpos( $q, 'MODIFY user_id' ) );
		$this->assertCount( 3, $modified, 'Only the SET NULL tables need user_id made nullable.' );

		$cleaned = array_filter( $queries->getArrayCopy(), static fn( $q ) => 0 === strpos( $q, 'UPDATE ' ) );
		$this->assertCount( 7, $cleaned, 'Orphaned references are cleared before every constraint.' );
	}

	public function test_run_skips_a_table_without_user_id_or_on_another_engine(): void {
		$this->schema(
			array( 'wp_ffc_activity_log' => 'MyISAM' ),
			array( 'wp_ffc_user_profiles' ),
			true
		);

		$result = MigrationForeignKeys::run();

		$this->assertTrue( $result['success'] );
		$this->assertCount( 5, $result['added'] );

		$skipped = array();
		foreach ( $result['skipped'] as $entry ) {
			$skipped[ $entry['table'] ] = $entry['message'];
		}
		$this->assertSame(
			array(
				'wp_ffc_activity_log'  => 'Engine is MyISAM, not InnoDB',
				'wp_ffc_user_profiles' => 'No user_id column',
			),
			$skipped
		);
	}

	public function test_run_reports_the_database_error_when_a_constraint_is_refused(): void {
		$this->schema( array(), array(), 'Cannot add foreign key constraint' );

		$result = MigrationForeignKeys::run();

		$this->assertFalse( $result['success'] );
		$this->assertCount( 7, $result['errors'] );
		$this->assertSame( 'Cannot add foreign key constraint', $result['errors'][0]['message'] );
		$this->assertSame( 'fk_ffc_submissions_user', $result['errors'][0]['constraint'] );
	}

	public function test_run_names_an_unknown_error_when_the_database_says_nothing(): void {
		$this->schema( array(), array(), '' );

		$result = MigrationForeignKeys::run();

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Unknown error adding FK', $result['errors'][0]['message'] );
	}

	public function test_run_leaves_an_existing_constraint_alone(): void {
		$queries = $this->schema( array(), array(), true, array( 'fk_ffc_appointments_user' ) );

		$result = MigrationForeignKeys::run();

		$this->assertCount( 6, $result['added'] );
		$this->assertSame( 'FK already exists', $result['skipped'][0]['message'] );
		$this->assertSame( 'fk_ffc_appointments_user', $result['skipped'][0]['constraint'] );
		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( 'wp_ffc_self_scheduling_appointments', $query, 'A table whose constraint exists is not touched at all.' );
		}
	}

	public function test_run_names_an_unknown_users_engine(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$result = MigrationForeignKeys::run();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'wp_users uses unknown engine', $result['errors'][0] );
	}
}
