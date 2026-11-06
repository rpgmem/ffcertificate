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
 * Runs in the shared process over a `$wpdb` double with the real ActivityLog:
 * the global `get_option` answers `ffc_settings` with no settings, so the
 * activity log is off whatever ran before.
 *
 * @covers \FreeFormCertificate\Migrations\MigrationForeignKeys
 */
class MigrationForeignKeysTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array{0: bool, 1: mixed} Whether `$wpdb` was set, and its value. */
	private array $wpdb_backup = array( false, null );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The code under test is audited by default (#2340); this test is not
		// about the log, so nothing is written.
		\FreeFormCertificate\Core\ActivityLog::disable_logging();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		// No `ffc_settings`: the activity log is off.
		Functions\when( 'get_option' )->justReturn( array() );

		$this->wpdb_backup = array( array_key_exists( 'wpdb', $GLOBALS ), $GLOBALS['wpdb'] ?? null );

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
		if ( $this->wpdb_backup[0] ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup[1];
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		\FreeFormCertificate\Core\ActivityLog::enable_logging();
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
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$status = MigrationForeignKeys::get_status();

		$this->assertTrue( $status['available'] );
		$this->assertSame( 9, $status['total_constraints'] );
		$this->assertSame( 0, $status['existing_constraints'] );
		$this->assertFalse( $status['is_complete'] );
	}

	// ==================================================================
	// get_status() — all constraints exist
	// ==================================================================

	public function test_get_status_returns_complete_when_all_fks_exist(): void {
		global $wpdb;
		$rows = array();
		foreach ( array( 'submissions', 'self_scheduling_appointments', 'activity_log', 'audience_attendance', 'audience_members', 'audience_booking_users', 'audience_schedule_permissions', 'user_profiles', 'audience_form_invites' ) as $table ) {
			$rows[] = (object) array(
				'TABLE_NAME'      => 'wp_ffc_' . $table,
				'CONSTRAINT_NAME' => 'fk_' . $table,
			);
		}
		$wpdb->shouldReceive( 'get_results' )->andReturn( $rows );

		$status = MigrationForeignKeys::get_status();

		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( 9, $status['existing_constraints'] );
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
	 * @param array<string, string> $existing   Table => constraint name that already exists on it.
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
				if ( false !== strpos( (string) $sql, 'KEY_COLUMN_USAGE' ) ) {
					$rows = array();
					foreach ( $existing as $table => $name ) {
						$rows[] = (object) array(
							'TABLE_NAME'      => $table,
							'CONSTRAINT_NAME' => $name,
						);
					}
					return $rows;
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
		$this->assertCount( 9, $result['added'] );
		$this->assertSame( array(), $result['skipped'] );
		$this->assertStringContainsString( '9 added, 0 skipped, 0 errors', $result['message'] );

		$alters = array_values( array_filter( $queries->getArrayCopy(), static fn( $q ) => false !== strpos( $q, 'ADD CONSTRAINT' ) ) );
		$this->assertCount( 9, $alters );
		// The name carries the table prefix, so two installs sharing one
		// database cannot collide on it (#2343).
		$this->assertStringContainsString( 'wp_ffc_submissions ADD CONSTRAINT wp_fk_ffc_submissions_user', $alters[0] );
		$this->assertStringEndsWith( 'ON DELETE SET NULL', $alters[0] );
		$this->assertStringEndsWith( 'ON DELETE SET NULL', $alters[3] );
		$this->assertStringContainsString( 'wp_ffc_audience_attendance ADD CONSTRAINT wp_fk_ffc_attendance_user', $alters[3] );
		$this->assertStringEndsWith( 'ON DELETE CASCADE', $alters[7] );
		$this->assertStringContainsString( 'wp_ffc_audience_form_invites ADD CONSTRAINT wp_fk_ffc_form_invites_user', $alters[8] );
		$this->assertStringEndsWith( 'ON DELETE CASCADE', $alters[8] );

		$modified = array_filter( $queries->getArrayCopy(), static fn( $q ) => false !== strpos( $q, 'MODIFY user_id' ) );
		$this->assertCount( 4, $modified, 'Only the SET NULL tables need user_id made nullable.' );

		// Orphans follow the table's rule (#2343): cleared on the four SET NULL
		// tables, deleted on the five CASCADE ones, whose user_id is NOT NULL.
		$nulled  = array_filter( $queries->getArrayCopy(), static fn( $q ) => 0 === strpos( $q, 'UPDATE ' ) );
		$deleted = array_values( array_filter( $queries->getArrayCopy(), static fn( $q ) => 0 === strpos( $q, 'DELETE FROM ' ) ) );
		$this->assertCount( 4, $nulled );
		$this->assertCount( 5, $deleted );
		$this->assertStringStartsWith( 'DELETE FROM wp_ffc_audience_members WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT ID FROM wp_users)', $deleted[0] );
	}

	public function test_run_skips_a_table_without_user_id_or_on_another_engine(): void {
		$this->schema(
			array( 'wp_ffc_activity_log' => 'MyISAM' ),
			array( 'wp_ffc_user_profiles' ),
			true
		);

		$result = MigrationForeignKeys::run();

		$this->assertTrue( $result['success'] );
		$this->assertCount( 7, $result['added'] );

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
		$this->assertCount( 9, $result['errors'] );
		$this->assertSame( 'Cannot add foreign key constraint', $result['errors'][0]['message'] );
		$this->assertSame( 'wp_fk_ffc_submissions_user', $result['errors'][0]['constraint'] );
	}

	public function test_run_names_an_unknown_error_when_the_database_says_nothing(): void {
		$this->schema( array(), array(), '' );

		$result = MigrationForeignKeys::run();

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Unknown error adding FK', $result['errors'][0]['message'] );
	}

	public function test_run_leaves_an_existing_constraint_alone(): void {
		// Under the bare name an older release used: still the same key, so
		// it is not given a second, prefixed one (#2343).
		$queries = $this->schema( array(), array(), true, array( 'wp_ffc_self_scheduling_appointments' => 'fk_ffc_appointments_user' ) );

		$result = MigrationForeignKeys::run();

		$this->assertCount( 8, $result['added'] );
		$this->assertSame( 'FK already exists', $result['skipped'][0]['message'] );
		$this->assertSame( 'wp_fk_ffc_appointments_user', $result['skipped'][0]['constraint'] );
		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( 'wp_ffc_self_scheduling_appointments', $query, 'A table whose constraint exists is not touched at all.' );
		}
	}

	public function test_status_counts_only_this_installs_tables(): void {
		// Another install in the same database holds every constraint under
		// its own prefix; the query is scoped to this install's tables, so a
		// row naming another table would only appear if that scope were lost.
		$queries = array();
		global $wpdb;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) use ( &$queries ) {
				$queries[] = array( (string) $sql, $args );
				return (string) $sql;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		MigrationForeignKeys::get_status();

		$this->assertStringContainsString( 'TABLE_NAME IN (%s,%s,%s,%s,%s,%s,%s,%s,%s)', $queries[0][0] );
		$this->assertContains( 'wp_ffc_submissions', $queries[0][1] );
		$this->assertContains( 'wp_users', $queries[0][1], 'The key must reference this install\'s users table.' );
	}

	public function test_run_names_an_unknown_users_engine(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$result = MigrationForeignKeys::run();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'wp_users uses unknown engine', $result['errors'][0] );
	}
}
