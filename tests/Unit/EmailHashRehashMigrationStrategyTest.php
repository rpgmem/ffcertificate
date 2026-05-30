<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\Strategies\EmailHashRehashMigrationStrategy;

/**
 * Tests for EmailHashRehashMigrationStrategy: rehash legacy unsalted
 * email_hash values using the salted Encryption::hash().
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\EmailHashRehashMigrationStrategy
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class EmailHashRehashMigrationStrategyTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface */
    private $wpdb;

    private EmailHashRehashMigrationStrategy $strategy;

    /** @var array<string, mixed> */
    private array $options;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb             = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'update' )->andReturn( 1 )->byDefault();
        $this->wpdb = $wpdb;

        if ( ! class_exists( 'FreeFormCertificate\Migrations\Strategies\WP_Error' ) ) {
            class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\Strategies\WP_Error' );
        }

        // In-memory option store so cursor + completion latch behave naturally.
        $this->options = array();
        $get_opt       = function ( $key, $default = false ) {
            return $this->options[ $key ] ?? $default;
        };
        $set_opt = function ( $key, $value ) {
            $this->options[ $key ] = $value;
            return true;
        };

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'get_option' )->alias( $get_opt );
        Functions\when( 'update_option' )->alias( $set_opt );

        Functions\when( 'FreeFormCertificate\Migrations\Strategies\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\Strategies\get_option' )->alias( $get_opt );
        Functions\when( 'FreeFormCertificate\Migrations\Strategies\update_option' )->alias( $set_opt );
        Functions\when( 'FreeFormCertificate\Core\get_option' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Settings\get_option' )->justReturn( array() );

        $this->strategy = new EmailHashRehashMigrationStrategy();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // get_name() + can_run()
    // ------------------------------------------------------------------

    public function test_get_name_returns_human_readable_label(): void {
        $this->assertSame( 'Email Hash Rehash', $this->strategy->get_name() );
    }

    public function test_can_run_returns_true_when_encryption_configured(): void {
        // Encryption::is_configured() reads SECURE_AUTH_KEY which is set in bootstrap.
        $result = $this->strategy->can_run( 'email_hash_rehash', array() );

        $this->assertTrue( $result );
    }

    // ------------------------------------------------------------------
    // calculate_status() — completion latch wins
    // ------------------------------------------------------------------

    public function test_calculate_status_reports_100_percent_when_completion_latched(): void {
        $this->options['ffc_email_hash_rehash_completed'] = true;

        // Both tables exist + have rows: total should still pass through, but
        // the latch overrides migrated/pending.
        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_submissions',                  // table_exists submissions
            '50',                                  // total submissions
            '0',                                   // migrated submissions
            'wp_ffc_self_scheduling_appointments', // table_exists appointments
            '20',                                  // total appointments
            '0'                                    // migrated appointments
        );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array() ) );

        $status = $this->strategy->calculate_status( 'email_hash_rehash', array() );

        $this->assertSame( 70, $status['total'] );
        $this->assertSame( 70, $status['migrated'] );
        $this->assertSame( 0, $status['pending'] );
        $this->assertSame( 100.0, $status['percent'] );
        $this->assertTrue( $status['is_complete'] );
    }

    // ------------------------------------------------------------------
    // calculate_status() — schema short-circuits
    // ------------------------------------------------------------------

    public function test_calculate_status_treats_missing_tables_as_zero(): void {
        // table_exists returns null for both tables.
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $status = $this->strategy->calculate_status( 'email_hash_rehash', array() );

        $this->assertSame( 0, $status['total'] );
        $this->assertSame( 0, $status['pending'] );
        $this->assertTrue( $status['is_complete'] );
        $this->assertSame( 100.0, (float) $status['percent'] );
    }

    public function test_calculate_status_aggregates_pending_from_both_tables(): void {
        // submissions: total=100, cursor=60 → migrated 60, pending 40.
        // appointments: total=20, cursor=20 → migrated 20, pending 0.
        $this->options['ffc_email_hash_rehash_cursor_wp_ffc_submissions']                  = 60;
        $this->options['ffc_email_hash_rehash_cursor_wp_ffc_self_scheduling_appointments'] = 20;

        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_submissions',                  // table_exists submissions
            '100',                                 // total submissions
            '60',                                  // migrated submissions
            'wp_ffc_self_scheduling_appointments', // table_exists appointments
            '20',                                  // total appointments
            '20'                                   // migrated appointments
        );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array() ) );

        $status = $this->strategy->calculate_status( 'email_hash_rehash', array() );

        $this->assertSame( 120, $status['total'] );
        $this->assertSame( 80, $status['migrated'] );
        $this->assertSame( 40, $status['pending'] );
        $this->assertFalse( $status['is_complete'] );
        $this->assertGreaterThan( 0, $status['percent'] );
        $this->assertLessThan( 100, $status['percent'] );
    }

    // ------------------------------------------------------------------
    // execute() — completion latch
    // ------------------------------------------------------------------

    public function test_execute_short_circuits_when_already_completed(): void {
        $this->options['ffc_email_hash_rehash_completed'] = true;

        $result = $this->strategy->execute( 'email_hash_rehash', array() );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertFalse( $result['has_more'] );
    }

    // ------------------------------------------------------------------
    // execute() — empty tables advance cursor to MAX(id) and latch complete
    // ------------------------------------------------------------------

    public function test_execute_latches_completion_when_both_tables_fully_walked(): void {
        // process_table for each table: get_results empty → MAX(id) → cursor advance.
        // calculate_status() then sees pending=0 because cursor == total covered.
        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            // process_table submissions: MAX(id) lookup when no records
            '50',
            // process_table appointments: MAX(id) lookup
            '20',
            // calculate_status submissions: table_exists, total, migrated
            'wp_ffc_submissions',
            '10',
            '10',
            // calculate_status appointments: table_exists, total, migrated
            'wp_ffc_self_scheduling_appointments',
            '5',
            '5'
        );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
        // process_table needs column_exists yes → get_results non-empty. But we
        // already stubbed get_results to empty, which would also fail
        // column_exists. Override:
        $col_seq = 0;
        $this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
            function () use ( &$col_seq ) {
                $col_seq++;
                // First 4 calls are column_exists (2 cols × 2 tables) → must
                // be non-empty; subsequent calls (the SELECT records) → empty.
                if ( $col_seq <= 4 ) {
                    return array( (object) array() );
                }
                return array();
            }
        );

        $result = $this->strategy->execute( 'email_hash_rehash', array() );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertFalse( $result['has_more'] );
        $this->assertTrue(
            (bool) ( $this->options['ffc_email_hash_rehash_completed'] ?? false ),
            'completion latch should be set when both tables are fully walked'
        );
    }

    // ------------------------------------------------------------------
    // execute() — happy path: rehash a row whose hash differs
    // ------------------------------------------------------------------

    public function test_execute_rehashes_row_when_current_hash_differs(): void {
        $plain     = 'user@example.com';
        $encrypted = \FreeFormCertificate\Core\Encryption::encrypt( $plain );
        $this->assertNotNull( $encrypted, 'precondition: Encryption must be configured' );
        $legacy_hash = hash( 'sha256', $plain ); // pre-fix unsalted hash.

        $record = array(
            'id'              => 7,
            'email_encrypted' => $encrypted,
            'email_hash'      => $legacy_hash,
        );

        // get_var sequence:
        //   process_table submissions: table_exists
        //   process_table appointments: table_exists, MAX(id) (empty)
        //   calculate_status submissions: table_exists, total, migrated
        //   calculate_status appointments: table_exists, total, migrated
        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_submissions',
            'wp_ffc_self_scheduling_appointments',
            '0', // appointments MAX(id) when no records
            'wp_ffc_submissions',
            '10',
            '10',
            'wp_ffc_self_scheduling_appointments',
            '5',
            '5'
        );

        $col_seq = 0;
        $this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
            function () use ( &$col_seq, $record ) {
                $col_seq++;
                // 1,2 = column_exists submissions (email_hash, email_encrypted)
                // 3   = SELECT records from submissions → our test row
                // 4,5 = column_exists appointments
                // 6   = SELECT records from appointments → empty
                // 7,8 = column_exists submissions inside calculate_status
                // 9,10= column_exists appointments inside calculate_status
                if ( 3 === $col_seq ) {
                    return array( $record );
                }
                if ( 6 === $col_seq ) {
                    return array();
                }
                return array( (object) array() );
            }
        );

        $update_captured = null;
        $this->wpdb->shouldReceive( 'update' )->andReturnUsing(
            function ( $table, $data, $where ) use ( &$update_captured ) {
                $update_captured = compact( 'table', 'data', 'where' );
                return 1;
            }
        );

        $result = $this->strategy->execute( 'email_hash_rehash', array() );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['processed'] );
        $this->assertNotNull( $update_captured );
        $this->assertSame( 'wp_ffc_submissions', $update_captured['table'] );
        $this->assertSame( 7, $update_captured['where']['id'] );
        $this->assertSame(
            \FreeFormCertificate\Core\Encryption::hash( $plain ),
            $update_captured['data']['email_hash']
        );
    }

    public function test_execute_skips_row_when_current_hash_already_correct(): void {
        $plain     = 'user@example.com';
        $encrypted = \FreeFormCertificate\Core\Encryption::encrypt( $plain );
        $this->assertNotNull( $encrypted );
        $correct_hash = \FreeFormCertificate\Core\Encryption::hash( $plain );

        $record = array(
            'id'              => 9,
            'email_encrypted' => $encrypted,
            'email_hash'      => $correct_hash,
        );

        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_submissions',
            'wp_ffc_self_scheduling_appointments',
            '0',
            'wp_ffc_submissions', '10', '10',
            'wp_ffc_self_scheduling_appointments', '5', '5'
        );

        $col_seq = 0;
        $this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
            function () use ( &$col_seq, $record ) {
                $col_seq++;
                if ( 3 === $col_seq ) {
                    return array( $record );
                }
                if ( 6 === $col_seq ) {
                    return array();
                }
                return array( (object) array() );
            }
        );

        $update_called = false;
        $this->wpdb->shouldReceive( 'update' )->andReturnUsing(
            function () use ( &$update_called ) {
                $update_called = true;
                return 1;
            }
        );

        $result = $this->strategy->execute( 'email_hash_rehash', array() );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertFalse( $update_called, 'idempotent: rows with correct hash skip the UPDATE' );
    }

    public function test_execute_records_error_when_decryption_fails(): void {
        $record = array(
            'id'              => 11,
            'email_encrypted' => 'not-a-valid-ciphertext',
            'email_hash'      => 'whatever',
        );

        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_submissions',
            'wp_ffc_self_scheduling_appointments',
            '0',
            'wp_ffc_submissions', '10', '10',
            'wp_ffc_self_scheduling_appointments', '5', '5'
        );

        $col_seq = 0;
        $this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
            function () use ( &$col_seq, $record ) {
                $col_seq++;
                if ( 3 === $col_seq ) {
                    return array( $record );
                }
                if ( 6 === $col_seq ) {
                    return array();
                }
                return array( (object) array() );
            }
        );

        $result = $this->strategy->execute( 'email_hash_rehash', array() );

        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'Could not decrypt email', $result['errors'][0] );
        $this->assertSame( 0, $result['processed'] );
    }

    public function test_execute_skips_record_with_empty_encrypted_payload(): void {
        $record = array(
            'id'              => 13,
            'email_encrypted' => '',
            'email_hash'      => 'irrelevant',
        );

        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_submissions',
            'wp_ffc_self_scheduling_appointments',
            '0',
            'wp_ffc_submissions', '10', '10',
            'wp_ffc_self_scheduling_appointments', '5', '5'
        );

        $col_seq = 0;
        $this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
            function () use ( &$col_seq, $record ) {
                $col_seq++;
                if ( 3 === $col_seq ) {
                    return array( $record );
                }
                if ( 6 === $col_seq ) {
                    return array();
                }
                return array( (object) array() );
            }
        );

        $update_called = false;
        $this->wpdb->shouldReceive( 'update' )->andReturnUsing( function () use ( &$update_called ) { $update_called = true; return 1; } );

        $result = $this->strategy->execute( 'email_hash_rehash', array() );

        $this->assertSame( 0, $result['processed'] );
        $this->assertSame( array(), $result['errors'] );
        $this->assertFalse( $update_called );
    }

    public function test_execute_respects_batch_size_from_config(): void {
        $captured_prepare_args = array();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
            function ( $sql, ...$args ) use ( &$captured_prepare_args ) {
                $captured_prepare_args[] = $args;
                return 'SQL';
            }
        );

        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_submissions',                    // submissions table_exists
            '0',                                     // submissions MAX(id) (records empty)
            'wp_ffc_self_scheduling_appointments',   // appointments table_exists
            '0',                                     // appointments MAX(id)
            'wp_ffc_submissions', '0', '0',          // status submissions
            'wp_ffc_self_scheduling_appointments', '0', '0'
        );
        $col_seq = 0;
        $this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
            function () use ( &$col_seq ) {
                $col_seq++;
                if ( in_array( $col_seq, array( 3, 6 ), true ) ) {
                    return array();
                }
                return array( (object) array() );
            }
        );

        $this->strategy->execute( 'email_hash_rehash', array( 'batch_size' => 7 ) );

        $found = false;
        foreach ( $captured_prepare_args as $args ) {
            if ( in_array( 7, $args, true ) ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'batch_size from config should reach the SELECT prepare call' );
    }
}
