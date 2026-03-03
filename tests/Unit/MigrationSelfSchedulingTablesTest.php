<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\MigrationSelfSchedulingTables;

/**
 * Tests for MigrationSelfSchedulingTables.
 *
 * Covers table rename migration logic: run(), rollback(), get_status(), is_completed().
 *
 * @covers \FreeFormCertificate\Migrations\MigrationSelfSchedulingTables
 */
class MigrationSelfSchedulingTablesTest extends TestCase {

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

        $this->wpdb = $wpdb;

        // Global WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\__' )->returnArg();
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

        $this->assertTrue( MigrationSelfSchedulingTables::is_completed() );
    }

    public function test_is_completed_returns_false_when_option_not_set(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );

        $this->assertFalse( MigrationSelfSchedulingTables::is_completed() );
    }

    // ==================================================================
    // run() — already completed
    // ==================================================================

    public function test_run_returns_early_when_already_completed(): void {
        Functions\when( 'get_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( true );

        $result = MigrationSelfSchedulingTables::run();

        $this->assertTrue( $result['success'] );
        $this->assertEmpty( $result['details'] );
        $this->assertStringContainsString( 'already completed', $result['message'] );
    }

    // ==================================================================
    // run() — new table already exists (skip)
    // ==================================================================

    public function test_run_skips_when_new_table_already_exists(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );

        // For each table pair: old_exists check returns '0', new_exists check returns '1'
        // prepare() is called for each information_schema query
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' );

        // For every get_var call: alternate between old table check (0) and new table check (1)
        // Since there are 3 table pairs, that's 6 get_var calls
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn( '0', '1', '0', '1', '0', '1' );

        $result = MigrationSelfSchedulingTables::run();

        $this->assertTrue( $result['success'] );
        $this->assertCount( 3, $result['details'] );
    }

    // ==================================================================
    // run() — old table doesn't exist (nothing to rename)
    // ==================================================================

    public function test_run_succeeds_when_old_tables_dont_exist(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );

        // Both old and new tables don't exist
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

        $result = MigrationSelfSchedulingTables::run();

        $this->assertTrue( $result['success'] );
        $this->assertCount( 3, $result['details'] );
        foreach ( $result['details'] as $detail ) {
            $this->assertTrue( $detail['success'] );
        }
    }

    // ==================================================================
    // run() — rename succeeds
    // ==================================================================

    public function test_run_renames_tables_successfully(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );

        // old_exists = 1, new_exists = 0 for each table pair
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn( '1', '0', '1', '0', '1', '0' );

        // RENAME TABLE succeeds (returns 0, not false)
        $this->wpdb->shouldReceive( 'query' )->andReturn( 0 );

        $result = MigrationSelfSchedulingTables::run();

        $this->assertTrue( $result['success'] );
        $this->assertCount( 3, $result['details'] );
    }

    // ==================================================================
    // run() — rename fails
    // ==================================================================

    public function test_run_reports_failure_when_rename_fails(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );

        // old_exists = 1, new_exists = 0
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn( '1', '0', '1', '0', '1', '0' );

        // RENAME TABLE fails
        $this->wpdb->shouldReceive( 'query' )->andReturn( false );
        $this->wpdb->last_error = 'Table rename failed';

        $result = MigrationSelfSchedulingTables::run();

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'could not be renamed', $result['message'] );
    }

    // ==================================================================
    // get_status()
    // ==================================================================

    public function test_get_status_returns_completed_status(): void {
        Functions\when( 'get_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( true );

        // 3 table pairs x 2 get_var calls each
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '0', '1', '0', '1', '0', '1' );

        $status = MigrationSelfSchedulingTables::get_status();

        $this->assertTrue( $status['completed'] );
        $this->assertArrayHasKey( 'tables', $status );
        $this->assertCount( 3, $status['tables'] );
    }

    public function test_get_status_shows_table_migration_state(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );

        // All tables: old exists, new does not
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '1', '0', '1', '0', '1', '0' );

        $status = MigrationSelfSchedulingTables::get_status();

        $this->assertFalse( $status['completed'] );
        foreach ( $status['tables'] as $table_info ) {
            $this->assertArrayHasKey( 'old_table', $table_info );
            $this->assertArrayHasKey( 'new_table', $table_info );
            $this->assertArrayHasKey( 'old_exists', $table_info );
            $this->assertArrayHasKey( 'new_exists', $table_info );
            $this->assertArrayHasKey( 'migrated', $table_info );
        }
    }

    // ==================================================================
    // rollback()
    // ==================================================================

    public function test_rollback_renames_tables_back(): void {
        Functions\when( 'delete_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\delete_option' )->justReturn( true );

        // For rollback: new table exists (current_table = new), original doesn't
        // old_exists check = 1, new_exists check = 0
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn( '1', '0', '1', '0', '1', '0' );

        $this->wpdb->shouldReceive( 'query' )->andReturn( 0 );

        $result = MigrationSelfSchedulingTables::rollback();

        $this->assertTrue( $result['success'] );
        $this->assertCount( 3, $result['details'] );
    }

    public function test_rollback_reports_failure(): void {
        // new table exists (to be renamed), original doesn't exist
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn( '1', '0', '1', '0', '1', '0' );

        $this->wpdb->shouldReceive( 'query' )->andReturn( false );
        $this->wpdb->last_error = 'Rollback failed';

        $result = MigrationSelfSchedulingTables::rollback();

        $this->assertFalse( $result['success'] );
    }
}
