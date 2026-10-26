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

		// Grouped by subject, the PDF last (#2062).
		preg_match_all( '/data-ffc-check="([a-z]+)"/', $html, $rows );
		$this->assertSame( array( 'secure', 'connection', 'server', 'browser', 'cookies', 'storage', 'captcha', 'device', 'clock', 'locale', 'location', 'screen', 'theme', 'pdf' ), $rows[1] );
		$this->assertSame( 14, substr_count( $html, 'class="ffc-device-check-result"' ) );
		$this->assertStringContainsString( '<noscript><div class="ffc-notice ffc-notice-warning"', $html );
		// The notice a stopped script cannot show sits in the page, for the
		// stylesheet to reveal, and the verdict has its box (#2062).
		$this->assertMatchesRegularExpression( '#<div class="ffc-device-check-stalled"><div class="ffc-notice ffc-notice-warning" role="alert" data-ffc-notice="device-check-stalled">#', $html );
		$this->assertStringContainsString( '<div class="ffc-device-check-verdict" aria-live="polite"></div>', $html );
		$this->assertStringNotContainsString( 'style=', $html );
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

	public function test_render_groups_the_rows_in_closed_sections(): void {
		$html = DeviceCheck::render();

		// Six native <details>, none open: the script opens what needs it (#2065).
		preg_match_all( '/<details class="ffc-device-check-group" data-ffc-group="([a-z]+)">/', $html, $groups );
		$this->assertSame( array( 'network', 'browser', 'protections', 'device', 'display', 'pdf' ), $groups[1] );
		$this->assertStringNotContainsString( '<details class="ffc-device-check-group" data-ffc-group="network" open', $html );
		$this->assertSame( 6, substr_count( $html, 'class="ffc-device-check-chip is-info"' ) );

		// Each section holds its own rows, in order.
		$sections = array();
		foreach ( preg_split( '/<details /', $html ) as $chunk ) {
			if ( preg_match( '/^class="ffc-device-check-group" data-ffc-group="([a-z]+)"/', $chunk, $m ) ) {
				preg_match_all( '/data-ffc-check="([a-z]+)"/', $chunk, $rows );
				$sections[ $m[1] ] = $rows[1];
			}
		}
		$this->assertSame(
			array(
				'network'     => array( 'secure', 'connection', 'server' ),
				'browser'     => array( 'browser', 'cookies', 'storage' ),
				'protections' => array( 'captcha', 'device' ),
				'device'      => array( 'clock', 'locale', 'location' ),
				'display'     => array( 'screen', 'theme' ),
				'pdf'         => array( 'pdf' ),
			),
			$sections
		);

		// The location button sits in the section of its row.
		$device = substr( $html, (int) strpos( $html, 'data-ffc-group="device"' ) );
		$device = substr( $device, 0, (int) strpos( $device, '</details>' ) );
		$this->assertStringContainsString( 'ffc-device-check-locate', $device );
		$this->assertSame( 1, substr_count( $html, 'ffc-device-check-locate' ) );
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

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'admin_url' )->alias( static fn( $path ) => 'https://example.com/wp-admin/' . $path );
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
		$this->assertSame( 'math', $data['ffc-device-check'][1]['captcha'] );
		// The one server request the test makes (#2064).
		$this->assertSame(
			array(
				'url'    => 'https://example.com/wp-admin/admin-ajax.php',
				'action' => DeviceCheck::AJAX_ACTION,
			),
			$data['ffc-device-check'][1]['ping']
		);
		// The location row is graded by the forms' precision settings (#2074).
		$this->assertSame(
			array(
				'reference' => 50,
				'weakShare' => 0.5,
				'tolerance' => 1.5,
			),
			$data['ffc-device-check'][1]['geo']
		);

		Functions\when( 'get_option' )->alias(
			static fn( $name ) => 'ffc_geolocation_settings' === $name
				? array(
					'gps_weak_share'         => '0.3',
					'gps_accuracy_tolerance' => '2',
				)
				: array()
		);
		DeviceCheck::enqueue_assets();
		$this->assertSame( 0.3, $data['ffc-device-check'][1]['geo']['weakShare'] );
		$this->assertSame( 2.0, $data['ffc-device-check'][1]['geo']['tolerance'] );
	}

	public function test_enqueue_assets_loads_the_altcha_bundle_first_where_the_site_uses_it(): void {
		$scripts = array();
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src, $deps ) use ( &$scripts ) {
				$scripts[ $handle ] = $deps;
			}
		);
		Functions\when( 'wp_script_is' )->alias( static fn( $handle, $list = 'enqueued' ) => 'ffc-altcha' === $handle && 'registered' === $list );

		foreach ( array( 'altcha', 'both' ) as $mode ) {
			Functions\when( 'get_option' )->justReturn( array( 'captcha_provider' => $mode ) );
			DeviceCheck::enqueue_assets();
			$this->assertSame( array( 'ffc-core', 'ffc-preflight', 'ffc-altcha' ), $scripts['ffc-device-check'], $mode );
		}
	}
}
