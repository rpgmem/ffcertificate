<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Tests\Support\Emoji;

/**
 * No pictographic emoji written straight into markup (#1613).
 *
 * `IconStylesheetTest` keeps emoji out of the sheets; this is the other half.
 * Twenty-one files once wrote one into markup directly: a tab label, a status
 * prefix, the glyph of a notice. Each one drew whatever the operating system
 * draws, ignored the text colour and the dark theme, and sat outside the one
 * icon set. They now use a `.ffc-icon-*` class or are dropped where the
 * surrounding notice already says the same thing.
 *
 * It reads PHP strings, inline HTML (HTML comments included, since they ship
 * in the page source) and JavaScript, skipping only code comments. A text
 * check mark or ballot X stays allowed: that is typography, coloured by the
 * sheet like any letter (`Support\Emoji` draws the line).
 *
 * @coversNothing
 */
class RawEmojiMarkupTest extends TestCase {

	/**
	 * Files allowed to keep an emoji, each with its reason.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		'includes/self-scheduling/class-ffc-self-scheduling-appointment-email-handler.php' => 'an email body: inline SVG and CSS masks are unreliable in Gmail and Outlook, so the icon set stops at the email (CLAUDE.md "Icons")',
	);

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_no_markup_writes_a_pictographic_emoji(): void {
		$found = array_diff_key( self::scan(), self::ALLOWED );
		$this->assertSame( array(), $found, 'use a .ffc-icon-* class from Core\Icons instead (or drop it where the notice already says it)' );
	}

	public function test_every_allowed_file_still_carries_an_emoji(): void {
		$scan = self::scan();
		foreach ( self::ALLOWED as $file => $reason ) {
			$this->assertNotSame( '', $reason, $file );
			$this->assertArrayHasKey( $file, $scan, $file . ' no longer carries an emoji: drop it from ALLOWED' );
		}
	}

	public function test_the_scan_reads_php_strings_and_javascript(): void {
		$files = self::files();
		$this->assertContains( 'includes/self-scheduling/class-ffc-self-scheduling-appointment-email-handler.php', $files, 'the scan did not reach a nested PHP file' );
		$this->assertContains( 'assets/js/ffc-geofence-frontend.js', $files, 'the scan did not reach assets/js' );
		$this->assertContains( 'templates/admin/qr/generator-page.php', $files, 'the scan did not reach templates' );
		$this->assertNotContains( 'assets/js/ffc-geofence-frontend.min.js', $files, 'a minified bundle is generated, not written' );
	}

	public function test_the_reader_skips_comments_and_keeps_strings(): void {
		$this->assertSame( array(), self::php_lines( "<?php\n// \u{2705} a note\n/* \u{1F4C4} */\n\$a = 1;\n" ) );
		$this->assertSame( array( 2 ), self::php_lines( "<?php\n\$a = '\u{274C} ' . \$b;\n" ) );
		$this->assertSame( array( 2 ), self::php_lines( "<?php ?>\n<!-- \u{2705} shipped in the source -->\n" ) );
		$this->assertSame( array(), self::js_lines( "// \u{26A0} note\n * \u{1F513}\nvar a = 1;\n" ) );
		$this->assertSame( array( 1 ), self::js_lines( "var a = '\u{1F513} ' + b;\n" ) );
		$this->assertSame( array(), self::js_lines( "var ok = '\u{2713} done';\n" ), 'a text check mark is typography' );
	}

	/**
	 * Every file under the scanned trees, repository-relative.
	 *
	 * @return array<int, string>
	 */
	private static function files(): array {
		$files = array();
		foreach ( array( 'includes', 'templates', 'assets/js' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$name = $file->getFilename();
				if ( ! preg_match( '/\.(php|js)$/', $name ) || str_ends_with( $name, '.min.js' ) ) {
					continue;
				}
				$files[] = substr( $file->getPathname(), strlen( self::root() ) + 1 );
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * The files that write an emoji, with the lines that do.
	 *
	 * @return array<string, array<int, int>>
	 */
	private static function scan(): array {
		$found = array();
		foreach ( self::files() as $file ) {
			$source = (string) file_get_contents( self::root() . '/' . $file );
			$lines  = str_ends_with( $file, '.php' ) ? self::php_lines( $source ) : self::js_lines( $source );
			if ( array() !== $lines ) {
				$found[ $file ] = $lines;
			}
		}
		return $found;
	}

	/**
	 * Lines of a PHP file whose non-comment tokens carry an emoji.
	 *
	 * @param string $source The file's source.
	 * @return array<int, int>
	 */
	private static function php_lines( string $source ): array {
		$lines = array();
		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) || in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( Emoji::in( $token[1] ) ) {
				$lines[] = $token[2];
			}
		}
		return array_values( array_unique( $lines ) );
	}

	/**
	 * Lines of a JavaScript file that carry an emoji outside a comment line.
	 *
	 * Comment lines are recognised by how they start, which is what every
	 * comment in `assets/js` looks like; a trailing comment after code is read
	 * with the code, which can only over-report.
	 *
	 * @param string $source The file's source.
	 * @return array<int, int>
	 */
	private static function js_lines( string $source ): array {
		$lines = array();
		foreach ( explode( "\n", $source ) as $i => $line ) {
			$trimmed = ltrim( $line );
			if ( str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '/*' ) ) {
				continue;
			}
			if ( Emoji::in( $line ) ) {
				$lines[] = $i + 1;
			}
		}
		return $lines;
	}
}
