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

	public function test_a_partially_saved_group_keeps_every_default_leaf(): void {
		// The runtime merged shallowly, so this stored group dropped
		// max_per_hour and refused every submission (#2339).
		Functions\when( '__' )->returnArg();
		$this->stub_option( array( 'ip' => array( 'enabled' => true ) ) );

		$settings = RateLimitSettingsReader::effective();

		$this->assertSame( 5, $settings['ip']['max_per_hour'] );
		$this->assertSame( 20, $settings['ip']['max_per_day'] );
		$this->assertSame( 3, $settings['email']['max_per_day'], 'A group never saved reads its defaults.' );
	}

	public function test_a_stored_list_replaces_the_default_list_whole(): void {
		Functions\when( '__' )->returnArg();
		$this->stub_option(
			array(
				'device'    => array( 'signals_enabled' => array( 'cookie', 'ua' ) ),
				'whitelist' => array( 'ips' => array( '10.0.0.1' ) ),
			)
		);

		$settings = RateLimitSettingsReader::effective();

		$this->assertSame( array( 'cookie', 'ua' ), $settings['device']['signals_enabled'], 'Merging by index would put back the signals that were turned off.' );
		$this->assertSame( array( '10.0.0.1' ), $settings['whitelist']['ips'] );
		$this->assertSame( 90, $settings['device']['retention_days'] );
	}

	public function test_the_runtime_and_the_tab_read_the_same_settings(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, (array) $args ) );
		$this->stub_option( array( 'ip' => array( 'max_per_day' => 9 ) ) );
		$cache = new \ReflectionProperty( \FreeFormCertificate\Security\RateLimitChecker::class, 'settings_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );

		$runtime = \FreeFormCertificate\Security\RateLimitChecker::get_settings();
		$cache->setValue( null, null );

		$this->assertSame( RateLimitSettingsReader::effective(), $runtime );
		$this->assertSame( 9, $runtime['ip']['max_per_day'] );
		$this->assertSame( 5, $runtime['ip']['max_per_hour'] );
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

	public function test_an_unidentified_address_is_let_through_by_default(): void {
		$this->stub_option( array() );

		$this->assertFalse( RateLimitSettingsReader::captcha_counts_unresolved() );
		$this->assertFalse( RateLimitSettingsReader::device_check_counts_unresolved() );
	}

	public function test_each_throttle_reads_its_own_unresolved_policy(): void {
		$this->stub_option( array( 'ip' => array( 'captcha_unresolved' => 'share' ) ) );

		$this->assertTrue( RateLimitSettingsReader::captcha_counts_unresolved() );
		$this->assertFalse( RateLimitSettingsReader::device_check_counts_unresolved() );

		$this->stub_option( array( 'ip' => array( 'device_check_unresolved' => 'share' ) ) );

		$this->assertFalse( RateLimitSettingsReader::captcha_counts_unresolved() );
		$this->assertTrue( RateLimitSettingsReader::device_check_counts_unresolved() );
	}

	public function test_an_unknown_policy_falls_back_to_letting_through(): void {
		$this->assertSame( 'allow', RateLimitSettingsReader::clamp_unresolved_policy( 'block' ) );
		$this->assertSame( 'allow', RateLimitSettingsReader::clamp_unresolved_policy( null ) );
		$this->assertSame( 'allow', RateLimitSettingsReader::clamp_unresolved_policy( array( 'share' ) ) );
		$this->assertSame( 'share', RateLimitSettingsReader::clamp_unresolved_policy( 'share' ) );
	}
}
