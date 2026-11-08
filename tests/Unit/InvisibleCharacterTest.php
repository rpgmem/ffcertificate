<?php
/**
 * No invisible characters in the shipped text.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Invisible-character guard (#2522).
 *
 * The pt_BR catalogue carried 70 U+200B (zero-width space) on 33 lines, left by
 * a machine-translation pass in front of words such as "reutilizáveis" and
 * "configuráveis". They render as nothing, so no review saw them. They still
 * reach the page and every compiled catalogue, split a word for search and
 * copy, and can be read out oddly by a screen reader. One sat in the
 * certificate form's consent sentence ("autorizo o armazenamento").
 *
 * Scanned: the translation catalogues, the readmes and every PHP, JS and CSS
 * source the plugin ships (minified bundles and vendored libraries excepted:
 * they are rebuilt or copied byte for byte, never edited). The characters are
 * the zero-width space, non-joiner and joiner, the word joiner and a byte
 * order mark anywhere in a file.
 */
class InvisibleCharacterTest extends TestCase {

	private const PATTERN = '/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u';

	private const DIRS = array( 'includes', 'templates', 'assets', 'languages' );

	private const EXTENSIONS = array( 'php', 'js', 'css', 'po', 'pot', 'txt' );

	private const TOP_FILES = array( 'ffcertificate.php', 'uninstall.php', 'readme.txt', 'CHANGELOG.md' );

	/**
	 * Every file the guard reads, through a recursive iterator.
	 *
	 * @return list<string> Paths relative to the repository root.
	 */
	private static function scanned(): array {
		$root  = dirname( __DIR__, 2 );
		$files = self::TOP_FILES;
		foreach ( self::DIRS as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$rel = substr( (string) $file->getPathname(), strlen( $root ) + 1 );
				if ( self::wanted( $rel ) ) {
					$files[] = $rel;
				}
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * The same population, by a second traversal (scandir).
	 *
	 * @param string $root Repository root.
	 * @param string $rel  Directory relative to the root.
	 * @return list<string>
	 */
	private static function recount( string $root, string $rel ): array {
		$found = array();
		foreach ( (array) scandir( $root . '/' . $rel ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $rel . '/' . $name;
			if ( is_dir( $root . '/' . $path ) ) {
				$found = array_merge( $found, self::recount( $root, $path ) );
			} elseif ( self::wanted( $path ) ) {
				$found[] = $path;
			}
		}
		return $found;
	}

	/**
	 * Whether a path is shipped text the guard reads.
	 *
	 * @param string $rel Path relative to the repository root.
	 */
	private static function wanted( string $rel ): bool {
		if ( false !== strpos( $rel, '.min.' ) || 0 === strpos( $rel, 'includes/libraries/' ) ) {
			return false;
		}
		return in_array( strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true );
	}

	public function test_no_shipped_file_carries_an_invisible_character(): void {
		$root     = dirname( __DIR__, 2 );
		$findings = array();
		foreach ( self::scanned() as $rel ) {
			$lines = file( $root . '/' . $rel ) ?: array();
			foreach ( $lines as $i => $line ) {
				if ( 1 === preg_match( self::PATTERN, $line ) ) {
					$findings[] = $rel . ':' . ( $i + 1 );
				}
			}
		}
		$this->assertSame( array(), $findings, "Invisible characters (zero-width space, joiner, word joiner or BOM):\n" . implode( "\n", $findings ) );
	}

	public function test_the_scan_reads_what_a_second_traversal_finds(): void {
		$root    = dirname( __DIR__, 2 );
		$recount = self::TOP_FILES;
		foreach ( self::DIRS as $dir ) {
			$recount = array_merge( $recount, self::recount( $root, $dir ) );
		}
		sort( $recount );

		$this->assertNotSame( array(), $recount );
		$this->assertSame( $recount, self::scanned() );
		$this->assertContains( 'languages/ffcertificate-pt_BR.po', self::scanned() );
	}

	public function test_the_pattern_catches_each_character_and_spares_ordinary_text(): void {
		foreach ( array( "\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}", "\u{FEFF}" ) as $char ) {
			$this->assertSame( 1, preg_match( self::PATTERN, 'autorizo' . $char . ' o' ), bin2hex( $char ) );
		}
		$this->assertSame( 0, preg_match( self::PATTERN, "Li e concordo com a Política de Privacidade\u{00A0}— ✓" ) );
	}
}
