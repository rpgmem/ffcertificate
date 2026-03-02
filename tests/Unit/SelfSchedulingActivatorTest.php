<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingActivator;

/**
 * Tests for SelfSchedulingActivator: table creation, migration,
 * index management, and drop operations.
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingActivator
 */
class SelfSchedulingActivatorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $this->wpdb = $wpdb;

        // Default wpdb stubs
        $this->wpdb->shouldReceive( 'get_charset_collate' )
            ->andReturn( 'DEFAULT CHARSET utf8mb4' )
            ->byDefault();
        $this->wpdb->shouldReceive( 'prepare' )
            ->andReturnUsing( function () {
                $args = func_get_args();
                $sql  = $args[0];
                for ( $i = 1; $i < count( $args ); $i++ ) {
                    $val = is_string( $args[ $i ] ) ? "'{$args[$i]}'" : $args[ $i ];
                    $sql = preg_replace( '/%[sidf]/', $val, $sql, 1 );
                }
                return $sql;
            } )
            ->byDefault();
        // Default: all tables exist — return the queried table name
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $m ) ) {
                    return $m[1];
                }
                return null;
            } )
            ->byDefault();
        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( [] )
            ->byDefault();
        $this->wpdb->shouldReceive( 'query' )
            ->andReturn( 1 )
            ->byDefault();

        // Default WP function stubs
        Functions\when( 'dbDelta' )->justReturn( [] );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // create_tables() — calendars table
    // ==================================================================

    public function test_create_tables_creates_calendars_when_not_exists(): void {
        // Return null for calendars table, existing for others
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false
                    && stripos( $query, 'ffc_self_scheduling_calendars' ) !== false
                ) {
                    return null;
                }
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
                    return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $tm ) ? $tm[1] : 'existing_table';
                }
                return null;
            } );

        $delta_sqls = [];
        Functions\when( 'dbDelta' )->alias( function ( $sql ) use ( &$delta_sqls ) {
            $delta_sqls[] = $sql;
        } );

        SelfSchedulingActivator::create_tables();

        $calendars_delta = array_filter( $delta_sqls, function ( $sql ) {
            return stripos( $sql, 'ffc_self_scheduling_calendars' ) !== false;
        } );

        $this->assertNotEmpty( $calendars_delta, 'dbDelta should be called with calendars table SQL' );
        $found_sql = reset( $calendars_delta );
        $this->assertStringContainsString( 'CREATE TABLE', $found_sql );
        $this->assertStringContainsString( 'slot_duration', $found_sql );
    }

    // ==================================================================
    // create_tables() — appointments table
    // ==================================================================

    public function test_create_tables_creates_appointments_when_not_exists(): void {
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false
                    && stripos( $query, 'ffc_self_scheduling_appointments' ) !== false
                ) {
                    return null;
                }
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
                    return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $tm ) ? $tm[1] : 'existing_table';
                }
                return null;
            } );

        $delta_sqls = [];
        Functions\when( 'dbDelta' )->alias( function ( $sql ) use ( &$delta_sqls ) {
            $delta_sqls[] = $sql;
        } );

        SelfSchedulingActivator::create_tables();

        $appointments_delta = array_filter( $delta_sqls, function ( $sql ) {
            return stripos( $sql, 'ffc_self_scheduling_appointments' ) !== false;
        } );

        $this->assertNotEmpty( $appointments_delta, 'dbDelta should be called with appointments table SQL' );
        $found_sql = reset( $appointments_delta );
        $this->assertStringContainsString( 'CREATE TABLE', $found_sql );
        $this->assertStringContainsString( 'calendar_id', $found_sql );
        $this->assertStringContainsString( 'appointment_date', $found_sql );
    }

    // ==================================================================
    // create_tables() — blocked dates table
    // ==================================================================

    public function test_create_tables_creates_blocked_dates_when_not_exists(): void {
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false
                    && stripos( $query, 'ffc_self_scheduling_blocked_dates' ) !== false
                ) {
                    return null;
                }
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
                    return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $tm ) ? $tm[1] : 'existing_table';
                }
                return null;
            } );

        $delta_sqls = [];
        Functions\when( 'dbDelta' )->alias( function ( $sql ) use ( &$delta_sqls ) {
            $delta_sqls[] = $sql;
        } );

        SelfSchedulingActivator::create_tables();

        $blocked_delta = array_filter( $delta_sqls, function ( $sql ) {
            return stripos( $sql, 'ffc_self_scheduling_blocked_dates' ) !== false;
        } );

        $this->assertNotEmpty( $blocked_delta, 'dbDelta should be called with blocked_dates table SQL' );
        $found_sql = reset( $blocked_delta );
        $this->assertStringContainsString( 'CREATE TABLE', $found_sql );
        $this->assertStringContainsString( 'block_type', $found_sql );
    }

    // ==================================================================
    // create_tables() — skips existing tables
    // ==================================================================

    public function test_create_tables_skips_existing_tables(): void {
        // Default setup: all tables exist

        $delta_called = false;
        Functions\when( 'dbDelta' )->alias( function () use ( &$delta_called ) {
            $delta_called = true;
        } );

        SelfSchedulingActivator::create_tables();

        $this->assertFalse( $delta_called, 'dbDelta should not be called when all tables already exist' );
    }

    // ==================================================================
    // drop_tables()
    // ==================================================================

    public function test_drop_tables_drops_all_three(): void {
        $prepared_queries = [];
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared_queries ) {
            $args = func_get_args();
            $prepared_queries[] = $args;
            return 'PREPARED_QUERY';
        } );
        $this->wpdb->shouldReceive( 'query' )->with( 'PREPARED_QUERY' )->andReturn( 1 );

        SelfSchedulingActivator::drop_tables();

        $drop_queries = array_filter( $prepared_queries, function ( $args ) {
            return stripos( $args[0], 'DROP TABLE IF EXISTS' ) !== false;
        } );

        $this->assertCount( 3, $drop_queries, 'Should prepare DROP TABLE for all 3 self-scheduling tables' );
    }

    // ==================================================================
    // maybe_migrate() — runs when tables exist
    // ==================================================================

    public function test_maybe_migrate_runs_when_tables_exist(): void {
        // Appointments table exists, calendars table exists
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
                    return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $tm ) ? $tm[1] : 'existing_table';
                }
                return null;
            } );

        // column_exists checks — return empty array (column does not exist) to trigger migration
        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( [] );

        // Expect ALTER TABLE queries for column additions
        $query_calls = [];
        $this->wpdb->shouldReceive( 'query' )
            ->andReturnUsing( function ( $query ) use ( &$query_calls ) {
                $query_calls[] = $query;
                return 1;
            } );

        SelfSchedulingActivator::maybe_migrate();

        // Should have issued ALTER TABLE queries for migrations
        $this->assertNotEmpty( $query_calls, 'Migration should execute ALTER TABLE queries when tables exist and columns are missing' );
    }

    // ==================================================================
    // maybe_migrate() — skips when tables missing
    // ==================================================================

    public function test_maybe_migrate_skips_when_tables_missing(): void {
        // Neither appointments nor calendars table exists
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
                    return null;
                }
                return null;
            } );

        $query_called = false;
        $this->wpdb->shouldReceive( 'query' )
            ->andReturnUsing( function () use ( &$query_called ) {
                $query_called = true;
                return 1;
            } );

        SelfSchedulingActivator::maybe_migrate();

        $this->assertFalse( $query_called, 'No ALTER TABLE queries should run when tables do not exist' );
    }

    // ==================================================================
    // create_tables() — appointments migration when table exists
    // ==================================================================

    public function test_create_tables_runs_appointments_migration_when_exists(): void {
        // Appointments table exists — should trigger migrate_appointments_table()
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
                    return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $tm ) ? $tm[1] : 'existing_table';
                }
                return null;
            } );

        // column_exists returns empty (column missing) to trigger migration
        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( [] );

        $query_calls = [];
        $this->wpdb->shouldReceive( 'query' )
            ->andReturnUsing( function ( $query ) use ( &$query_calls ) {
                $query_calls[] = $query;
                return 1;
            } );

        SelfSchedulingActivator::create_tables();

        // When appointments table exists, create_appointments_table() calls
        // migrate_appointments_table() which issues ALTER TABLE ADD COLUMN queries
        $alter_queries = array_filter( $query_calls, function ( $q ) {
            return stripos( $q, 'ALTER TABLE' ) !== false;
        } );

        $this->assertNotEmpty( $alter_queries, 'Should run migration ALTER TABLE queries when appointments table exists' );
    }

    // ==================================================================
    // ensure_unique_validation_code_index
    // ==================================================================

    public function test_ensure_unique_validation_code_index(): void {
        // All tables exist
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
                    return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $tm ) ? $tm[1] : 'existing_table';
                }
                return null;
            } );

        // For index_exists check on validation_code: return non-empty result with Non_unique = 1
        // For other get_results calls (column_exists in migrations): return non-empty to skip migrations
        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW INDEX' ) !== false
                    && stripos( $query, 'validation_code' ) !== false
                ) {
                    $idx = (object) [ 'Non_unique' => 1, 'Key_name' => 'validation_code' ];
                    return [ $idx ];
                }
                // Column exists checks — return non-empty to skip add_column_if_missing
                return [ (object) [ 'Field' => 'some_column' ] ];
            } );

        $query_calls = [];
        $this->wpdb->shouldReceive( 'query' )
            ->andReturnUsing( function ( $query ) use ( &$query_calls ) {
                $query_calls[] = $query;
                return 1;
            } );

        SelfSchedulingActivator::create_tables();

        // Should drop the old non-unique index and add a UNIQUE KEY
        $unique_queries = array_filter( $query_calls, function ( $q ) {
            return stripos( $q, 'ADD UNIQUE KEY' ) !== false;
        } );

        $this->assertNotEmpty( $unique_queries, 'Should add UNIQUE KEY for validation_code' );
    }

    // ==================================================================
    // create_tables() — dbDelta calls
    // ==================================================================

    public function test_create_tables_calls_dbdelta(): void {
        // All tables do not exist
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
                    return null;
                }
                return null;
            } );

        $delta_count = 0;
        Functions\when( 'dbDelta' )->alias( function () use ( &$delta_count ) {
            $delta_count++;
        } );

        SelfSchedulingActivator::create_tables();

        // 3 tables: calendars, appointments, blocked_dates
        $this->assertSame( 3, $delta_count, 'dbDelta should be called 3 times for 3 tables' );
    }
}
