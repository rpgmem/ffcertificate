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

	public function test_probe_reports_error_on_wp_error(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		$err = Mockery::mock( 'WP_Error' );
		$err->shouldReceive( 'get_error_message' )->andReturn( 'timeout' );
		Functions\when( 'wp_remote_get' )->justReturn( $err );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );

		$out = CloudflareCacheProbe::get( true );
		$this->assertSame( CloudflareCacheProbe::STATUS_ERROR, $out['status'] );
		$this->assertSame( 'timeout', $out['detail'] );
	}

	public function test_probe_caches_result_via_set_transient(): void {
		$this->stub_request_with_cache_status( 'HIT' );
		$captured = null;
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$captured ) {
				$captured = array( $key, $value );
				return true;
			}
		);
		CloudflareCacheProbe::get( true );
		$this->assertIsArray( $captured );
		$this->assertSame( CloudflareCacheProbe::TRANSIENT_KEY, $captured[0] );
		$this->assertSame( CloudflareCacheProbe::STATUS_CACHING, $captured[1]['status'] );
	}
}
