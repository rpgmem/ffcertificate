<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\RateLimitChecker;
use FreeFormCertificate\Security\RateLimitLogger;

/**
 * Tests for RateLimitLogger: log_attempt persistence + retention cleanup paths.
 */
class RateLimitLoggerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $ref   = new \ReflectionClass( RateLimitChecker::class );
        $cache = $ref->getProperty( 'settings_cache' );
        $cache->setAccessible( true );
        $cache->setValue( null );

        global $wpdb;
        $wpdb         = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
        $wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();

        Functions\when( '__' )->returnArg();
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_replace_recursive( $defaults, is_array( $args ) ? $args : array() );
        } );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        $_SERVER['REMOTE_ADDR']     = '198.51.100.7';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub the rate-limit settings so {@see RateLimitChecker::get_settings()}
     * returns a deterministic shape.
     */
    private function stub_settings( array $overrides = array() ): void {
        $defaults = array(
            'logging' => array(
                'enabled'        => true,
                'log_allowed'    => false,
                'log_blocked'    => true,
                'retention_days' => 30,
                'max_logs'       => 10000,
            ),
            'device'  => array(
                'enabled'        => false,
                'retention_days' => 90,
            ),
        );
        $merged   = array_replace_recursive( $defaults, $overrides );
        Functions\when( 'get_option' )->justReturn( $merged );
    }

    // ------------------------------------------------------------------
    // log_attempt() — short-circuit guards
    // ------------------------------------------------------------------

    public function test_log_attempt_skips_when_logging_disabled(): void {
        $this->stub_settings( array( 'logging' => array( 'enabled' => false ) ) );

        global $wpdb;
        $wpdb->shouldReceive( 'insert' )->never();

        RateLimitLogger::log_attempt( 'ip', '203.0.113.1', 'blocked', 'too_many', 42 );

        $this->assertTrue( true ); // Mockery verifies the never() in tearDown.
    }

    public function test_log_attempt_skips_allowed_action_when_log_allowed_disabled(): void {
        $this->stub_settings(); // log_allowed defaults to false.

        global $wpdb;
        $wpdb->shouldReceive( 'insert' )->never();

        RateLimitLogger::log_attempt( 'ip', '203.0.113.1', 'allowed', 'ok', 42 );

        $this->assertTrue( true );
    }

    public function test_log_attempt_records_allowed_action_when_log_allowed_enabled(): void {
        $this->stub_settings( array( 'logging' => array( 'log_allowed' => true ) ) );

        global $wpdb;
        $captured = null;
        $wpdb->shouldReceive( 'insert' )->andReturnUsing( function ( $table, $row ) use ( &$captured ) {
            $captured = $row;
            return 1;
        } );

        RateLimitLogger::log_attempt( 'ip', '203.0.113.1', 'allowed', 'ok', 42 );

        $this->assertNotNull( $captured );
        $this->assertSame( 'allowed', $captured['action'] );
    }

    // ------------------------------------------------------------------
    // log_attempt() — identifier hashing (LGPD/GDPR)
    // ------------------------------------------------------------------

    public function test_log_attempt_keeps_ip_identifier_plaintext(): void {
        $this->stub_settings();

        global $wpdb;
        $captured = null;
        $wpdb->shouldReceive( 'insert' )->andReturnUsing( function ( $table, $row ) use ( &$captured ) {
            $captured = $row;
            return 1;
        } );

        RateLimitLogger::log_attempt( 'ip', '203.0.113.42', 'blocked', 'ip_hour_limit', 7 );

        $this->assertSame( '203.0.113.42', $captured['identifier'] );
    }

    public function test_log_attempt_hashes_non_ip_identifier(): void {
        $this->stub_settings();

        global $wpdb;
        $captured = null;
        $wpdb->shouldReceive( 'insert' )->andReturnUsing( function ( $table, $row ) use ( &$captured ) {
            $captured = $row;
            return 1;
        } );

        RateLimitLogger::log_attempt( 'email', 'user@example.com', 'blocked', 'email_day', 7 );

        $this->assertSame( hash( 'sha256', 'user@example.com' ), $captured['identifier'] );
    }

    public function test_log_attempt_populates_row_metadata(): void {
        $this->stub_settings();

        global $wpdb;
        $captured        = null;
        $captured_table  = null;
        $captured_format = null;
        $wpdb->shouldReceive( 'insert' )->andReturnUsing(
            function ( $table, $row, $format ) use ( &$captured, &$captured_table, &$captured_format ) {
                $captured        = $row;
                $captured_table  = $table;
                $captured_format = $format;
                return 1;
            }
        );

        RateLimitLogger::log_attempt( 'cpf', '12345678900', 'blocked', 'cpf_threshold', 9 );

        $this->assertSame( 'wp_ffc_rate_limit_logs', $captured_table );
        $this->assertSame( 'cpf', $captured['type'] );
        $this->assertSame( 9, $captured['form_id'] );
        $this->assertSame( 'cpf_threshold', $captured['reason'] );
        $this->assertSame( '198.51.100.7', $captured['ip_address'] );
        $this->assertSame( 'TestAgent/1.0', $captured['user_agent'] );
        $this->assertSame( 0, $captured['current_count'] );
        $this->assertSame( 0, $captured['max_allowed'] );
        $this->assertSame(
            array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d' ),
            $captured_format
        );
    }

    public function test_log_attempt_falls_back_to_empty_user_agent_when_unset(): void {
        unset( $_SERVER['HTTP_USER_AGENT'] );
        $this->stub_settings();

        global $wpdb;
        $captured = null;
        $wpdb->shouldReceive( 'insert' )->andReturnUsing( function ( $table, $row ) use ( &$captured ) {
            $captured = $row;
            return 1;
        } );

        RateLimitLogger::log_attempt( 'ip', '203.0.113.1', 'blocked', 'r', 1 );

        $this->assertSame( '', $captured['user_agent'] );
    }

    // ------------------------------------------------------------------
    // log_attempt() — cleanup is invoked on each insert
    // ------------------------------------------------------------------

    public function test_log_attempt_triggers_cleanup_of_old_logs(): void {
        $this->stub_settings();

        global $wpdb;
        $delete_old_called = false;
        $wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $sql ) use ( &$delete_old_called ) {
            // The cleanup_old_logs() path runs at least one DELETE.
            $delete_old_called = true;
            return 0;
        } );
        $wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

        RateLimitLogger::log_attempt( 'ip', '203.0.113.1', 'blocked', 'r', 1 );

        $this->assertTrue( $delete_old_called, 'cleanup_old_logs() should run on every log_attempt() insert' );
    }

    public function test_log_attempt_cleanup_purges_overflow_when_count_exceeds_max(): void {
        $this->stub_settings( array( 'logging' => array( 'max_logs' => 100 ) ) );

        global $wpdb;
        $query_count = 0;
        $wpdb->shouldReceive( 'query' )->andReturnUsing( function () use ( &$query_count ) {
            $query_count++;
            return 0;
        } );
        // First get_var (count) returns above max → the overflow branch runs a second DELETE.
        $wpdb->shouldReceive( 'get_var' )->andReturn( '500' );

        RateLimitLogger::log_attempt( 'ip', '203.0.113.1', 'blocked', 'r', 1 );

        $this->assertSame( 2, $query_count, 'overflow branch should issue a second DELETE' );
    }

    // ------------------------------------------------------------------
    // cleanup_expired()
    // ------------------------------------------------------------------

    public function test_cleanup_expired_returns_deleted_row_count_from_main_table(): void {
        // retention_days = 0 keeps the device_signals branch dormant.
        $this->stub_settings( array( 'device' => array( 'retention_days' => 0 ) ) );

        global $wpdb;
        $wpdb->shouldReceive( 'query' )->andReturn( 17 )->once();

        $deleted = RateLimitLogger::cleanup_expired();

        $this->assertSame( 17, $deleted );
    }

    public function test_cleanup_expired_also_cleans_device_signals_when_retention_set(): void {
        $this->stub_settings(
            array(
                'device' => array(
                    'enabled'        => true,
                    'retention_days' => 30,
                ),
            )
        );

        global $wpdb;
        $queries = array();
        $wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $sql ) use ( &$queries ) {
            $queries[] = $sql;
            return 5; // each DELETE removed 5 rows.
        } );

        $deleted = RateLimitLogger::cleanup_expired();

        $this->assertSame( 2, count( $queries ), 'expected two DELETE statements' );
        $this->assertSame( 10, $deleted, 'totals from main + device_signals should be summed' );
    }

    public function test_cleanup_expired_skips_device_signals_when_retention_zero(): void {
        $this->stub_settings(
            array(
                'device' => array(
                    'enabled'        => true,
                    'retention_days' => 0,
                ),
            )
        );

        global $wpdb;
        $wpdb->shouldReceive( 'query' )->andReturn( 3 )->once();

        $deleted = RateLimitLogger::cleanup_expired();

        $this->assertSame( 3, $deleted );
    }
}
