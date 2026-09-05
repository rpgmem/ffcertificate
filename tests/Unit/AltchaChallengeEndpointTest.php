<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\AltchaChallengeEndpoint;

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

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Core\Captcha\AltchaChallengeEndpoint' );

		$this->transients = array();
		$this->responses  = array();
		$this->statuses   = array();
		$this->nocache    = false;
		$this->hooks      = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
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
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
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
}
