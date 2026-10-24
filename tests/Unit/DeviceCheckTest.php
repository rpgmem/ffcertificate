<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\DeviceCheck;
use FreeFormCertificate\Tests\Support\ButtonMarkup;

/**
 * The "Test my device" mode of the verification page (#2040).
 *
 * @covers \FreeFormCertificate\Frontend\DeviceCheck
 */
class DeviceCheckTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $_GET[ DeviceCheck::QUERY_VAR ] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_requested_only_when_the_query_argument_is_one(): void {
		$this->assertFalse( DeviceCheck::requested() );

		$_GET[ DeviceCheck::QUERY_VAR ] = '0';
		$this->assertFalse( DeviceCheck::requested() );

		$_GET[ DeviceCheck::QUERY_VAR ] = '1';
		$this->assertTrue( DeviceCheck::requested() );
	}

	public function test_url_drops_a_magic_token_and_adds_the_mode(): void {
		$removed = array();
		Functions\when( 'remove_query_arg' )->alias(
			static function ( $key, $url ) use ( &$removed ) {
				$removed[] = $key;
				return 'https://example.com/valid/';
			}
		);
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );

		$this->assertSame( 'https://example.com/valid/?ffc_device_check=1', DeviceCheck::url( 'https://example.com/valid/?token=abc' ) );
		$this->assertSame( array( 'token' ), $removed );
	}

	public function test_render_lists_every_check_and_never_a_coordinate_field(): void {
		$html = DeviceCheck::render( 'https://example.com/valid/' );

		foreach ( array( 'secure', 'browser', 'cookies', 'storage', 'captcha', 'device', 'clock', 'locale', 'location', 'accuracy', 'pdf', 'screen', 'connection', 'theme' ) as $key ) {
			$this->assertStringContainsString( 'data-ffc-check="' . $key . '"', $html, "Row {$key} is missing." );
		}
		$this->assertSame( 14, substr_count( $html, 'class="ffc-device-check-result"' ) );
		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertStringContainsString( 'href="https://example.com/valid/"', $html );
		$this->assertStringNotContainsString( 'latitude', $html );

		$locate = ButtonMarkup::classes_by_hook( $html, 'ffc-device-check-locate' );
		$this->assertCount( 1, $locate );
		$this->assertContains( 'ffc-btn-primary', $locate[0] );
		$this->assertContains( 'ffc-icon-map-pin', $locate[0] );

		$copy = ButtonMarkup::classes_by_hook( $html, 'ffc-device-check-copy' );
		$this->assertCount( 1, $copy );
		$this->assertContains( 'ffc-btn-neutral', $copy[0] );
		$this->assertContains( 'ffc-icon-copy', $copy[0] );
	}

	public function test_render_without_a_back_address_prints_no_back_link(): void {
		$this->assertStringNotContainsString( 'ffc-device-check-back', DeviceCheck::render() );
	}

	public function test_strings_carry_every_key_the_script_reads(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ffc-device-check.js' );
		preg_match_all( "/text\\('([A-Za-z]+)'/", $script, $m );
		// The fixes are named by key where a row fails (#2054).
		preg_match_all( "/'(help[A-Z][A-Za-z]+)'/", $script, $h );
		// The connection medium and speed are looked up in maps of
		// [ key, fallback ] pairs (#2060).
		preg_match_all( "/\\['([a-z]+[A-Z][A-Za-z]*)', '/", $script, $p );
		$read = array_values( array_unique( array_merge( $m[1], $h[1], $p[1] ) ) );

		$this->assertNotEmpty( $read );
		sort( $read );
		$keys = array_keys( DeviceCheck::strings() );
		sort( $keys );
		$this->assertSame( $read, $keys );
	}

	public function test_enqueue_assets_declares_the_palette_and_the_queue(): void {
		$styles  = array();
		$scripts = array();
		$data    = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle, $src, $deps ) use ( &$styles ) {
				$styles[ $handle ] = $deps;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src, $deps ) use ( &$scripts ) {
				$scripts[ $handle ] = $deps;
			}
		);
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $value ) use ( &$data ) {
				$data[ $handle ] = array( $name, $value );
			}
		);

		Functions\when( 'wp_script_is' )->justReturn( false );
		DeviceCheck::enqueue_assets();

		$this->assertSame( array( 'ffc-common' ), $styles['ffc-device-check'] );
		$this->assertSame( array( 'ffc-core', 'ffc-preflight' ), $scripts['ffc-device-check'] );

		// Where the device limit loaded the collector, the test reads it, so
		// it loads after it (#2054).
		Functions\when( 'wp_script_is' )->alias( static fn( $handle ) => 'ffc-device-signals' === $handle );
		DeviceCheck::enqueue_assets();
		$this->assertSame( array( 'ffc-core', 'ffc-preflight', 'ffc-device-signals' ), $scripts['ffc-device-check'] );
		$this->assertSame( 'ffcDeviceCheck', $data['ffc-device-check'][0] );
		$this->assertSame( DeviceCheck::strings(), $data['ffc-device-check'][1]['strings'] );
	}
}
