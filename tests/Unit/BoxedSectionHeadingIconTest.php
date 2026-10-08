<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every section heading on the boxed admin screens carries an icon (#1627).
 *
 * The pattern (CLAUDE.md "Icons"): a screen opens with a `.card` whose h2
 * carries the tab's icon, and every other section is a `.card` whose h2
 * carries the icon of its subject. These screens drifted from it once, each
 * with a bare `<h2>`; the scan refuses one coming back. It reads the markup
 * as text, so it sees a heading's class, never whether a box surrounds it —
 * the render tests of each screen hold that half.
 */
class BoxedSectionHeadingIconTest extends TestCase {

	/**
	 * The files that draw the boxed screens' section headings.
	 */
	private const FILES = array(
		'includes/admin/class-ffc-cert-template-receipt-settings.php',
		'includes/audience/class-ffc-audience-admin-calendar.php',
		'includes/audience/class-ffc-audience-admin-environment.php',
		'includes/audience/class-ffc-audience-admin-import.php',
		'includes/recruitment/class-ffc-recruitment-admin-page-renderer.php',
		'templates/admin/audience/audience-tab.php',
		'templates/admin/audience/general-tab.php',
		'templates/admin/audience/self-scheduling-tab.php',
		'templates/admin/recruitment/admin-page/candidates-csv-import-section.php',
		'templates/admin/recruitment/admin-page/create-adjutancy-form.php',
		'templates/admin/recruitment/admin-page/create-notice-form.php',
		'templates/admin/recruitment/admin-page/create-reason-form.php',
		'templates/admin/recruitment/admin-page/settings-tab.php',
	);

	/**
	 * Read a file of the list.
	 *
	 * @param string $file Repository-relative path.
	 * @return string
	 */
	private static function read( string $file ): string {
		$path = dirname( __DIR__, 2 ) . '/' . $file;
		self::assertFileExists( $path, 'a listed file moved; re-point the list' );
		return (string) file_get_contents( $path );
	}

	public function test_no_section_heading_is_bare(): void {
		foreach ( self::FILES as $file ) {
			$src = self::read( $file );
			// `<h2 class="nav-tab-wrapper">` is WordPress's horizontal tab strip,
			// not a section heading.
			preg_match_all( '/<h2(?:\s+class="([^"]*)")?\s*>/', $src, $m, PREG_SET_ORDER );
			foreach ( $m as $h2 ) {
				$class = $h2[1] ?? '';
				// A printf placeholder is the renderer's own helper; the class it
				// receives comes from its icon map, which the render tests read.
				if ( 'nav-tab-wrapper' === $class || 1 === preg_match( '/^%\d\$s$/', $class ) ) {
					continue;
				}
				$this->assertMatchesRegularExpression( '/\bffc-icon-[a-z-]+\b/', $class, $file . ': a section heading without an icon: ' . $h2[0] );
			}
		}
	}

	public function test_every_listed_file_draws_an_icon_heading(): void {
		// Self-check: a file that stopped drawing headings (moved, renamed) must
		// not read as clean.
		foreach ( self::FILES as $file ) {
			$src = self::read( $file );
			$this->assertMatchesRegularExpression(
				'/<h2 class="(?:ffc-icon-|%1\$s)|open_(?:tab|section)_card\(/',
				$src,
				$file . ': no icon heading found'
			);
		}
	}
}
