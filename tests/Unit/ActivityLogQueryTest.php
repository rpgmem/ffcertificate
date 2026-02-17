<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLogQuery;

/**
 * Tests for ActivityLogQuery: query building, statistics caching,
 * log cleanup, run_cleanup settings-driven behaviour.
 */
class ActivityLogQueryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, $args );
        } );
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'ffc_settings' ) {
                return array( 'activity_log_retention_days' => 90 );
            }
            return $default;
        } );

        global $wpdb;
        $this->wpdb = Mockery::mock( 'wpdb' );
        $this->wpdb->prefix = 'wp_';
        $wpdb = $this->wpdb;
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // get_activities() — query building & JSON context decoding
    // ==================================================================

    public function test_get_activities_defaults(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array() );

        $result = ActivityLogQuery::get_activities();
        $this->assertSame( array(), $result );
    }

    public function test_get_activities_decodes_json_context(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( array(
                array( 'id' => 1, 'action' => 'test', 'context' => '{"key":"value"}' ),
            ) );

        $result = ActivityLogQuery::get_activities();
        $this->assertSame( array( 'key' => 'value' ), $result[0]['context'] );
    }

    public function test_get_activities_invalid_json_context_becomes_empty_array(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( array(
                array( 'id' => 1, 'action' => 'test', 'context' => 'not json' ),
            ) );

        $result = ActivityLogQuery::get_activities();
        $this->assertSame( array(), $result[0]['context'] );
    }

    public function test_get_activities_empty_context_becomes_empty_array(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( array(
                array( 'id' => 1, 'action' => 'test', 'context' => '' ),
            ) );

        $result = ActivityLogQuery::get_activities();
        $this->assertSame( array(), $result[0]['context'] );
    }

    public function test_get_activities_with_level_filter(): void {
        $all_prepares = array();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$all_prepares ) {
            $all_prepares[] = func_get_args()[0];
            return 'PREPARED';
        } );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        ActivityLogQuery::get_activities( array( 'level' => 'error' ) );

        $found = false;
        foreach ( $all_prepares as $sql ) {
            if ( str_contains( $sql, 'level = %s' ) ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'Expected level filter in prepared queries' );
    }

    public function test_get_activities_with_search_filter(): void {
        $all_prepares = array();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$all_prepares ) {
            $all_prepares[] = func_get_args()[0];
            return 'PREPARED';
        } );
        $this->wpdb->shouldReceive( 'esc_like' )->with( 'test' )->andReturn( 'test' );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        ActivityLogQuery::get_activities( array( 'search' => 'test' ) );

        $found = false;
        foreach ( $all_prepares as $sql ) {
            if ( str_contains( $sql, 'action LIKE %s OR context LIKE %s' ) ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'Expected search filter in prepared queries' );
    }

    public function test_get_activities_invalid_orderby_defaults_to_created_at(): void {
        $prepared = null;
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared ) {
            $prepared = func_get_args()[0];
            return 'SELECT ...';
        } );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        ActivityLogQuery::get_activities( array( 'orderby' => 'DROP TABLE' ) );
        $this->assertStringContainsString( 'ORDER BY created_at', $prepared );
    }

    public function test_get_activities_valid_orderby_accepted(): void {
        $prepared = null;
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared ) {
            $prepared = func_get_args()[0];
            return 'SELECT ...';
        } );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        ActivityLogQuery::get_activities( array( 'orderby' => 'action' ) );
        $this->assertStringContainsString( 'ORDER BY action', $prepared );
    }

    public function test_get_activities_order_normalized_to_asc_desc(): void {
        $prepared = null;
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared ) {
            $prepared = func_get_args()[0];
            return 'SELECT ...';
        } );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        ActivityLogQuery::get_activities( array( 'order' => 'asc' ) );
        $this->assertStringContainsString( 'ASC', $prepared );
    }

    public function test_get_activities_invalid_order_defaults_to_desc(): void {
        $prepared = null;
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared ) {
            $prepared = func_get_args()[0];
            return 'SELECT ...';
        } );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        ActivityLogQuery::get_activities( array( 'order' => 'INVALID' ) );
        $this->assertStringContainsString( 'DESC', $prepared );
    }

    // ==================================================================
    // count_activities()
    // ==================================================================

    public function test_count_activities_returns_integer(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT COUNT...' );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '42' );

        $this->assertSame( 42, ActivityLogQuery::count_activities() );
    }

    public function test_count_activities_with_filters(): void {
        $all_prepares = array();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$all_prepares ) {
            $all_prepares[] = func_get_args()[0];
            return 'PREPARED';
        } );
        $this->wpdb->shouldReceive( 'esc_like' )->andReturn( 'term' );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '5' );

        $count = ActivityLogQuery::count_activities( array(
            'level'     => 'warning',
            'action'    => 'login',
            'user_id'   => 10,
            'date_from' => '2030-01-01',
            'search'    => 'term',
        ) );

        $this->assertSame( 5, $count );

        $all_sql = implode( ' ', $all_prepares );
        $this->assertStringContainsString( 'level = %s', $all_sql );
        $this->assertStringContainsString( 'action = %s', $all_sql );
        $this->assertStringContainsString( 'user_id = %d', $all_sql );
        $this->assertStringContainsString( 'created_at >= %s', $all_sql );
    }

    // ==================================================================
    // get_stats() — transient caching
    // ==================================================================

    public function test_stats_returns_cached_when_available(): void {
        $cached = array( 'total' => 100, 'by_level' => array() );
        Functions\when( 'get_transient' )->justReturn( $cached );

        $result = ActivityLogQuery::get_stats( 30 );
        $this->assertSame( 100, $result['total'] );
    }

    public function test_stats_queries_db_when_no_cache(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '50' );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        $result = ActivityLogQuery::get_stats( 7 );
        $this->assertSame( 50, $result['total'] );
        $this->assertSame( 7, $result['period_days'] );
        $this->assertArrayHasKey( 'by_level', $result );
        $this->assertArrayHasKey( 'top_actions', $result );
        $this->assertArrayHasKey( 'top_users', $result );
    }

    // ==================================================================
    // cleanup() — deletes old logs + clears transients
    // ==================================================================

    public function test_cleanup_returns_deleted_count(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'DELETE ...' );
        $this->wpdb->shouldReceive( 'query' )->andReturn( 15 );

        $deleted = ActivityLogQuery::cleanup( 90 );
        $this->assertSame( 15, $deleted );
    }

    public function test_cleanup_clears_stats_transients(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'DELETE ...' );
        $this->wpdb->shouldReceive( 'query' )->andReturn( 0 );

        $cleared = array();
        Functions\when( 'delete_transient' )->alias( function ( $key ) use ( &$cleared ) {
            $cleared[] = $key;
            return true;
        } );

        ActivityLogQuery::cleanup();

        $this->assertContains( 'ffc_activity_stats_7', $cleared );
        $this->assertContains( 'ffc_activity_stats_30', $cleared );
        $this->assertContains( 'ffc_activity_stats_90', $cleared );
    }

    // ==================================================================
    // run_cleanup() — settings-driven
    // ==================================================================

    public function test_run_cleanup_uses_settings_retention(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'DELETE ...' );
        $this->wpdb->shouldReceive( 'query' )->andReturn( 10 );

        $deleted = ActivityLogQuery::run_cleanup();
        $this->assertSame( 10, $deleted );
    }

    public function test_run_cleanup_zero_retention_skips(): void {
        Functions\when( 'get_option' )->justReturn( array( 'activity_log_retention_days' => 0 ) );

        $result = ActivityLogQuery::run_cleanup();
        $this->assertSame( 0, $result );
    }

    public function test_run_cleanup_missing_setting_defaults_to_90(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'DELETE ...' );
        $this->wpdb->shouldReceive( 'query' )->andReturn( 5 );

        $deleted = ActivityLogQuery::run_cleanup();
        $this->assertSame( 5, $deleted );
    }
}
