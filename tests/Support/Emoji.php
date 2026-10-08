<?php
/**
 * Pictographic emoji detector shared by the icon guards.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * What counts as an emoji for the icon guards (#1613).
 *
 * Shared for the reason `CssSelectors` is: `IconStylesheetTest` reads the
 * sheets and `RawEmojiMarkupTest` reads the markup, and the two must not
 * disagree about what an emoji is. The line is drawn at the pictograph: its
 * glyph belongs to the operating system, so it ignores the text colour and
 * the dark theme. A text check mark (U+2713) or ballot X (U+2717) is
 * typography, coloured by the sheet like any letter, and stays allowed.
 */
final class Emoji {

	/**
	 * Whether a string carries a pictographic emoji, written either as the
	 * character or as a CSS escape.
	 *
	 * @param string $value The text to read.
	 * @return bool
	 */
	public static function in( string $value ): bool {
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
