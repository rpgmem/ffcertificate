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
        $wpdb = Mockery::mock( 'wpdb' );
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

        $this->assertCount( 2, $delta_calls, 'dbDelta should be called once for each table' );
        $this->assertStringContainsString( 'ffc_rate_limits', $delta_calls[0] );
        $this->assertStringContainsString( 'ffc_rate_limit_logs', $delta_calls[1] );
    }

    public function test_create_tables_skips_existing_tables(): void {
        $this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );

        // prepare is called for table_exists checks
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        // Both tables exist — return the table name
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function () {
                static $call = 0;
                $call++;
                // First call: rate_limits table exists
                if ( $call === 1 ) {
                    return 'wp_ffc_rate_limits';
                }
                // Second call: rate_limit_logs table exists
                return 'wp_ffc_rate_limit_logs';
            } );

        $delta_called = false;
        Functions\when( 'dbDelta' )->alias( function () use ( &$delta_called ) {
            $delta_called = true;
        } );

        RateLimitActivator::create_tables();

        $this->assertFalse( $delta_called, 'dbDelta should NOT be called when both tables already exist' );
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
        $this->assertSame( '1.0.0', $updated_options['ffc_rate_limit_db_version'] );
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
                return 'wp_ffc_rate_limit_logs';
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
        $this->wpdb->shouldReceive( 'query' )->with( 'PREPARED_QUERY' )->twice()->andReturn( 1 );

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
        $this->assertCount( 2, $drop_queries, 'Should prepare DROP TABLE for both tables' );

        // Verify the option was deleted
        $this->assertContains( 'ffc_rate_limit_db_version', $deleted_options );
    }
}
