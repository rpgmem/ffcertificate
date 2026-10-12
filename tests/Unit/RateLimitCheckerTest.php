<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Security\RateLimitChecker;
use FreeFormCertificate\Tests\Support\Identifiers;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * RateLimitChecker::check_all() and the counters it reads and writes.
 *
 * Each test configures the settings it needs and pushes a limit to zero so the
 * gate under test refuses without any stored history, then reads the refusal
 * and the log row it leaves.
 *
 * @covers \FreeFormCertificate\Security\RateLimitChecker
 */
class RateLimitCheckerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Rows the logger inserted, as [ type, action, reason ].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $logged = array();

	/**
	 * Object-cache contents.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = array();

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

		$this->logged   = array();
		$this->cache    = array();
		$this->settings = array();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() ) );
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => 'ffc_rate_limit_settings' === $name ? $this->settings : $default
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// The logger records the requester's IP and user agent.
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2026-10-12 10:00:00' );
		Functions\when( 'wp_cache_get' )->alias( fn( $key ) => $this->cache[ $key ] ?? false );
		Functions\when( 'wp_cache_set' )->alias(
			function ( $key, $value ) {
				$this->cache[ $key ] = $value;
				return true;
			}
		);

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => $sql )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				if ( 'wp_ffc_rate_limit_logs' === $table ) {
					$this->logged[] = array( $data['type'], $data['action'], $data['reason'] );
				}
				return 1;
			}
		)->byDefault();
		$wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	protected function tearDown(): void {
		// The settings cache is static: never let this class's settings reach a later test.
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
			$defaults[ $section ] = array_merge( $defaults[ $section ], $values );
		}
		$this->settings = $defaults;
	}

	public function test_blacklisted_identifiers_are_refused_and_logged(): void {
		$this->configure(
			array(
				'blacklist' => array(
					'ips'           => array( '198.51.100.1' ),
					'emails'        => array( 'banned@example.org' ),
					'email_domains' => array( '*@spam.test' ),
					'cpfs'          => array( Identifiers::CPF_A ),
				),
			)
		);

		$this->assertSame( 'ip_blacklisted', RateLimitChecker::check_all( '198.51.100.1' )['reason'] );
		$this->assertSame( 'email_blacklisted', RateLimitChecker::check_all( '203.0.113.9', 'banned@example.org' )['reason'] );
		$this->assertSame( 'domain_blacklisted', RateLimitChecker::check_all( '203.0.113.9', 'anyone@spam.test' )['reason'] );
		$result = RateLimitChecker::check_all( '203.0.113.9', 'ok@example.org', '518.178.420-80', 7 );
		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'cpf_blacklisted', $result['reason'] );

		$this->assertSame(
			array(
				array( 'blacklist', 'blocked', 'ip_blacklisted' ),
				array( 'blacklist', 'blocked', 'email_blacklisted' ),
				array( 'blacklist', 'blocked', 'domain_blacklisted' ),
				array( 'blacklist', 'blocked', 'cpf_blacklisted' ),
			),
			$this->logged
		);
	}

	public function test_whitelisted_identifiers_skip_every_limit(): void {
		$this->configure(
			array(
				// Every limit would refuse, so only the whitelist can let these through.
				'ip'        => array( 'max_per_hour' => 0 ),
				'whitelist' => array(
					'emails'        => array( 'boss@example.org' ),
					'email_domains' => array( '*@school.test' ),
					'cpfs'          => array( Identifiers::CPF_B ),
				),
			)
		);

		$this->assertTrue( RateLimitChecker::check_all( '203.0.113.9', 'boss@example.org' )['allowed'] );
		$this->assertTrue( RateLimitChecker::check_all( '203.0.113.9', 'teacher@school.test' )['allowed'] );
		$this->assertTrue( RateLimitChecker::check_all( '203.0.113.9', 'nobody@example.org', '204.569.420-84' )['allowed'] );
		$this->assertSame( array_fill( 0, 3, array( 'whitelist', 'whitelisted', 'In whitelist' ) ), $this->logged );
		$this->assertFalse( RateLimitChecker::check_all( '203.0.113.9', 'nobody@example.org' )['allowed'], 'not whitelisted: the IP limit refuses' );
	}

	public function test_a_device_already_seen_is_refused_and_logged_by_its_cookie(): void {
		$this->configure(
			array(
				'device' => array(
					'enabled'    => true,
					'log_blocks' => true,
				),
			)
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		global $wpdb;
		$wpdb->shouldReceive( 'get_var' )->andReturn( '1' );

		$result = RateLimitChecker::check_all( '203.0.113.9', null, null, 7, array( 'cookie' => 'cookiehash' ) );

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( array( array( 'device', 'blocked', $result['reason'] ) ), $this->logged );
	}

	public function test_a_device_block_is_not_logged_when_block_logging_is_off(): void {
		$this->configure(
			array(
				'device' => array(
					'enabled'    => true,
					'log_blocks' => false,
				),
			)
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		global $wpdb;
		$wpdb->shouldReceive( 'get_var' )->andReturn( '1' );

		$result = RateLimitChecker::check_all( '203.0.113.9', null, null, 7, array( 'cookie' => 'cookiehash' ) );

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( array(), $this->logged );
	}

	public function test_the_device_check_is_skipped_for_managers(): void {
		$this->configure( array( 'device' => array( 'enabled' => true ) ) );
		global $wpdb;
		$wpdb->shouldReceive( 'get_var' )->andReturn( '1' );

		$result = RateLimitChecker::check_all( '203.0.113.9', null, null, 7, array( 'cookie' => 'cookiehash' ), true );

		$this->assertTrue( $result['allowed'] );
	}

	public function test_the_global_minute_limit_refuses_first(): void {
		$this->configure(
			array(
				'global' => array(
					'enabled'        => true,
					'max_per_minute' => 0,
				),
			)
		);

		$result = RateLimitChecker::check_all( '203.0.113.9' );

		$this->assertSame( 'global_minute_limit', $result['reason'] );
		$this->assertSame( 60, $result['wait_seconds'] );
		$this->assertSame( array( array( 'global', 'blocked', 'global_minute_limit' ) ), $this->logged );
	}

	public function test_the_global_hour_limit_reads_the_stored_counter(): void {
		$this->configure(
			array(
				'global' => array(
					'enabled'        => true,
					'max_per_minute' => 10,
					'max_per_hour'   => 5,
				),
			)
		);
		global $wpdb;
		$wpdb->shouldReceive( 'get_var' )->andReturn( '5' );

		$result = RateLimitChecker::check_all( '203.0.113.9' );

		$this->assertSame( 'global_hour_limit', $result['reason'] );
		$this->assertSame( 3600, $result['wait_seconds'] );
	}

	public function test_the_ip_limit_refusal_is_logged_against_the_ip(): void {
		$this->configure( array( 'ip' => array( 'max_per_hour' => 0 ) ) );

		$result = RateLimitChecker::check_all( '203.0.113.9' );

		$this->assertSame( 'ip_hour_limit', $result['reason'] );
		$this->assertSame( array( array( 'ip', 'blocked', 'ip_hour_limit' ) ), $this->logged );
	}

	public function test_the_ip_limit_only_applies_to_the_listed_forms(): void {
		$this->configure(
			array(
				'ip'      => array(
					'max_per_hour' => 0,
					'apply_to'     => array( 3 ),
				),
				'email'   => array( 'enabled' => false ),
				'logging' => array( 'log_allowed' => true ),
			)
		);

		$this->assertTrue( RateLimitChecker::check_all( '203.0.113.9', null, null, 9 )['allowed'] );
		$this->assertSame( array( array( 'ip', 'allowed', 'Passed' ) ), $this->logged, 'an allowed attempt is logged when asked to' );
		$this->assertFalse( RateLimitChecker::check_all( '203.0.113.9', null, null, 3 )['allowed'] );
	}

	public function test_the_cpf_limit_refusal_is_logged(): void {
		$this->configure(
			array(
				'ip'  => array( 'enabled' => false ),
				'cpf' => array(
					'enabled'       => true,
					'max_per_month' => 0,
				),
			)
		);

		$result = RateLimitChecker::check_all( '203.0.113.9', null, '518.178.420-80', 4 );

		$this->assertSame( 'cpf_month_limit', $result['reason'] );
		$this->assertSame( array( array( 'cpf', 'blocked', 'cpf_month_limit' ) ), $this->logged );
	}

	public function test_the_email_limit_refusal_is_logged(): void {
		$this->configure(
			array(
				'ip'    => array( 'enabled' => false ),
				'email' => array( 'max_per_day' => 0 ),
			)
		);

		$result = RateLimitChecker::check_all( '203.0.113.9', 'person@example.org', null, 4 );

		$this->assertSame( 'email_day_limit', $result['reason'] );
		$this->assertSame( array( array( 'email', 'blocked', 'email_day_limit' ) ), $this->logged );
	}

	public function test_verification_counts_the_first_attempt_and_logs_it(): void {
		$this->configure( array() );

		$this->assertTrue( RateLimitChecker::check_verification( '203.0.113.9' )['allowed'] );

		$this->assertSame( array( 1, 1 ), array_values( $this->cache ), 'hour and day counters start at one' );
		$this->assertSame( array(), $this->logged, 'allowed attempts are not logged unless log_allowed is on' );
	}

	public function test_verification_is_unlimited_when_the_ip_limit_is_off(): void {
		$this->configure( array( 'ip' => array( 'enabled' => false ) ) );

		$this->assertSame( array( 'allowed' => true ), RateLimitChecker::check_verification( '203.0.113.9' ) );
		$this->assertSame( array(), $this->cache );
	}

	public function test_record_attempt_counts_email_and_cpf_per_window(): void {
		$this->configure( array() );
		global $wpdb;
		$windows = array();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			static function ( $table, $data ) use ( &$windows ) {
				$windows[] = $data['type'] . ':' . $data['window_type'];
				return 1;
			}
		);

		RateLimitChecker::record_attempt( 'email', 'person@example.org', 4 );
		RateLimitChecker::record_attempt( 'cpf', Identifiers::CPF_C, 4 );

		$this->assertSame( array( 'email:day', 'email:month', 'cpf:day', 'cpf:month', 'cpf:hour' ), $windows );
	}

	public function test_format_wait_time_picks_seconds_minutes_or_hours(): void {
		$method = new \ReflectionMethod( RateLimitChecker::class, 'format_wait_time' );
		$method->setAccessible( true );

		$this->assertSame( '1 second', $method->invoke( null, 1 ) );
		$this->assertSame( '45 seconds', $method->invoke( null, 45 ) );
		$this->assertSame( '2 minutes', $method->invoke( null, 61 ) );
		$this->assertSame( '2 hours', $method->invoke( null, 3601 ) );
	}

	public function test_a_read_limit_needs_a_well_formed_endpoint_entry(): void {
		$this->configure( array() );
		$this->settings['read'] = 'not an array';
		$this->assertSame( array( 'allowed' => true ), RateLimitChecker::check_read_limit( '203.0.113.9', 'calendar_slots' ) );

		$this->reset_settings_cache();
		$this->settings['read'] = array( 'endpoints' => array( 'calendar_slots' => 'not an array' ) );
		$this->assertSame( array( 'allowed' => true ), RateLimitChecker::check_read_limit( '203.0.113.9', 'calendar_slots' ) );

		$this->reset_settings_cache();
		$this->settings['read'] = array(
			'endpoints' => array(
				'calendar_slots' => array(
					'enabled'        => true,
					'max_per_minute' => 0,
					'max_per_hour'   => 0,
				),
			),
		);
		$this->assertSame( array( 'allowed' => true ), RateLimitChecker::check_read_limit( '203.0.113.9', 'calendar_slots' ), 'zero means no limit' );
	}
}
