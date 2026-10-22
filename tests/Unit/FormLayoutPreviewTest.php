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
