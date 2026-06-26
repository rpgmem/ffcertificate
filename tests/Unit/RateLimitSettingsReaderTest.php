<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\RateLimitSettingsReader;

/**
 * Tests for RateLimitSettingsReader: generic accessors + the `ip` sub-group
 * over `ffc_rate_limit_settings`.
 *
 * @covers \FreeFormCertificate\Settings\RateLimitSettingsReader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RateLimitSettingsReaderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: stub `get_option('ffc_rate_limit_settings', [])`.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function stub_option( array $settings ): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = null ) use ( $settings ) {
			if ( RateLimitSettingsReader::OPTION_KEY === $key ) {
				return $settings;
			}
			return $default;
		} );
	}

	public function test_option_key_constant(): void {
		$this->assertSame( 'ffc_rate_limit_settings', RateLimitSettingsReader::OPTION_KEY );
	}

	public function test_get_returns_value_when_key_present(): void {
		$this->stub_option( array( 'foo' => 'bar', 'n' => 7 ) );

		$this->assertSame( 'bar', RateLimitSettingsReader::get( 'foo' ) );
		$this->assertSame( 7, RateLimitSettingsReader::get( 'n' ) );
	}

	public function test_get_returns_default_when_key_absent(): void {
		$this->stub_option( array( 'foo' => 'bar' ) );

		$this->assertSame( 'fallback', RateLimitSettingsReader::get( 'missing', 'fallback' ) );
		$this->assertNull( RateLimitSettingsReader::get( 'missing' ) );
	}

	public function test_all_returns_raw_array(): void {
		$this->stub_option( array( 'a' => 1, 'b' => 'two' ) );

		$this->assertSame( array( 'a' => 1, 'b' => 'two' ), RateLimitSettingsReader::all() );
	}

	public function test_all_returns_empty_array_when_option_is_not_array(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertSame( array(), RateLimitSettingsReader::all() );
	}

	public function test_ip_returns_sub_group_when_present(): void {
		$ip = array(
			'enabled'      => true,
			'max_per_hour' => 1000,
		);
		$this->stub_option( array( 'ip' => $ip ) );

		$this->assertSame( $ip, RateLimitSettingsReader::ip() );
	}

	public function test_ip_returns_empty_array_when_absent(): void {
		$this->stub_option( array() );
		$this->assertSame( array(), RateLimitSettingsReader::ip() );
	}

	public function test_ip_returns_empty_array_when_not_array(): void {
		// Defensive: a scalar `ip` leaf coerces to empty array.
		$this->stub_option( array( 'ip' => 'bogus' ) );
		$this->assertSame( array(), RateLimitSettingsReader::ip() );
	}
}
