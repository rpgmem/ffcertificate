<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\RateLimitSupport;
use FreeFormCertificate\Security\DeviceLimiter;

/**
 * #563 Sprint 4 (A4, PR 4b) — unit tests for DeviceLimiter's non-SQL branches.
 *
 * The two-tier SQL matching path is covered end-to-end by RateLimiterTest; here
 * we pin the early-return guards (disabled / empty / whitelist), the per-form
 * effective-settings resolver, and the record_signals no-op — exercised in
 * isolation with a fixture-settings RateLimitSupport injected.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DeviceLimiterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function limiter( array $device ): DeviceLimiter {
		return new DeviceLimiter( new RateLimitSupport( array( 'device' => $device ) ) );
	}

	public function test_allows_when_device_disabled(): void {
		$res = $this->limiter( array( 'enabled' => false ) )->check( 7, array( 'cookie' => 'abc' ) );
		$this->assertTrue( $res['allowed'] );
	}

	public function test_allows_when_no_enabled_signals_present(): void {
		// signals_enabled empty -> every incoming signal filtered out -> allowed.
		$device = array( 'enabled' => true, 'signals_enabled' => array(), 'bypass_whitelist_signals' => array() );
		$res    = $this->limiter( $device )->check( 7, array( 'cookie' => 'abc', 'ua' => 'def' ) );
		$this->assertTrue( $res['allowed'] );
	}

	public function test_allows_when_cookie_is_whitelisted(): void {
		$device = array(
			'enabled'                  => true,
			'signals_enabled'          => array( 'cookie' ),
			'bypass_whitelist_signals' => array( 'whitelisted-cookie' ),
		);
		$res = $this->limiter( $device )->check( 7, array( 'cookie' => 'whitelisted-cookie' ) );
		$this->assertTrue( $res['allowed'] );
	}

	public function test_effective_settings_use_globals_without_overrides(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' ); // no per-form overrides.
		$device = array(
			'max_per_form'     => 4,
			'match_threshold'  => 7,
			'match_strong_min' => 3,
			'message'          => 'Global msg',
		);
		$eff = $this->limiter( $device )->get_effective_settings( 7 );
		$this->assertSame( 4, $eff['max'] );
		$this->assertSame( 7, $eff['threshold'] );
		$this->assertSame( 3, $eff['strong_min'] );
		$this->assertSame( 'Global msg', $eff['message'] );
	}

	public function test_effective_settings_apply_per_form_overrides(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				$map = array(
					'_ffc_device_limit_max'       => '9',
					'_ffc_device_match_threshold' => '10',
					'_ffc_device_strong_min'      => '4',
					'_ffc_device_limit_message'   => 'Per-form msg',
				);
				return $map[ $key ] ?? '';
			}
		);
		$device = array( 'max_per_form' => 4, 'match_threshold' => 7, 'match_strong_min' => 2, 'message' => 'Global' );
		$eff    = $this->limiter( $device )->get_effective_settings( 7 );
		$this->assertSame( 9, $eff['max'] );
		$this->assertSame( 10, $eff['threshold'] );
		$this->assertSame( 4, $eff['strong_min'] );
		$this->assertSame( 'Per-form msg', $eff['message'] );
	}

	public function test_record_signals_is_noop_when_disabled(): void {
		// No global $wpdb set: a no-op return proves it never reaches the insert.
		$this->limiter( array( 'enabled' => false ) )->record_signals( 5, 7, array( 'cookie' => 'abc' ) );
		$this->assertTrue( true );
	}
}
