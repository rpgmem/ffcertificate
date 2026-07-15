<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\CacheVersion;

/**
 * Tests for CacheVersion: per-domain version counter used for coarse-grained
 * invalidation of un-enumerable (md5-hashed) query caches.
 *
 * @covers \FreeFormCertificate\Core\CacheVersion
 */
class CacheVersionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\class_exists( '\FreeFormCertificate\Core\CacheVersion' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// current() / suffix()
	// ==================================================================

	public function test_current_defaults_to_zero_when_unset(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => $default
		);

		$this->assertSame( 0, CacheVersion::current( 'audience' ) );
	}

	public function test_current_returns_stored_int(): void {
		Functions\when( 'get_option' )->justReturn( '7' );

		$this->assertSame( 7, CacheVersion::current( 'audience' ) );
	}

	public function test_suffix_folds_current_version(): void {
		Functions\when( 'get_option' )->justReturn( 42 );

		$this->assertSame( 'v42', CacheVersion::suffix( 'audience' ) );
	}

	// ==================================================================
	// option-name resolution (per domain)
	// ==================================================================

	public function test_recruitment_domain_maps_to_legacy_option_key(): void {
		$seen = null;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$seen ) {
				$seen = $name;
				return $default;
			}
		);

		CacheVersion::current( 'recruitment_public' );

		// Backward-compat: must keep the pre-existing option so upgrading
		// does not cold-start the recruitment public cache.
		$this->assertSame( 'ffc_recruitment_public_cache_version', $seen );
	}

	public function test_audience_domain_maps_to_namespaced_option_key(): void {
		$seen = null;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$seen ) {
				$seen = $name;
				return $default;
			}
		);

		CacheVersion::current( 'audience' );

		$this->assertSame( 'ffc_cache_version_audience', $seen );
	}

	public function test_unknown_domain_falls_back_to_prefixed_key(): void {
		$seen = null;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$seen ) {
				$seen = $name;
				return $default;
			}
		);

		CacheVersion::current( 'something_new' );

		$this->assertSame( 'ffc_cache_version_something_new', $seen );
	}

	// ==================================================================
	// bump()
	// ==================================================================

	public function test_bump_increments_and_writes_non_autoloaded(): void {
		Functions\when( 'get_option' )->justReturn( 4 );

		$captured = array();
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $autoload = null ) use ( &$captured ) {
				$captured = array( $name, $value, $autoload );
				return true;
			}
		);

		CacheVersion::bump( 'audience' );

		$this->assertSame( 'ffc_cache_version_audience', $captured[0] );
		$this->assertSame( 5, $captured[1], 'bump writes current + 1' );
		$this->assertFalse( $captured[2], 'version option must not autoload' );
	}

	public function test_bump_starts_at_one_from_unset(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => $default
		);

		$value = null;
		Functions\when( 'update_option' )->alias(
			static function ( $name, $v, $autoload = null ) use ( &$value ) {
				$value = $v;
				return true;
			}
		);

		CacheVersion::bump( 'audience' );

		$this->assertSame( 1, $value );
	}

	public function test_bump_wraps_at_php_int_max(): void {
		Functions\when( 'get_option' )->justReturn( PHP_INT_MAX );

		$value = null;
		Functions\when( 'update_option' )->alias(
			static function ( $name, $v, $autoload = null ) use ( &$value ) {
				$value = $v;
				return true;
			}
		);

		CacheVersion::bump( 'audience' );

		// ( PHP_INT_MAX + 1 ) overflows to a float; % PHP_INT_MAX brings it
		// back to a safe small int rather than a float/negative.
		$this->assertIsInt( $value );
	}
}
