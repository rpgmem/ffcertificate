<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\FormBackground;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormBackground: the single reader of a form's background URL.
 *
 * The editor saves the URL in `_ffc_form_config['bg_image']`; the legacy
 * `_ffc_form_bg` meta is read only when the config has none.
 *
 * @covers \FreeFormCertificate\Core\FormBackground
 */
class FormBackgroundTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stubs get_post_meta() from a map of meta key => value.
	 *
	 * @param array<string, mixed> $meta Meta values by key.
	 */
	private function meta( array $meta ): void {
		Functions\when( 'get_post_meta' )->alias(
			static fn ( int $id, string $key, bool $single ) => $meta[ $key ] ?? ''
		);
	}

	public function test_returns_the_editor_value_from_the_config(): void {
		$this->meta(
			array(
				'_ffc_form_config' => array( 'bg_image' => 'https://example.test/bg.png' ),
			)
		);

		$this->assertSame( 'https://example.test/bg.png', FormBackground::url( 7 ) );
	}

	public function test_editor_value_wins_over_the_legacy_meta(): void {
		$this->meta(
			array(
				'_ffc_form_config' => array( 'bg_image' => 'https://example.test/new.png' ),
				'_ffc_form_bg'     => 'https://example.test/old.png',
			)
		);

		$this->assertSame( 'https://example.test/new.png', FormBackground::url( 7 ) );
	}

	public function test_falls_back_to_the_legacy_meta_when_the_config_has_no_background(): void {
		$this->meta(
			array(
				'_ffc_form_config' => array( 'bg_image' => '' ),
				'_ffc_form_bg'     => 'https://example.test/legacy.png',
			)
		);

		$this->assertSame( 'https://example.test/legacy.png', FormBackground::url( 7 ) );
	}

	public function test_falls_back_to_the_legacy_meta_when_the_config_is_not_an_array(): void {
		// A config written as a string or left unset must not fatal or be read.
		$this->meta(
			array(
				'_ffc_form_config' => 'corrupt',
				'_ffc_form_bg'     => 'https://example.test/legacy.png',
			)
		);

		$this->assertSame( 'https://example.test/legacy.png', FormBackground::url( 7 ) );
	}

	public function test_returns_empty_string_when_neither_location_has_a_background(): void {
		$this->meta( array() );

		$this->assertSame( '', FormBackground::url( 7 ) );
	}

	public function test_non_scalar_editor_value_is_treated_as_absent(): void {
		$this->meta(
			array(
				'_ffc_form_config' => array( 'bg_image' => array( 'nested' ) ),
				'_ffc_form_bg'     => 'https://example.test/legacy.png',
			)
		);

		$this->assertSame( 'https://example.test/legacy.png', FormBackground::url( 7 ) );
	}
	/**
	 * Stubs sanitize_hex_color() with WordPress's rule: a `#` and three or six
	 * hex digits, anything else null.
	 */
	private function hex_sanitizer(): void {
		Functions\when( 'sanitize_hex_color' )->alias(
			static function ( string $color ): ?string {
				return 1 === preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ? $color : null;
			}
		);
	}

	public function test_normalize_color_keeps_a_valid_hex_value(): void {
		$this->hex_sanitizer();

		$this->assertSame( '#f3e9d2', FormBackground::normalize_color( '#f3e9d2' ) );
	}

	public function test_normalize_color_falls_back_to_white_when_empty(): void {
		// An unset colour field posts the empty string; the default must hold.
		$this->hex_sanitizer();

		$this->assertSame( '#ffffff', FormBackground::normalize_color( '' ) );
	}

	public function test_normalize_color_falls_back_to_white_when_invalid(): void {
		$this->hex_sanitizer();

		$this->assertSame( '#ffffff', FormBackground::normalize_color( 'javascript:alert(1)' ) );
		$this->assertSame( '#ffffff', FormBackground::normalize_color( 'red' ) );
	}

	public function test_color_reads_the_editor_value_from_the_config(): void {
		$this->hex_sanitizer();
		$this->meta(
			array(
				'_ffc_form_config' => array( 'bg_color' => '#1d3557' ),
			)
		);

		$this->assertSame( '#1d3557', FormBackground::color( 7 ) );
	}

	public function test_color_is_white_when_the_form_has_no_config(): void {
		$this->hex_sanitizer();
		$this->meta( array() );

		$this->assertSame( '#ffffff', FormBackground::color( 7 ) );
	}

	public function test_color_is_white_when_the_config_is_not_an_array(): void {
		$this->hex_sanitizer();
		$this->meta( array( '_ffc_form_config' => 'corrupt' ) );

		$this->assertSame( '#ffffff', FormBackground::color( 7 ) );
	}
}
