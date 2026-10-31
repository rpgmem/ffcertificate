<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Security\RateCounter;
use FreeFormCertificate\Security\RateLimitChecker;
use FreeFormCertificate\Security\RateLimiter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The short-window rate counters persist without an object cache (#1861).
 *
 * The object cache below forgets everything, which is what `wp_cache_*`
 * does on a site with no Redis/Memcached drop-in: it lives for one request.
 * The transient store persists, as the options table does. Every counter
 * is driven across several "requests" and must still trip.
 *
 * Before #1861 the counters lived in the object cache alone, so on such a
 * site each of these limits read zero on every request and never tripped.
 *
 * @covers \FreeFormCertificate\Security\RateCounter
 * @covers \FreeFormCertificate\Security\RateLimitChecker
 * @covers \FreeFormCertificate\Security\IpLimiter
 */
class RateLimitPersistenceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Transient store (persistent across requests).
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Rate-limit settings served for the option.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_settings_cache();

		$this->transients = array();
		$this->settings   = array();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() ) );
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => 'ffc_rate_limit_settings' === $name ? $this->settings : $default
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2026-10-12 10:00:00' );

		// A non-persistent object cache: nothing written survives.
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => $sql )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	protected function tearDown(): void {
		$this->reset_settings_cache();
		unset( $_SERVER['REMOTE_ADDR'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function reset_settings_cache(): void {
		$prop = new \ReflectionProperty( RateLimitChecker::class, 'settings_cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * Serve these rate-limit settings (merged over the defaults per section).
	 *
	 * @param array<string, array<string, mixed>> $sections Section => values.
	 */
	private function configure( array $sections ): void {
		$this->reset_settings_cache();
		$defaults = RateLimitChecker::get_settings();
		$this->reset_settings_cache();
		foreach ( $sections as $section => $values ) {
			$defaults[ $section ] = array_merge( $defaults[ $section ] ?? array(), $values );
		}
		$this->settings = $defaults;
	}

	/**
	 * Sum of the stored counters whose key starts with a prefix.
	 *
	 * Summing over every bucket is what keeps a clock-bucketed key honest:
	 * if the hour turns between two calls the attempts split across two
	 * keys, and the sum still counts every one.
	 */
	private function stored_sum( string $prefix ): int {
		$sum = 0;
		foreach ( $this->transients as $key => $value ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				$sum += (int) $value;
			}
		}
		return $sum;
	}

	public function test_counter_reads_zero_when_absent_and_counts_up(): void {
		$this->assertSame( 0, RateCounter::get( 'ffc_x' ) );
		$this->assertSame( 1, RateCounter::increment( 'ffc_x', 60 ) );
		$this->assertSame( 2, RateCounter::increment( 'ffc_x', 60 ) );
		$this->assertSame( 2, RateCounter::get( 'ffc_x' ) );

		RateCounter::put( 'ffc_y', 1234, 60 );
		$this->assertSame( 1234, RateCounter::get( 'ffc_y' ) );
	}

	public function test_ip_hour_limit_trips_across_requests_after_recorded_attempts(): void {
		$this->configure(
			array(
				'ip' => array(
					'enabled'          => true,
					'max_per_hour'     => 3,
					'max_per_day'      => 1000,
					'cooldown_seconds' => 0,
				),
			)
		);

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( RateLimiter::check_ip_limit( '198.51.100.7' )['allowed'], 'attempt ' . ( $i + 1 ) . ' is under the limit' );
			RateLimiter::record_attempt( 'ip', '198.51.100.7' );
		}

		$result = RateLimiter::check_ip_limit( '198.51.100.7' );
		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'ip_hour_limit', $result['reason'] );
		$this->assertSame( 3, RateCounter::get( 'ffc_rate_ip_' . md5( \FreeFormCertificate\Core\IpAddress::fingerprint( '198.51.100.7' ) ) . '_hour' ) );
	}

	public function test_ip_cooldown_holds_across_requests(): void {
		$this->configure(
			array(
				'ip' => array(
					'enabled'          => true,
					'max_per_hour'     => 1000,
					'max_per_day'      => 1000,
					'cooldown_seconds' => 600,
				),
			)
		);

		RateLimiter::record_attempt( 'ip', '198.51.100.8' );
		$result = RateLimiter::check_ip_limit( '198.51.100.8' );

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'ip_cooldown', $result['reason'] );
	}

	public function test_verification_counts_every_attempt_across_requests(): void {
		$this->configure( array( 'ip' => array( 'enabled' => true ) ) );

		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertTrue( RateLimiter::check_verification( '198.51.100.9' )['allowed'] );
		}

		$prefix = 'ffc_verify_ip_' . md5( \FreeFormCertificate\Core\IpAddress::fingerprint( '198.51.100.9' ) );
		$this->assertSame( 4, $this->stored_sum( $prefix . '_hour_' ) );
		$this->assertSame( 4, $this->stored_sum( $prefix . '_day_' ) );
	}

	public function test_verification_hour_limit_trips_from_the_stored_counter(): void {
		$this->configure( array( 'ip' => array( 'enabled' => true ) ) );

		// Seed the bucket of the hour read before the call and of the hour
		// read after it: whichever one the code picks holds the limit.
		$before = gmdate( 'YmdH' );
		$key    = 'ffc_verify_ip_' . md5( \FreeFormCertificate\Core\IpAddress::fingerprint( '198.51.100.10' ) ) . '_hour_';
		$this->transients[ $key . $before ] = 10;
		$result = RateLimiter::check_verification( '198.51.100.10' );
		$after  = gmdate( 'YmdH' );

		if ( $before === $after ) {
			$this->assertFalse( $result['allowed'] );
		} else {
			$this->assertIsBool( $result['allowed'] );
		}
	}

	public function test_user_limit_counts_every_attempt_and_trips(): void {
		for ( $i = 0; $i < 2; $i++ ) {
			$this->assertTrue( RateLimiter::check_user_limit( 7, 'password_change', 2, 10 )['allowed'] );
		}
		$this->assertSame( 2, $this->stored_sum( 'ffc_rate_user_7_password_change_hour_' ) );

		$before = gmdate( 'YmdH' );
		$third  = RateLimiter::check_user_limit( 7, 'password_change', 2, 10 );
		$after  = gmdate( 'YmdH' );
		if ( $before === $after ) {
			$this->assertFalse( $third['allowed'] );
		} else {
			$this->assertIsBool( $third['allowed'] );
		}
	}

	public function test_read_limit_trips_across_requests(): void {
		$this->configure(
			array(
				'read' => array(
					'endpoints' => array(
						'calendar_slots' => array(
							'enabled'        => true,
							'max_per_minute' => 2,
							'max_per_hour'   => 100,
						),
					),
				),
			)
		);

		RateLimiter::record_read_attempt( '198.51.100.11', 'calendar_slots' );
		RateLimiter::record_read_attempt( '198.51.100.11', 'calendar_slots' );

		$result = RateLimiter::check_read_limit( '198.51.100.11', 'calendar_slots' );
		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'read_minute_limit', $result['reason'] );
	}
}
