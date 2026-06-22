<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\GeolocationSettingsReader;

/**
 * Tests for GeolocationSettingsReader: generic + typed accessors over
 * `ffc_geolocation_settings`.
 *
 * @covers \FreeFormCertificate\Settings\GeolocationSettingsReader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class GeolocationSettingsReaderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// absint() is used by the int accessors.
		Functions\when( 'absint' )->alias( function ( $value ) {
			return abs( (int) $value );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: stub `get_option('ffc_geolocation_settings', [])`.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function stub_option( array $settings ): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = null ) use ( $settings ) {
			if ( GeolocationSettingsReader::OPTION_KEY === $key ) {
				return $settings;
			}
			return $default;
		} );
	}

	// ------------------------------------------------------------------
	// Generic accessors
	// ------------------------------------------------------------------

	public function test_get_returns_value_when_key_present(): void {
		$this->stub_option( array( 'foo' => 'bar', 'baz' => 42 ) );

		$this->assertSame( 'bar', GeolocationSettingsReader::get( 'foo' ) );
		$this->assertSame( 42, GeolocationSettingsReader::get( 'baz' ) );
	}

	public function test_get_returns_default_when_key_absent(): void {
		$this->stub_option( array( 'foo' => 'bar' ) );

		$this->assertSame( 'fallback', GeolocationSettingsReader::get( 'missing', 'fallback' ) );
		$this->assertNull( GeolocationSettingsReader::get( 'missing' ) );
	}

	public function test_all_returns_raw_array(): void {
		$this->stub_option( array( 'a' => 1, 'b' => 'two' ) );

		$this->assertSame( array( 'a' => 1, 'b' => 'two' ), GeolocationSettingsReader::all() );
	}

	public function test_all_returns_empty_array_when_option_is_not_array(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertSame( array(), GeolocationSettingsReader::all() );
	}

	public function test_option_key_constant(): void {
		$this->assertSame( 'ffc_geolocation_settings', GeolocationSettingsReader::OPTION_KEY );
	}

	// ------------------------------------------------------------------
	// Typed bool accessors
	// ------------------------------------------------------------------

	public function test_ip_api_enabled_default_false(): void {
		$this->stub_option( array() );
		$this->assertFalse( GeolocationSettingsReader::ip_api_enabled() );

		$this->stub_option( array( 'ip_api_enabled' => 1 ) );
		$this->assertTrue( GeolocationSettingsReader::ip_api_enabled() );
	}

	public function test_ip_cache_enabled_uses_not_empty_semantics(): void {
		// Absent → false (NOT the tab's `true` default — matches consumers).
		$this->stub_option( array() );
		$this->assertFalse( GeolocationSettingsReader::ip_cache_enabled() );

		$this->stub_option( array( 'ip_cache_enabled' => 0 ) );
		$this->assertFalse( GeolocationSettingsReader::ip_cache_enabled() );

		$this->stub_option( array( 'ip_cache_enabled' => 1 ) );
		$this->assertTrue( GeolocationSettingsReader::ip_cache_enabled() );
	}

	public function test_ip_api_cascade_default_false(): void {
		$this->stub_option( array() );
		$this->assertFalse( GeolocationSettingsReader::ip_api_cascade() );

		$this->stub_option( array( 'ip_api_cascade' => 1 ) );
		$this->assertTrue( GeolocationSettingsReader::ip_api_cascade() );
	}

	public function test_admin_bypass_datetime_default_false(): void {
		$this->stub_option( array() );
		$this->assertFalse( GeolocationSettingsReader::admin_bypass_datetime() );

		$this->stub_option( array( 'admin_bypass_datetime' => 1 ) );
		$this->assertTrue( GeolocationSettingsReader::admin_bypass_datetime() );
	}

	public function test_admin_bypass_geo_default_false(): void {
		$this->stub_option( array() );
		$this->assertFalse( GeolocationSettingsReader::admin_bypass_geo() );

		$this->stub_option( array( 'admin_bypass_geo' => 1 ) );
		$this->assertTrue( GeolocationSettingsReader::admin_bypass_geo() );
	}

	// ------------------------------------------------------------------
	// Typed string accessors
	// ------------------------------------------------------------------

	public function test_ip_api_service_default_and_value(): void {
		$this->stub_option( array() );
		$this->assertSame( 'ip-api', GeolocationSettingsReader::ip_api_service() );

		$this->stub_option( array( 'ip_api_service' => 'ipinfo' ) );
		$this->assertSame( 'ipinfo', GeolocationSettingsReader::ip_api_service() );
	}

	public function test_ipinfo_api_key_default_and_value(): void {
		$this->stub_option( array() );
		$this->assertSame( '', GeolocationSettingsReader::ipinfo_api_key() );

		$this->stub_option( array( 'ipinfo_api_key' => 'abc123' ) );
		$this->assertSame( 'abc123', GeolocationSettingsReader::ipinfo_api_key() );
	}

	public function test_api_fallback_default_and_value(): void {
		$this->stub_option( array() );
		$this->assertSame( 'gps_only', GeolocationSettingsReader::api_fallback() );

		$this->stub_option( array( 'api_fallback' => 'block' ) );
		$this->assertSame( 'block', GeolocationSettingsReader::api_fallback() );
	}

	// ------------------------------------------------------------------
	// Typed int accessors
	// ------------------------------------------------------------------

	public function test_ip_cache_ttl_default_and_value(): void {
		$this->stub_option( array() );
		$this->assertSame( 600, GeolocationSettingsReader::ip_cache_ttl() );

		// Empty/zero → default.
		$this->stub_option( array( 'ip_cache_ttl' => 0 ) );
		$this->assertSame( 600, GeolocationSettingsReader::ip_cache_ttl() );

		$this->stub_option( array( 'ip_cache_ttl' => '1800' ) );
		$this->assertSame( 1800, GeolocationSettingsReader::ip_cache_ttl() );
	}

	public function test_gps_cache_ttl_default_and_value(): void {
		$this->stub_option( array() );
		$this->assertSame( 600, GeolocationSettingsReader::gps_cache_ttl() );

		$this->stub_option( array( 'gps_cache_ttl' => 0 ) );
		$this->assertSame( 600, GeolocationSettingsReader::gps_cache_ttl() );

		$this->stub_option( array( 'gps_cache_ttl' => '900' ) );
		$this->assertSame( 900, GeolocationSettingsReader::gps_cache_ttl() );
	}
}
