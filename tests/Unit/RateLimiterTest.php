<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\RateLimiter;

/**
 * Tests for RateLimiter: settings caching, check methods, verification, user limits.
 */
class RateLimiterTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Reset static settings cache between tests.
        $ref = new \ReflectionClass( RateLimiter::class );
        $cache = $ref->getProperty( 'settings_cache' );
        $cache->setAccessible( true );
        $cache->setValue( null );

        // Provide global $wpdb mock for DB-hitting methods.
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: stub default settings so get_settings() works.
     */
    private function stub_default_settings( array $overrides = [] ): void {
        $defaults = array(
            'ip'        => array( 'enabled' => true, 'max_per_hour' => 5, 'max_per_day' => 20, 'cooldown_seconds' => 60, 'apply_to' => 'all', 'message' => 'Limit reached. Please wait {time}.' ),
            'email'     => array( 'enabled' => true, 'max_per_day' => 3, 'max_per_week' => 10, 'max_per_month' => 30, 'wait_hours' => 24, 'apply_to' => 'all', 'message' => 'You already have {count} certificates.', 'check_database' => true ),
            'cpf'       => array( 'enabled' => false, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 3, 'block_hours' => 1, 'block_duration' => 24, 'apply_to' => 'all', 'message' => 'CPF/RF limit reached.', 'check_database' => true ),
            'global'    => array( 'enabled' => false, 'max_per_minute' => 100, 'max_per_hour' => 1000, 'message' => 'System unavailable.' ),
            'whitelist' => array( 'ips' => array(), 'emails' => array(), 'email_domains' => array(), 'cpfs' => array() ),
            'blacklist' => array( 'ips' => array(), 'emails' => array(), 'email_domains' => array(), 'cpfs' => array() ),
            'logging'   => array( 'enabled' => false, 'log_allowed' => false, 'log_blocked' => false, 'retention_days' => 30, 'max_logs' => 10000 ),
            'ui'        => array( 'show_remaining' => true, 'show_wait_time' => true, 'countdown_timer' => true ),
        );

        $merged = array_replace_recursive( $defaults, $overrides );

        Functions\when( 'get_option' )->justReturn( $merged );
        Functions\when( 'wp_parse_args' )->alias( function( $args, $defaults ) {
            return array_replace_recursive( $defaults, $args );
        });
        Functions\when( '__' )->returnArg();
        Functions\when( '_n' )->returnArg();
    }

    // ------------------------------------------------------------------
    // check_ip_limit()
    // ------------------------------------------------------------------

    public function test_ip_limit_allows_when_under_threshold(): void {
        $this->stub_default_settings();

        // Object cache returns 0 (no previous attempts)
        Functions\when( 'wp_cache_get' )->justReturn( 0 );

        $result = RateLimiter::check_ip_limit( '192.168.1.1' );

        $this->assertTrue( $result['allowed'] );
    }

    public function test_ip_limit_blocks_when_hour_exceeded(): void {
        $this->stub_default_settings();

        // Object cache returns max_per_hour (5)
        Functions\when( 'wp_cache_get' )->justReturn( 5 );

        $result = RateLimiter::check_ip_limit( '192.168.1.1' );

        $this->assertFalse( $result['allowed'] );
        $this->assertSame( 'ip_hour_limit', $result['reason'] );
        $this->assertSame( 3600, $result['wait_seconds'] );
    }

    public function test_ip_limit_blocks_on_cooldown(): void {
        $this->stub_default_settings();

        // Hour count is OK, day count is OK, but cooldown is active
        $call_count = 0;
        Functions\when( 'wp_cache_get' )->alias( function( $key ) use ( &$call_count ) {
            $call_count++;
            if ( $call_count === 1 ) return 1;  // hour count
            if ( $call_count === 2 ) return time() - 10;  // last attempt 10s ago (cooldown = 60s)
            return false;
        });

        $result = RateLimiter::check_ip_limit( '192.168.1.1' );

        $this->assertFalse( $result['allowed'] );
        $this->assertSame( 'ip_cooldown', $result['reason'] );
        $this->assertGreaterThan( 0, $result['wait_seconds'] );
    }

    // ------------------------------------------------------------------
    // check_verification()
    // ------------------------------------------------------------------

    public function test_verification_allows_when_under_limit(): void {
        $this->stub_default_settings();

        Functions\when( 'wp_cache_get' )->justReturn( 0 );
        Functions\when( 'wp_cache_set' )->justReturn( true );

        $result = RateLimiter::check_verification( '10.0.0.1' );

        $this->assertTrue( $result['allowed'] );
    }

    public function test_verification_blocks_when_hour_exceeded(): void {
        $this->stub_default_settings();

        // Return 10 for hour count (max is 10)
        Functions\when( 'wp_cache_get' )->justReturn( 10 );
        Functions\when( 'wp_cache_set' )->justReturn( true );

        $result = RateLimiter::check_verification( '10.0.0.1' );

        $this->assertFalse( $result['allowed'] );
        $this->assertGreaterThan( 0, $result['wait_seconds'] );
    }

    public function test_verification_blocks_when_day_exceeded(): void {
        $this->stub_default_settings();

        $call_count = 0;
        Functions\when( 'wp_cache_get' )->alias( function() use ( &$call_count ) {
            $call_count++;
            if ( $call_count === 1 ) return 5;   // hour count OK
            if ( $call_count === 2 ) return 30;  // day count >= 30
            return 0;
        });
        Functions\when( 'wp_cache_set' )->justReturn( true );

        $result = RateLimiter::check_verification( '10.0.0.1' );

        $this->assertFalse( $result['allowed'] );
    }

    public function test_verification_skipped_when_ip_disabled(): void {
        $this->stub_default_settings( array( 'ip' => array( 'enabled' => false ) ) );

        $result = RateLimiter::check_verification( '10.0.0.1' );

        $this->assertTrue( $result['allowed'] );
    }

    // ------------------------------------------------------------------
    // check_user_limit()
    // ------------------------------------------------------------------

    public function test_user_limit_allows_when_under_threshold(): void {
        Functions\when( 'wp_cache_get' )->justReturn( 0 );
        Functions\when( 'wp_cache_set' )->justReturn( true );

        $result = RateLimiter::check_user_limit( 42, 'password_change', 3, 10 );

        $this->assertTrue( $result['allowed'] );
    }

    public function test_user_limit_blocks_when_hour_exceeded(): void {
        Functions\when( 'wp_cache_get' )->justReturn( 3 );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( '_n' )->returnArg();
        Functions\when( '__' )->returnArg();

        $result = RateLimiter::check_user_limit( 42, 'password_change', 3, 10 );

        $this->assertFalse( $result['allowed'] );
        $this->assertArrayHasKey( 'wait_seconds', $result );
    }

    public function test_user_limit_blocks_when_day_exceeded(): void {
        $call_count = 0;
        Functions\when( 'wp_cache_get' )->alias( function() use ( &$call_count ) {
            $call_count++;
            return $call_count === 1 ? 1 : 10; // hour OK, day at limit
        });
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( '__' )->returnArg();

        $result = RateLimiter::check_user_limit( 42, 'privacy_request', 5, 10 );

        $this->assertFalse( $result['allowed'] );
    }

    public function test_user_limit_allows_zero_user_id(): void {
        $result = RateLimiter::check_user_limit( 0, 'password_change' );

        $this->assertTrue( $result['allowed'] );
    }

    // ------------------------------------------------------------------
    // check_all() integration
    // ------------------------------------------------------------------

    public function test_check_all_blocks_blacklisted_ip(): void {
        $this->stub_default_settings( array(
            'blacklist' => array(
                'ips'            => array( '10.0.0.1' ),
                'emails'         => array(),
                'email_domains'  => array(),
                'cpfs'           => array(),
            ),
        ) );

        $result = RateLimiter::check_all( '10.0.0.1', 'a@b.com', null, 1 );

        $this->assertFalse( $result['allowed'] );
    }

    public function test_check_all_allows_whitelisted_ip(): void {
        $this->stub_default_settings( array(
            'whitelist' => array(
                'ips'            => array( '10.0.0.1' ),
                'emails'         => array(),
                'email_domains'  => array(),
                'cpfs'           => array(),
            ),
        ) );

        $result = RateLimiter::check_all( '10.0.0.1', null, null, null );

        $this->assertTrue( $result['allowed'] );
    }

    public function test_check_all_allows_when_no_limits_exceeded(): void {
        $this->stub_default_settings();

        Functions\when( 'wp_cache_get' )->justReturn( 0 );

        $result = RateLimiter::check_all( '192.168.1.1', null, null, null );

        $this->assertTrue( $result['allowed'] );
    }

    // ------------------------------------------------------------------
    // check_email_limit()
    // ------------------------------------------------------------------

    public function test_email_limit_skipped_when_check_database_false(): void {
        $this->stub_default_settings( array(
            'email' => array( 'check_database' => false ),
        ) );

        $result = RateLimiter::check_email_limit( 'test@example.com' );

        $this->assertTrue( $result['allowed'] );
    }

    // ------------------------------------------------------------------
    // Blacklist/whitelist edge cases
    // ------------------------------------------------------------------

    public function test_blacklist_blocks_email(): void {
        $this->stub_default_settings( array(
            'blacklist' => array(
                'ips'            => array(),
                'emails'         => array( 'bad@example.com' ),
                'email_domains'  => array(),
                'cpfs'           => array(),
            ),
        ) );

        $result = RateLimiter::check_all( '10.0.0.1', 'bad@example.com', null, null );

        $this->assertFalse( $result['allowed'] );
    }

    public function test_blacklist_blocks_domain(): void {
        $this->stub_default_settings( array(
            'blacklist' => array(
                'ips'            => array(),
                'emails'         => array(),
                'email_domains'  => array( '*@evil.com' ),
                'cpfs'           => array(),
            ),
        ) );

        $result = RateLimiter::check_all( '10.0.0.1', 'user@evil.com', null, null );

        $this->assertFalse( $result['allowed'] );
    }

    public function test_blacklist_blocks_cpf(): void {
        $this->stub_default_settings( array(
            'blacklist' => array(
                'ips'            => array(),
                'emails'         => array(),
                'email_domains'  => array(),
                'cpfs'           => array( '12345678901' ),
            ),
        ) );

        $result = RateLimiter::check_all( '10.0.0.1', null, '123.456.789-01', null );

        $this->assertFalse( $result['allowed'] );
    }
}
