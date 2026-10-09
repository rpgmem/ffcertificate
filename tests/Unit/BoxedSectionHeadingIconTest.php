<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every section heading the plugin draws in wp-admin carries an icon.
 *
 * The pattern (CLAUDE.md "Icons"): a screen opens with a `.card` whose h2
 * carries the tab's icon, and every other section names its subject with an
 * icon. This guard used to read a fixed list of files, and that is how the
 * recruitment editors, the scheduling dashboard and four settings cards kept
 * a bare `<h2>` until an audit crawled every screen (#1631). It now reads the
 * whole of `includes/` and `templates/admin/`, and a file with a bare heading
 * passes only by being named below with the reason it is not an admin
 * section. It reads the markup as text, so it sees a heading's class, never
 * whether a box surrounds it — the render tests of each screen hold that half.
 */
class BoxedSectionHeadingIconTest extends TestCase {

	/**
	 * Files whose bare `<h2>` is not an admin section heading, with why.
	 *
	 * Each entry must still hold a bare heading: one that gained its icon or
	 * left the file fails the self-check, so the list only ever shrinks.
	 */
	private const NOT_ADMIN_SECTIONS = array(
		// WordPress core screens keep WordPress styling (CLAUDE.md "Theme").
		'includes/admin/class-ffc-admin-user-capabilities.php'          => 'profile / user-edit: a WordPress core screen',
		'includes/admin/class-ffc-admin-user-columns.php'               => 'users.php: a WordPress core screen',
		'includes/admin/class-ffc-admin-user-custom-fields.php'         => 'profile / user-edit: a WordPress core screen',
		'includes/self-scheduling/views/appointments-list.php'          => 'the details dialog borrows the core postbox header',
		// Not wp-admin at all: the public site, e-mail bodies, the PDF, and
		// the text WordPress's privacy guide prints.
		'includes/audience/class-ffc-audience-notification-handler.php' => 'an e-mail body',
		'includes/audience/class-ffc-audience-shortcode.php'            => 'a public shortcode',
		'includes/frontend/class-ffc-public-csv-download.php'           => 'a public page',
		'includes/frontend/class-ffc-shortcodes.php'                    => 'a public shortcode',
		'includes/generators/class-ffc-pdf-html-renderer.php'           => 'the PDF body',
		'includes/privacy/class-ffc-privacy-handler.php'                => 'policy text for the WordPress privacy guide',
		'includes/recruitment/class-ffc-recruitment-dashboard-section.php' => 'the public user dashboard',
		'includes/self-scheduling/class-ffc-self-scheduling-appointment-receipt-handler.php' => 'the printable public receipt',
	);

	/**
	 * Files whose heading carries its icon in a way a class scan cannot read.
	 */
	private const ICON_NOT_IN_CLASS = array(
		'includes/admin/class-ffc-form-editor-metabox-renderer.php' => 'the icon is a child span (`ffc-form-tabs__icon`) of the panel title',
		'templates/admin/date-messages/page.php'                    => 'the class is the tab\'s icon, read from a static map',
	);

	/**
	 * Every PHP file the scan reads.
	 *
	 * @return array<int, string> Repository-relative paths.
	 */
	private static function files(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array();
		foreach ( array( 'includes', 'templates/admin' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$path = substr( (string) $file, strlen( $root ) + 1 );
				if ( str_ends_with( $path, '.php' ) && ! str_starts_with( $path, 'includes/libraries/' ) ) {
					$files[] = $path;
				}
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Bare `<h2>` openings in a file: no icon class, not a tab strip, not a
	 * screen-reader heading.
	 *
	 * @param string $src File contents.
	 * @return array<int, string>
	 */
	private static function bare_headings( string $src ): array {
		preg_match_all( '/<h2(?:\s+class="([^"]*)")?\s*>/', $src, $m, PREG_SET_ORDER );
		$bare = array();
		foreach ( $m as $h2 ) {
			$class = $h2[1] ?? '';
			// `ffc-icon-%s` / `%1$s`: a printf placeholder the renderer fills
			// from its icon map, which that screen's render test reads.
			if ( 1 === preg_match( '/\bffc-icon-(?:[a-z-]+|%)|\bnav-tab-wrapper\b|\bscreen-reader-text\b|^%\d\$s$/', $class ) ) {
				continue;
			}
			$bare[] = $h2[0];
		}
		return $bare;
	}

	public function test_no_admin_section_heading_is_bare(): void {
		$exempt = self::NOT_ADMIN_SECTIONS + self::ICON_NOT_IN_CLASS;
		foreach ( self::files() as $file ) {
			if ( isset( $exempt[ $file ] ) ) {
				continue;
			}
			$bare = self::bare_headings( (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file ) );
			$this->assertSame( array(), $bare, $file . ': a section heading without an icon — give it an `ffc-icon-*` class, or name the file above with why it is not an admin section' );
		}
	}

	public function test_every_exemption_still_holds_a_bare_heading(): void {
		// A file that gained its icon, or moved, leaves the list — the list is
		// a register of exceptions, not a place for names to linger.
		$files = self::files();
		foreach ( array_keys( self::NOT_ADMIN_SECTIONS + self::ICON_NOT_IN_CLASS ) as $file ) {
			$this->assertContains( $file, $files, $file . ' moved; re-point or drop the exemption' );
			$this->assertNotSame(
				array(),
				self::bare_headings( (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file ) ),
				$file . ' has no bare heading left; drop the exemption'
			);
		}
	}

	public function test_the_scan_reaches_the_whole_tree(): void {
		// Self-check against an independent count (CLAUDE.md "The guards"):
		// glob over the same two roots must find exactly what the scan read.
		$root  = dirname( __DIR__, 2 );
		$count = 0;
		foreach ( array( 'includes', 'templates/admin' ) as $dir ) {
			$stack = array( $root . '/' . $dir );
			while ( $stack ) {
				$current = array_pop( $stack );
				foreach ( (array) glob( $current . '/*' ) as $entry ) {
					if ( is_dir( (string) $entry ) ) {
						if ( $root . '/includes/libraries' !== $entry ) {
							$stack[] = (string) $entry;
						}
					} elseif ( str_ends_with( (string) $entry, '.php' ) ) {
						++$count;
					}
				}
			}
		}
		$this->assertSame( $count, count( self::files() ) );
		$this->assertContains( 'templates/admin/reregistration/form.php', self::files() );
	}
}
