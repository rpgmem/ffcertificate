<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\Strategies\ActivityLogClearPlaintextMigrationStrategy;

/**
 * Tests for ActivityLogClearPlaintextMigrationStrategy: NULL-out plaintext
 * `context` on rows where `context_encrypted` already holds the payload.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\ActivityLogClearPlaintextMigrationStrategy
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class ActivityLogClearPlaintextMigrationStrategyTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface */
    private $wpdb;

    private ActivityLogClearPlaintextMigrationStrategy $strategy;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb              = Mockery::mock( 'wpdb' );
        $wpdb->prefix      = 'wp_';
        $wpdb->last_error  = '';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
        $this->wpdb = $wpdb;

        if ( ! class_exists( 'FreeFormCertificate\Migrations\Strategies\WP_Error' ) ) {
            class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\Strategies\WP_Error' );
        }

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'get_option' )->justReturn( 0 );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) { return $thing instanceof \WP_Error; } );

        Functions\when( 'FreeFormCertificate\Migrations\Strategies\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\Strategies\get_option' )->justReturn( 0 );
        Functions\when( 'FreeFormCertificate\Migrations\Strategies\update_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Core\get_option' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Settings\get_option' )->justReturn( array() );

        $this->strategy = new ActivityLogClearPlaintextMigrationStrategy();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // get_name()
    // ------------------------------------------------------------------

    public function test_get_name_returns_human_readable_label(): void {
        $this->assertSame(
            'Activity Log: Clear Plaintext Context on Encrypted Rows',
            $this->strategy->get_name()
        );
    }

    // ------------------------------------------------------------------
    // can_run()
    // ------------------------------------------------------------------

    public function test_can_run_returns_true_when_table_exists(): void {
        // table_exists() reads SHOW TABLES LIKE => must return the table name.
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_activity_log' );

        $result = $this->strategy->can_run( 'activity_log_clear_plaintext', array() );

        $this->assertTrue( $result );
    }

    public function test_can_run_returns_wp_error_when_table_missing(): void {
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $result = $this->strategy->can_run( 'activity_log_clear_plaintext', array() );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'activity_log_table_missing', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // calculate_status() — schema short-circuits
    // ------------------------------------------------------------------

    public function test_calculate_status_returns_complete_when_table_missing(): void {
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null ); // table_exists => false

        $status = $this->strategy->calculate_status( 'activity_log_clear_plaintext', array() );

        $this->assertSame( 0, $status['total'] );
        $this->assertSame( 0, $status['pending'] );
        $this->assertTrue( $status['is_complete'] );
        $this->assertSame( 100, $status['percent'] );
    }

    public function test_calculate_status_returns_complete_when_context_column_missing(): void {
        // table_exists: yes; column_exists(context) -> get_results returns empty.
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_activity_log' );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        $status = $this->strategy->calculate_status( 'activity_log_clear_plaintext', array() );

        $this->assertTrue( $status['is_complete'] );
    }

    // ------------------------------------------------------------------
    // calculate_status() — counts from queries
    // ------------------------------------------------------------------

    public function test_calculate_status_reports_pending_and_percent(): void {
        // Sequence: table_exists, COUNT(total), COUNT(pending). column_exists
        // returns non-empty for both 'context' + 'context_encrypted'.
        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_activity_log', // table_exists
            '100',                 // total
            '40'                   // pending
        );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'context' ) ) );

        $status = $this->strategy->calculate_status( 'activity_log_clear_plaintext', array() );

        $this->assertSame( 100, $status['total'] );
        $this->assertSame( 60, $status['migrated'] );
        $this->assertSame( 40, $status['pending'] );
        $this->assertSame( 60.0, $status['percent'] );
        $this->assertFalse( $status['is_complete'] );
    }

    public function test_calculate_status_handles_zero_total_without_division_by_zero(): void {
        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_activity_log',
            '0',
            '0'
        );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array() ) );

        $status = $this->strategy->calculate_status( 'activity_log_clear_plaintext', array() );

        $this->assertSame( 100.0, (float) $status['percent'] );
        $this->assertTrue( $status['is_complete'] );
    }

    // ------------------------------------------------------------------
    // execute() — schema short-circuit
    // ------------------------------------------------------------------

    public function test_execute_no_op_when_table_missing(): void {
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $result = $this->strategy->execute( 'activity_log_clear_plaintext', array() );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertFalse( $result['has_more'] );
    }

    // ------------------------------------------------------------------
    // execute() — empty-IDs branch (advances cursor to MAX(id))
    // ------------------------------------------------------------------

    public function test_execute_advances_cursor_to_max_id_when_no_pending_rows(): void {
        // table_exists yes; column_exists yes; get_col empty (no matching ids);
        // MAX(id) query returns 999.
        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_activity_log', // table_exists
            '999'                  // COALESCE(MAX(id), 0)
        );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array() ) );
        $this->wpdb->shouldReceive( 'get_col' )->andReturn( array() );

        $cursor_updates = 0;
        $tracker        = function ( $key, $value ) use ( &$cursor_updates ) {
            if ( 'ffc_activity_log_clear_plaintext_cursor' === $key ) {
                $cursor_updates++;
            }
            return true;
        };
        Functions\when( 'update_option' )->alias( $tracker );
        Functions\when( 'FreeFormCertificate\Migrations\Strategies\update_option' )->alias( $tracker );

        $result = $this->strategy->execute( 'activity_log_clear_plaintext', array() );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertFalse( $result['has_more'] );
        $this->assertSame( 1, $cursor_updates, 'cursor should be advanced to MAX(id)' );
    }

    // ------------------------------------------------------------------
    // execute() — happy path
    // ------------------------------------------------------------------

    public function test_execute_updates_batch_and_advances_cursor(): void {
        // get_var sequence:
        //   execute() prelude → table_exists
        //   calculate_status() → table_exists, COUNT(total), COUNT(pending)
        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_activity_log', // execute table_exists
            'wp_ffc_activity_log', // calculate_status table_exists
            '100',                 // total
            '95'                   // pending (still has_more)
        );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array() ) );
        $this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '10', '20', '30', '42' ) );
        $this->wpdb->shouldReceive( 'query' )->andReturn( 4 ); // UPDATE affected 4 rows.

        $captured_cursor = null;
        $tracker         = function ( $key, $value ) use ( &$captured_cursor ) {
            if ( 'ffc_activity_log_clear_plaintext_cursor' === $key ) {
                $captured_cursor = $value;
            }
            return true;
        };
        Functions\when( 'update_option' )->alias( $tracker );
        Functions\when( 'FreeFormCertificate\Migrations\Strategies\update_option' )->alias( $tracker );

        $result = $this->strategy->execute( 'activity_log_clear_plaintext', array( 'batch_size' => 200 ) );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 4, $result['processed'] );
        $this->assertTrue( $result['has_more'], 'pending > 0 means more work remains' );
        $this->assertSame( 42, $captured_cursor, 'cursor should advance to last id in batch' );
    }

    public function test_execute_marks_failure_when_update_query_returns_false(): void {
        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_activity_log',
            'wp_ffc_activity_log',
            '50',
            '50'
        );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array() ) );
        $this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '7' ) );
        $this->wpdb->shouldReceive( 'query' )->andReturn( false );
        $this->wpdb->last_error = 'Deadlock found when trying to get lock';

        $result = $this->strategy->execute( 'activity_log_clear_plaintext', array() );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertContains( 'Deadlock found when trying to get lock', $result['errors'] );
    }

    public function test_execute_respects_batch_size_from_config(): void {
        $captured_args = array();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
            function ( $sql, ...$args ) use ( &$captured_args ) {
                $captured_args[] = $args;
                return 'SQL';
            }
        );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_activity_log', // execute table_exists
            'wp_ffc_activity_log', // calculate_status table_exists
            '5',
            '0' // pending=0 → has_more=false
        );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array() ) );
        $this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '1', '2', '3' ) );
        $this->wpdb->shouldReceive( 'query' )->andReturn( 3 );

        $result = $this->strategy->execute( 'activity_log_clear_plaintext', array( 'batch_size' => 50 ) );

        // The SELECT id query receives (table, cursor, batch_size).
        $found_batch_size = false;
        foreach ( $captured_args as $args ) {
            if ( in_array( 50, $args, true ) ) {
                $found_batch_size = true;
                break;
            }
        }
        $this->assertTrue( $found_batch_size, 'batch_size from config should reach the prepared statement' );
        $this->assertFalse( $result['has_more'] );
    }
}
