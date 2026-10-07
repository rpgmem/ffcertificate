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
	 * Numbers the arc a circle frame writes its caption on. Unlike the
	 * gradient, that path is referenced by id from inline SVG, and a page
	 * that prints a thumbnail beside the preview must not resolve one
	 * document's id to the other's path.
	 *
	 * @var int
	 */
	private static int $sequence = 0;

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
				$design->transparent ? 'none' : $design->background,
				$design->logo,
				self::n( $at + $inset ),
				self::n( $side - 2 * $inset )
			);
		}

		// A transparent design draws no ground at all, so the code sits on
		// whatever it is printed on (#1570).
		$ground = $design->transparent ? '' : sprintf( '<rect width="%1$d" height="%1$d" fill="%2$s"/>', $box, $design->background );
		$code   = sprintf(
			'%1$s%2$s<g fill="%3$s">%4$s</g>%5$s%6$s',
			$defs,
			$ground,
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
		// The paper under the code and its caption; none on a transparent design.
		$paper = $design->transparent ? 'none' : $design->background;

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

		/*
		 * The frame's icon, drawn from the same set as the admin screens and
		 * coloured through currentColor, so one drawing serves any ink.
		 */
		$icon     = static function ( float $x, float $y, float $size, string $ink ) use ( $design ): string {
			$paths = 'none' === $design->frame_icon ? '' : QrIcons::paths( $design->frame_icon );
			if ( '' === $paths ) {
				return '';
			}
			return sprintf(
				'<g transform="translate(%1$s %2$s) scale(%3$s)" color="%4$s" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%5$s</g>',
				self::n( $x ),
				self::n( $y ),
				self::n( $size / 24 ),
				$ink,
				$paths
			);
		};
		$has_icon = 'none' !== $design->frame_icon && '' !== QrIcons::paths( $design->frame_icon );

		switch ( $design->frame ) {
			case 'pill':
				// A rounded border around the code, and below it a pill holding
				// the icon in a disc and the caption (#1570).
				$stroke = $box * 0.035;
				$pad    = $box * 0.06;
				$width  = $box + 2 * ( $pad + $stroke );
				$gap    = $box * 0.06;
				$band   = $box * 0.22;
				$height = $width + $gap + $band;
				$disc   = $has_icon ? $band : 0.0;
				$markup = sprintf(
					'<rect x="%1$s" y="%1$s" width="%2$s" height="%2$s" rx="%3$s" fill="%4$s" stroke="%5$s" stroke-width="%6$s"/>',
					self::n( $stroke / 2 ),
					self::n( $width - $stroke ),
					self::n( $box * 0.1 ),
					$paper,
					$color,
					self::n( $stroke )
				)
					. sprintf( '<g transform="translate(%1$s %1$s)">%2$s</g>', self::n( $stroke + $pad ), $code )
					. sprintf( '<rect y="%1$s" width="%2$s" height="%3$s" rx="%4$s" fill="%5$s"/>', self::n( $width + $gap ), self::n( $width ), self::n( $band ), self::n( $band / 2 ), $color );
				if ( $has_icon ) {
					$r       = $band * 0.38;
					$markup .= sprintf( '<circle cx="%s" cy="%s" r="%s" fill="%s"/>', self::n( $band / 2 ), self::n( $width + $gap + $band / 2 ), self::n( $r ), $on )
						. $icon( $band / 2 - $r * 0.62, $width + $gap + $band / 2 - $r * 0.62, $r * 1.24, $color );
				}
				$markup .= $text( $disc + ( $width - $disc ) / 2, $width + $gap + $band / 2, $fit( $width - $disc, $box * 0.13 ), $on );
				return array( $markup, $width, $height );

			case 'speech':
				// A rounded outline with a small pointer below, and the caption
				// under it beside the icon, in the frame colour on the paper.
				$stroke  = $box * 0.025;
				$pad     = $box * 0.07;
				$width   = $box + 2 * $pad;
				$tail    = $box * 0.06;
				$line    = $box * 0.16;
				$height  = $width + $tail + $line + $box * 0.04;
				$mid     = $width / 2;
				$markup  = sprintf(
					'<rect x="%1$s" y="%1$s" width="%2$s" height="%2$s" rx="%3$s" fill="%4$s" stroke="%5$s" stroke-width="%6$s"/>',
					self::n( $stroke / 2 ),
					self::n( $width - $stroke ),
					self::n( $box * 0.1 ),
					$paper,
					$color,
					self::n( $stroke )
				)
					. sprintf( '<g transform="translate(%1$s %1$s)">%2$s</g>', self::n( $pad ), $code )
					. sprintf(
						'<path d="M%1$s %2$sL%3$s %4$sL%5$s %2$s" fill="%6$s" stroke="%7$s" stroke-width="%8$s" stroke-linejoin="round"/>',
						self::n( $mid - $tail * 1.4 ),
						self::n( $width - $stroke / 2 ),
						self::n( $mid ),
						self::n( $width + $tail ),
						self::n( $mid + $tail * 1.4 ),
						$paper,
						$color,
						self::n( $stroke )
					);
				$size    = $fit( $width * 0.8, $box * 0.11 );
				$cy      = $width + $tail + $box * 0.04 + $line / 2;
				$shift   = $has_icon ? $size * 0.7 : 0.0;
				$markup .= $text( $mid + $shift, $cy, $size, $color );
				if ( $has_icon && '' !== $caption ) {
					$half_text = min( $width * 0.4, $length * $size * 0.31 );
					$markup   .= $icon( $mid + $shift - $half_text - $size * 1.4, $cy - $size * 0.55, $size * 1.1, $color );
				}
				return array( $markup, $width, $height );

			case 'circle':
				// The square code inside a ring, with the caption on the arc
				// below it; the circle's diameter grows to fit the code's corners.
				$ring   = $box * 0.035;
				$inner  = $box * M_SQRT2 / 2 + $box * 0.03;
				$band   = $box * 0.17;
				$radius = $inner + $band + $ring;
				$side   = 2 * $radius;
				$offset = $radius - $box / 2;
				$id     = 'ffc-qr-arc-' . ( ++self::$sequence );
				$arc    = $inner + $band / 2;
				$markup = sprintf( '<circle cx="%1$s" cy="%1$s" r="%2$s" fill="%3$s" stroke="%4$s" stroke-width="%5$s"/>', self::n( $radius ), self::n( $radius - $ring / 2 ), $paper, $color, self::n( $ring ) )
					. sprintf( '<g transform="translate(%1$s %1$s)">%2$s</g>', self::n( $offset ), $code );
				if ( '' !== $caption ) {
					// Left to right along the bottom half, so the text reads upright.
					$size    = min( $band * 0.68, ( M_PI * $arc * 0.75 ) / ( $length * 0.62 ) );
					$markup .= sprintf(
						'<defs><path id="%1$s" d="M%2$s %3$sA%4$s %4$s 0 0 0 %5$s %3$s"/></defs><text font-size="%6$s" fill="%7$s" font-family="Helvetica, Arial, sans-serif" font-weight="700" letter-spacing="%8$s"><textPath href="#%1$s" startOffset="50%%" text-anchor="middle" dominant-baseline="central">%9$s</textPath></text>',
						$id,
						self::n( $radius - $arc ),
						self::n( $radius ),
						self::n( $arc ),
						self::n( $radius + $arc ),
						self::n( $size ),
						$color,
						self::n( $size * 0.12 ),
						$caption
					);
				}
				return array( $markup, $side, $side );

			case 'brackets':
				// Corner brackets around the code, and the caption below.
				$stroke = $box * 0.03;
				$pad    = $box * 0.08;
				$width  = $box + 2 * $pad;
				$arm    = $box * 0.18;
				$line   = '' === $caption ? 0.0 : $box * 0.16;
				$height = $width + $line;
				$e      = $stroke / 2;
				$w      = $width - $e;
				$markup = sprintf( '<rect width="%1$s" height="%2$s" fill="%3$s"/>', self::n( $width ), self::n( $height ), $paper )
					. sprintf(
						'<path d="M%1$s %2$sV%1$sH%2$sM%3$s %1$sH%4$sV%2$sM%4$s %5$sV%4$sH%3$sM%2$s %4$sH%1$sV%5$s" fill="none" stroke="%6$s" stroke-width="%7$s" stroke-linecap="round" stroke-linejoin="round"/>',
						self::n( $e ),
						self::n( $e + $arm ),
						self::n( $w - $arm ),
						self::n( $w ),
						self::n( $w - $arm ),
						$color,
						self::n( $stroke )
					)
					. sprintf( '<g transform="translate(%1$s %1$s)">%2$s</g>', self::n( $pad ), $code )
					. $text( $width / 2, $width + $line / 2 - $box * 0.02, $fit( $width, $box * 0.11 ), $color );
				return array( $markup, $width, $height );

			case 'double':
				// A band above and below, the caption in both.
				$band   = $box * 0.2;
				$pad    = $box * 0.05;
				$width  = $box + 2 * $pad;
				$height = $box + 2 * $pad + 2 * $band;
				$size   = $fit( $width, $box * 0.11 );
				$markup = sprintf( '<rect width="%1$s" height="%2$s" rx="%3$s" fill="%4$s"/>', self::n( $width ), self::n( $height ), self::n( $box * 0.06 ), $color )
					. sprintf( '<rect x="%1$s" y="%2$s" width="%3$s" height="%3$s" fill="%4$s"/>', self::n( $pad / 2 ), self::n( $band + $pad / 2 ), self::n( $box + $pad ), $paper )
					. sprintf( '<g transform="translate(%1$s %2$s)">%3$s</g>', self::n( $pad ), self::n( $band + $pad ), $code )
					. $text( $width / 2, $band / 2, $size, $on )
					. $text( $width / 2, $height - $band / 2, $size, $on );
				return array( $markup, $width, $height );

			case 'banner':
				$pad    = $box * 0.08;
				$band   = $box * 0.28;
				$width  = $box + 2 * $pad;
				$height = $box + 2 * $pad + $band;
				$inner  = $box * 0.03;
				$markup = sprintf( '<rect width="%1$s" height="%2$s" rx="%3$s" fill="%4$s"/>', self::n( $width ), self::n( $height ), self::n( $box * 0.07 ), $color )
					. sprintf( '<rect x="%1$s" y="%1$s" width="%2$s" height="%2$s" rx="%3$s" fill="%4$s"/>', self::n( $pad - $inner ), self::n( $box + 2 * $inner ), self::n( $box * 0.05 ), $paper )
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
					$paper,
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
			case 'star':
				return self::polygon( self::star( $x + $half, $y + $half, $u * 0.56, $u * 0.27 ) );
			case 'cross':
				// Arms 0.44 of the cell wide: thin enough to read as a cross,
				// thick enough to keep most of the module dark.
				return self::polygon( self::plus( (float) $x, (float) $y, (float) $u, $u * 0.44 ) );
			case 'heart':
				// Drawn once in a 10x10 cell and placed; U is 10, so the scale is 1.
				return sprintf(
					'<path transform="translate(%d %d)" d="M5 9.6C3.2 8.3 0 6 0 3.3 0 1.5 1.4.2 3 .2c.9 0 1.6.4 2 1.1.4-.7 1.1-1.1 2-1.1 1.6 0 3 1.3 3 3.1C10 6 6.8 8.3 5 9.6z"/>',
					$x,
					$y
				);
			case 'x':
				return self::polygon( self::saltire( (float) $x, (float) $y, (float) $u, $u * 0.2 ) );
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

		if ( 'dotted' === $design->eye_frame ) {
			// One dot per module of the 7x7 ring: the ring still reads as the
			// 1:1:3:1:1 run a finder needs, just drawn in dots.
			$frame = '';
			for ( $i = 0; $i < 7; $i++ ) {
				for ( $j = 0; $j < 7; $j++ ) {
					if ( 0 === $i || 6 === $i || 0 === $j || 6 === $j ) {
						$frame .= sprintf( '<circle cx="%s" cy="%s" r="%s"/>', self::n( $x + $j * $u + $u / 2 ), self::n( $y + $i * $u + $u / 2 ), self::n( $u * 0.5 ) );
					}
				}
			}
			$frame = sprintf( '<g fill="%s">%s</g>', $design->eye_frame_color, $frame );
		} elseif ( 'corner' === $design->eye_frame || 'cut' === $design->eye_frame ) {
			if ( 'corner' === $design->eye_frame ) {
				// One rounded corner, the top-left.
				$r = $u * 2.4;
				$d = sprintf(
					'M%1$s %2$sH%3$sV%4$sH%5$sV%6$sQ%5$s %2$s %1$s %2$sZ',
					self::n( $a + $r ),
					self::n( $b ),
					self::n( $a + $side ),
					self::n( $b + $side ),
					self::n( $a ),
					self::n( $b + $r )
				);
			} else {
				// Chamfered corners: an octagon.
				$c = $u * 1.5;
				$d = sprintf(
					'M%1$s %2$sH%3$sL%4$s %5$sV%6$sL%3$s %7$sH%1$sL%8$s %6$sV%5$sZ',
					self::n( $a + $c ),
					self::n( $b ),
					self::n( $a + $side - $c ),
					self::n( $a + $side ),
					self::n( $b + $c ),
					self::n( $b + $side - $c ),
					self::n( $b + $side ),
					self::n( $a )
				);
			}
			$frame = sprintf( '<path d="%s" fill="none" stroke="%s" stroke-width="%d" stroke-linejoin="round"/>', $d, $design->eye_frame_color, $u );
		} elseif ( 'leaf' === $design->eye_frame ) {
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
			case 'star':
				$inner = self::polygon( self::star( $bx + $mid, $by + $mid + $u * 0.12, $u * 2.1, $u * 1.3 ), $design->eye_ball_color );
				break;
			case 'cross':
				$inner = self::polygon( self::plus( (float) $bx, (float) $by, (float) $ball, $u * 2.0 ), $design->eye_ball_color );
				break;
			case 'flower':
				$inner = sprintf( '<g fill="%s">', $design->eye_ball_color );
				foreach ( array( array( -1, -1 ), array( 1, -1 ), array( -1, 1 ), array( 1, 1 ) ) as $petal ) {
					$inner .= sprintf( '<circle cx="%s" cy="%s" r="%s"/>', self::n( $bx + $mid + $petal[0] * $u * 0.62 ), self::n( $by + $mid + $petal[1] * $u * 0.62 ), self::n( $u * 0.9 ) );
				}
				$inner .= sprintf( '<circle cx="%s" cy="%s" r="%s"/></g>', self::n( $bx + $mid ), self::n( $by + $mid ), self::n( $u * 0.9 ) );
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
	 * A closed polygon path through points.
	 *
	 * @param array<int, array{0: float, 1: float}> $points Points.
	 * @param string                                $fill   Fill attribute, '' to inherit.
	 * @return string
	 */
	private static function polygon( array $points, string $fill = '' ): string {
		$d = '';
		foreach ( $points as $i => $point ) {
			$d .= ( 0 === $i ? 'M' : 'L' ) . self::n( $point[0] ) . ' ' . self::n( $point[1] );
		}
		return sprintf( '<path d="%sZ"%s/>', $d, '' === $fill ? '' : ' fill="' . $fill . '"' );
	}

	/**
	 * The points of a five-pointed star.
	 *
	 * @param float $cx    Centre x.
	 * @param float $cy    Centre y.
	 * @param float $outer Tip radius.
	 * @param float $inner Notch radius.
	 * @return array<int, array{0: float, 1: float}>
	 */
	private static function star( float $cx, float $cy, float $outer, float $inner ): array {
		$points = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$radius   = 0 === $i % 2 ? $outer : $inner;
			$angle    = -M_PI / 2 + $i * M_PI / 5;
			$points[] = array( $cx + $radius * cos( $angle ), $cy + $radius * sin( $angle ) );
		}
		return $points;
	}

	/**
	 * The points of a plus sign filling a square.
	 *
	 * @param float $x    Left.
	 * @param float $y    Top.
	 * @param float $side Square side.
	 * @param float $arm  Arm width.
	 * @return array<int, array{0: float, 1: float}>
	 */
	private static function plus( float $x, float $y, float $side, float $arm ): array {
		$a = ( $side - $arm ) / 2;
		$b = $a + $arm;
		return array(
			array( $x + $a, $y ),
			array( $x + $b, $y ),
			array( $x + $b, $y + $a ),
			array( $x + $side, $y + $a ),
			array( $x + $side, $y + $b ),
			array( $x + $b, $y + $b ),
			array( $x + $b, $y + $side ),
			array( $x + $a, $y + $side ),
			array( $x + $a, $y + $b ),
			array( $x, $y + $b ),
			array( $x, $y + $a ),
			array( $x + $a, $y + $a ),
		);
	}

	/**
	 * The points of a diagonal cross (an X) filling a square.
	 *
	 * @param float $x    Left.
	 * @param float $y    Top.
	 * @param float $side Square side.
	 * @param float $arm  Distance from each corner the arms start, along an edge.
	 * @return array<int, array{0: float, 1: float}>
	 */
	private static function saltire( float $x, float $y, float $side, float $arm ): array {
		$m = $side / 2;
		$k = $m - $arm;
		return array(
			array( $x, $y + $arm ),
			array( $x + $arm, $y ),
			array( $x + $m, $y + $k ),
			array( $x + $side - $arm, $y ),
			array( $x + $side, $y + $arm ),
			array( $x + $side - $k, $y + $m ),
			array( $x + $side, $y + $side - $arm ),
			array( $x + $side - $arm, $y + $side ),
			array( $x + $m, $y + $side - $k ),
			array( $x + $arm, $y + $side ),
			array( $x, $y + $side - $arm ),
			array( $x + $k, $y + $m ),
		);
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
