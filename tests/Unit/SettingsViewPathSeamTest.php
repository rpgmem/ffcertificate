<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Settings views are resolved in one place, `SettingsTab::view_path()` (#1811).
 *
 * A tab that builds `'includes/settings/views/…'` inline brings back the
 * shape that let six tab tests copy `render()`: with no seam to override,
 * a test can only point `render()` at a view of its own by re-implementing
 * it, and then it asserts against the copy. So the path literal may appear
 * in the seam and nowhere else under `includes/`.
 *
 * The scan reads raw text, deliberately: the literal is what the seam
 * replaces, and a comment that spells it is rare and cheap to reword.
 *
 * @coversNothing
 */
class SettingsViewPathSeamTest extends TestCase {

	/** The path fragment only the seam may spell. */
	private const NEEDLE = "'includes/settings/views/";

	/** The seam's file, relative to the repository root. */
	private const OWNER = 'includes/settings/class-ffc-settings-tab.php';

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every PHP file under `includes/`, through the iterator the scan uses.
	 *
	 * @return array<int, string> Paths relative to the repository root, sorted.
	 */
	private static function scanned_files(): array {
		$root  = self::root();
		$files = array();
		$it    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $info ) {
			if ( 'php' === $info->getExtension() ) {
				$files[] = substr( $info->getPathname(), strlen( $root ) + 1 );
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * The same population counted by a different traversal, so a scan that
	 * silently reads half the tree fails instead of passing as clean.
	 *
	 * @param string $dir Absolute directory.
	 * @return int
	 */
	private static function recount( string $dir ): int {
		$count = 0;
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$count += self::recount( $path );
			} elseif ( '.php' === substr( (string) $entry, -4 ) ) {
				++$count;
			}
		}
		return $count;
	}

	public function test_only_the_seam_spells_the_settings_views_path(): void {
		$offenders = array();
		foreach ( self::scanned_files() as $file ) {
			if ( self::OWNER === $file ) {
				continue;
			}
			if ( false !== strpos( (string) file_get_contents( self::root() . '/' . $file ), self::NEEDLE ) ) {
				$offenders[] = $file;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'Resolve a settings view with $this->view_path( \'ffc-tab-….php\' ), not an inline path.'
		);
	}

	public function test_the_scan_reads_the_whole_tree(): void {
		$this->assertCount( self::recount( self::root() . '/includes' ), self::scanned_files() );
	}

	public function test_the_needle_matches_the_seam_it_protects(): void {
		$this->assertContains( self::OWNER, self::scanned_files() );
		$this->assertSame(
			1,
			substr_count( (string) file_get_contents( self::root() . '/' . self::OWNER ), self::NEEDLE ),
			'The seam must spell the path exactly once; if it changed, update NEEDLE with it.'
		);
	}
}
