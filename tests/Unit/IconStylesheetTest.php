<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Icons;

/**
 * The `.ffc-icon-*` classes and the stylesheet that draws them (#1613).
 *
 * Three things fail CI here:
 *
 * 1. The generated block in `assets/css/ffc-common.css` disagrees with
 *    `Icons::stylesheet()`. The drawing lives in PHP and nowhere else; the
 *    sheet carries a copy only because a stylesheet cannot ask PHP. Regenerate
 *    with `FFC_UPDATE_ICON_CSS=1 vendor/bin/phpunit --filter IconStylesheetTest`.
 * 2. Some markup — PHP, a template or JS-built HTML — uses a `.ffc-icon-*`
 *    class the registry does not map, which renders as nothing at all. Two did
 *    when this guard was written: `ffc-icon-search` had never had a rule, and
 *    `ffc-icon-logout` had a bespoke one in the dashboard sheet.
 * 3. A stylesheet paints an emoji through `content:` again. That is the defect
 *    class this registry exists to retire: an emoji's glyph is the operating
 *    system's, so it ignores `currentColor` and the dark theme.
 *
 * @covers \FreeFormCertificate\Core\Icons
 */
class IconStylesheetTest extends TestCase {

	private const COMMON = 'assets/css/ffc-common.css';

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_the_common_sheet_carries_the_generated_block(): void {
		$path = self::root() . '/' . self::COMMON;
		$css  = (string) file_get_contents( $path );

		$begin = strpos( $css, Icons::CSS_BEGIN );
		$end   = strpos( $css, Icons::CSS_END );
		$this->assertNotFalse( $begin, 'the begin marker is missing from ' . self::COMMON );
		$this->assertNotFalse( $end, 'the end marker is missing from ' . self::COMMON );

		$current  = substr( $css, (int) $begin, (int) $end + strlen( Icons::CSS_END ) - (int) $begin );
		$expected = Icons::stylesheet();

		if ( '1' === getenv( 'FFC_UPDATE_ICON_CSS' ) && $current !== $expected ) {
			file_put_contents( $path, str_replace( $current, $expected, $css ) );
			$current = $expected;
		}

		$this->assertSame( $expected, $current, 'regenerate: FFC_UPDATE_ICON_CSS=1 vendor/bin/phpunit --filter IconStylesheetTest' );
	}

	public function test_every_icon_class_in_use_is_registered(): void {
		$used = self::used_classes();

		// The scan must reach both the deepest PHP view and the JS-built markup;
		// a scan that read nothing would otherwise pass as "every class is known".
		$this->assertArrayHasKey( 'search', $used, 'the scan did not reach includes/settings/views' );
		$this->assertArrayHasKey( 'logout', $used, 'the scan did not reach assets/js' );

		$unknown = array_diff_key( $used, Icons::classes(), array_flip( Icons::modifiers() ) );
		$this->assertSame( array(), $unknown, 'these .ffc-icon-* classes draw nothing; map them in Core\Icons::CLASSES' );
	}

	public function test_no_stylesheet_paints_an_emoji(): void {
		$sheets = array_values(
			array_filter(
				(array) glob( self::root() . '/assets/css/*.css' ),
				static fn( $f ): bool => ! str_ends_with( (string) $f, '.min.css' )
			)
		);
		$this->assertGreaterThan( 20, count( $sheets ), 'the stylesheet scan read almost nothing' );

		$found = array();
		foreach ( $sheets as $sheet ) {
			$css = (string) file_get_contents( (string) $sheet );
			if ( ! preg_match_all( '/\bcontent\s*:\s*([^;}]+)/', $css, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $value ) {
				if ( self::is_emoji( $value ) ) {
					$found[] = basename( (string) $sheet ) . ': content: ' . trim( $value );
				}
			}
		}

		$this->assertSame( array(), $found, 'use a .ffc-icon-* class from Core\Icons instead' );
	}

	public function test_the_emoji_detector_sees_both_spellings(): void {
		$this->assertTrue( self::is_emoji( '"📄 "' ), 'a literal pictograph' );
		$this->assertTrue( self::is_emoji( '"\1F4CB "' ), 'an escaped pictograph' );
		$this->assertTrue( self::is_emoji( '"\2699\FE0F "' ), 'an escaped symbol with the emoji selector' );
		$this->assertTrue( self::is_emoji( '"✅"' ), 'a dingbat with emoji presentation' );
		$this->assertFalse( self::is_emoji( '"✓"' ), 'a text check mark is typography, coloured by the sheet' );
		$this->assertFalse( self::is_emoji( '"\f345"' ), 'a dashicons glyph' );
		$this->assertFalse( self::is_emoji( '""' ) );
		$this->assertFalse( self::is_emoji( 'attr(title)' ) );
	}

	/**
	 * Every `.ffc-icon-*` class named in PHP, templates and non-minified JS.
	 *
	 * @return array<string, true>
	 */
	private static function used_classes(): array {
		$used = array();
		$dirs = array( 'includes', 'templates', 'assets/js' );
		foreach ( $dirs as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$name = $file->getFilename();
				if ( ! preg_match( '/\.(php|js)$/', $name ) || str_ends_with( $name, '.min.js' ) ) {
					continue;
				}
				// The registry's own CSS markers mention the prefix in prose.
				if ( 'class-ffc-icons.php' === $name ) {
					continue;
				}
				if ( preg_match_all( '/\bffc-icon-([a-z][a-z0-9-]*)/', (string) file_get_contents( $file->getPathname() ), $m ) ) {
					foreach ( $m[1] as $class ) {
						$used[ $class ] = true;
					}
				}
			}
		}
		ksort( $used );
		return $used;
	}

	/**
	 * Whether a `content:` value paints a pictographic emoji, written either as
	 * the character or as a CSS escape.
	 *
	 * @param string $value The declaration value.
	 * @return bool
	 */
	private static function is_emoji( string $value ): bool {
		$codepoints = array();
		if ( preg_match_all( '/\\\\([0-9a-fA-F]{1,6})/', $value, $m ) ) {
			foreach ( $m[1] as $hex ) {
				$codepoints[] = (int) hexdec( $hex );
			}
		}
		foreach ( (array) preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$codepoints[] = (int) mb_ord( (string) $char, 'UTF-8' );
		}

		foreach ( $codepoints as $cp ) {
			if ( $cp >= 0x1F000 || 0xFE0F === $cp ) {
				return true;
			}
			if ( ( $cp >= 0x2300 && $cp <= 0x23FF ) || ( $cp >= 0x2600 && $cp <= 0x26FF ) || ( $cp >= 0x2B00 && $cp <= 0x2BFF ) ) {
				return true;
			}
			if ( in_array( $cp, array( 0x2139, 0x2705, 0x270F, 0x274C, 0x2753 ), true ) ) {
				return true;
			}
		}
		return false;
	}
}
