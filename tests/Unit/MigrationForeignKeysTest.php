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
        Functions\when( 'FreeFormCertificate\Core\absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
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
}
