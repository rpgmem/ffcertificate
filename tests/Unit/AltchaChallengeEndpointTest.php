<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\AltchaChallengeEndpoint;
use FreeFormCertificate\Core\Captcha\CaptchaSettings;

/**
 * Tests for the ALTCHA challenge endpoint (#1053 PR3).
 *
 * Alias-mocks `RequestInput`, so this runs in its own process.
 *
 * @covers \FreeFormCertificate\Core\Captcha\AltchaChallengeEndpoint
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AltchaChallengeEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> */
	private array $transients = array();

	/** @var array<int, mixed> Bodies passed to wp_send_json(). */
	private array $responses = array();

	/** @var array<int, int> Status codes passed to status_header(). */
	private array $statuses = array();

	/** @var bool Whether nocache_headers() was called. */
	private bool $nocache = false;

	/** @var array<int, string> Hooks registered by init(). */
	private array $hooks = array();

	/** @var array<string, mixed> Stored `ffc_rate_limit_settings`. */
	private array $rate_limit_settings = array();

	/** @var array<int, int> TTLs passed to set_transient(). */
	private array $transient_expirations = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Core\Captcha\AltchaChallengeEndpoint' );

		$this->transients = array();
		$this->responses  = array();
		$this->statuses   = array();
		$this->nocache    = false;
		$this->hooks      = array();

		$this->rate_limit_settings   = array();
		$this->transient_expirations = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => 'ffc_rate_limit_settings' === $key ? $this->rate_limit_settings : array()
		);
		Functions\when( 'add_action' )->alias(
			function ( string $hook ): bool {
				$this->hooks[] = $hook;
				return true;
			}
		);
		Functions\when( 'nocache_headers' )->alias(
			function (): void {
				$this->nocache = true;
			}
		);
		Functions\when( 'status_header' )->alias(
			function ( int $code ): void {
				$this->statuses[] = $code;
			}
		);
		Functions\when( 'wp_send_json' )->alias(
			function ( $data ): void {
				$this->responses[] = $data;
				throw new \RuntimeException( 'wp_send_json' );
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, $expiration = 0 ): bool {
				$this->transients[ $key ]      = $value;
				$this->transient_expirations[] = (int) $expiration;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function mock_ip( string $ip ): void {
		$request = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
		$request->shouldReceive( 'get_user_ip' )->andReturn( $ip );
	}

	private function set_mint_cap( int $cap ): void {
		$this->rate_limit_settings = array( 'ip' => array( 'captcha_max_per_window' => $cap ) );
	}

	/** @return array<int, int> The TTLs set_transient() was given. */
	private function transient_ttls(): array {
		return $this->transient_expirations;
	}

	private function call_handle(): void {
		try {
			AltchaChallengeEndpoint::handle();
		} catch ( \RuntimeException $e ) {
			// wp_send_json() exits in production; the stub throws instead.
		}
	}

	public function test_init_registers_both_privileged_and_anonymous_hooks(): void {
		// The forms this guards are public, so a nopriv handler is not
		// optional — without it every anonymous visitor gets 0 back.
		AltchaChallengeEndpoint::init();

		$this->assertSame(
			array(
				'wp_ajax_' . AltchaChallengeEndpoint::AJAX_ACTION,
				'wp_ajax_nopriv_' . AltchaChallengeEndpoint::AJAX_ACTION,
			),
			$this->hooks
		);
	}

	public function test_the_response_is_a_bare_challenge_not_a_success_envelope(): void {
		// The widget reads the body as an ALTCHA challenge and would not find
		// these fields inside {success, data}.
		$this->mock_ip( '203.0.113.10' );

		$this->call_handle();

		$this->assertCount( 1, $this->responses );
		$body = $this->responses[0];

		$this->assertSame( 'SHA-256', $body['algorithm'] );
		$this->assertArrayHasKey( 'challenge', $body );
		$this->assertArrayHasKey( 'salt', $body );
		$this->assertArrayHasKey( 'signature', $body );
		$this->assertArrayNotHasKey( 'success', $body );
	}

	public function test_the_response_is_never_cacheable(): void {
		// A cached challenge is a shared challenge, and a shared single-use
		// token is one the first solver spends for everyone else.
		$this->mock_ip( '203.0.113.11' );

		$this->call_handle();

		$this->assertTrue( $this->nocache, 'nocache_headers() must run before anything is emitted.' );
	}

	public function test_each_call_mints_a_distinct_challenge(): void {
		$this->mock_ip( '203.0.113.12' );

		$this->call_handle();
		$this->call_handle();

		$this->assertNotSame(
			$this->responses[0]['salt'],
			$this->responses[1]['salt'],
			'Two visitors sharing a salt would share a solution.'
		);
	}

	public function test_a_flood_from_one_address_is_throttled(): void {
		$this->mock_ip( '203.0.113.13' );

		for ( $i = 0; $i < 61; $i++ ) {
			$this->call_handle();
		}

		$this->assertSame( array( 429 ), $this->statuses );
		$this->assertArrayHasKey(
			'error',
			$this->responses[60],
			'The refusal must be a body the widget can surface, not an empty 429.'
		);
	}

	public function test_the_throttle_is_per_address(): void {
		// Institutional NAT already crowds one address; one visitor must not
		// be able to lock out another site's entirely.
		$this->mock_ip( '203.0.113.14' );
		for ( $i = 0; $i < 61; $i++ ) {
			$this->call_handle();
		}
		$this->assertSame( array( 429 ), $this->statuses );

		Mockery::close();
		$this->mock_ip( '203.0.113.15' );
		$this->call_handle();

		$this->assertSame( array( 429 ), $this->statuses, 'A second address must still be served.' );
	}

	public function test_an_unresolvable_address_is_served_rather_than_blocked(): void {
		// With no address there is nothing to count, and refusing everyone
		// behind a proxy the resolver cannot read would break the form
		// outright — a worse failure than an uncounted mint.
		$this->mock_ip( '' );

		$this->call_handle();

		$this->assertArrayHasKey( 'challenge', $this->responses[0] );
		$this->assertSame( array(), $this->statuses );
	}

	// ==================================================================
	// The cap is configurable (#1111)
	// ==================================================================

	public function test_the_default_cap_is_the_one_the_endpoint_enforced_before_it_was_configurable(): void {
		// An install that never touches the field must not change state on
		// upgrade, so the unconfigured cap is exactly the old constant.
		$this->mock_ip( '203.0.113.20' );

		for ( $i = 0; $i < CaptchaSettings::MINT_CAP_DEFAULT; $i++ ) {
			$this->call_handle();
		}
		$this->assertSame( array(), $this->statuses, 'The cap must not bite before it is reached.' );

		$this->call_handle();
		$this->assertSame( array( 429 ), $this->statuses );
	}

	public function test_a_configured_cap_replaces_the_default(): void {
		$this->set_mint_cap( 3 );
		$this->mock_ip( '203.0.113.21' );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->call_handle();
		}
		$this->assertSame( array(), $this->statuses );

		$this->call_handle();
		$this->assertSame( array( 429 ), $this->statuses );
	}

	public function test_a_cap_of_zero_lifts_the_throttle_entirely(): void {
		// The escape hatch for institutional NAT, where any per-address
		// number caps the building rather than the farmer.
		$this->set_mint_cap( 0 );
		$this->mock_ip( '203.0.113.22' );

		for ( $i = 0; $i < CaptchaSettings::MINT_CAP_DEFAULT + 5; $i++ ) {
			$this->call_handle();
		}

		$this->assertSame( array(), $this->statuses );
		$this->assertSame( array(), $this->transients, 'With no cap there is nothing to count, so nothing is written.' );
	}

	public function test_the_window_is_configurable_and_is_also_the_counter_lifetime(): void {
		// A cap whose window is a private constant is half a setting: the
		// same number means something different over a minute and an hour.
		$this->rate_limit_settings = array(
			'ip' => array(
				'captcha_max_per_window' => 5,
				'captcha_window_seconds' => 120,
			),
		);
		$this->mock_ip( '203.0.113.24' );

		$this->call_handle();

		$this->assertSame( array( 120 ), $this->transient_ttls(), 'The counter must expire with its own window.' );
		$this->assertStringEndsWith( '_' . (int) floor( time() / 120 ), array_key_first( $this->transients ) );
	}

	public function test_an_out_of_range_window_is_clamped_rather_than_taken_literally(): void {
		// Bounded on read as well as on save. A one-second window would make
		// the throttle a formality; the floor is what stops that.
		$this->rate_limit_settings = array(
			'ip' => array(
				'captcha_max_per_window' => 5,
				'captcha_window_seconds' => 1,
			),
		);
		$this->mock_ip( '203.0.113.25' );

		$this->call_handle();

		$this->assertSame( array( CaptchaSettings::MINT_WINDOW_MIN ), $this->transient_ttls() );
	}

	public function test_an_unconfigured_window_keeps_the_ten_minutes_the_constant_carried(): void {
		$this->mock_ip( '203.0.113.26' );

		$this->call_handle();

		$this->assertSame( 600, CaptchaSettings::MINT_WINDOW_DEFAULT );
		$this->assertSame( array( CaptchaSettings::MINT_WINDOW_DEFAULT ), $this->transient_ttls() );
	}

	public function test_a_nonsense_stored_value_falls_back_to_the_default_rather_than_lifting_the_cap(): void {
		// Bounded on read, not only on save. The failure mode that matters is
		// the permissive one: a garbage value must not read as "no cap".
		// The numeric bounds themselves are asserted in CaptchaSettingsTest.
		$this->rate_limit_settings = array( 'ip' => array( 'captcha_max_per_window' => 'plenty' ) );
		$this->mock_ip( '203.0.113.23' );

		for ( $i = 0; $i < CaptchaSettings::MINT_CAP_DEFAULT; $i++ ) {
			$this->call_handle();
		}
		$this->assertSame( array(), $this->statuses );

		$this->call_handle();
		$this->assertSame( array( 429 ), $this->statuses );
	}
}
