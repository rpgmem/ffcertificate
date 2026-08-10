<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\IpDiagnosticsSettingsReader;

/**
 * #901 phase 2 — the reader over `ffc_ip_diagnostics_settings`. Pins the enum
 * validation (unknown values fall back to the safe default) and the CIDR-list
 * parsing that the settings→resolver bridge relies on.
 *
 * @covers \FreeFormCertificate\Settings\IpDiagnosticsSettingsReader
 */
class IpDiagnosticsSettingsReaderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Settings\IpDiagnosticsSettingsReader' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $option
	 */
	private function with_option( array $option ): void {
		Functions\when( 'get_option' )->justReturn( $option );
	}

	public function test_defaults_when_option_absent(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( 'legacy', IpDiagnosticsSettingsReader::strategy() );
		$this->assertFalse( IpDiagnosticsSettingsReader::is_secure() );
		$this->assertSame( 'auto', IpDiagnosticsSettingsReader::trusted_proxy_mode() );
		$this->assertSame( array(), IpDiagnosticsSettingsReader::custom_proxies() );
		$this->assertFalse( IpDiagnosticsSettingsReader::shadow_logging_enabled() );
	}

	public function test_non_array_option_coerced(): void {
		Functions\when( 'get_option' )->justReturn( 'corrupt' );
		$this->assertSame( array(), IpDiagnosticsSettingsReader::all() );
		$this->assertSame( 'legacy', IpDiagnosticsSettingsReader::strategy() );
	}

	public function test_valid_values_pass_through(): void {
		$this->with_option(
			array(
				'strategy'           => 'secure',
				'trusted_proxy_mode' => 'custom',
				'shadow_logging'     => 1,
			)
		);
		$this->assertSame( 'secure', IpDiagnosticsSettingsReader::strategy() );
		$this->assertTrue( IpDiagnosticsSettingsReader::is_secure() );
		$this->assertSame( 'custom', IpDiagnosticsSettingsReader::trusted_proxy_mode() );
		$this->assertTrue( IpDiagnosticsSettingsReader::shadow_logging_enabled() );
	}

	public function test_unknown_strategy_falls_back_to_legacy(): void {
		$this->with_option( array( 'strategy' => 'bogus' ) );
		$this->assertSame( 'legacy', IpDiagnosticsSettingsReader::strategy() );
	}

	public function test_unknown_proxy_mode_falls_back_to_auto(): void {
		$this->with_option( array( 'trusted_proxy_mode' => 'nonsense' ) );
		$this->assertSame( 'auto', IpDiagnosticsSettingsReader::trusted_proxy_mode() );
	}

	public function test_custom_proxies_parses_and_dedups(): void {
		$this->with_option(
			array( 'custom_proxies' => "10.0.0.0/8\n192.168.0.0/16 , 10.0.0.0/8\n\n" )
		);
		$this->assertSame(
			array( '10.0.0.0/8', '192.168.0.0/16' ),
			IpDiagnosticsSettingsReader::custom_proxies()
		);
	}

	public function test_custom_proxies_accepts_array_shape(): void {
		$this->with_option( array( 'custom_proxies' => array( ' 172.16.0.0/12 ', '' ) ) );
		$this->assertSame( array( '172.16.0.0/12' ), IpDiagnosticsSettingsReader::custom_proxies() );
	}

	public function test_rate_limit_ip_source_defaults_to_remote_addr(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( 'remote_addr', IpDiagnosticsSettingsReader::rate_limit_ip_source() );
	}

	public function test_rate_limit_ip_source_accepts_resolver(): void {
		$this->with_option( array( 'rate_limit_ip_source' => 'resolver' ) );
		$this->assertSame( 'resolver', IpDiagnosticsSettingsReader::rate_limit_ip_source() );
	}

	public function test_rate_limit_ip_source_unknown_falls_back_to_remote_addr(): void {
		$this->with_option( array( 'rate_limit_ip_source' => 'bogus' ) );
		$this->assertSame( 'remote_addr', IpDiagnosticsSettingsReader::rate_limit_ip_source() );
	}
}
