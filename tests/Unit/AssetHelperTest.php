<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\AssetHelper;

/**
 * #563 Sprint 5 phase 2 (B1) — unit tests for the AssetHelper extracted from
 * Core\Utils (the minified-suffix resolver + the shared dark-mode enqueue).
 */
class AssetHelperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
			define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
		}
		if ( ! defined( 'FFC_VERSION' ) ) {
			define( 'FFC_VERSION', '6.11.3' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_asset_suffix_returns_min_in_production(): void {
		// SCRIPT_DEBUG not defined → production → '.min'.
		$this->assertSame( '.min', AssetHelper::asset_suffix() );
	}

	public function test_enqueue_dark_mode_noop_when_off(): void {
		Functions\when( 'get_option' )->justReturn( array( 'dark_mode' => 'off' ) );
		// wp_enqueue_script must never fire on the off branch; leaving it
		// unstubbed means any call throws an undefined-function error.
		AssetHelper::enqueue_dark_mode();
		$this->assertTrue( true );
	}

	public function test_enqueue_dark_mode_enqueues_when_enabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'dark_mode' => 'dark' ) );
		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with( 'ffc-dark-mode', \Mockery::type( 'string' ), array(), FFC_VERSION, false );
		Functions\expect( 'wp_localize_script' )
			->once()
			->with( 'ffc-dark-mode', 'ffcDarkMode', array( 'mode' => 'dark' ) );

		AssetHelper::enqueue_dark_mode();
		// The Brain Monkey expectations above are the assertions; tell PHPUnit
		// so the test isn't flagged risky for "no assertions".
		$this->addToAssertionCount( 1 );
	}
}
