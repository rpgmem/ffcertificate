<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\MigrationCustomFieldsTables;

/**
 * Tests for MigrationCustomFieldsTables.
 *
 * Covers table creation migration logic: run(), is_completed(), get_status().
 *
 * @covers \FreeFormCertificate\Migrations\MigrationCustomFieldsTables
 */
class MigrationCustomFieldsTablesTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock $wpdb
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'utf8mb4_general_ci' )->byDefault();

        $this->wpdb = $wpdb;

        // Global WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\dbDelta' )->justReturn( array() );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // is_completed()
    // ==================================================================

    public function test_is_completed_returns_true_when_option_set(): void {
        Functions\when( 'get_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( true );

        $this->assertTrue( MigrationCustomFieldsTables::is_completed() );
    }

    public function test_is_completed_returns_false_when_option_not_set(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );

        $this->assertFalse( MigrationCustomFieldsTables::is_completed() );
    }

    // ==================================================================
    // run() — already completed
    // ==================================================================

    public function test_run_returns_early_when_already_completed(): void {
        Functions\when( 'get_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( true );

        $result = MigrationCustomFieldsTables::run();

        $this->assertTrue( $result['success'] );
        $this->assertEmpty( $result['details'] );
        $this->assertStringContainsString( 'already completed', $result['message'] );
    }

    // ==================================================================
    // run() — tables already exist (skipped via table_exists)
    // ==================================================================

    public function test_run_skips_existing_tables(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );

        // table_exists checks: get_var(prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name
        // We capture the table name from prepare and return it from get_var so the === passes.
        $last_table_name = '';
        $this->wpdb->shouldReceive( 'prepare' )
            ->andReturnUsing( function () use ( &$last_table_name ) {
                $args = func_get_args();
                if ( isset( $args[1] ) && is_string( $args[1] ) && strpos( $args[1], 'wp_ffc_' ) === 0 ) {
                    $last_table_name = $args[1];
                }
                return 'Q';
            } );

        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function () use ( &$last_table_name ) {
                return $last_table_name;
            } );

        $result = MigrationCustomFieldsTables::run();

        $this->assertTrue( $result['success'] );
        $this->assertCount( 3, $result['details'] );

        // All should have succeeded (skipped because already existing)
        foreach ( $result['details'] as $detail ) {
            $this->assertTrue( $detail['success'] );
            $this->assertStringContainsString( 'already exists', $detail['message'] );
        }
    }

    // ==================================================================
    // run() — tables don't exist, creation succeeds
    // ==================================================================

    public function test_run_creates_tables_successfully(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );

        // table_exists calls: first check returns null (doesn't exist),
        // after dbDelta, second check returns the table name (exists now).
        // For each of the 3 tables: 1st call = null, 2nd call = table_name
        $last_table_name = '';
        $call_count = 0;

        $this->wpdb->shouldReceive( 'prepare' )
            ->andReturnUsing( function () use ( &$last_table_name ) {
                $args = func_get_args();
                if ( isset( $args[1] ) && is_string( $args[1] ) && strpos( $args[1], 'wp_ffc_' ) === 0 ) {
                    $last_table_name = $args[1];
                }
                return 'Q';
            } );

        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function () use ( &$last_table_name, &$call_count ) {
                $call_count++;
                // Odd calls = first table_exists (null = doesn't exist)
                // Even calls = second table_exists after dbDelta (table name = exists)
                return ( $call_count % 2 === 0 ) ? $last_table_name : null;
            } );

        $result = MigrationCustomFieldsTables::run();

        $this->assertTrue( $result['success'] );
        $this->assertCount( 3, $result['details'] );
        $this->assertStringContainsString( 'successfully', $result['message'] );
    }

    // ==================================================================
    // run() — table creation fails
    // ==================================================================

    public function test_run_reports_failure_when_table_creation_fails(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'dbDelta' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Migrations\dbDelta' )->justReturn( array() );

        // table_exists always returns null (table never gets created)
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $result = MigrationCustomFieldsTables::run();

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'could not be created', $result['message'] );

        // All details should show failure
        foreach ( $result['details'] as $detail ) {
            $this->assertFalse( $detail['success'] );
            $this->assertStringContainsString( 'Failed', $detail['message'] );
        }
    }

    // ==================================================================
    // run() — partial failure (some tables fail)
    // ==================================================================

    public function test_run_reports_partial_failure(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'dbDelta' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Migrations\dbDelta' )->justReturn( array() );

        // First table: doesn't exist, then created (2 calls)
        // Second table: doesn't exist, still doesn't exist after dbDelta (2 calls)
        // Third table: doesn't exist, still doesn't exist after dbDelta (2 calls)
        $call_count = 0;
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing( function () use ( &$call_count ) {
                $call_count++;
                // Call 1: first table check (null = doesn't exist)
                // Call 2: first table post-dbDelta (exists)
                // Call 3-6: remaining tables (all null = fail)
                if ( $call_count === 2 ) {
                    return 'wp_ffc_table';
                }
                return null;
            } );

        $result = MigrationCustomFieldsTables::run();

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'could not be created', $result['message'] );
    }

    // ==================================================================
    // get_status()
    // ==================================================================

    public function test_get_status_when_completed(): void {
        Functions\when( 'get_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( true );

        // table_exists returns table name for all 3 tables
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn( 'wp_ffc_table' );

        $status = MigrationCustomFieldsTables::get_status();

        $this->assertTrue( $status['completed'] );
        $this->assertArrayHasKey( 'tables', $status );
        $this->assertCount( 3, $status['tables'] );

        // Verify expected table keys
        $this->assertArrayHasKey( 'ffc_custom_fields', $status['tables'] );
        $this->assertArrayHasKey( 'ffc_reregistrations', $status['tables'] );
        $this->assertArrayHasKey( 'ffc_reregistration_submissions', $status['tables'] );
    }

    public function test_get_status_when_not_completed(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );

        // table_exists returns null (tables don't exist)
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $status = MigrationCustomFieldsTables::get_status();

        $this->assertFalse( $status['completed'] );
        $this->assertCount( 3, $status['tables'] );

        foreach ( $status['tables'] as $table_info ) {
            $this->assertFalse( $table_info['exists'] );
            $this->assertArrayHasKey( 'table', $table_info );
        }
    }

    public function test_get_status_table_info_structure(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );

        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_custom_fields' );

        $status = MigrationCustomFieldsTables::get_status();

        foreach ( $status['tables'] as $suffix => $info ) {
            $this->assertArrayHasKey( 'table', $info );
            $this->assertArrayHasKey( 'exists', $info );
            $this->assertStringStartsWith( 'wp_', $info['table'] );
        }
    }
}
