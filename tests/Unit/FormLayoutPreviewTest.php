<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\FormLayoutPreview;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormLayoutPreview: the page the form editor previews a layout on (#1958 follow-up).
 *
 * @covers \FreeFormCertificate\Admin\FormLayoutPreview
 */
class FormLayoutPreviewTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_wrap_paints_the_colour_on_the_page_and_the_image_under_the_body(): void {
		$markup = FormLayoutPreview::wrap( '<p>Body</p>', 'https://example.test/bg.png', '#f3e9d2' );

		$this->assertStringContainsString( 'background-color:#f3e9d2;', $markup );
		$this->assertStringContainsString( '<img src="https://example.test/bg.png"', $markup );
		$this->assertStringContainsString( 'object-fit:cover;', $markup );
		// The image comes before the body, so the body paints over it.
		$this->assertLessThan( strpos( $markup, '<p>Body</p>' ), strpos( $markup, '<img' ) );
	}

	public function test_wrap_without_an_image_or_colour_has_neither(): void {
		$markup = FormLayoutPreview::wrap( '<p>Body</p>', '', '' );

		$this->assertStringNotContainsString( 'background-color', $markup );
		$this->assertStringNotContainsString( '<img', $markup );
		$this->assertStringContainsString( '<p>Body</p>', $markup );
	}

	public function test_wrap_keeps_the_a4_landscape_page_size(): void {
		$markup = FormLayoutPreview::wrap( '', '', '#ffffff' );

		$this->assertStringContainsString( 'width:1123px;height:794px;', $markup );
	}

	public function test_document_has_no_margin_so_the_frame_shows_the_whole_page(): void {
		$doc = FormLayoutPreview::document( 'Certificate', '<div>page</div>' );

		$this->assertStringStartsWith( '<!DOCTYPE html>', $doc );
		$this->assertStringContainsString( 'html,body{margin:0;padding:0;overflow:hidden;}', $doc );
		$this->assertStringContainsString( '<body><div>page</div></body>', $doc );
	}
}
