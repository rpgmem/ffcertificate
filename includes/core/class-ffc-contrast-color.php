<?php
/**
 * Readable foreground for an operator-chosen background.
 *
 * @package FreeFormCertificate\Core
 * @since   6.24.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks black or white text for a background the plugin does not choose (#1126).
 *
 * Badges and status banners paint a colour an administrator picked in the
 * settings. A palette token cannot answer that: the token would have to be
 * legible over *every* hue an operator might choose, and no single value is.
 * Both call sites therefore hardcoded `color:#333` — which is a contrast
 * lottery, readable over a pale yellow and unreadable over a navy, and which
 * also **beats the stylesheet**, since an inline declaration wins over any
 * rule. Tokenising those classes had no effect while this was here.
 *
 * The answer is to compute rather than pick: WCAG relative luminance decides
 * between the palette's darkest text and white, whichever contrasts more.
 * That is the only choice that holds for an arbitrary input.
 */
final class ContrastColor {

	/**
	 * The two candidates. Deliberately literal: these are the two ends of the
	 * scale, not palette roles — the value must not follow the theme, because
	 * the background it sits on does not either.
	 *
	 * **Pure black, not the palette's `#1d2327`, and that is measured.** A
	 * chooser with only two candidates is worst at the mid-tone where they
	 * cross, and the guarantee it can offer is exactly the ratio at that point.
	 * With `#1d2327` the worst background in the RGB cube (`#d25a3c`) lands at
	 * **3,99:1** — below AA. With `#000000` the crossover sits at **4,58:1**,
	 * so every colour an administrator can pick clears the floor for normal
	 * text. `ContrastColorTest` sweeps the cube and fails if that stops holding.
	 */
	private const DARK  = '#000000';
	private const LIGHT = '#ffffff';

	/**
	 * Readable text colour for a background.
	 *
	 * @param string $background Hex colour, `#rgb` or `#rrggbb`. Anything else
	 *                           falls back to the dark end, which is what the
	 *                           call sites used before this existed.
	 * @return string Hex colour.
	 */
	public static function on( string $background ): string {
		$rgb = self::to_rgb( $background );

		if ( null === $rgb ) {
			return self::DARK;
		}

		$luminance = self::relative_luminance( $rgb );

		// Contrast against white is 1.05 / (L + 0.05); against the dark end it is
		// (L + 0.05) / (Ldark + 0.05). Comparing the two picks the better of the
		// pair instead of guessing a threshold — and the crossover between them
		// IS the guarantee this class offers, which is why both ends are the
		// extremes of the scale rather than palette values.
		//
		// `self::DARK` is pure black, whose relative luminance is 0 by
		// definition, so there is nothing to parse here and no failure to guard
		// against. `ContrastColorTest` pins that black end behaviourally and
		// sweeps the whole RGB cube, so changing it fails loudly rather than
		// quietly shifting the maths.
		$against_light = 1.05 / ( $luminance + 0.05 );
		$against_dark  = ( $luminance + 0.05 ) / 0.05;

		return $against_dark >= $against_light ? self::DARK : self::LIGHT;
	}

	/**
	 * Parse a hex colour.
	 *
	 * @param string $hex Colour.
	 * @return array{0: int, 1: int, 2: int}|null
	 */
	private static function to_rgb( string $hex ): ?array {
		$hex = ltrim( trim( $hex ), '#' );

		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return null;
		}

		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * WCAG 2.1 relative luminance.
	 *
	 * @param array{0: int, 1: int, 2: int} $rgb Channels, 0-255.
	 * @return float
	 */
	private static function relative_luminance( array $rgb ): float {
		$channel = static function ( int $value ): float {
			$c = $value / 255;
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};

		return 0.2126 * $channel( $rgb[0] ) + 0.7152 * $channel( $rgb[1] ) + 0.0722 * $channel( $rgb[2] );
	}
}
