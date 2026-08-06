<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\LegacyHtmlRefs;

/**
 * Unit tests for the shared legacy `html/` reference detector (#865). One
 * definition of the marker + URL-token regex is used by the Phase 0 notice, the
 * Rewrite-html/-image-refs migration and the Phase 4 save-linter.
 *
 * @covers \FreeFormCertificate\Core\LegacyHtmlRefs
 */
class LegacyHtmlRefsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Autoload the class so pcov attributes coverage even when it is first
		// referenced inside a test method.
		class_exists( '\\FreeFormCertificate\\Core\\LegacyHtmlRefs' );
	}

	public function test_marker_is_plugin_scoped(): void {
		$this->assertSame( 'ffcertificate/html/', LegacyHtmlRefs::MARKER );
	}

	public function test_has_marker_true_for_html_folder_ref(): void {
		$this->assertTrue(
			LegacyHtmlRefs::has_marker( 'https://x.test/wp-content/plugins/ffcertificate/html/logo.png' )
		);
	}

	public function test_has_marker_false_for_assets_and_empty(): void {
		// A shipped asset must never match — that is the whole point of the
		// plugin-scoped marker.
		$this->assertFalse(
			LegacyHtmlRefs::has_marker( 'https://x.test/wp-content/plugins/ffcertificate/assets/img/logo.png' )
		);
		$this->assertFalse( LegacyHtmlRefs::has_marker( '' ) );
		$this->assertFalse( LegacyHtmlRefs::has_marker( 'https://x.test/wp-content/uploads/logo.png' ) );
	}

	public function test_find_urls_extracts_attribute_and_bare_tokens(): void {
		$content = '<img src="https://x.test/plugins/ffcertificate/html/a.png">'
			. ' background="/wp-content/plugins/ffcertificate/html/b.jpg"'
			. ' and a bare ffcertificate/html/c.gif token';

		$urls = LegacyHtmlRefs::find_urls( $content );

		$this->assertContains( 'https://x.test/plugins/ffcertificate/html/a.png', $urls );
		$this->assertContains( '/wp-content/plugins/ffcertificate/html/b.jpg', $urls );
		$this->assertContains( 'ffcertificate/html/c.gif', $urls );
		$this->assertCount( 3, $urls );
	}

	public function test_find_urls_deduplicates(): void {
		$dup     = 'ffcertificate/html/same.png';
		$content = "src=\"$dup\" href=\"$dup\"";

		$this->assertSame( array( $dup ), LegacyHtmlRefs::find_urls( $content ) );
	}

	public function test_find_urls_ignores_assets_and_returns_empty(): void {
		$this->assertSame(
			array(),
			LegacyHtmlRefs::find_urls( '<img src="/plugins/ffcertificate/assets/img/logo.png">' )
		);
		$this->assertSame( array(), LegacyHtmlRefs::find_urls( '' ) );
	}
}
