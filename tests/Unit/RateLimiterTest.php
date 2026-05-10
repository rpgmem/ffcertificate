<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\RateLimiter;
use FreeFormCertificate\Security\RateLimitChecker;

/**
 * Tests for RateLimiter: settings caching, check methods, verification, user limits.
 */
class RateLimiterTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Reset static settings cache between tests. The cache moved to
        // RateLimitChecker in the S4 facade refactor; RateLimiter is now a
        // thin forwarder so the underlying state lives on the checker class.
        $ref = new \ReflectionClass( RateLimitChecker::class );
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

    // ------------------------------------------------------------------
    // device.* — should_bypass_for_manager()
    // ------------------------------------------------------------------

    public function test_should_bypass_for_manager_returns_false_when_disabled(): void {
        $this->stub_default_settings(
            array(
                'device' => array(
                    'enabled'                   => true,
                    'bypass_logged_in_managers' => false,
                ),
            )
        );
        Functions\when( 'is_user_logged_in' )->justReturn( true );

        $this->assertFalse( RateLimiter::should_bypass_for_manager() );
    }

    public function test_should_bypass_for_manager_returns_false_when_logged_out(): void {
        $this->stub_default_settings(
            array( 'device' => array( 'bypass_logged_in_managers' => true ) )
        );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $this->assertFalse( RateLimiter::should_bypass_for_manager() );
    }

    public function test_should_bypass_for_manager_returns_true_for_admin_fallback(): void {
        $this->stub_default_settings(
            array( 'device' => array( 'bypass_logged_in_managers' => true ) )
        );
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        // Without the Utils class loaded, should_bypass_for_manager() falls
        // back to current_user_can('manage_options'). The class IS loaded
        // in this test environment via composer autoload, so we route
        // through Utils::current_user_can_admin_or().
        $this->assertTrue( RateLimiter::should_bypass_for_manager() );
    }

    // ------------------------------------------------------------------
    // device.* — get_device_effective_settings()
    // ------------------------------------------------------------------

    public function test_get_device_effective_settings_inherits_global_defaults(): void {
        $this->stub_default_settings(
            array(
                'device' => array(
                    'enabled'         => true,
                    'max_per_form'    => 7,
                    'match_threshold' => 6,
                    'message'         => 'global-message',
                ),
            )
        );
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $eff = RateLimiter::get_device_effective_settings( 42 );

        $this->assertSame( 7, $eff['max'] );
        $this->assertSame( 6, $eff['threshold'] );
        $this->assertSame( 'global-message', $eff['message'] );
    }

    public function test_get_device_effective_settings_form_meta_overrides_global(): void {
        $this->stub_default_settings(
            array(
                'device' => array(
                    'enabled'         => true,
                    'max_per_form'    => 1,
                    'match_threshold' => 5,
                    'message'         => 'global',
                ),
            )
        );
        Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key ) {
            $map = array(
                '_ffc_device_limit_max'        => '3',
                '_ffc_device_match_threshold'  => '7',
                '_ffc_device_limit_message'    => 'form-override',
            );
            return $map[ $key ] ?? '';
        } );

        $eff = RateLimiter::get_device_effective_settings( 42 );

        $this->assertSame( 3, $eff['max'] );
        $this->assertSame( 7, $eff['threshold'] );
        $this->assertSame( 'form-override', $eff['message'] );
    }

    public function test_get_device_effective_settings_default_threshold_is_7(): void {
        // 6.3.2 raised the fresh-install default from 5 to 7 to match the
        // expanded 13-signal palette (was 5/9 ≈ 55%, now 7/13 ≈ 54%).
        Functions\when( 'get_option' )->justReturn( array() ); // No persisted settings.
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, is_array( $args ) ? $args : array() );
        } );
        Functions\when( '__' )->returnArg();
        Functions\when( '_n' )->returnArg();
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $eff = RateLimiter::get_device_effective_settings( 42 );

        $this->assertSame( 7, $eff['threshold'] );
    }

    public function test_get_device_effective_settings_clamps_threshold(): void {
        $this->stub_default_settings(
            array( 'device' => array( 'enabled' => true, 'match_threshold' => 5, 'max_per_form' => 1 ) )
        );
        Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key ) {
            return '_ffc_device_match_threshold' === $key ? '99' : '';
        } );

        $eff = RateLimiter::get_device_effective_settings( 42 );

        $this->assertSame( 12, $eff['threshold'], 'Threshold above 12 must clamp to 12 (raised from 8 in 6.3.2 to match the 13-signal palette).' );
    }

    // ------------------------------------------------------------------
    // device.* — check_device_limit()
    // ------------------------------------------------------------------

    public function test_check_device_limit_allows_when_globally_disabled(): void {
        $this->stub_default_settings(
            array( 'device' => array( 'enabled' => false ) )
        );

        $result = RateLimiter::check_device_limit( 42, array( 'cookie' => str_repeat( 'a', 64 ) ) );

        $this->assertTrue( $result['allowed'] );
    }

    public function test_check_device_limit_allows_whitelisted_cookie(): void {
        $cookie_hash = str_repeat( 'b', 64 );
        $this->stub_default_settings(
            array(
                'device' => array(
                    'enabled'                  => true,
                    'signals_enabled'          => array( 'cookie' ),
                    'bypass_whitelist_signals' => array( $cookie_hash ),
                    'max_per_form'             => 1,
                ),
            )
        );

        $result = RateLimiter::check_device_limit( 42, array( 'cookie' => $cookie_hash ) );

        $this->assertTrue( $result['allowed'] );
    }

    public function test_check_device_limit_blocks_when_count_reaches_max(): void {
        global $wpdb;
        $wpdb->shouldReceive( 'get_var' )->andReturn( '2' );

        $this->stub_default_settings(
            array(
                'device' => array(
                    'enabled'         => true,
                    'signals_enabled' => array( 'cookie', 'ua', 'screen' ),
                    'max_per_form'    => 1,
                    'match_threshold' => 3,
                    'message'         => 'blocked!',
                ),
            )
        );
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $result = RateLimiter::check_device_limit(
            42,
            array(
                'cookie' => str_repeat( 'c', 64 ),
                'ua'     => str_repeat( 'd', 64 ),
                'screen' => str_repeat( 'e', 64 ),
            )
        );

        $this->assertFalse( $result['allowed'] );
        $this->assertSame( 'device_limit', $result['reason'] );
    }

    // ------------------------------------------------------------------
    // device.* — record_device_signals()
    // ------------------------------------------------------------------

    public function test_record_device_signals_no_op_when_disabled(): void {
        global $wpdb;
        $wpdb->shouldReceive( 'insert' )->never();

        $this->stub_default_settings( array( 'device' => array( 'enabled' => false ) ) );

        RateLimiter::record_device_signals( 7, 42, array( 'cookie' => str_repeat( 'f', 64 ) ) );

        // Mockery verifies the insert expectation in tearDown.
        $this->assertTrue( true );
    }

    public function test_record_device_signals_inserts_only_enabled_keys(): void {
        global $wpdb;
        $captured = null;
        $wpdb->shouldReceive( 'insert' )->andReturnUsing( function ( $table, $row ) use ( &$captured ) {
            $captured = $row;
            return 1;
        } );

        // Override wp_parse_args with shallow merge so signals_enabled
        // gets replaced wholesale instead of being array_replace_recursive'd
        // index-by-index against the 10-element default.
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, is_array( $args ) ? $args : array() );
        } );
        Functions\when( 'get_option' )->justReturn(
            array(
                'device' => array(
                    'enabled'                   => true,
                    'max_per_form'              => 1,
                    'match_threshold'           => 5,
                    'signals_enabled'           => array( 'cookie', 'ua' ),
                    'bypass_logged_in_managers' => false,
                    'bypass_whitelist_signals'  => array(),
                    'message'                   => 'msg',
                    'retention_days'            => 90,
                    'log_blocks'                => true,
                ),
            )
        );
        Functions\when( '__' )->returnArg();
        Functions\when( '_n' )->returnArg();

        RateLimiter::record_device_signals(
            7,
            42,
            array(
                'cookie' => str_repeat( 'a', 64 ),
                'ua'     => str_repeat( 'b', 64 ),
                'canvas' => str_repeat( 'c', 64 ), // Should be filtered out (not enabled).
            )
        );

        $this->assertNotNull( $captured );
        $this->assertSame( 7, $captured['submission_id'] );
        $this->assertSame( 42, $captured['form_id'] );
        $this->assertSame( str_repeat( 'a', 64 ), $captured['sig_cookie'] );
        $this->assertSame( str_repeat( 'b', 64 ), $captured['sig_ua'] );
        $this->assertNull( $captured['sig_canvas'] );
    }
}
