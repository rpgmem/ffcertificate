<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\EmailTemplateOptions;

/**
 * @covers \FreeFormCertificate\Core\EmailTemplateOptions
 */
class EmailTemplateOptionsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\class_exists( '\FreeFormCertificate\Core\EmailTemplateOptions' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->alias(
			static function ( $color ) {
				$color = (string) $color;
				return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ? $color : null;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// defaults() / all() / get()
	// ==================================================================

	public function test_defaults_carry_the_locked_palette(): void {
		$d = EmailTemplateOptions::defaults();

		$this->assertSame( '#2271b1', $d['header_bg'] );
		$this->assertSame( '#ffffff', $d['header_text_color'] );
		$this->assertSame( 'center', $d['header_alignment'] );
		$this->assertSame( 600, $d['body_max_width'] );
		$this->assertSame( '#f5f5f5', $d['footer_bg'] );
		$this->assertSame( 'Sent by {{site_title}}', $d['footer_text'] );
		$this->assertSame( '#f0f0f1', $d['wrapper_bg'] );
		// No footer_powered_by key (dropped by request).
		$this->assertArrayNotHasKey( 'footer_powered_by', $d );
	}

	public function test_all_merges_stored_over_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array( 'header_bg' => '#000000', 'body_max_width' => 720 ) );

		$all = EmailTemplateOptions::all();

		$this->assertSame( '#000000', $all['header_bg'] );
		$this->assertSame( 720, $all['body_max_width'] );
		// Untouched keys still come from defaults.
		$this->assertSame( '#ffffff', $all['header_text_color'] );
	}

	public function test_all_ignores_non_array_stored_value(): void {
		Functions\when( 'get_option' )->justReturn( 'corrupt' );

		$this->assertSame( EmailTemplateOptions::defaults(), EmailTemplateOptions::all() );
	}

	public function test_get_returns_single_value_and_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '#2271b1', EmailTemplateOptions::get( 'header_bg' ) );
		$this->assertSame( 'fallback', EmailTemplateOptions::get( 'nope', 'fallback' ) );
	}

	public function test_font_stack_maps_key_and_falls_back(): void {
		$this->assertStringContainsString( 'Georgia', EmailTemplateOptions::font_stack( 'serif' ) );
		$this->assertStringContainsString( '-apple-system', EmailTemplateOptions::font_stack( 'unknown' ) );
	}

	// ==================================================================
	// sanitize()
	// ==================================================================

	public function test_sanitize_keeps_valid_colors_and_rejects_bad_ones(): void {
		$out = EmailTemplateOptions::sanitize(
			array(
				'header_bg'         => '#abcdef',
				'body_text_color'   => 'not-a-color',
			)
		);

		$this->assertSame( '#abcdef', $out['header_bg'] );
		// Invalid → falls back to default, never empty.
		$this->assertSame( '#333333', $out['body_text_color'] );
	}

	public function test_sanitize_coerces_ints_and_enums(): void {
		$out = EmailTemplateOptions::sanitize(
			array(
				'body_font_size'   => '18',
				'header_padding'   => '-4',
				'header_alignment' => 'right',
				'body_font_family' => 'bogus',
			)
		);

		$this->assertSame( 18, $out['body_font_size'] );
		$this->assertSame( 4, $out['header_padding'] );
		$this->assertSame( 'right', $out['header_alignment'] );
		// Invalid enum → default.
		$this->assertSame( 'system', $out['body_font_family'] );
	}

	public function test_sanitize_is_self_contained_every_key_present(): void {
		$out = EmailTemplateOptions::sanitize( array() );

		$this->assertSame( array_keys( EmailTemplateOptions::defaults() ), array_keys( $out ) );
	}

	// ==================================================================
	// update() / reset()
	// ==================================================================

	public function test_update_persists_sanitized_values(): void {
		$captured = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$captured ) {
				$captured = array( $name, $value );
				return true;
			}
		);

		$clean = EmailTemplateOptions::update( array( 'header_bg' => '#123456' ) );

		$this->assertSame( 'ffc_email_template', $captured[0] );
		$this->assertSame( '#123456', $captured[1]['header_bg'] );
		$this->assertSame( '#123456', $clean['header_bg'] );
	}

	public function test_reset_deletes_the_row(): void {
		$deleted = null;
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$deleted ) {
				$deleted = $name;
				return true;
			}
		);

		EmailTemplateOptions::reset();

		$this->assertSame( 'ffc_email_template', $deleted );
	}

	// ==================================================================
	// footer_tokens()
	// ==================================================================

	public function test_footer_tokens_resolves_context_and_site_values(): void {
		Functions\when( 'get_option' )->alias( static fn( $k, $d = false ) => 'admin_email' === $k ? 'admin@x.com' : $d );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->justReturn( 'https://my.site' );
		Functions\when( 'wp_date' )->justReturn( '2026' );
		\Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$tokens = EmailTemplateOptions::footer_tokens( array( 'recipient' => 'user@x.com' ) );

		$this->assertSame( 'My Site', $tokens['{{site_title}}'] );
		$this->assertSame( 'https://my.site', $tokens['{{home_url}}'] );
		$this->assertSame( 'admin@x.com', $tokens['{{admin_email}}'] );
		$this->assertSame( 'user@x.com', $tokens['{{recipient}}'] );
		$this->assertSame( '2026', $tokens['{{year}}'] );
	}
}
