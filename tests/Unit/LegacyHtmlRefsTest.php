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

	// ==================================================================
	// The drop folder itself (#1438)
	// ==================================================================

	/**
	 * A probe, not a constant: the two surfaces that read the folder decide
	 * whether to exist from this, so it has to answer about the disk.
	 */
	public function test_the_drop_folder_is_absent_on_this_tree(): void {
		$this->assertFalse(
			LegacyHtmlRefs::drop_folder_exists(),
			'The plugin stopped shipping html/ in 6.23.0, so the default answer is the state every install is in.'
		);
	}

	public function test_the_drop_folder_is_reported_when_it_exists(): void {
		$root = sys_get_temp_dir() . '/ffc-drop-' . bin2hex( random_bytes( 6 ) );
		mkdir( $root . '/html', 0777, true );

		try {
			$this->assertTrue(
				LegacyHtmlRefs::drop_folder_exists( $root ),
				'Recreating the folder must bring the surfaces that read it back on its own.'
			);
			$this->assertTrue(
				LegacyHtmlRefs::drop_folder_exists( $root . '/' ),
				'A trailing slash is the shape FFC_PLUGIN_DIR actually has.'
			);
		} finally {
			rmdir( $root . '/html' );
			rmdir( $root );
		}
	}

	public function test_a_plugin_root_without_the_folder_reports_absent(): void {
		$root = sys_get_temp_dir() . '/ffc-drop-' . bin2hex( random_bytes( 6 ) );
		mkdir( $root, 0777, true );

		try {
			$this->assertFalse( LegacyHtmlRefs::drop_folder_exists( $root ) );
		} finally {
			rmdir( $root );
		}
	}
}
