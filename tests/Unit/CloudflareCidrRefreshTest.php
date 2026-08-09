<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Integrations\CloudflareCidrRefresh;

/**
 * #901 phase 2 — the daily Cloudflare CIDR refresh. Pins the fetch/parse/cache
 * cycle, the "never overwrite last-known-good with an empty fetch" guard, and
 * the filter injection over the bundled fallback.
 *
 * @covers \FreeFormCertificate\Integrations\CloudflareCidrRefresh
 */
class CloudflareCidrRefreshTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Integrations\CloudflareCidrRefresh' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_run_fetches_parses_and_caches_valid_ranges(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'wp_remote_get' )->justReturn(
			array( 'body' => "104.16.0.0/13\n2400:cb00::/32\ngarbage-line\n" )
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $r ) => is_array( $r ) ? (string) $r['body'] : ''
		);

		$captured = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		// Both fetches return the same list → deduped to two valid ranges.
		$this->assertSame( 2, CloudflareCidrRefresh::run() );
		$this->assertIsArray( $captured );
		$this->assertContains( '104.16.0.0/13', $captured['ranges'] );
		$this->assertContains( '2400:cb00::/32', $captured['ranges'] );
		$this->assertNotContains( 'garbage-line', $captured['ranges'] );
		$this->assertSame( 2, $captured['count'] );
	}

	public function test_run_keeps_last_known_good_on_fetch_error(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'wp_remote_get' )->justReturn( 'irrelevant' );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\expect( 'update_option' )->never();

		$this->assertSame( 0, CloudflareCidrRefresh::run() );
	}

	public function test_inject_ranges_appends_cached_to_bundled(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'ranges' => array( '1.2.3.0/24' ), 'count' => 1 )
		);
		$out = CloudflareCidrRefresh::inject_ranges( array( '104.16.0.0/13' ) );
		$this->assertContains( '104.16.0.0/13', $out );
		$this->assertContains( '1.2.3.0/24', $out );
	}

	public function test_inject_ranges_passthrough_when_no_cache(): void {
		Functions\when( 'get_option' )->justReturn( null );
		$this->assertSame(
			array( '104.16.0.0/13' ),
			CloudflareCidrRefresh::inject_ranges( array( '104.16.0.0/13' ) )
		);
	}
}
