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

	/**
	 * Record every add_filter() call.
	 *
	 * @return \ArrayObject<int, array{0: string, 1: mixed}>
	 */
	private function capture_filters(): \ArrayObject {
		$added = new \ArrayObject();
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback ) use ( $added ) {
				$added[] = array( $hook, $callback );
			}
		);
		return $added;
	}

	public function test_dev_cache_busting_registers_nothing_in_production(): void {
		$added = $this->capture_filters();

		AssetHelper::register_dev_cache_busting( false );
		// SCRIPT_DEBUG is not defined here, so the default reads production too.
		AssetHelper::register_dev_cache_busting();

		$this->assertSame( array(), $added->getArrayCopy() );
	}

	public function test_dev_cache_busting_filters_scripts_and_styles_on_a_debug_install(): void {
		$added = $this->capture_filters();

		AssetHelper::register_dev_cache_busting( true );

		$callback = array( AssetHelper::class, 'version_by_file_time' );
		$this->assertSame( array( array( 'script_loader_src', $callback ), array( 'style_loader_src', $callback ) ), $added->getArrayCopy() );
	}

	public function test_a_plugin_asset_is_versioned_by_its_file_time(): void {
		Functions\when( 'add_query_arg' )->alias(
			static fn( $key, $value, $url ) => preg_replace( '/([?&])' . $key . '=[^&#]*/', '$1' . $key . '=' . $value, $url )
		);
		$file = 'assets/js/ffc-qr-generator.js';
		$time = filemtime( FFC_PLUGIN_DIR . $file );

		$this->assertSame(
			FFC_PLUGIN_URL . $file . '?ver=' . FFC_VERSION . '.' . $time,
			AssetHelper::version_by_file_time( FFC_PLUGIN_URL . $file . '?ver=' . FFC_VERSION )
		);
	}

	public function test_anything_else_passes_unchanged(): void {
		Functions\expect( 'add_query_arg' )->never();
		$core    = 'https://example.com/wp-includes/js/jquery/jquery.min.js?ver=3.7.1';
		$missing = FFC_PLUGIN_URL . 'assets/js/no-such-file.js?ver=1';
		$escape  = FFC_PLUGIN_URL . '../../../wp-config.php?ver=1';

		$this->assertSame( $core, AssetHelper::version_by_file_time( $core ) );
		$this->assertSame( $missing, AssetHelper::version_by_file_time( $missing ) );
		$this->assertSame( $escape, AssetHelper::version_by_file_time( $escape ) );
		$this->assertFalse( AssetHelper::version_by_file_time( false ) );
	}

	public function test_enqueue_dark_mode_noop_when_off(): void {
		Functions\when( 'get_option' )->justReturn( array( 'dark_mode' => 'off' ) );

		// #1030: the comment reasoned that an unstubbed call "throws", which is
		// the environment's behaviour rather than the test's assertion. Say it.
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$enqueued ) {
				$enqueued[] = $handle;
			}
		);

		AssetHelper::enqueue_dark_mode();

		$this->assertSame( array(), $enqueued );
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

	/**
	 * `off` + `$always` still loads the script, and says so.
	 *
	 * The screen that CHANGES the setting needs the script in the `off` state
	 * too: it is the half that repaints `<html>` when the select auto-saves.
	 * Without it the option was written and the page kept its old theme until
	 * the next load, which reads as a save that did not work.
	 */
	public function test_enqueue_dark_mode_loads_in_the_off_state_when_always(): void {
		Functions\when( 'get_option' )->justReturn( array( 'dark_mode' => 'off' ) );
		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with( 'ffc-dark-mode', \Mockery::type( 'string' ), array(), FFC_VERSION, false );
		Functions\expect( 'wp_localize_script' )
			->once()
			->with( 'ffc-dark-mode', 'ffcDarkMode', array( 'mode' => 'off' ) );

		AssetHelper::enqueue_dark_mode( true );

		$this->addToAssertionCount( 1 );
	}
}
