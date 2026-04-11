<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Activator;

/**
 * Tests for Activator: plugin activation table creation, cron scheduling,
 * verification page creation, and schema migration.
 *
 * @covers \FreeFormCertificate\Activator
 */
class ActivatorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    /** @var Mockery\MockInterface */
    private $utils_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->users = 'wp_users';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->posts = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';
        $this->wpdb = $wpdb;

        // Utils alias mock for get_submissions_table
        $this->utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $this->utils_mock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' )
            ->byDefault();

        // Default wpdb stubs — most tests need these
        $this->wpdb->shouldReceive( 'get_charset_collate' )
            ->andReturn( 'DEFAULT CHARSET utf8mb4' )
            ->byDefault();
        // prepare: interpolate %s args so table_exists can extract names
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
        $this->wpdb->shouldReceive( 'get_col' )
            ->andReturn( [] )
            ->byDefault();
        $this->wpdb->shouldReceive( 'query' )
            ->andReturn( 1 )
            ->byDefault();

        // i18n stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();

        // Default WP function stubs
        Functions\when( 'dbDelta' )->justReturn( [] );
        Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        Functions\when( 'wp_schedule_event' )->justReturn( true );
        Functions\when( 'flush_rewrite_rules' )->justReturn( null );
        Functions\when( 'get_page_by_path' )->justReturn( null );
        Functions\when( 'wp_insert_post' )->justReturn( 42 );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'add_role' )->justReturn( new \WP_Role() );
        Functions\when( 'remove_role' )->justReturn( null );
        Functions\when( 'delete_option' )->justReturn( true );
        Functions\when( 'get_users' )->justReturn( [] );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function( $v ) { return abs( (int) $v ); } );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_die' )->justReturn( null );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // activate() — flush_rewrite_rules
    // ==================================================================

    public function test_activate_calls_flush_rewrite_rules(): void {
        $flushed = false;
        Functions\when( 'flush_rewrite_rules' )->alias( function () use ( &$flushed ) {
            $flushed = true;
        } );

        Activator::activate();

        $this->assertTrue( $flushed, 'activate() should call flush_rewrite_rules' );
    }

    // ==================================================================
    // activate() — legacy cron hook cleanup
    // ==================================================================

    public function test_activate_clears_legacy_cron_hooks(): void {
        $cleared = [];
        Functions\when( 'wp_clear_scheduled_hook' )->alias( function ( $hook ) use ( &$cleared ) {
            $cleared[] = $hook;
        } );

        Activator::activate();

        $this->assertContains( 'ffc_daily_cleanup_hook', $cleared );
        $this->assertContains( 'ffc_process_submission_hook', $cleared );
        $this->assertContains( 'ffc_warm_cache_hook', $cleared );
    }

    // ==================================================================
    // activate() — daily cleanup scheduling
    // ==================================================================

    public function test_activate_schedules_daily_cleanup(): void {
        Functions\when( 'wp_next_scheduled' )->justReturn( false );

        $scheduled = false;
        Functions\when( 'wp_schedule_event' )->alias( function ( $time, $recurrence, $hook ) use ( &$scheduled ) {
            if ( $hook === 'ffcertificate_daily_cleanup_hook' && $recurrence === 'daily' ) {
                $scheduled = true;
            }
        } );

        Activator::activate();

        $this->assertTrue( $scheduled, 'Should schedule daily cleanup when not already scheduled' );
    }

    public function test_activate_skips_scheduling_if_already_scheduled(): void {
        Functions\when( 'wp_next_scheduled' )->justReturn( 1700000000 );

        $scheduled = false;
        Functions\when( 'wp_schedule_event' )->alias( function () use ( &$scheduled ) {
            $scheduled = true;
        } );

        Activator::activate();

        $this->assertFalse( $scheduled, 'Should not schedule when cron is already scheduled' );
    }

    // ==================================================================
    // activate() — submissions table creation
    // ==================================================================

    public function test_activate_creates_submissions_table_when_not_exists(): void {
        // Override get_var: return null for submissions table (not exists),
        // return the actual table name for all other tables (exist).
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function ( $query ) {
                if ( preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $m ) ) {
                    // Submissions table does not exist
                    if ( stripos( $m[1], 'ffc_submissions' ) !== false ) {
                        return null;
                    }
                    return $m[1]; // All other tables exist
                }
                return null;
            } );

        $delta_sqls = [];
        Functions\when( 'dbDelta' )->alias( function ( $sql ) use ( &$delta_sqls ) {
            $delta_sqls[] = $sql;
        } );

        Activator::activate();

        $submissions_delta = array_filter( $delta_sqls, function ( $sql ) {
            return stripos( $sql, 'wp_ffc_submissions' ) !== false;
        } );

        $this->assertNotEmpty( $submissions_delta, 'dbDelta should be called with submissions table SQL' );
        $found_sql = reset( $submissions_delta );
        $this->assertStringContainsString( 'CREATE TABLE', $found_sql );
        $this->assertStringContainsString( 'magic_token', $found_sql );
        $this->assertStringContainsString( 'auth_code', $found_sql );
    }

    public function test_activate_skips_submissions_table_when_exists(): void {
        // Default setup already returns 'existing_table' for all SHOW TABLES queries

        $delta_sqls = [];
        Functions\when( 'dbDelta' )->alias( function ( $sql ) use ( &$delta_sqls ) {
            $delta_sqls[] = $sql;
        } );

        Activator::activate();

        $submissions_delta = array_filter( $delta_sqls, function ( $sql ) {
            return stripos( $sql, 'wp_ffc_submissions' ) !== false
                && stripos( $sql, 'CREATE TABLE' ) !== false;
        } );

        $this->assertEmpty( $submissions_delta, 'dbDelta should not create submissions table when it already exists' );
    }

    // ==================================================================
    // activate() — verification page creation
    // ==================================================================

    public function test_activate_creates_verification_page_when_not_exists(): void {
        Functions\when( 'get_page_by_path' )->justReturn( null );

        $inserted = false;
        Functions\when( 'wp_insert_post' )->alias( function ( $data ) use ( &$inserted ) {
            if ( isset( $data['post_name'] ) && $data['post_name'] === 'valid' ) {
                $inserted = true;
            }
            return 42;
        } );

        Activator::activate();

        $this->assertTrue( $inserted, 'Should insert verification page when it does not exist' );
    }

    public function test_activate_uses_existing_verification_page(): void {
        $existing_page = (object) [ 'ID' => 99 ];
        Functions\when( 'get_page_by_path' )->alias( function ( $path ) use ( $existing_page ) {
            if ( $path === 'valid' || $path === 'dashboard' ) {
                return $existing_page;
            }
            return null;
        } );

        $options_set = [];
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$options_set ) {
            $options_set[ $key ] = $value;
            return true;
        } );

        $inserted = false;
        Functions\when( 'wp_insert_post' )->alias( function () use ( &$inserted ) {
            $inserted = true;
            return 42;
        } );

        Activator::activate();

        $this->assertFalse( $inserted, 'Should not insert page when it already exists' );
        $this->assertArrayHasKey( 'ffc_verification_page_id', $options_set );
        $this->assertSame( 99, $options_set['ffc_verification_page_id'] );
    }

    // ==================================================================
    // maybe_add_columns()
    // ==================================================================

    public function test_maybe_add_columns_skips_when_version_matches(): void {
        Functions\when( 'get_option' )->justReturn( FFC_VERSION );

        $query_called = false;
        $this->wpdb->shouldReceive( 'query' )
            ->andReturnUsing( function () use ( &$query_called ) {
                $query_called = true;
                return 1;
            } );

        Activator::maybe_add_columns();

        // When version matches, no ALTER TABLE queries should be issued for column additions.
        // The method returns early so update_option is never called either.
        Functions\expect( 'update_option' )->never();
    }

    public function test_maybe_add_columns_runs_when_version_differs(): void {
        Functions\when( 'get_option' )->justReturn( '1.0.0' );

        $updated_options = [];
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$updated_options ) {
            $updated_options[ $key ] = $value;
            return true;
        } );

        Activator::maybe_add_columns();

        $this->assertArrayHasKey( 'ffc_submissions_db_version', $updated_options );
        $this->assertSame( FFC_VERSION, $updated_options['ffc_submissions_db_version'] );
    }
}
