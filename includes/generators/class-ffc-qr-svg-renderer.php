<?php
/**
 * Styled QR code SVG renderer.
 *
 * @package FreeFormCertificate\Generators
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws a QR matrix as SVG in a given {@see QrDesign} (#1563).
 *
 * The bundled `phpqrcode` stays the encoder; this class only draws. The
 * three finder patterns ("eyes") are drawn as shapes of their own instead of
 * as modules, which is what lets a design round them without breaking the
 * pattern a reader locks onto first.
 *
 * Geometry runs in units of `U` per module, so a viewBox is exact and the
 * same markup scales to any size: the size argument only sets the
 * `width`/`height` attributes.
 */
final class QrSvgRenderer {

	/** Units per module. */
	private const U = 10;

	/** Gradient id; each SVG is its own document, so one id is enough. */
	private const GRADIENT_ID = 'ffc-qr-gradient';

	/**
	 * Encode text into a boolean module matrix.
	 *
	 * Reads phpqrcode's binarised output, never its raw frame: the raw frame
	 * keeps function-pattern flags in the high bits, so a light module there
	 * is a non-zero byte, and testing the byte for truth paints every module
	 * dark — which is what the short URL SVG download did until #1563.
	 *
	 * @param string $text Payload.
	 * @param string $ecc  L, M, Q or H.
	 * @return array<int, array<int, bool>> Rows of modules; empty on failure.
	 */
	public static function matrix( string $text, string $ecc = 'M' ): array {
		if ( '' === $text ) {
			return array();
		}

		if ( ! class_exists( '\\QRcode' ) ) {
			require_once FFC_PLUGIN_DIR . 'libs/phpqrcode/qrlib.php';
		}

		$levels = array(
			'L' => \QR_ECLEVEL_L,
			'M' => \QR_ECLEVEL_M,
			'Q' => \QR_ECLEVEL_Q,
			'H' => \QR_ECLEVEL_H,
		);
		$level  = $levels[ strtoupper( $ecc ) ] ?? \QR_ECLEVEL_M;

		try {
			$rows = \QRcode::text( $text, false, $level );
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$matrix = array();
		foreach ( $rows as $row ) {
			$matrix[] = array_map(
				static function ( string $cell ): bool {
					return '1' === $cell;
				},
				str_split( (string) $row )
			);
		}

		return $matrix;
	}

	/**
	 * Render text as a styled SVG document.
	 *
	 * @param string   $text   Payload.
	 * @param QrDesign $design Design.
	 * @param string   $ecc    Error correction level (L, M, Q, H).
	 * @param int      $margin Quiet zone, in modules (0-10).
	 * @param int      $size   Rendered width/height in px; 0 omits the attributes.
	 * @return string SVG markup, or '' when the text cannot be encoded.
	 */
	public static function render( string $text, QrDesign $design, string $ecc = 'M', int $margin = 2, int $size = 0 ): string {
		$matrix = self::matrix( $text, $ecc );
		if ( array() === $matrix ) {
			return '';
		}

		return self::render_matrix( $matrix, $design, $margin, $size );
	}

	/**
	 * Render an already-encoded matrix.
	 *
	 * @param array<int, array<int, bool>> $matrix Modules.
	 * @param QrDesign                     $design Design.
	 * @param int                          $margin Quiet zone, in modules.
	 * @param int                          $size   Width/height in px; 0 omits them.
	 * @return string
	 */
	public static function render_matrix( array $matrix, QrDesign $design, int $margin = 2, int $size = 0 ): string {
		$n      = count( $matrix );
		$margin = max( 0, min( 10, $margin ) );
		$u      = self::U;
		$box    = ( $n + 2 * $margin ) * $u;

		$is_on = static function ( int $r, int $c ) use ( $matrix, $n ): bool {
			if ( $r < 0 || $c < 0 || $r >= $n || $c >= $n || self::in_finder( $r, $c, $n ) ) {
				return false;
			}
			return ! empty( $matrix[ $r ][ $c ] );
		};

		$modules = array();
		for ( $r = 0; $r < $n; $r++ ) {
			for ( $c = 0; $c < $n; $c++ ) {
				if ( $is_on( $r, $c ) ) {
					$modules[] = self::module( $design->dots, ( $c + $margin ) * $u, ( $r + $margin ) * $u, $is_on( $r, $c + 1 ), $is_on( $r + 1, $c ) );
				}
			}
		}

		$eyes = '';
		foreach ( array( array( 0, 0 ), array( 0, $n - 7 ), array( $n - 7, 0 ) ) as $origin ) {
			$eyes .= self::eye( $design, ( $origin[1] + $margin ) * $u, ( $origin[0] + $margin ) * $u );
		}

		$defs = '';
		$fill = $design->color;
		if ( '' !== $design->color_end ) {
			$defs = sprintf(
				'<defs><linearGradient id="%1$s" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="%2$d" y2="%2$d"><stop offset="0" stop-color="%3$s"/><stop offset="1" stop-color="%4$s"/></linearGradient></defs>',
				self::GRADIENT_ID,
				$box,
				$design->color,
				$design->color_end
			);
			$fill = 'url(#' . self::GRADIENT_ID . ')';
		}

		$dimensions = $size > 0 ? sprintf( ' width="%1$d" height="%1$d"', $size ) : '';

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d"%2$s shape-rendering="geometricPrecision">%3$s<rect width="%1$d" height="%1$d" fill="%4$s"/><g fill="%5$s">%6$s</g>%7$s</svg>',
			$box,
			$dimensions,
			$defs,
			$design->background,
			$fill,
			implode( '', $modules ),
			$eyes
		);
	}

	/**
	 * Whether a cell belongs to one of the three 7x7 finder patterns.
	 *
	 * @param int $r Row.
	 * @param int $c Column.
	 * @param int $n Matrix side.
	 * @return bool
	 */
	private static function in_finder( int $r, int $c, int $n ): bool {
		return ( $r < 7 && $c < 7 ) || ( $r < 7 && $c >= $n - 7 ) || ( $r >= $n - 7 && $c < 7 );
	}

	/**
	 * One dark module.
	 *
	 * @param string $shape One of QrDesign::DOTS.
	 * @param int    $x     Left, in units.
	 * @param int    $y     Top, in units.
	 * @param bool   $right Whether the module to the right is dark (fluid joins).
	 * @param bool   $below Whether the module below is dark (fluid joins).
	 * @return string
	 */
	private static function module( string $shape, int $x, int $y, bool $right, bool $below ): string {
		$u    = self::U;
		$half = $u / 2;

		switch ( $shape ) {
			case 'dots':
				return sprintf( '<circle cx="%s" cy="%s" r="%s"/>', self::n( $x + $half ), self::n( $y + $half ), self::n( $u * 0.42 ) );
			case 'diamond':
				return sprintf(
					'<path d="M%1$s %2$sL%3$s %4$sL%1$s %5$sL%6$s %4$sZ"/>',
					self::n( $x + $half ),
					self::n( $y ),
					self::n( $x + $u ),
					self::n( $y + $half ),
					self::n( $y + $u ),
					self::n( $x )
				);
			case 'fluid':
				// A circle per module, bridged to each dark neighbour so runs
				// read as one rounded stroke.
				$out = sprintf( '<circle cx="%s" cy="%s" r="%s"/>', self::n( $x + $half ), self::n( $y + $half ), self::n( $half ) );
				if ( $right ) {
					$out .= sprintf( '<rect x="%s" y="%s" width="%d" height="%d"/>', self::n( $x + $half ), self::n( $y ), $u, $u );
				}
				if ( $below ) {
					$out .= sprintf( '<rect x="%s" y="%s" width="%d" height="%d"/>', self::n( $x ), self::n( $y + $half ), $u, $u );
				}
				return $out;
			case 'rounded':
				return sprintf( '<rect x="%s" y="%s" width="%s" height="%s" rx="%s"/>', self::n( $x + 0.4 ), self::n( $y + 0.4 ), self::n( $u - 0.8 ), self::n( $u - 0.8 ), self::n( $u * 0.3 ) );
			default:
				return sprintf( '<rect x="%d" y="%d" width="%d" height="%d"/>', $x, $y, $u, $u );
		}
	}

	/**
	 * One finder pattern: a 7x7 frame drawn as a stroke, and a 3x3 ball.
	 *
	 * @param QrDesign $design Design.
	 * @param int      $x      Left, in units.
	 * @param int      $y      Top, in units.
	 * @return string
	 */
	private static function eye( QrDesign $design, int $x, int $y ): string {
		$u = self::U;

		// The stroke is centred on the path, so the frame's path sits half a
		// module inside the 7x7 box and the stroke fills exactly one module.
		$a    = $x + $u / 2;
		$b    = $y + $u / 2;
		$side = $u * 6;

		if ( 'leaf' === $design->eye_frame ) {
			$r     = $u * 2.4;
			$frame = sprintf(
				'<path d="M%1$s %2$sH%3$sV%4$sQ%3$s %5$s %6$s %5$sH%7$sV%8$sQ%7$s %2$s %1$s %2$sZ" fill="none" stroke="%9$s" stroke-width="%10$d"/>',
				self::n( $a + $r ),
				self::n( $b ),
				self::n( $a + $side ),
				self::n( $b + $side - $r ),
				self::n( $b + $side ),
				self::n( $a + $side - $r ),
				self::n( $a ),
				self::n( $b + $r ),
				$design->eye_frame_color,
				$u
			);
		} else {
			$radius = array(
				'square'  => 0,
				'rounded' => $u * 1.8,
				'circle'  => $u * 3,
			);
			$frame  = sprintf(
				'<rect x="%1$s" y="%2$s" width="%3$s" height="%3$s" rx="%4$s" fill="none" stroke="%5$s" stroke-width="%6$d"/>',
				self::n( $a ),
				self::n( $b ),
				self::n( $side ),
				self::n( $radius[ $design->eye_frame ] ?? 0 ),
				$design->eye_frame_color,
				$u
			);
		}

		$bx   = $x + $u * 2;
		$by   = $y + $u * 2;
		$ball = $u * 3;
		$mid  = $ball / 2;

		switch ( $design->eye_ball ) {
			case 'circle':
				$inner = sprintf( '<circle cx="%s" cy="%s" r="%s" fill="%s"/>', self::n( $bx + $mid ), self::n( $by + $mid ), self::n( $mid ), $design->eye_ball_color );
				break;
			case 'diamond':
				$over  = $u * 0.2;
				$inner = sprintf(
					'<path d="M%1$s %2$sL%3$s %4$sL%1$s %5$sL%6$s %4$sZ" fill="%7$s"/>',
					self::n( $bx + $mid ),
					self::n( $by - $over ),
					self::n( $bx + $ball + $over ),
					self::n( $by + $mid ),
					self::n( $by + $ball + $over ),
					self::n( $bx - $over ),
					$design->eye_ball_color
				);
				break;
			default:
				$inner = sprintf(
					'<rect x="%1$d" y="%2$d" width="%3$d" height="%3$d" rx="%4$s" fill="%5$s"/>',
					$bx,
					$by,
					$ball,
					self::n( 'rounded' === $design->eye_ball ? $u * 0.8 : 0 ),
					$design->eye_ball_color
				);
		}

		return $frame . $inner;
	}

	/**
	 * Format a coordinate without trailing zeros or a locale's decimal comma.
	 *
	 * @param float|int $value Number.
	 * @return string
	 */
	private static function n( $value ): string {
		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
	}
}
