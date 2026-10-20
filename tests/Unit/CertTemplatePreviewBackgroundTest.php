<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\CertTemplateAdminScreen;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the background layer of the server-side template preview.
 *
 * `with_background()` places the template's background image behind its
 * rendered body, the way the PDF generator does, so the preview shows what the
 * PDF will draw (#1958).
 *
 * @covers \FreeFormCertificate\Admin\CertTemplateAdminScreen
 */
class CertTemplatePreviewBackgroundTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_url' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Calls the protected method on an instance built without its constructor,
	 * which only registers hooks.
	 */
	private function with_background( string $body, string $bg ): string {
		$screen = ( new \ReflectionClass( CertTemplateAdminScreen::class ) )->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod( CertTemplateAdminScreen::class, 'with_background' );
		$method->setAccessible( true );

		return (string) $method->invoke( $screen, $body, $bg );
	}

	public function test_empty_background_returns_the_body_unchanged(): void {
		$body = '<div class="template">Body</div>';

		$this->assertSame( $body, $this->with_background( $body, '' ) );
	}

	public function test_background_is_placed_before_the_body_covering_the_canvas(): void {
		$body   = '<div class="template">Body</div>';
		$result = $this->with_background( $body, 'https://example.test/bg.png' );

		$this->assertStringContainsString( '<img src="https://example.test/bg.png"', $result );
		$this->assertStringContainsString( 'object-fit:cover', $result );
		$this->assertStringContainsString( 'width:1123px;height:794px', $result );
		$this->assertLessThan(
			strpos( $result, $body ),
			strpos( $result, '<img' ),
			'the image must come before the body so the body paints above it'
		);
	}

	public function test_background_url_goes_through_esc_url(): void {
		$escaped = array();
		Functions\when( 'esc_url' )->alias(
			static function ( $url ) use ( &$escaped ) {
				$escaped[] = $url;
				return 'escaped:' . $url;
			}
		);

		$result = $this->with_background( '<div></div>', 'https://example.test/a.png' );

		$this->assertSame( array( 'https://example.test/a.png' ), $escaped );
		$this->assertStringContainsString( 'src="escaped:https://example.test/a.png"', $result );
	}
}
