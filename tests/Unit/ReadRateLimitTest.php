<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\RateLimitChecker;

/**
 * Tests for the per-read rate-limit infrastructure added in #259:
 *   - RateLimitChecker::check_read_limit() / record_read_attempt()
 *   - RateLimitChecker::is_ip_whitelisted()
 *
 * The trait `ReadRateLimitGuardTrait` is exercised indirectly via
 * `CalendarRestControllerTest` (which already mocks the public API
 * the trait delegates to); this file pins the underlying checker
 * methods.
 *
 * @covers \FreeFormCertificate\Security\RateLimitChecker::check_read_limit
 * @covers \FreeFormCertificate\Security\RateLimitChecker::record_read_attempt
 * @covers \FreeFormCertificate\Security\RateLimitChecker::is_ip_whitelisted
 */
class ReadRateLimitTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, int> */
	private array $cache;

	/** @var array<string, mixed> */
	private array $settings;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->cache    = array();
		$this->settings = array(
			'whitelist' => array( 'ips' => array(), 'emails' => array(), 'email_domains' => array(), 'cpfs' => array() ),
			'read'      => array(
				'message'   => 'Too many requests. Please wait {time}.',
				'endpoints' => array(
					'calendar_slots' => array( 'enabled' => true, 'max_per_minute' => 2, 'max_per_hour' => 10 ),
					'calendar_list'  => array( 'enabled' => false, 'max_per_minute' => 100, 'max_per_hour' => 1000 ),
				),
			),
		);

		$cache    =& $this->cache;
		$settings =& $this->settings;

		Functions\when( 'wp_cache_get' )->alias( function ( $key, $group = '' ) use ( &$cache ) {
			return $cache[ $group . '|' . $key ] ?? false;
		} );
		Functions\when( 'wp_cache_set' )->alias( function ( $key, $value, $group = '' ) use ( &$cache ) {
			$cache[ $group . '|' . $key ] = $value;
			return true;
		} );
		Functions\when( 'get_option' )->alias( function ( $name, $default_value = false ) use ( &$settings ) {
			if ( 'ffc_rate_limit_settings' === $name ) {
				return $settings;
			}
			return $default_value;
		} );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
			return array_merge( $defaults, is_array( $args ) ? $args : array() );
		} );
		Functions\when( 'current_time' )->justReturn( '2026-05-20 12:00:00' );
		Functions\when( 'absint' )->alias( fn ( $v ) => abs( (int) $v ) );

		// Reset the static settings cache so each test sees its own
		// `$this->settings` (the checker memoizes the first lookup).
		$ref = new \ReflectionProperty( RateLimitChecker::class, 'settings_cache' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		// Minimal wpdb stub — record_read_attempt → record_attempt
		// → increment_counter writes one row per (type, identifier, day).
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'update' )->andReturn( 1 )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// check_read_limit()
	// ------------------------------------------------------------------

	public function test_check_read_limit_allows_when_endpoint_disabled(): void {
		$result = RateLimitChecker::check_read_limit( '1.1.1.1', 'calendar_list' );

		$this->assertTrue( $result['allowed'] );
	}

	public function test_check_read_limit_allows_when_endpoint_key_unknown(): void {
		$result = RateLimitChecker::check_read_limit( '1.1.1.1', 'nonexistent_key' );

		$this->assertTrue( $result['allowed'] );
	}

	public function test_check_read_limit_allows_below_threshold(): void {
		$result = RateLimitChecker::check_read_limit( '1.1.1.1', 'calendar_slots' );

		$this->assertTrue( $result['allowed'] );
	}

	public function test_check_read_limit_blocks_at_minute_threshold(): void {
		// Two prior hits → next one trips the per-minute cap (= 2).
		RateLimitChecker::record_read_attempt( '1.1.1.1', 'calendar_slots' );
		RateLimitChecker::record_read_attempt( '1.1.1.1', 'calendar_slots' );

		$result = RateLimitChecker::check_read_limit( '1.1.1.1', 'calendar_slots' );

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'read_minute_limit', $result['reason'] );
		$this->assertSame( 60, $result['wait_seconds'] );
	}

	public function test_check_read_limit_isolates_per_endpoint_key(): void {
		// Saturating slots shouldn't affect a (hypothetically enabled) detail key.
		$this->settings['read']['endpoints']['calendar_detail'] = array( 'enabled' => true, 'max_per_minute' => 5, 'max_per_hour' => 100 );

		RateLimitChecker::record_read_attempt( '1.1.1.1', 'calendar_slots' );
		RateLimitChecker::record_read_attempt( '1.1.1.1', 'calendar_slots' );

		$slots_result  = RateLimitChecker::check_read_limit( '1.1.1.1', 'calendar_slots' );
		$detail_result = RateLimitChecker::check_read_limit( '1.1.1.1', 'calendar_detail' );

		$this->assertFalse( $slots_result['allowed'] );
		$this->assertTrue( $detail_result['allowed'] );
	}

	public function test_check_read_limit_isolates_per_ip(): void {
		RateLimitChecker::record_read_attempt( '1.1.1.1', 'calendar_slots' );
		RateLimitChecker::record_read_attempt( '1.1.1.1', 'calendar_slots' );

		$blocked_for_a = RateLimitChecker::check_read_limit( '1.1.1.1', 'calendar_slots' );
		$allowed_for_b = RateLimitChecker::check_read_limit( '2.2.2.2', 'calendar_slots' );

		$this->assertFalse( $blocked_for_a['allowed'] );
		$this->assertTrue( $allowed_for_b['allowed'] );
	}

	public function test_check_read_limit_skips_minute_gate_when_threshold_zero(): void {
		// max_per_minute=0 means "no per-minute cap"; the hour gate still applies.
		$this->settings['read']['endpoints']['calendar_slots']['max_per_minute'] = 0;

		// 12 attempts shouldn't trip the (disabled) minute gate; would trip
		// the per-hour cap (10) on the 11th.
		for ( $i = 0; $i < 10; $i++ ) {
			RateLimitChecker::record_read_attempt( '1.1.1.1', 'calendar_slots' );
		}

		$result = RateLimitChecker::check_read_limit( '1.1.1.1', 'calendar_slots' );

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'read_hour_limit', $result['reason'] );
	}

	// ------------------------------------------------------------------
	// is_ip_whitelisted()
	// ------------------------------------------------------------------

	public function test_is_ip_whitelisted_returns_false_when_not_listed(): void {
		$this->settings['whitelist']['ips'] = array( '10.0.0.1' );

		$this->assertFalse( RateLimitChecker::is_ip_whitelisted( '1.1.1.1' ) );
	}

	public function test_is_ip_whitelisted_returns_true_when_listed(): void {
		$this->settings['whitelist']['ips'] = array( '10.0.0.1', '1.1.1.1' );

		$this->assertTrue( RateLimitChecker::is_ip_whitelisted( '1.1.1.1' ) );
	}

	public function test_is_ip_whitelisted_returns_false_when_list_missing(): void {
		unset( $this->settings['whitelist'] );

		$this->assertFalse( RateLimitChecker::is_ip_whitelisted( '1.1.1.1' ) );
	}
}
