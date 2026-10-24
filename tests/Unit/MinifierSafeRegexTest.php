<?php
/**
 * Refuses a JavaScript regular expression literal that ends in an escaped
 * slash, the shape a server-side minifier cuts in half.
 *
 * LiteSpeed Cache minifies and combines a site's scripts for visitors who
 * are not logged in, through matthiasmullie/minify. That minifier reads
 * `/Line\//` followed by `]` or a line break as a regex that closes at the
 * escaped slash, then takes the real closing slash for the start of a
 * comment, and drops the rest of the line. The combined file no longer
 * parses, so every script in it stops: on the testing site the device test
 * stayed at "Checking…" for every visitor, while a logged-in administrator,
 * who gets the scripts uncombined, saw it work (#2056).
 *
 * The rule is wider than the measured trigger on purpose. Which characters
 * may follow the literal safely is the minifier's business and changes
 * with its version; a literal that does not end in `\/` cannot be cut that
 * way at all, and `\/{2}` or `\/\d` always say the same thing.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class MinifierSafeRegexTest extends TestCase {

	/**
	 * The plugin's own readable scripts: what the minifier receives.
	 *
	 * @return array<int, string>
	 */
	private static function sources(): array {
		$files = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/assets/js/*.js' ) as $file ) {
			if ( ! str_ends_with( (string) $file, '.min.js' ) ) {
				$files[] = (string) $file;
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Lines of code that hold a regex literal ending in an escaped slash.
	 * Comments are skipped: the minifier drops them, so they cannot break.
	 *
	 * @param string $source JavaScript source.
	 * @return array<int, int> Line numbers, 1-based.
	 */
	private static function offending_lines( string $source ): array {
		$found = array();
		foreach ( explode( "\n", $source ) as $i => $line ) {
			$code = ltrim( $line );
			if ( str_starts_with( $code, '*' ) || str_starts_with( $code, '//' ) || str_starts_with( $code, '/*' ) ) {
				continue;
			}
			// An escaped slash directly followed by the closing slash and
			// optional flags, outside a comment opener (`\//` is never `//`).
			if ( preg_match( '#\\\\/\/[dgimsuyv]*(?![\w/*])#', $line ) ) {
				$found[] = $i + 1;
			}
		}
		return $found;
	}

	public function test_no_regex_literal_ends_in_an_escaped_slash(): void {
		$offenders = array();
		foreach ( self::sources() as $file ) {
			foreach ( self::offending_lines( (string) file_get_contents( $file ) ) as $line ) {
				$offenders[] = basename( $file ) . ':' . $line;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'A regex literal ends in an escaped slash, which the LiteSpeed minifier cuts in half (#2056). Write `\/{2}` or `\/\d` instead.'
		);
	}

	/**
	 * The scan must reach every script: a recount by another traversal.
	 */
	public function test_the_scan_reads_every_readable_script(): void {
		$dir    = dirname( __DIR__, 2 ) . '/assets/js';
		$recount = array();
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( preg_match( '/^(?!.*\.min\.js$).+\.js$/', (string) $name ) ) {
				$recount[] = $dir . '/' . $name;
			}
		}
		sort( $recount );

		$this->assertNotEmpty( $recount );
		$this->assertSame( $recount, self::sources() );
	}

	/**
	 * Pins what the scan flags and what it leaves alone.
	 */
	public function test_canary(): void {
		$this->assertSame( array( 1 ), self::offending_lines( "var a = [['LINE', /Line\\//]];" ) );
		$this->assertSame( array( 1 ), self::offending_lines( "x = /a\\//i.test(u);" ) );
		$this->assertSame( array( 1 ), self::offending_lines( "u.replace(/^https?:\\/\\//, '');" ) );
		$this->assertSame( array(), self::offending_lines( "var a = [['LINE', /Line\\/\\d/]];" ) );
		$this->assertSame( array(), self::offending_lines( "u.replace(/^https?:\\/{2}/, '');" ) );
		$this->assertSame( array(), self::offending_lines( "var url = 'https://example.org/';" ) );
		$this->assertSame( array(), self::offending_lines( " * `/Line\\//` is the old shape." ) );
	}
}
