<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Generators\FormLayoutPreview;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormLayoutPreview: the page the form editor and the public CSV
 * preview capture as an image, drawn the way the PDF draws it (#1958 follow-up).
 *
 * @covers \FreeFormCertificate\Generators\FormLayoutPreview
 */
class FormLayoutPreviewTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_wrap_puts_colour_then_image_behind_the_body(): void {
		$markup = FormLayoutPreview::wrap( '<p>Body</p>', 'https://example.test/bg.png', '#f3e9d2' );

		$this->assertStringContainsString( 'background-color:#f3e9d2;', $markup );
		$this->assertStringContainsString( '<img src="https://example.test/bg.png"', $markup );
		$this->assertStringContainsString( 'object-fit:cover', $markup );
		// The body is painted last, so it sits above the colour and the image.
		$this->assertLessThan( strpos( $markup, '<p>Body</p>' ), strpos( $markup, '<img' ) );
	}

	public function test_wrap_uses_the_pdf_generators_layers(): void {
		$markup = FormLayoutPreview::wrap( '<p>Body</p>', '', '#f3e9d2' );

		// The generator's own wrapper and content layer, so the preview takes
		// the text styles of ffc-pdf-core.css, not the admin document's (#2598).
		$this->assertStringStartsWith( '<div class="ffc-pdf-wrapper" style="', $markup );
		$this->assertStringContainsString( '<div class="ffc-pdf-content"><p>Body</p></div></div>', $markup );
		// The page colour stays on the wrapper, which the sheet no longer
		// overrides with an important white.
		$this->assertMatchesRegularExpression( '/class="ffc-pdf-wrapper" style="[^"]*background-color:#f3e9d2;/', $markup );
	}

	public function test_the_wrapper_lets_the_page_colour_and_inline_text_styles_through(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-pdf-core.css' );
		preg_match( '/\.ffc-pdf-wrapper\s*\{([^}]*)\}/', $css, $wrapper );
		$this->assertNotEmpty( $wrapper, 'the wrapper rule is read' );

		// An important white printed every page colour white (#2598).
		$this->assertMatchesRegularExpression( '/background-color:\s*#ffffff;/', $wrapper[1] );
		$this->assertDoesNotMatchRegularExpression( '/background-color:[^;]*!important/', $wrapper[1] );
		// The inherited text properties are the page's own, so a theme's
		// weight or spacing cannot reach the certificate.
		foreach ( array( 'font-weight: 400', 'letter-spacing: normal', 'text-transform: none' ) as $declaration ) {
			$this->assertStringContainsString( $declaration . ';', $wrapper[1] );
		}
		// A host rule for the tag (wp-admin's p) does not set the line height.
		$this->assertMatchesRegularExpression( '/\.ffc-pdf-wrapper p,[^{]*\{[^}]*line-height:\s*inherit;/', $css );
	}

	public function test_wrap_escapes_the_image_url(): void {
		$escaped = array();
		Functions\when( 'esc_url' )->alias(
			static function ( $url ) use ( &$escaped ) {
				$escaped[] = $url;
				return 'escaped:' . $url;
			}
		);

		$markup = FormLayoutPreview::wrap( '<div></div>', 'https://example.test/a.png', '' );

		$this->assertSame( array( 'https://example.test/a.png' ), $escaped );
		$this->assertStringContainsString( 'src="escaped:https://example.test/a.png"', $markup );
	}

	public function test_wrap_without_image_or_colour_draws_only_the_body(): void {
		$markup = FormLayoutPreview::wrap( '<p>Body</p>', '', '' );

		$this->assertStringNotContainsString( 'background-color', $markup );
		$this->assertStringNotContainsString( '<img', $markup );
		$this->assertStringContainsString( '<p>Body</p>', $markup );
	}

	public function test_page_size_is_a4_landscape_by_default_and_swaps_for_portrait(): void {
		$this->assertSame( array( 'width' => 1123, 'height' => 794 ), FormLayoutPreview::page_size() );
		$this->assertSame( array( 'width' => 794, 'height' => 1123 ), FormLayoutPreview::page_size( true ) );

		$markup = FormLayoutPreview::wrap( '', '', '', true );
		$this->assertStringContainsString( 'width:794px;height:1123px;', $markup );
	}

	public function test_with_field_labels_fills_only_the_missing_names(): void {
		$samples = FormLayoutPreview::with_field_labels(
			array( 'name' => 'Maria', 'empty' => '' ),
			array(
				array( 'name' => 'name', 'label' => 'Full name' ),
				array( 'name' => 'empty', 'label' => 'Empty field' ),
				array( 'name' => 'course', 'label' => 'Course' ),
			)
		);

		$this->assertSame( 'Maria', $samples['name'], 'A sample that already has a value keeps it.' );
		$this->assertSame( 'Empty field', $samples['empty'], 'An empty sample counts as missing.' );
		$this->assertSame( 'Course', $samples['course'] );
	}

	public function test_with_field_labels_ignores_a_field_without_a_name(): void {
		$samples = FormLayoutPreview::with_field_labels( array(), array( array( 'name' => '', 'label' => 'Nameless' ) ) );

		$this->assertSame( array(), $samples );
	}
}
