<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\RateLimitActivator;

/**
 * Tests for RateLimitActivator: table creation, existence checks, and drop.
 *
 * @covers \FreeFormCertificate\Security\RateLimitActivator
 */
class RateLimitActivatorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';

        $this->wpdb = $wpdb;

        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'delete_option' )->justReturn( true );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // create_tables()
    // ==================================================================

    public function test_create_tables_when_tables_dont_exist(): void {
        $this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        // table_exists returns null for both tables (neither exists)
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $delta_calls = array();
        Functions\when( 'dbDelta' )->alias( function ( $sql ) use ( &$delta_calls ) {
            $delta_calls[] = $sql;
        } );

        RateLimitActivator::create_tables();

        $this->assertCount( 3, $delta_calls, 'dbDelta should be called once for each table' );
        $this->assertStringContainsString( 'ffc_rate_limits', $delta_calls[0] );
        $this->assertStringContainsString( 'ffc_rate_limit_logs', $delta_calls[1] );
        $this->assertStringContainsString( 'ffc_device_signals', $delta_calls[2] );
    }

    public function test_create_tables_runs_dbdelta_on_signals_for_upgrade_path(): void {
        // From 6.3.2 onward we always call dbDelta on the signals table
        // (even when it exists) so the 4 columns added in 6.3.2 land on
        // existing 6.3.0/6.3.1 installs via the same code path. The
        // limits + logs tables remain guarded by table_exists() since
        // their schema hasn't changed since 5.x.
        $this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function () {
                static $call = 0;
                $call++;
                if ( $call === 1 ) {
                    return 'wp_ffc_rate_limits';
                }
                if ( $call === 2 ) {
                    return 'wp_ffc_rate_limit_logs';
                }
                return 'wp_ffc_device_signals';
            } );

        $delta_calls = array();
        Functions\when( 'dbDelta' )->alias( function ( $sql ) use ( &$delta_calls ) {
            $delta_calls[] = $sql;
        } );

        RateLimitActivator::create_tables();

        $this->assertCount( 1, $delta_calls, 'Only the signals dbDelta should run when the other two tables exist' );
        $this->assertStringContainsString( 'ffc_device_signals', $delta_calls[0] );
        $this->assertStringContainsString( 'sig_plugins', $delta_calls[0], '6.3.2 schema must include sig_plugins' );
        $this->assertStringContainsString( 'sig_permissions', $delta_calls[0] );
        $this->assertStringContainsString( 'sig_mediaqueries', $delta_calls[0] );
        $this->assertStringContainsString( 'sig_math', $delta_calls[0] );
    }

    public function test_create_tables_updates_option(): void {
        $this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        Functions\when( 'dbDelta' )->justReturn( array() );

        $updated_options = array();
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$updated_options ) {
            $updated_options[ $key ] = $value;
        } );

        RateLimitActivator::create_tables();

        $this->assertArrayHasKey( 'ffc_rate_limit_db_version', $updated_options );
        $this->assertSame( '1.2.0', $updated_options['ffc_rate_limit_db_version'] );
    }

    public function test_create_tables_returns_true(): void {
        $this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        Functions\when( 'dbDelta' )->justReturn( array() );

        $result = RateLimitActivator::create_tables();

        $this->assertTrue( $result );
    }

    // ==================================================================
    // tables_exist()
    // ==================================================================

    public function test_tables_exist_returns_true_when_both_exist(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function () {
                static $call = 0;
                $call++;
                if ( $call === 1 ) {
                    return 'wp_ffc_rate_limits';
                }
                if ( $call === 2 ) {
                    return 'wp_ffc_rate_limit_logs';
                }
                return 'wp_ffc_device_signals';
            } );

        $result = RateLimitActivator::tables_exist();

        $this->assertTrue( $result );
    }

    public function test_tables_exist_returns_false_when_limits_missing(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        // First call (rate_limits) returns null — table missing
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $result = RateLimitActivator::tables_exist();

        $this->assertFalse( $result );
    }

    public function test_tables_exist_returns_false_when_logs_missing(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function () {
                static $call = 0;
                $call++;
                if ( $call === 1 ) {
                    return 'wp_ffc_rate_limits'; // First table exists
                }
                return null; // Second table missing
            } );

        $result = RateLimitActivator::tables_exist();

        $this->assertFalse( $result );
    }

    // ==================================================================
    // drop_tables()
    // ==================================================================

    public function test_drop_tables_drops_both_and_deletes_option(): void {
        $prepared_queries = array();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared_queries ) {
            $args = func_get_args();
            $prepared_queries[] = $args;
            return 'PREPARED_QUERY';
        } );
        $this->wpdb->shouldReceive( 'query' )->with( 'PREPARED_QUERY' )->times( 3 )->andReturn( 1 );

        $deleted_options = array();
        Functions\when( 'delete_option' )->alias( function ( $option ) use ( &$deleted_options ) {
            $deleted_options[] = $option;
        } );

        $result = RateLimitActivator::drop_tables();

        $this->assertTrue( $result );

        // Verify both DROP TABLE queries were prepared
        $drop_queries = array_filter( $prepared_queries, function ( $args ) {
            return strpos( $args[0], 'DROP TABLE IF EXISTS' ) !== false;
        } );
        $this->assertCount( 3, $drop_queries, 'Should prepare DROP TABLE for all three tables' );

        // Verify the option was deleted
        $this->assertContains( 'ffc_rate_limit_db_version', $deleted_options );
    }
}
