<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\DeviceCheck;
use FreeFormCertificate\Settings\RateLimitSettingsReader;

/**
 * The device test's one server request and its per-address limit (#2064).
 *
 * The address comes from `$_SERVER`, read by the real resolver, so these
 * tests see what a visitor with no readable address really sends (#2106).
 *
 * @covers \FreeFormCertificate\Frontend\DeviceCheck
 * @covers \FreeFormCertificate\Core\WindowThrottle
 * @covers \FreeFormCertificate\Settings\RateLimitSettingsReader
 * @preserveGlobalState disabled
 */
class DeviceCheckPingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> */
	private array $server_snapshot = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	/** @var array<int, array{bool, mixed, int|null}> success, data, status. */
	private array $responses = array();

	/** @var bool */
	private bool $nocache = false;

	/** @var array<string, mixed> Stored `ffc_rate_limit_settings`. */
	private array $rate_limit_settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// RequestInput resolves the address for real: the default `legacy`
		// mode, through these, over a $_SERVER this test owns.
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->server_snapshot = $_SERVER;
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' ) as $header ) {
			unset( $_SERVER[ $header ] );
		}

		$this->transients          = array();
		$this->responses           = array();
		$this->nocache             = false;
		$this->rate_limit_settings = array();

		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => 'ffc_rate_limit_settings' === $key ? $this->rate_limit_settings : array()
		);
		Functions\when( 'nocache_headers' )->alias(
			function (): void {
				$this->nocache = true;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null, $status = null ): void {
				$this->responses[] = array( true, $data, $status );
				throw new \RuntimeException( 'sent' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status = null ): void {
				$this->responses[] = array( false, $data, $status );
				throw new \RuntimeException( 'sent' );
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_snapshot;
		Monkey\tearDown();
		parent::tearDown();
	}

	private function mock_ip( string $ip ): void {
		if ( '' === $ip ) {
			unset( $_SERVER['REMOTE_ADDR'] );
			return;
		}
		$_SERVER['REMOTE_ADDR'] = $ip;
	}

	private function ping(): void {
		try {
			DeviceCheck::handle_ping();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'sent', $e->getMessage() );
		}
	}

	public function test_init_registers_the_ping_for_visitors_and_signed_in_users(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			function ( string $hook, $callback ) use ( &$hooks ): bool {
				$hooks[ $hook ] = $callback;
				return true;
			}
		);

		DeviceCheck::init();

		$callback = array( DeviceCheck::class, 'handle_ping' );
		$this->assertSame(
			array(
				'wp_ajax_ffc_device_check_ping'        => $callback,
				'wp_ajax_nopriv_ffc_device_check_ping' => $callback,
			),
			$hooks
		);
	}

	public function test_answers_with_the_server_clock_and_the_version_never_cached(): void {
		$this->mock_ip( '203.0.113.7' );
		$before = time();
		$this->ping();
		$after = time();

		$this->assertTrue( $this->nocache );
		$this->assertCount( 1, $this->responses );
		[ $success, $data ] = $this->responses[0];
		$this->assertTrue( $success );
		$this->assertSame( FFC_VERSION, $data['version'] );
		// The clock is read once; accept either side of a second boundary.
		$this->assertGreaterThanOrEqual( $before, $data['time'] );
		$this->assertLessThanOrEqual( $after, $data['time'] );
	}

	public function test_refuses_past_the_cap_with_the_seconds_to_wait(): void {
		$this->mock_ip( '203.0.113.7' );
		$this->rate_limit_settings = array(
			'ip' => array(
				'device_check_max_per_window' => 2,
				'device_check_window_seconds' => 600,
			),
		);

		$this->ping();
		$this->ping();
		$this->ping();

		$this->assertCount( 3, $this->responses );
		$this->assertTrue( $this->responses[0][0] );
		$this->assertTrue( $this->responses[1][0] );
		[ $success, $data, $status ] = $this->responses[2];
		$this->assertFalse( $success );
		$this->assertSame( 429, $status );
		$this->assertGreaterThanOrEqual( 1, $data['retryAfter'] );
		$this->assertLessThanOrEqual( 600, $data['retryAfter'] );

		// The address is hashed into the key, never stored raw.
		$this->assertCount( 1, $this->transients );
		$key = (string) array_key_first( $this->transients );
		$this->assertStringStartsWith( 'ffc_dcping_', $key );
		$this->assertStringNotContainsString( '203.0.113.7', $key );
	}

	public function test_a_cap_of_zero_counts_nothing(): void {
		$this->mock_ip( '203.0.113.7' );
		$this->rate_limit_settings = array( 'ip' => array( 'device_check_max_per_window' => 0 ) );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->ping();
		}

		$this->assertSame( array( true, true, true, true, true ), array_column( $this->responses, 0 ) );
		$this->assertSame( array(), $this->transients );
	}

	public function test_a_request_without_an_address_is_let_through_uncounted(): void {
		$this->mock_ip( '' );
		$this->rate_limit_settings = array( 'ip' => array( 'device_check_max_per_window' => 1 ) );

		$this->ping();
		$this->ping();

		$this->assertSame( array( true, true ), array_column( $this->responses, 0 ) );
		$this->assertSame( array(), $this->transients );
	}

	public function test_each_address_has_its_own_count(): void {
		$this->rate_limit_settings = array( 'ip' => array( 'device_check_max_per_window' => 1 ) );

		$this->mock_ip( '203.0.113.7' );
		$this->ping();
		$this->ping();
		$this->mock_ip( '198.51.100.9' );
		$this->ping();

		$this->assertSame( array( true, false, true ), array_column( $this->responses, 0 ) );
	}

	public function test_an_ipv6_subscriber_counts_once_across_its_64(): void {
		// One subscriber holds the whole /64 and can rotate through it, so
		// moving to the next address is not a fresh count (#2106).
		$this->rate_limit_settings = array( 'ip' => array( 'device_check_max_per_window' => 1 ) );

		$this->mock_ip( '2804:14c:1:2::1' );
		$this->ping();
		$this->mock_ip( '2804:14C:1:2:ffff::9' );
		$this->ping();
		$this->mock_ip( '2804:14c:1:3::1' );
		$this->ping();

		$this->assertSame( array( true, false, true ), array_column( $this->responses, 0 ) );
	}

	public function test_unidentified_callers_share_one_count_when_the_setting_says_so(): void {
		$this->mock_ip( '' );
		$this->rate_limit_settings = array(
			'ip' => array(
				'device_check_max_per_window' => 1,
				'device_check_unresolved'     => 'share',
			),
		);

		$this->ping();
		$this->ping();

		$this->assertSame( array( true, false ), array_column( $this->responses, 0 ) );
	}

	public function test_a_private_address_is_unidentified(): void {
		// An internal network reaches the site from private addresses, which
		// the legacy resolver does not accept as a client address.
		$this->mock_ip( '10.0.0.5' );
		$this->rate_limit_settings = array( 'ip' => array( 'device_check_max_per_window' => 1 ) );

		$this->ping();
		$this->ping();

		$this->assertSame( array( true, true ), array_column( $this->responses, 0 ) );
		$this->assertSame( array(), $this->transients );
	}

	public function test_the_cap_and_window_are_bounded_on_read(): void {
		$this->rate_limit_settings = array(
			'ip' => array(
				'device_check_max_per_window' => 999999,
				'device_check_window_seconds' => 0,
			),
		);
		$this->assertSame( RateLimitSettingsReader::DEVICE_CHECK_CAP_MAX, RateLimitSettingsReader::device_check_cap() );
		$this->assertSame( RateLimitSettingsReader::DEVICE_CHECK_WINDOW_MIN, RateLimitSettingsReader::device_check_window() );

		$this->rate_limit_settings = array();
		$this->assertSame( 60, RateLimitSettingsReader::device_check_cap() );
		$this->assertSame( 600, RateLimitSettingsReader::device_check_window() );

		$this->assertSame( RateLimitSettingsReader::DEVICE_CHECK_CAP_DEFAULT, RateLimitSettingsReader::clamp_device_check_cap( 'lots' ) );
		$this->assertSame( RateLimitSettingsReader::DEVICE_CHECK_WINDOW_MAX, RateLimitSettingsReader::clamp_device_check_window( 86400 ) );
	}
}
