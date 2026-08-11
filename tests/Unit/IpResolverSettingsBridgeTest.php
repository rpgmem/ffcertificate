<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\IpResolverSettingsBridge;

/**
 * #901 phase 2 — the producer that feeds the dormant ClientIpResolver filters
 * from stored config. Pins each filter's output per proxy mode / strategy.
 *
 * @covers \FreeFormCertificate\Settings\IpResolverSettingsBridge
 */
class IpResolverSettingsBridgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Settings\IpResolverSettingsBridge' );
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

	public function test_init_registers_five_filters(): void {
		$hooks = array();
		Functions\when( 'add_filter' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);
		IpResolverSettingsBridge::init();
		$this->assertContains( 'ffc_ip_resolver_mode', $hooks );
		$this->assertContains( 'ffc_trusted_proxies', $hooks );
		$this->assertContains( 'ffc_cloudflare_ip_ranges', $hooks );
		$this->assertContains( 'ffc_ip_shadow_logging', $hooks );
	}

	public function test_filter_mode_returns_stored_strategy(): void {
		$this->with_option( array( 'strategy' => 'secure' ) );
		$this->assertSame( 'secure', IpResolverSettingsBridge::filter_mode( 'legacy' ) );
	}

	public function test_filter_mode_defaults_legacy(): void {
		$this->with_option( array() );
		$this->assertSame( 'legacy', IpResolverSettingsBridge::filter_mode( 'secure' ) );
	}

	public function test_custom_mode_appends_custom_proxies(): void {
		$this->with_option(
			array(
				'trusted_proxy_mode' => 'custom',
				'custom_proxies'     => "192.0.2.0/24\n198.51.100.0/24",
			)
		);
		$out = IpResolverSettingsBridge::filter_trusted_proxies( array( '10.0.0.0/8' ) );
		$this->assertSame( array( '10.0.0.0/8', '192.0.2.0/24', '198.51.100.0/24' ), $out );
	}

	public function test_non_custom_mode_leaves_trusted_proxies_untouched(): void {
		$this->with_option( array( 'trusted_proxy_mode' => 'auto', 'custom_proxies' => '192.0.2.0/24' ) );
		$this->assertSame( array( '10.0.0.0/8' ), IpResolverSettingsBridge::filter_trusted_proxies( array( '10.0.0.0/8' ) ) );
	}

	public function test_direct_mode_clears_cloudflare_ranges(): void {
		$this->with_option( array( 'trusted_proxy_mode' => 'direct' ) );
		$this->assertSame( array(), IpResolverSettingsBridge::filter_cloudflare_ranges( array( '104.16.0.0/13' ) ) );
	}

	public function test_non_direct_mode_passes_cloudflare_ranges_through(): void {
		$this->with_option( array( 'trusted_proxy_mode' => 'cloudflare' ) );
		$this->assertSame(
			array( '104.16.0.0/13' ),
			IpResolverSettingsBridge::filter_cloudflare_ranges( array( '104.16.0.0/13' ) )
		);
	}

	public function test_shadow_logging_opt_in_forces_true(): void {
		$this->with_option( array( 'shadow_logging' => 1 ) );
		$this->assertTrue( IpResolverSettingsBridge::filter_shadow_logging( false ) );
	}

	public function test_shadow_logging_off_preserves_incoming(): void {
		$this->with_option( array() );
		$this->assertTrue( IpResolverSettingsBridge::filter_shadow_logging( true ) );
		$this->assertFalse( IpResolverSettingsBridge::filter_shadow_logging( false ) );
	}
}
