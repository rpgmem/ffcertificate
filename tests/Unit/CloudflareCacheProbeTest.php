<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Integrations\CloudflareCacheProbe;

/**
 * #921 — the Cloudflare page-cache probe. Pins the transient short-circuit and
 * the `cf-cache-status` → verdict classification (caching / safe / no_cf /
 * error).
 *
 * @covers \FreeFormCertificate\Integrations\CloudflareCacheProbe
 */
class CloudflareCacheProbeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Integrations\CloudflareCacheProbe' );
		Functions\when( 'get_transient' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Stub the outbound request to return $header as cf-cache-status. */
	private function stub_request_with_cache_status( string $header ): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'ok' => true ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $resp, $name ) use ( $header ) {
				return 'cf-cache-status' === $name ? $header : '';
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
	}

	public function test_get_returns_cached_verdict_without_probing(): void {
		Functions\when( 'get_transient' )->justReturn(
			array( 'status' => CloudflareCacheProbe::STATUS_SAFE, 'raw' => 'DYNAMIC', 'checked' => 123, 'detail' => '' )
		);
		// A cache hit must not touch the network.
		Functions\expect( 'wp_remote_get' )->never();

		$out = CloudflareCacheProbe::get();
		$this->assertSame( CloudflareCacheProbe::STATUS_SAFE, $out['status'] );
		$this->assertSame( 'DYNAMIC', $out['raw'] );
	}

	public function test_probe_flags_caching_on_hit(): void {
		$this->stub_request_with_cache_status( 'HIT' );
		$out = CloudflareCacheProbe::get( true );
		$this->assertSame( CloudflareCacheProbe::STATUS_CACHING, $out['status'] );
		$this->assertSame( 'HIT', $out['raw'] );
	}

	public function test_probe_flags_caching_on_miss(): void {
		// MISS also proves the edge is attempting to cache HTML.
		$this->stub_request_with_cache_status( 'MISS' );
		$this->assertSame( CloudflareCacheProbe::STATUS_CACHING, CloudflareCacheProbe::get( true )['status'] );
	}

	public function test_probe_reports_safe_on_dynamic(): void {
		$this->stub_request_with_cache_status( 'DYNAMIC' );
		$this->assertSame( CloudflareCacheProbe::STATUS_SAFE, CloudflareCacheProbe::get( true )['status'] );
	}

	public function test_probe_reports_safe_on_bypass(): void {
		$this->stub_request_with_cache_status( 'BYPASS' );
		$this->assertSame( CloudflareCacheProbe::STATUS_SAFE, CloudflareCacheProbe::get( true )['status'] );
	}

	public function test_probe_reports_no_cf_when_header_absent(): void {
		$this->stub_request_with_cache_status( '' );
		$out = CloudflareCacheProbe::get( true );
		$this->assertSame( CloudflareCacheProbe::STATUS_NO_CF, $out['status'] );
		$this->assertSame( '', $out['raw'] );
	}

	/** Make the loopback fail. */
	private function stub_failing_request(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		$err = Mockery::mock( 'WP_Error' );
		$err->shouldReceive( 'get_error_message' )->andReturn( 'timeout' );
		Functions\when( 'wp_remote_get' )->justReturn( $err );
		Functions\when( 'is_wp_error' )->justReturn( true );
	}

	public function test_probe_reports_error_on_wp_error(): void {
		$this->stub_failing_request();
		$stored = null;
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$before = time();
		$out    = CloudflareCacheProbe::get( true );
		$after  = time();

		$this->assertSame( CloudflareCacheProbe::STATUS_ERROR, $out['status'] );
		$this->assertSame( 'timeout', $out['detail'] );
		// The first failure is retried after ten minutes, like the release check (#2342).
		$this->assertSame( 1, $stored['failures'] );
		$this->assertGreaterThanOrEqual( $before + 600, $stored['retry_at'] );
		$this->assertLessThanOrEqual( $after + 600, $stored['retry_at'] );
	}

	public function test_a_failed_probe_waits_for_its_retry_time(): void {
		Functions\when( 'get_transient' )->justReturn(
			array(
				'status'           => CloudflareCacheProbe::STATUS_ERROR,
				'raw'              => '',
				'checked'          => 1,
				'detail'           => 'timeout',
				'failures'         => 1,
				'first_failure_at' => time() - 60,
				'retry_at'         => time() + 540,
			)
		);
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( CloudflareCacheProbe::STATUS_ERROR, CloudflareCacheProbe::get()['status'] );
	}

	public function test_a_failed_probe_past_its_retry_time_counts_the_next_failure(): void {
		$first = time() - 3000;
		Functions\when( 'get_transient' )->justReturn(
			array(
				'status'           => CloudflareCacheProbe::STATUS_ERROR,
				'failures'         => 2,
				'first_failure_at' => $first,
				'retry_at'         => time() - 1,
			)
		);
		$this->stub_failing_request();
		$stored = null;
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		CloudflareCacheProbe::get();

		$this->assertSame( 3, $stored['failures'] );
		$this->assertSame( $first, $stored['first_failure_at'] );
	}

	public function test_probe_caches_result_via_set_transient(): void {
		$this->stub_request_with_cache_status( 'HIT' );
		$captured = null;
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $expiration ) use ( &$captured ) {
				$captured = array( $key, $value, $expiration );
				return true;
			}
		);
		CloudflareCacheProbe::get( true );
		$this->assertIsArray( $captured );
		$this->assertSame( CloudflareCacheProbe::TRANSIENT_KEY, $captured[0] );
		$this->assertSame( CloudflareCacheProbe::STATUS_CACHING, $captured[1]['status'] );
		$this->assertSame( 12 * HOUR_IN_SECONDS, $captured[2] );
		$this->assertArrayNotHasKey( 'retry_at', $captured[1] );
	}
}
