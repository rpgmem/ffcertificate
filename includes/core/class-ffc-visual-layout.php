<?php
/**
 * VisualLayout
 *
 * The layout model of a visual certificate template and its server renderer
 * (#2584, item D1 of #1951).
 *
 * @package FreeFormCertificate\Core
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A visual template is a JSON layout of positioned elements, rendered here to
 * the HTML the existing capture (html2canvas-pro + jsPDF) already draws, so
 * what the editor shows is what the PDF prints.
 *
 * Schema, version 1:
 *
 *     { "version": 1,
 *       "page": { "orientation": "landscape" | "portrait" },
 *       "elements": [ { "id", "type", "x", "y", "w", "h", "rotation", "locked", …per type } ] }
 *
 * - Coordinates are page pixels: 1123 × 794 landscape, 794 × 1123 portrait,
 *   independent of any editor zoom.
 * - The list order is the stacking order: the first element is at the back.
 *   The template's background image and page colour sit beneath every
 *   element, as they do for an HTML template.
 * - Types: `text` (literal text, tokens allowed), `field` (one token), `image`
 *   (a media library id, or the `{{signer:signature}}` token), `qr`, `frame`
 *   and `html`. Only the properties #1951 measured identical between the
 *   browser and the capture exist: no `object-fit` (contain is drawn with a
 *   maximum size, cover with an explicit size and position), no vertical
 *   text, no shadow.
 *
 * Validation refuses what cannot be a layout (not JSON, another version, no
 * element list, too many elements, an unknown type) and neutralises the rest:
 * numbers are clamped, an unknown value falls back to its default, an unknown
 * key is dropped. Rendering escapes every text value; only the HTML block
 * passes markup, through the same `wp_kses()` policy as an HTML template, and
 * the whole output passes it again.
 */
final class VisualLayout {

	/** Template meta: which editor the template uses, `html` or `visual`. */
	public const META_EDITOR = '_ffc_template_editor';

	/** Template meta: the layout JSON of a visual template. */
	public const META_LAYOUT = '_ffc_template_layout';

	/** The HTML editor (every template until #2584). */
	public const EDITOR_HTML = 'html';

	/** The visual editor. */
	public const EDITOR_VISUAL = 'visual';

	/** The schema version this class reads and writes. */
	public const SCHEMA_VERSION = 1;

	/** How many elements one layout may hold. */
	public const MAX_ELEMENTS = 200;

	/** The element types. */
	public const TYPES = array( 'text', 'field', 'image', 'qr', 'frame', 'html' );

	/** Longest text of a text element, in characters. */
	private const MAX_TEXT = 5000;

	/** Longest HTML block, in bytes. */
	private const MAX_HTML = 50000;

	/** Allowed values of the enumerated properties; the first is the default. */
	private const ENUMS = array(
		'font_style'     => array( 'normal', 'italic' ),
		'align'          => array( 'left', 'center', 'right', 'justify' ),
		'text_transform' => array( 'none', 'uppercase', 'lowercase', 'capitalize' ),
		'fit'            => array( 'none', 'line', 'box' ),
		'image_fit'      => array( 'contain', 'cover' ),
		'border_style'   => array( 'solid', 'dashed', 'dotted', 'double' ),
		'orientation'    => array( 'landscape', 'portrait' ),
	);

	/** Numeric bounds: minimum, maximum, default. */
	private const BOUNDS = array(
		'font_size'      => array( 4, 300, 16 ),
		'letter_spacing' => array( -20, 100, 0 ),
		'line_height'    => array( 0.5, 5, 1.2 ),
		'rotation'       => array( -360, 360, 0 ),
		'border_width'   => array( 0, 100, 1 ),
		'radius'         => array( 0, 600, 0 ),
		'qr_margin'      => array( 0, 10, 0 ),
		'gradient_angle' => array( 0, 360, 180 ),
		'gradient_at'    => array( 0, 100, 0 ),
	);

	/** How many colours a frame's gradient may hold. */
	private const GRADIENT_STOPS = array( 2, 5 );

	/** A field token: one `{{…}}` with no markup inside. */
	private const FIELD_TOKEN = '/^\{\{[a-z0-9_]+(?::[a-z0-9_]+)*(?::(?:format|size|margin)=[^{}<>"]{0,60})*\}\}$/';

	/** The image token a signer prints as. */
	private const SIGNATURE_TOKEN = '{{signer:signature}}';

	/**
	 * The page size for an orientation, in CSS pixels.
	 *
	 * @param string $orientation `landscape` or `portrait`.
	 * @return array{width: int, height: int}
	 */
	public static function page_size( string $orientation ): array {
		return 'portrait' === $orientation
			? array(
				'width'  => 794,
				'height' => 1123,
			)
			: array(
				'width'  => 1123,
				'height' => 794,
			);
	}

	/**
	 * An empty layout, what a new visual template starts from.
	 *
	 * @return array{version: int, page: array{orientation: string}, elements: array<int, array<string, mixed>>}
	 */
	public static function blank(): array {
		return array(
			'version'  => self::SCHEMA_VERSION,
			'page'     => array( 'orientation' => 'landscape' ),
			'elements' => array(),
		);
	}

	/**
	 * Whether a template uses the visual editor.
	 *
	 * @param int $template_id Template post id.
	 */
	public static function is_visual( int $template_id ): bool {
		return $template_id > 0 && self::EDITOR_VISUAL === (string) get_post_meta( $template_id, self::META_EDITOR, true );
	}

	/**
	 * A template's stored layout, or null when it has none that validates.
	 *
	 * @param int $template_id Template post id.
	 * @return array{version: int, page: array{orientation: string}, elements: array<int, array<string, mixed>>}|null
	 */
	public static function stored( int $template_id ): ?array {
		$layout = self::validate( (string) get_post_meta( $template_id, self::META_LAYOUT, true ) );
		return is_array( $layout ) ? $layout : null;
	}

	/**
	 * A clean layout from its JSON, or why it cannot be one.
	 *
	 * @param string $json Layout JSON.
	 * @return array{version: int, page: array{orientation: string}, elements: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function validate( string $json ) {
		if ( strlen( $json ) > self::MAX_HTML * 20 ) {
			return new \WP_Error( 'ffc_layout_too_large', __( 'The layout is too large.', 'ffcertificate' ) );
		}
		$raw = json_decode( $json, true, 16 );
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'ffc_layout_not_json', __( 'The layout is not valid JSON.', 'ffcertificate' ) );
		}
		if ( self::SCHEMA_VERSION !== ( $raw['version'] ?? null ) ) {
			return new \WP_Error(
				'ffc_layout_version',
				/* translators: %d: the schema version this release reads */
				sprintf( __( 'The layout must declare "version": %d.', 'ffcertificate' ), self::SCHEMA_VERSION )
			);
		}
		$elements = $raw['elements'] ?? null;
		if ( ! is_array( $elements ) || ! array_is_list( $elements ) ) {
			return new \WP_Error( 'ffc_layout_elements', __( 'The layout must carry an "elements" list.', 'ffcertificate' ) );
		}
		if ( count( $elements ) > self::MAX_ELEMENTS ) {
			return new \WP_Error(
				'ffc_layout_too_many',
				/* translators: %d: maximum number of elements */
				sprintf( __( 'A layout holds at most %d elements.', 'ffcertificate' ), self::MAX_ELEMENTS )
			);
		}

		$page        = is_array( $raw['page'] ?? null ) ? $raw['page'] : array();
		$orientation = self::enum( 'orientation', $page['orientation'] ?? null );
		$size        = self::page_size( $orientation );

		$clean = array();
		$ids   = array();
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				return new \WP_Error(
					'ffc_layout_element',
					/* translators: %d: element position, starting at 1 */
					sprintf( __( 'Element %d is not an object.', 'ffcertificate' ), $index + 1 )
				);
			}
			$type = is_string( $element['type'] ?? null ) ? $element['type'] : '';
			if ( ! in_array( $type, self::TYPES, true ) ) {
				return new \WP_Error(
					'ffc_layout_type',
					/* translators: 1: element position, starting at 1, 2: the type it declares */
					sprintf( __( 'Element %1$d has an unknown type "%2$s".', 'ffcertificate' ), $index + 1, sanitize_key( $type ) )
				);
			}
			$id = self::element_id( $element['id'] ?? '', $index, $ids );

			$ids[ $id ] = true;
			$clean[]    = array_merge(
				array(
					'id'       => $id,
					'type'     => $type,
					'x'        => self::number( $element['x'] ?? 0, -$size['width'], 2 * $size['width'], 0 ),
					'y'        => self::number( $element['y'] ?? 0, -$size['height'], 2 * $size['height'], 0 ),
					'w'        => self::number( $element['w'] ?? 100, 1, 2 * $size['width'], 100 ),
					'h'        => self::number( $element['h'] ?? 40, 1, 2 * $size['height'], 40 ),
					'rotation' => self::bounded( 'rotation', $element['rotation'] ?? null ),
					'locked'   => true === ( $element['locked'] ?? false ),
				),
				self::type_properties( $type, $element )
			);
		}

		return array(
			'version'  => self::SCHEMA_VERSION,
			'page'     => array( 'orientation' => $orientation ),
			'elements' => $clean,
		);
	}

	/**
	 * The layout as the JSON the template stores.
	 *
	 * @param array<string, mixed> $layout A validated layout.
	 */
	public static function to_json( array $layout ): string {
		return (string) wp_json_encode( $layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	/**
	 * Store a template's layout JSON. `update_post_meta()` unslashes what it
	 * is given, and a layout carries backslashes — every quote inside an
	 * HTML block or a font stack is `\"` in JSON — so the value is slashed
	 * first, or the stored JSON stops parsing (#2586).
	 *
	 * @param int    $template_id Template post id.
	 * @param string $json        Layout JSON.
	 * @return void
	 */
	public static function store_json( int $template_id, string $json ): void {
		update_post_meta( $template_id, self::META_LAYOUT, wp_slash( $json ) );
	}

	/**
	 * The HTML a validated layout prints as. Tokens stay in place for the
	 * certificate renderer, which resolves and escapes them.
	 *
	 * @param array{version: int, page: array{orientation: string}, elements: array<int, array<string, mixed>>} $layout A validated layout.
	 */
	public static function render( array $layout ): string {
		$size = self::page_size( $layout['page']['orientation'] );
		$html = sprintf(
			'<div class="ffc-visual-page" style="position:relative;width:%dpx;height:%dpx;overflow:hidden">',
			$size['width'],
			$size['height']
		);
		foreach ( $layout['elements'] as $index => $element ) {
			$html .= "\n" . self::render_element( $element, $index + 1 );
		}
		$html .= "\n</div>";

		return HtmlPolicy::kses_layout( $html );
	}

	/**
	 * One element.
	 *
	 * @param array<string, mixed> $element A validated element.
	 * @param int                  $layer   Its stacking position, from 1.
	 */
	private static function render_element( array $element, int $layer ): string {
		$style = array(
			'position:absolute',
			'left:' . self::px( $element['x'] ),
			'top:' . self::px( $element['y'] ),
			'width:' . self::px( $element['w'] ),
			'height:' . self::px( $element['h'] ),
			'z-index:' . $layer,
		);
		if ( 0.0 !== (float) $element['rotation'] ) {
			$style[] = 'transform:rotate(' . self::num( $element['rotation'] ) . 'deg)';
		}
		$classes = array( 'ffc-visual-el', 'ffc-visual-' . $element['type'] );

		switch ( $element['type'] ) {
			case 'text':
			case 'field':
				$style = array_merge( $style, self::text_style( $element ) );
				if ( 'line' === $element['fit'] ) {
					$classes[] = 'ffc-fit';
				} elseif ( 'box' === $element['fit'] ) {
					$classes[] = 'ffc-fit-box';
				}
				$inner = 'field' === $element['type']
					? $element['token']
					: nl2br( esc_html( $element['text'] ), false );
				break;
			case 'image':
				$style[] = 'overflow:hidden';
				$inner   = self::image_inner( $element );
				if ( 'cover' !== $element['fit'] || '' === $inner ) {
					// Contain: the browser keeps the ratio within a maximum size,
					// which the capture draws as it is laid out.
					$style = array_merge( $style, array( 'display:flex', 'align-items:center', 'justify-content:center' ) );
				}
				break;
			case 'qr':
				$size  = (int) floor( min( $element['w'], $element['h'] ) );
				$style = array_merge( $style, array( 'display:flex', 'align-items:center', 'justify-content:center' ) );
				$inner = sprintf( '{{qr_code:size=%d:margin=%d}}', $size, (int) $element['margin'] );
				break;
			case 'frame':
				// The border is drawn inside the box: the sanitiser drops
				// box-sizing, so the width is reduced by hand.
				$border   = (float) $element['border_width'];
				$style[3] = 'width:' . self::px( max( 0, $element['w'] - 2 * $border ) );
				$style[4] = 'height:' . self::px( max( 0, $element['h'] - 2 * $border ) );
				$style[]  = sprintf( 'border:%s %s %s', self::px( $border ), $element['border_style'], $element['border_color'] );
				if ( $element['radius'] > 0 ) {
					$style[] = 'border-radius:' . self::px( $element['radius'] );
				}
				if ( '' !== $element['fill'] ) {
					$style[] = 'background-color:' . $element['fill'];
				}
				if ( ! empty( $element['gradient'] ) ) {
					$style[] = 'background-image:' . self::gradient_css( $element['gradient'] );
				}
				$inner = '';
				break;
			default:
				$style[] = 'overflow:hidden';
				$inner   = $element['html'];
		}

		return sprintf(
			'<div class="%s" style="%s">%s</div>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( implode( ';', $style ) ),
			$inner
		);
	}

	/**
	 * The image of an image element: a library file, or the signer's signature.
	 *
	 * @param array<string, mixed> $element A validated image element.
	 */
	private static function image_inner( array $element ): string {
		if ( self::SIGNATURE_TOKEN === $element['token'] ) {
			return sprintf(
				'<img src="%s" alt="" style="max-width:%s;max-height:%s">',
				self::SIGNATURE_TOKEN,
				self::px( $element['w'] ),
				self::px( $element['h'] )
			);
		}
		$asset = (string) ( $element['asset'] ?? '' );
		if ( $element['attachment_id'] <= 0 && '' !== $asset ) {
			// An image shipped with the plugin (#2606), such as the default
			// models' signatures.
			$url  = FFC_PLUGIN_URL . $asset;
			$size = @getimagesize( FFC_PLUGIN_DIR . $asset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An SVG has no raster size; the image then draws contained.
			$meta = is_array( $size ) ? array(
				'width'  => $size[0],
				'height' => $size[1],
			) : null;
		} else {
			$url  = $element['attachment_id'] > 0 ? wp_get_attachment_url( $element['attachment_id'] ) : false;
			$meta = is_string( $url ) && '' !== $url ? wp_get_attachment_metadata( $element['attachment_id'] ) : null;
		}
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$width  = is_array( $meta ) ? (float) ( $meta['width'] ?? 0 ) : 0.0;
		$height = is_array( $meta ) ? (float) ( $meta['height'] ?? 0 ) : 0.0;

		if ( 'cover' === $element['fit'] && $width > 0 && $height > 0 ) {
			// Cover as an explicit size and position: the capture ignores
			// object-fit.
			$scale = max( $element['w'] / $width, $element['h'] / $height );
			$draw  = array( $width * $scale, $height * $scale );
			return sprintf(
				'<img src="%s" alt="" style="position:absolute;left:%s;top:%s;width:%s;height:%s;max-width:none">',
				esc_url( $url ),
				self::px( ( $element['w'] - $draw[0] ) / 2 ),
				self::px( ( $element['h'] - $draw[1] ) / 2 ),
				self::px( $draw[0] ),
				self::px( $draw[1] )
			);
		}
		return sprintf(
			'<img src="%s" alt="" style="max-width:%s;max-height:%s">',
			esc_url( $url ),
			self::px( $element['w'] ),
			self::px( $element['h'] )
		);
	}

	/**
	 * The text declarations of a text or field element.
	 *
	 * @param array<string, mixed> $element A validated element.
	 * @return array<int, string>
	 */
	private static function text_style( array $element ): array {
		$style = array(
			'font-size:' . self::px( $element['font_size'] ),
			'font-weight:' . $element['font_weight'],
			'color:' . $element['color'],
			'text-align:' . $element['align'],
			'line-height:' . self::num( $element['line_height'] ),
		);
		if ( '' !== $element['font_family'] ) {
			array_unshift( $style, 'font-family:' . $element['font_family'] );
		}
		if ( 'normal' !== $element['font_style'] ) {
			$style[] = 'font-style:' . $element['font_style'];
		}
		if ( 0.0 !== (float) $element['letter_spacing'] ) {
			$style[] = 'letter-spacing:' . self::px( $element['letter_spacing'] );
		}
		if ( 'none' !== $element['text_transform'] ) {
			$style[] = 'text-transform:' . $element['text_transform'];
		}
		return $style;
	}

	/**
	 * The properties a type carries, each cleaned.
	 *
	 * @param string               $type    Element type.
	 * @param array<string, mixed> $element Raw element.
	 * @return array<string, mixed>
	 */
	private static function type_properties( string $type, array $element ): array {
		switch ( $type ) {
			case 'text':
				return array( 'text' => self::text( $element['text'] ?? '' ) ) + self::text_properties( $element );
			case 'field':
				$token = is_string( $element['token'] ?? null ) ? trim( $element['token'] ) : '';
				return array( 'token' => 1 === preg_match( self::FIELD_TOKEN, $token ) ? $token : '{{name}}' ) + self::text_properties( $element );
			case 'image':
				return array(
					'attachment_id' => max( 0, (int) ( is_numeric( $element['attachment_id'] ?? null ) ? $element['attachment_id'] : 0 ) ),
					'token'         => self::SIGNATURE_TOKEN === ( $element['token'] ?? '' ) ? self::SIGNATURE_TOKEN : '',
					'asset'         => self::asset( $element['asset'] ?? '' ),
					'fit'           => self::enum( 'image_fit', $element['fit'] ?? null ),
				);
			case 'qr':
				return array( 'margin' => (int) self::bounded( 'qr_margin', $element['margin'] ?? null ) );
			case 'frame':
				return array(
					'border_width' => self::bounded( 'border_width', $element['border_width'] ?? null ),
					'border_style' => self::enum( 'border_style', $element['border_style'] ?? null ),
					'border_color' => self::color( $element['border_color'] ?? null, '#000000' ),
					'radius'       => self::bounded( 'radius', $element['radius'] ?? null ),
					'fill'         => self::color( $element['fill'] ?? null, '' ),
					'gradient'     => self::gradient( $element['gradient'] ?? null ),
				);
			default:
				$html = is_string( $element['html'] ?? null ) ? substr( $element['html'], 0, self::MAX_HTML ) : '';
				return array( 'html' => HtmlPolicy::kses_layout( $html ) );
		}
	}

	/**
	 * The text properties of a text or field element.
	 *
	 * @param array<string, mixed> $element Raw element.
	 * @return array<string, mixed>
	 */
	private static function text_properties( array $element ): array {
		$weight = $element['font_weight'] ?? 400;
		$weight = is_numeric( $weight ) ? (int) $weight : ( 'bold' === $weight ? 700 : 400 );
		$weight = (int) min( 900, max( 100, (int) round( $weight / 100 ) * 100 ) );

		return array(
			'font_family'    => self::font_family( $element['font_family'] ?? '' ),
			'font_size'      => self::bounded( 'font_size', $element['font_size'] ?? null ),
			'font_weight'    => $weight,
			'font_style'     => self::enum( 'font_style', $element['font_style'] ?? null ),
			'color'          => self::color( $element['color'] ?? null, '#000000' ),
			'align'          => self::enum( 'align', $element['align'] ?? null ),
			'letter_spacing' => self::bounded( 'letter_spacing', $element['letter_spacing'] ?? null ),
			'line_height'    => self::bounded( 'line_height', $element['line_height'] ?? null ),
			'text_transform' => self::enum( 'text_transform', $element['text_transform'] ?? null ),
			'fit'            => self::enum( 'fit', $element['fit'] ?? null ),
		);
	}

	/**
	 * A font stack of family names only: letters, digits, spaces, hyphens,
	 * commas and quotes, so it can never close the declaration.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function font_family( $value ): string {
		$value = is_string( $value ) ? $value : '';
		$value = (string) preg_replace( '/[^\p{L}\p{N} ,\'"_-]/u', '', $value );
		return trim( substr( (string) preg_replace( '/\s+/', ' ', $value ), 0, 200 ) );
	}

	/**
	 * An image shipped with the plugin, as a path under `assets/img/` that
	 * names a file the plugin has (#2606); '' for anything else.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function asset( $value ): string {
		$value = is_string( $value ) ? $value : '';
		if ( 1 !== preg_match( '#^assets/img/(?:[a-z0-9_-]+/)*[a-z0-9_.-]+\.(?:png|jpe?g|gif|svg|webp)$#i', $value ) || str_contains( $value, '..' ) ) {
			return '';
		}
		return is_file( FFC_PLUGIN_DIR . $value ) ? $value : '';
	}

	/**
	 * Literal text: no control characters but the line break.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function text( $value ): string {
		$value = is_string( $value ) ? $value : '';
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = (string) preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/u', '', $value );
		return mb_substr( $value, 0, self::MAX_TEXT );
	}

	/**
	 * A stable element id: the given one when it is clean and unused, else one
	 * made from its position.
	 *
	 * @param mixed               $value Raw id.
	 * @param int                 $index Element position.
	 * @param array<string, bool> $used  Ids already taken.
	 */
	private static function element_id( $value, int $index, array $used ): string {
		$id = is_string( $value ) ? substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ), 0, 40 ) : '';
		if ( '' === $id || isset( $used[ $id ] ) ) {
			$id   = 'el-' . ( $index + 1 );
			$step = 1;
			while ( isset( $used[ $id ] ) ) {
				$id = 'el-' . ( $index + 1 ) . '-' . ( ++$step );
			}
		}
		return $id;
	}

	/**
	 * An enumerated value, or its default.
	 *
	 * @param string $name  Property name in ENUMS.
	 * @param mixed  $value Raw value.
	 */
	private static function enum( string $name, $value ): string {
		return is_string( $value ) && in_array( $value, self::ENUMS[ $name ], true ) ? $value : self::ENUMS[ $name ][0];
	}

	/**
	 * A bounded number, or its default.
	 *
	 * @param string $name  Property name in BOUNDS.
	 * @param mixed  $value Raw value.
	 */
	private static function bounded( string $name, $value ): float {
		list( $min, $max, $default ) = self::BOUNDS[ $name ];
		return self::number( $value, $min, $max, $default );
	}

	/**
	 * A finite number clamped to a range, rounded to hundredths.
	 *
	 * @param mixed     $value   Raw value.
	 * @param int|float $min     Minimum.
	 * @param int|float $max     Maximum.
	 * @param int|float $default Value for anything that is not a finite number.
	 */
	private static function number( $value, $min, $max, $default ): float {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
			return (float) $default;
		}
		$value = (float) $value;
		if ( ! is_finite( $value ) ) {
			return (float) $default;
		}
		return round( min( (float) $max, max( (float) $min, $value ) ), 2 );
	}

	/**
	 * A frame's gradient: an angle and two to five colours, each at a
	 * position along it; none when the value is not one (#2609).
	 *
	 * @param mixed $value Raw value.
	 * @return array{angle: float, stops: list<array{color: string, at: float}>}|array{}
	 */
	private static function gradient( $value ): array {
		if ( ! is_array( $value ) || ! is_array( $value['stops'] ?? null ) ) {
			return array();
		}
		$stops = array();
		foreach ( $value['stops'] as $stop ) {
			// A stop may let the ground through: a hard-edged half, such as a
			// CSS triangle read from an HTML template (#2618).
			$color = is_array( $stop ) && 'transparent' === ( $stop['color'] ?? null )
				? 'transparent'
				: ( is_array( $stop ) ? self::color( $stop['color'] ?? null, '' ) : '' );
			if ( '' === $color ) {
				continue;
			}
			$stops[] = array(
				'color' => $color,
				'at'    => self::bounded( 'gradient_at', $stop['at'] ?? null ),
			);
		}
		list( $min, $max ) = self::GRADIENT_STOPS;
		if ( count( $stops ) < $min ) {
			return array();
		}
		return array(
			'angle' => self::bounded( 'gradient_angle', $value['angle'] ?? null ),
			'stops' => array_slice( $stops, 0, $max ),
		);
	}

	/**
	 * The CSS of a validated gradient.
	 *
	 * @param array{angle: float, stops: list<array{color: string, at: float}>} $gradient Gradient.
	 */
	private static function gradient_css( array $gradient ): string {
		$stops = array_map(
			static fn( array $stop ): string => $stop['color'] . ' ' . self::num( $stop['at'] ) . '%',
			$gradient['stops']
		);
		return 'linear-gradient(' . self::num( $gradient['angle'] ) . 'deg,' . implode( ',', $stops ) . ')';
	}

	/**
	 * A `#rrggbb` colour, or the fallback.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Fallback, '' for none.
	 */
	private static function color( $value, string $fallback ): string {
		$color = is_string( $value ) ? (string) sanitize_hex_color( $value ) : '';
		return '' !== $color ? strtolower( $color ) : $fallback;
	}

	/**
	 * A length in pixels.
	 *
	 * @param int|float $value Value.
	 */
	private static function px( $value ): string {
		return self::num( $value ) . 'px';
	}

	/**
	 * A number without trailing zeros.
	 *
	 * @param int|float $value Value.
	 */
	private static function num( $value ): string {
		$text = rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
		return '-0' === $text ? '0' : $text;
	}
}
