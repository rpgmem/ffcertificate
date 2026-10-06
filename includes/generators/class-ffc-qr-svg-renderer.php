<?php
/**
 * Styled QR code SVG renderer.
 *
 * @package FreeFormCertificate\Generators
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

use FreeFormCertificate\Core\ContrastColor;

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
	 * A logo raises the error correction to H whatever was asked, because
	 * the modules it covers can only be rebuilt from the redundancy.
	 *
	 * @param string   $text   Payload.
	 * @param QrDesign $design Design.
	 * @param string   $ecc    Error correction level (L, M, Q, H).
	 * @param int      $margin Quiet zone, in modules (0-10).
	 * @param int      $size   Rendered width in px; 0 omits the dimensions.
	 * @return string SVG markup, or '' when the text cannot be encoded.
	 */
	public static function render( string $text, QrDesign $design, string $ecc = 'M', int $margin = 2, int $size = 0 ): string {
		return self::render_sized( $text, $design, $ecc, $margin, $size )['svg'];
	}

	/**
	 * Render text, and report the dimensions a frame gives the document.
	 *
	 * @param string   $text   Payload.
	 * @param QrDesign $design Design.
	 * @param string   $ecc    Error correction level (L, M, Q, H).
	 * @param int      $margin Quiet zone, in modules (0-10).
	 * @param int      $size   Rendered width in px; 0 omits the dimensions.
	 * @return array{svg: string, width: int, height: int} Empty svg on failure.
	 */
	public static function render_sized( string $text, QrDesign $design, string $ecc = 'M', int $margin = 2, int $size = 0 ): array {
		$matrix = self::matrix( $text, $design->error_level( $ecc ) );
		if ( array() === $matrix ) {
			return array(
				'svg'    => '',
				'width'  => 0,
				'height' => 0,
			);
		}

		return self::render_matrix_sized( $matrix, $design, $margin, $size );
	}

	/**
	 * Render an already-encoded matrix.
	 *
	 * @param array<int, array<int, bool>> $matrix Modules.
	 * @param QrDesign                     $design Design.
	 * @param int                          $margin Quiet zone, in modules.
	 * @param int                          $size   Width in px; 0 omits the dimensions.
	 * @return string
	 */
	public static function render_matrix( array $matrix, QrDesign $design, int $margin = 2, int $size = 0 ): string {
		return self::render_matrix_sized( $matrix, $design, $margin, $size )['svg'];
	}

	/**
	 * Render an already-encoded matrix, with the document's dimensions.
	 *
	 * @param array<int, array<int, bool>> $matrix Modules.
	 * @param QrDesign                     $design Design.
	 * @param int                          $margin Quiet zone, in modules.
	 * @param int                          $size   Width in px; 0 omits the dimensions.
	 * @return array{svg: string, width: int, height: int}
	 */
	public static function render_matrix_sized( array $matrix, QrDesign $design, int $margin = 2, int $size = 0 ): array {
		$n      = count( $matrix );
		$margin = max( 0, min( 10, $margin ) );
		$u      = self::U;
		$box    = ( $n + 2 * $margin ) * $u;

		// The logo's clearing: an odd number of modules about 22% of the side,
		// centred, plus a one-module ring so no module touches the logo.
		$logo_side  = 0;
		$logo_start = 0;
		if ( '' !== $design->logo ) {
			$logo_side  = (int) floor( $n * 0.22 );
			$logo_side += 0 === $logo_side % 2 ? 1 : 0;
			$logo_start = (int) floor( ( $n - $logo_side ) / 2 );
		}
		$in_logo = static function ( int $r, int $c ) use ( $logo_side, $logo_start ): bool {
			return $logo_side > 0
				&& $r >= $logo_start - 1 && $r <= $logo_start + $logo_side
				&& $c >= $logo_start - 1 && $c <= $logo_start + $logo_side;
		};

		$is_on = static function ( int $r, int $c ) use ( $matrix, $n, $in_logo ): bool {
			if ( $r < 0 || $c < 0 || $r >= $n || $c >= $n || self::in_finder( $r, $c, $n ) || $in_logo( $r, $c ) ) {
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

		$logo = '';
		if ( $logo_side > 0 ) {
			$at    = ( $logo_start + $margin ) * $u;
			$side  = $logo_side * $u;
			$inset = $u * 0.3;
			$logo  = sprintf(
				'<rect x="%1$d" y="%1$d" width="%2$d" height="%2$d" rx="%3$d" fill="%4$s"/><image href="%5$s" x="%6$s" y="%6$s" width="%7$s" height="%7$s" preserveAspectRatio="xMidYMid meet"/>',
				$at,
				$side,
				$u,
				$design->background,
				$design->logo,
				self::n( $at + $inset ),
				self::n( $side - 2 * $inset )
			);
		}

		$code = sprintf(
			'%1$s<rect width="%2$d" height="%2$d" fill="%3$s"/><g fill="%4$s">%5$s</g>%6$s%7$s',
			$defs,
			$box,
			$design->background,
			$fill,
			implode( '', $modules ),
			$eyes,
			$logo
		);

		list( $body, $width, $height ) = self::frame( $code, (float) $box, $design );

		$px_width  = $size > 0 ? $size : 0;
		$px_height = $size > 0 ? (int) round( $size * $height / $width ) : 0;
		$dimension = $size > 0 ? sprintf( ' width="%d" height="%d"', $px_width, $px_height ) : '';

		return array(
			'svg'    => sprintf(
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$s"%3$s shape-rendering="geometricPrecision">%4$s</svg>',
				self::n( $width ),
				self::n( $height ),
				$dimension,
				$body
			),
			'width'  => $px_width,
			'height' => $px_height,
		);
	}

	/**
	 * A thumbnail of one design choice, for the visual pickers (#1570).
	 *
	 * Drawn by the same private methods the codes are, so a thumbnail cannot
	 * show a shape the renderer would draw differently. Shapes are painted in
	 * `currentColor` and take the tile's text colour; a frame keeps its own
	 * paper and ink, because it is a printed object on either theme.
	 *
	 * @param string $kind  dots, eye_frame, eye_ball or frame.
	 * @param string $value A value of that kind's allowlist.
	 * @return string SVG markup, or '' for an unknown kind or value.
	 */
	public static function swatch( string $kind, string $value ): string {
		$allowed = array(
			'dots'      => QrDesign::DOTS,
			'eye_frame' => QrDesign::EYE_FRAMES,
			'eye_ball'  => QrDesign::EYE_BALLS,
			'frame'     => QrDesign::FRAMES,
		);
		if ( ! isset( $allowed[ $kind ] ) || ! in_array( $value, $allowed[ $kind ], true ) ) {
			return '';
		}

		$u   = self::U;
		$svg = static function ( float $width, float $height, string $body ): string {
			return sprintf(
				'<svg class="ffc-qr-swatch" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$s" aria-hidden="true" focusable="false">%3$s</svg>',
				self::n( $width ),
				self::n( $height ),
				$body
			);
		};

		if ( 'dots' === $kind ) {
			// A fixed sample with runs in both directions, so "fluid" shows
			// its joins and the other shapes show their spacing.
			$sample = array( '11011', '10010', '11110', '00101', '10111' );
			$on     = static function ( int $r, int $c ) use ( $sample ): bool {
				return isset( $sample[ $r ][ $c ] ) && '1' === $sample[ $r ][ $c ];
			};
			$body   = '';
			for ( $r = 0; $r < 5; $r++ ) {
				for ( $c = 0; $c < 5; $c++ ) {
					if ( $on( $r, $c ) ) {
						$body .= self::module( $value, $c * $u, $r * $u, $on( $r, $c + 1 ), $on( $r + 1, $c ) );
					}
				}
			}
			return $svg( 5 * $u, 5 * $u, '<g fill="currentColor">' . $body . '</g>' );
		}

		if ( 'frame' === $kind ) {
			if ( 'none' === $value ) {
				return $svg( 24, 24, '<g fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M6.5 17.5l11-11"/></g>' );
			}
			$plain = new QrDesign();
			$code  = sprintf( '<rect width="%1$d" height="%1$d" fill="#ffffff"/>', 7 * $u ) . self::eye( $plain, 0, 0 );

			list( $body, $width, $height ) = self::frame(
				$code,
				(float) ( 7 * $u ),
				new QrDesign(
					array(
						'frame'       => $value,
						'frame_text'  => 'SCAN',
						// A mid grey: the frame still reads on a dark tile.
						'frame_color' => '#646970',
					)
				)
			);
			return $svg( $width, $height, $body );
		}

		$design = new QrDesign(
			array(
				'eye_frame' => 'eye_frame' === $kind ? $value : 'square',
				'eye_ball'  => 'eye_ball' === $kind ? $value : 'square',
			)
		);
		// The plain design paints in black; the thumbnail paints in the
		// tile's own text colour instead.
		return $svg( 7 * $u, 7 * $u, str_replace( '#000000', 'currentColor', self::eye( $design, 0, 0 ) ) );
	}

	/**
	 * Wrap the drawn code in its frame.
	 *
	 * Every measure is a share of the code's side, so a frame looks the same
	 * on a version-2 code and a version-10 one. The caption shrinks to fit its
	 * band rather than overflowing it.
	 *
	 * @param string   $code   The code's markup, drawn from (0,0).
	 * @param float    $box    The code's side, in units.
	 * @param QrDesign $design Design.
	 * @return array{0: string, 1: float, 2: float} Markup, width, height.
	 */
	private static function frame( string $code, float $box, QrDesign $design ): array {
		if ( 'none' === $design->frame ) {
			return array( $code, $box, $box );
		}

		$caption = htmlspecialchars( $design->frame_text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$length  = max( 1, mb_strlen( $design->frame_text ) );
		$color   = $design->frame_color;
		$on      = ContrastColor::on( $color );
		$font    = 'font-family="Helvetica, Arial, sans-serif" font-weight="700" text-anchor="middle" dominant-baseline="central"';

		$fit = static function ( float $width, float $max ) use ( $length ): float {
			// An average glyph is about 0.62 em wide in a bold sans.
			return min( $max, ( $width * 0.86 ) / ( $length * 0.62 ) );
		};

		$text = static function ( float $x, float $y, float $size, string $fill ) use ( $caption, $font ): string {
			if ( '' === $caption ) {
				return '';
			}
			return sprintf( '<text x="%s" y="%s" font-size="%s" fill="%s" %s>%s</text>', self::n( $x ), self::n( $y ), self::n( $size ), $fill, $font, $caption );
		};

		switch ( $design->frame ) {
			case 'banner':
				$pad    = $box * 0.08;
				$band   = $box * 0.28;
				$width  = $box + 2 * $pad;
				$height = $box + 2 * $pad + $band;
				$inner  = $box * 0.03;
				$markup = sprintf( '<rect width="%1$s" height="%2$s" rx="%3$s" fill="%4$s"/>', self::n( $width ), self::n( $height ), self::n( $box * 0.07 ), $color )
					. sprintf( '<rect x="%1$s" y="%1$s" width="%2$s" height="%2$s" rx="%3$s" fill="%4$s"/>', self::n( $pad - $inner ), self::n( $box + 2 * $inner ), self::n( $box * 0.05 ), $design->background )
					. sprintf( '<g transform="translate(%1$s %1$s)">%2$s</g>', self::n( $pad ), $code )
					. $text( $width / 2, $box + 2 * $pad + $band / 2 - $pad / 2, $fit( $width, $box * 0.12 ), $on );
				return array( $markup, $width, $height );

			case 'badge':
				$pad    = $box * 0.12;
				$top    = $box * 0.22;
				$stroke = $box * 0.025;
				$width  = $box + 2 * $pad;
				$height = $box + 2 * $pad + $top;
				$markup = sprintf(
					'<rect x="%1$s" y="%1$s" width="%2$s" height="%3$s" rx="%4$s" fill="%5$s" stroke="%6$s" stroke-width="%7$s"/>',
					self::n( $stroke / 2 ),
					self::n( $width - $stroke ),
					self::n( $height - $stroke ),
					self::n( $box * 0.12 ),
					$design->background,
					$color,
					self::n( $stroke )
				)
					. $text( $width / 2, ( $top + $pad ) / 2 + $stroke, $fit( $width, $box * 0.12 ), $color )
					. sprintf( '<g transform="translate(%1$s %2$s)">%3$s</g>', self::n( $pad ), self::n( $top + $pad ), $code );
				return array( $markup, $width, $height );

			default: // Bubble.
				$bubble = $box * 0.27;
				$gap    = $box * 0.09;
				$tail   = $box * 0.06;
				$width  = $box;
				$height = $box + $bubble + $gap;
				$markup = sprintf( '<rect width="%1$s" height="%2$s" rx="%3$s" fill="%4$s"/>', self::n( $width ), self::n( $bubble ), self::n( $bubble / 2 ), $color )
					. sprintf(
						'<path d="M%1$s %2$sL%3$s %4$sL%5$s %2$sZ" fill="%6$s"/>',
						self::n( $width / 2 - $tail ),
						self::n( $bubble - 1 ),
						self::n( $width / 2 ),
						self::n( $bubble + $gap * 0.75 ),
						self::n( $width / 2 + $tail ),
						$color
					)
					. $text( $width / 2, $bubble / 2, $fit( $width * 0.9, $box * 0.11 ), $on )
					. sprintf( '<g transform="translate(0 %s)">%s</g>', self::n( $bubble + $gap ), $code );
				return array( $markup, $width, $height );
		}
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
