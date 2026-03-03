<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceActivator;

/**
 * Tests for AudienceActivator: table creation, capabilities registration,
 * migration, table status reporting, and drop operations.
 *
 * @covers \FreeFormCertificate\Audience\AudienceActivator
 */
class AudienceActivatorTest extends TestCase {

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
        Functions\when( 'get_role' )->justReturn( null );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // create_tables() — schedules table
    // ==================================================================

    public function test_create_tables_creates_schedules_table(): void {
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false
                    && stripos( $query, 'ffc_audience_schedules' ) !== false
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

        AudienceActivator::create_tables();

        $schedules_delta = array_filter( $delta_sqls, function ( $sql ) {
            return stripos( $sql, 'ffc_audience_schedules' ) !== false;
        } );

        $this->assertNotEmpty( $schedules_delta, 'dbDelta should be called with schedules table SQL' );
        $found_sql = reset( $schedules_delta );
        $this->assertStringContainsString( 'CREATE TABLE', $found_sql );
        $this->assertStringContainsString( 'visibility', $found_sql );
    }

    // ==================================================================
    // create_tables() — all 9 tables
    // ==================================================================

    public function test_create_tables_creates_all_tables_when_none_exist(): void {
        // No tables exist
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

        AudienceActivator::create_tables();

        $this->assertSame( 9, $delta_count, 'dbDelta should be called 9 times for 9 audience tables' );
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

        AudienceActivator::create_tables();

        $this->assertFalse( $delta_called, 'dbDelta should not be called when all tables already exist' );
    }

    // ==================================================================
    // register_capabilities() — ffc_user role
    // ==================================================================

    public function test_register_capabilities_adds_to_ffc_user_role(): void {
        $ffc_user_role = new \WP_Role();

        Functions\when( 'get_role' )->alias( function ( $role ) use ( $ffc_user_role ) {
            if ( $role === 'ffc_user' ) {
                return $ffc_user_role;
            }
            return null;
        } );

        AudienceActivator::register_capabilities();

        $this->assertArrayHasKey( 'ffc_view_audience_bookings', $ffc_user_role->capabilities );
        $this->assertTrue( $ffc_user_role->capabilities['ffc_view_audience_bookings'] );
    }

    // ==================================================================
    // register_capabilities() — subscriber role
    // ==================================================================

    public function test_register_capabilities_adds_to_subscriber_role(): void {
        $subscriber_role = new \WP_Role();

        Functions\when( 'get_role' )->alias( function ( $role ) use ( $subscriber_role ) {
            if ( $role === 'subscriber' ) {
                return $subscriber_role;
            }
            return null;
        } );

        AudienceActivator::register_capabilities();

        $this->assertArrayHasKey( 'ffc_view_audience_bookings', $subscriber_role->capabilities );
        $this->assertTrue( $subscriber_role->capabilities['ffc_view_audience_bookings'] );
    }

    // ==================================================================
    // register_capabilities() — handles missing roles
    // ==================================================================

    public function test_register_capabilities_handles_missing_roles(): void {
        // get_role returns null for all roles (default)
        Functions\when( 'get_role' )->justReturn( null );

        // Should not throw any exception
        AudienceActivator::register_capabilities();

        // If we got here without error, the test passes
        $this->assertTrue( true, 'register_capabilities should handle null roles gracefully' );
    }

    // ==================================================================
    // drop_tables()
    // ==================================================================

    public function test_drop_tables_drops_all_nine(): void {
        $prepared_queries = [];
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared_queries ) {
            $args = func_get_args();
            $prepared_queries[] = $args;
            return 'PREPARED_QUERY';
        } );
        $this->wpdb->shouldReceive( 'query' )->with( 'PREPARED_QUERY' )->andReturn( 1 );

        AudienceActivator::drop_tables();

        $drop_queries = array_filter( $prepared_queries, function ( $args ) {
            return stripos( $args[0], 'DROP TABLE IF EXISTS' ) !== false;
        } );

        $this->assertCount( 9, $drop_queries, 'Should prepare DROP TABLE for all 9 audience tables' );
    }

    // ==================================================================
    // get_tables_status() — all exist
    // ==================================================================

    public function test_get_tables_status_all_exist(): void {
        $call_count = 0;
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) use ( &$call_count ) {
                $call_count++;
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
                    return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $tm ) ? $tm[1] : 'existing_table';
                }
                // SELECT COUNT(*) queries — return a count
                if ( stripos( $query, 'SELECT COUNT' ) !== false ) {
                    return '5';
                }
                return null;
            } );

        $status = AudienceActivator::get_tables_status();

        $this->assertCount( 9, $status, 'Should return status for 9 tables' );

        foreach ( $status as $key => $info ) {
            $this->assertTrue( $info['exists'], "Table '$key' should be reported as existing" );
            $this->assertSame( 5, $info['count'], "Table '$key' should have count 5" );
            $this->assertArrayHasKey( 'table', $info );
        }

        $this->assertArrayHasKey( 'schedules', $status );
        $this->assertArrayHasKey( 'audiences', $status );
        $this->assertArrayHasKey( 'bookings', $status );
    }

    // ==================================================================
    // get_tables_status() — none exist
    // ==================================================================

    public function test_get_tables_status_none_exist(): void {
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
                    return null;
                }
                return null;
            } );

        $status = AudienceActivator::get_tables_status();

        $this->assertCount( 9, $status, 'Should return status for 9 tables' );

        foreach ( $status as $key => $info ) {
            $this->assertFalse( $info['exists'], "Table '$key' should be reported as not existing" );
            $this->assertSame( 0, $info['count'], "Table '$key' should have count 0 when not existing" );
        }
    }

    // ==================================================================
    // maybe_migrate() — runs when tables exist
    // ==================================================================

    public function test_maybe_migrate_runs_migrations_when_table_exists(): void {
        // Schedules table exists, audiences table exists
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
                    return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $tm ) ? $tm[1] : 'existing_table';
                }
                return null;
            } );

        // column_exists returns empty (columns missing) to trigger migration
        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( [] );

        $query_calls = [];
        $this->wpdb->shouldReceive( 'query' )
            ->andReturnUsing( function ( $query ) use ( &$query_calls ) {
                $query_calls[] = $query;
                return 1;
            } );

        AudienceActivator::maybe_migrate();

        // Should have issued ALTER TABLE queries for column additions
        $alter_queries = array_filter( $query_calls, function ( $q ) {
            return stripos( $q, 'ALTER TABLE' ) !== false;
        } );

        $this->assertNotEmpty( $alter_queries, 'Migration should execute ALTER TABLE queries when tables exist' );
    }

    // ==================================================================
    // maybe_migrate() — skips when table missing
    // ==================================================================

    public function test_maybe_migrate_skips_when_table_missing(): void {
        // No tables exist
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

        AudienceActivator::maybe_migrate();

        $this->assertFalse( $query_called, 'No ALTER TABLE queries should run when tables do not exist' );
    }

    // ==================================================================
    // create_tables() — calls register_capabilities
    // ==================================================================

    public function test_create_tables_calls_register_capabilities(): void {
        // All tables exist (skip creation), but register_capabilities should still be called
        $ffc_user_role = new \WP_Role();
        $subscriber_role = new \WP_Role();

        Functions\when( 'get_role' )->alias( function ( $role ) use ( $ffc_user_role, $subscriber_role ) {
            if ( $role === 'ffc_user' ) {
                return $ffc_user_role;
            }
            if ( $role === 'subscriber' ) {
                return $subscriber_role;
            }
            return null;
        } );

        AudienceActivator::create_tables();

        // Verify capabilities were added by register_capabilities (called from create_tables)
        $this->assertArrayHasKey(
            'ffc_view_audience_bookings',
            $ffc_user_role->capabilities,
            'create_tables() should call register_capabilities() which adds cap to ffc_user'
        );
        $this->assertArrayHasKey(
            'ffc_view_audience_bookings',
            $subscriber_role->capabilities,
            'create_tables() should call register_capabilities() which adds cap to subscriber'
        );
    }
}
