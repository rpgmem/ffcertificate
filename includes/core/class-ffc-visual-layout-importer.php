<?php
/**
 * A visual layout read from an HTML certificate template (#2587).
 *
 * @package FreeFormCertificate\Core
 * @since 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads an HTML template into a visual layout, and says what it did.
 *
 * The page is the template's single outer element when it declares its
 * width and height in pixels. Each child the page positions absolutely in
 * pixels, with a size, becomes a box:
 *
 * - nothing but a border or a fill: a frame;
 * - one image: an image (a Media Library file, or the signer's signature);
 * - one `{{qr_code}}` tag: a QR code;
 * - text alone, through a single chain of elements: a data field when the
 *   text is one tag, otherwise a text.
 *
 * Every other child becomes an HTML block, with the page's own text style
 * carried in so it reads the same outside the page. A child with no fixed
 * size runs to the page's edge; the children in the page's flow share one
 * block the size of the page, at the back. A box keeps the declarations the
 * visual vocabulary has; the others are dropped, and the report names each.
 */
class VisualLayoutImporter {

	/** Report status: became a typed element. */
	public const CONVERTED = 'converted';

	/** Report status: kept as an HTML block. */
	public const KEPT = 'kept';

	/** Report status: dropped. */
	public const DROPPED = 'dropped';

	/** Declarations that place a box; read for geometry, never reported. */
	private const GEOMETRY = array( 'position', 'left', 'top', 'right', 'bottom', 'width', 'height', 'z-index', 'box-sizing', 'transform', 'overflow' );

	/** Text declarations the visual text element carries. */
	private const TEXT = array( 'font-family', 'font-size', 'font-weight', 'font-style', 'color', 'text-align', 'letter-spacing', 'line-height', 'text-transform' );

	/** Elements a text may pass through on its way to its words. */
	private const TEXT_WRAPPERS = array( 'div', 'p', 'span', 'strong', 'b', 'em', 'i', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * The layout an HTML template reads as, the report, and the page colour.
	 *
	 * @param string $html Template HTML.
	 * @return array{layout: array{version: int, page: array{orientation: string}, elements: array<int, array<string, mixed>>}, report: list<array{status: string, label: string}>, bg_color: string}
	 */
	public static function from_html( string $html ): array {
		$state = array(
			'elements' => array(),
			'report'   => array(),
			'bg_color' => '',
		);

		$doc  = self::parse( $html );
		$top  = $doc ? self::element_children( $doc->documentElement ) : array();
		$root = 1 === count( $top ) ? $top[0] : null;

		$root_style = $root ? self::style( $root ) : array();
		$width      = $root ? self::px( $root_style['width'] ?? '' ) : null;
		$height     = $root ? self::px( $root_style['height'] ?? '' ) : null;

		if ( ! $root || null === $width || null === $height ) {
			// No page to measure against: the whole template is one block.
			$size                = VisualLayout::page_size( 'landscape' );
			$state['elements'][] = self::html_element( trim( $html ), 0, 0, $size['width'], $size['height'], '' );
			$state['report'][]   = self::entry( self::KEPT, __( 'The whole template, which has no page with a width and height in pixels', 'ffcertificate' ) );
			return self::finish( $state, 'landscape' );
		}

		$orientation = $width >= $height ? 'landscape' : 'portrait';
		$page        = VisualLayout::page_size( $orientation );
		$inherited   = self::declarations( $root_style, self::TEXT );

		$fill = self::color( $root_style['background-color'] ?? ( $root_style['background'] ?? '' ) );
		if ( '' !== $fill ) {
			$state['bg_color'] = $fill;
			$state['report'][] = self::entry( self::CONVERTED, __( 'The page colour, as the template\'s page colour', 'ffcertificate' ) );
		}

		$flow  = '';
		$boxes = array();
		foreach ( $root->childNodes as $index => $node ) {
			if ( ! $node instanceof \DOMElement ) {
				$flow .= $node instanceof \DOMText ? $node->wholeText : '';
				continue;
			}
			$style = self::style( $node );
			if ( 'absolute' !== strtolower( trim( $style['position'] ?? '' ) ) ) {
				$flow             .= self::outer_html( $node );
				$state['report'][] = self::entry( self::KEPT, self::describe( $node ) );
				continue;
			}
			$boxes[] = array(
				'node'  => $node,
				'style' => $style,
				'z'     => (int) ( $style['z-index'] ?? 0 ),
				'order' => $index,
			);
		}

		if ( '' !== trim( $flow ) ) {
			$state['elements'][] = self::html_element( $flow, 0, 0, $page['width'], $page['height'], $inherited );
		}

		// Later children and higher z-index draw on top, as in the source.
		usort(
			$boxes,
			static fn( array $a, array $b ): int => array( $a['z'], $a['order'] ) <=> array( $b['z'], $b['order'] )
		);
		foreach ( $boxes as $box ) {
			self::read_box( $box['node'], $box['style'], $page, $inherited, $state );
		}

		return self::finish( $state, $orientation );
	}

	/**
	 * Read one absolutely positioned child into the state.
	 *
	 * @param \DOMElement                                                                                                             $node      The child.
	 * @param array<string, string>                                                                                                   $style     Its declarations.
	 * @param array{width: int, height: int}                                                                                          $page      Page size.
	 * @param string                                                                                                                  $inherited The page's text declarations.
	 * @param array{elements: array<int, array<string, mixed>>, report: list<array{status: string, label: string}>, bg_color: string} $state State.
	 * @return void
	 */
	private static function read_box( \DOMElement $node, array $style, array $page, string $inherited, array &$state ): void {
		$left   = self::px( $style['left'] ?? '' );
		$top    = self::px( $style['top'] ?? '' );
		$right  = self::px( $style['right'] ?? '' );
		$bottom = self::px( $style['bottom'] ?? '' );
		$x      = $left ?? ( null !== $right && null !== self::px( $style['width'] ?? '' ) ? $page['width'] - $right - (float) self::px( $style['width'] ) : 0.0 );
		$y      = $top ?? ( null !== $bottom && null !== self::px( $style['height'] ?? '' ) ? $page['height'] - $bottom - (float) self::px( $style['height'] ) : 0.0 );
		$w      = self::px( $style['width'] ?? '' ) ?? ( null !== $right ? $page['width'] - $right - $x : null );
		$h      = self::px( $style['height'] ?? '' ) ?? ( null !== $bottom ? $page['height'] - $bottom - $y : null );

		$rotation = 0.0;
		if ( 1 === preg_match( '/rotate\(\s*(-?[\d.]+)deg\s*\)/i', $style['transform'] ?? '', $m ) ) {
			$rotation = (float) $m[1];
		}

		if ( null === $w || null === $h ) {
			// No fixed size: the block runs to the page's edges.
			$element             = self::html_element(
				self::outer_html( $node, true ),
				$x,
				$y,
				$w ?? max( 1, $page['width'] - $x ),
				$h ?? max( 1, $page['height'] - $y ),
				$inherited
			);
			$state['elements'][] = $element;
			$state['report'][]   = self::entry( self::KEPT, self::describe( $node ) );
			return;
		}

		$geometry = array(
			'x'        => round( $x, 2 ),
			'y'        => round( $y, 2 ),
			'w'        => round( max( 1, $w ), 2 ),
			'h'        => round( max( 1, $h ), 2 ),
			'rotation' => $rotation,
		);

		// An empty box that draws nothing leaves nothing to place.
		$empty = '' === trim( $node->textContent ) && array() === self::element_children( $node ) && 'img' !== strtolower( $node->tagName );
		$typed = self::typed( $node, $style, $inherited, $state );
		if ( null === $typed && $empty ) {
			$state['report'][] = self::entry( self::DROPPED, __( 'An empty box with no border or fill', 'ffcertificate' ) );
			return;
		}
		if ( null !== $typed ) {
			$state['elements'][] = $geometry + $typed;
			$state['report'][]   = self::entry( self::CONVERTED, self::describe( $node ) );
			return;
		}

		$state['elements'][] = $geometry + array(
			'type' => 'html',
			'html' => self::wrap( self::outer_html( $node, true ), $inherited ),
		);
		$state['report'][]   = self::entry( self::KEPT, self::describe( $node ) );
	}

	/**
	 * The typed properties of a box, or null when it is not one of the
	 * shapes a visual element draws.
	 *
	 * @param \DOMElement                                                                                                             $node      The box.
	 * @param array<string, string>                                                                                                   $style     Its declarations.
	 * @param string                                                                                                                  $inherited The page's text declarations.
	 * @param array{elements: array<int, array<string, mixed>>, report: list<array{status: string, label: string}>, bg_color: string} $state State, for the report.
	 * @return array<string, mixed>|null
	 */
	private static function typed( \DOMElement $node, array $style, string $inherited, array &$state ): ?array {
		$text = trim( $node->textContent );

		// A frame: nothing inside, a border or a fill on the box.
		if ( '' === $text && array() === self::element_children( $node ) && 'img' !== strtolower( $node->tagName ) ) {
			return self::frame( $style, $state );
		}

		// An image: the box is one, or holds one alone.
		$image = 'img' === strtolower( $node->tagName ) ? $node : self::only_image( $node );
		if ( null !== $image && '' === $text ) {
			$src = trim( $image->getAttribute( 'src' ) );
			if ( '{{signer:signature}}' === $src ) {
				return array(
					'type'  => 'image',
					'token' => $src,
					'fit'   => 'contain',
				);
			}
			$attachment = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $src ) : 0;
			if ( $attachment > 0 ) {
				return array(
					'type'          => 'image',
					'attachment_id' => $attachment,
					'fit'           => 'contain',
				);
			}
			return null;
		}

		// A QR code: the validation tag alone.
		if ( 1 === preg_match( '/^\{\{qr_code((?::[a-z]+=[^{}:]*)*)\}\}$/', $text, $m ) && self::only_text( $node ) ) {
			preg_match( '/:margin=(\d+)/', $m[1], $margin );
			return array(
				'type'   => 'qr',
				'margin' => isset( $margin[1] ) ? (int) $margin[1] : 0,
			);
		}

		// Text, through a single chain of wrappers.
		$chain = self::text_chain( $node );
		if ( null === $chain || '' === $text ) {
			return null;
		}
		$merged = array();
		foreach ( $chain as $link ) {
			$merged = array_merge( $merged, self::style( $link ) );
			$tag    = strtolower( $link->tagName );
			if ( in_array( $tag, array( 'strong', 'b' ), true ) && ! isset( $merged['font-weight'] ) ) {
				$merged['font-weight'] = '700';
			}
			if ( in_array( $tag, array( 'em', 'i' ), true ) && ! isset( $merged['font-style'] ) ) {
				$merged['font-style'] = 'italic';
			}
		}
		$properties = self::text_properties( self::style_from( $inherited ), $merged, $state, $node );
		$fit        = 'none';
		foreach ( $chain as $link ) {
			$classes = ' ' . $link->getAttribute( 'class' ) . ' ';
			if ( false !== strpos( $classes, ' ffc-fit-box ' ) ) {
				$fit = 'box';
			} elseif ( false !== strpos( $classes, ' ffc-fit ' ) ) {
				$fit = 'line';
			}
		}
		$properties['fit'] = $fit;
		$words             = self::text_of( $chain[ count( $chain ) - 1 ] );

		if ( 1 === preg_match( '/^\{\{[a-z0-9_]+(?::[a-z0-9_]+)*\}\}$/', $words ) ) {
			return array(
				'type'  => 'field',
				'token' => $words,
			) + $properties;
		}
		return array(
			'type' => 'text',
			'text' => $words,
		) + $properties;
	}

	/**
	 * A frame's properties from its box's border and fill.
	 *
	 * @param array<string, string>                                                                                                   $style Declarations.
	 * @param array{elements: array<int, array<string, mixed>>, report: list<array{status: string, label: string}>, bg_color: string} $state State, for the report.
	 * @return array<string, mixed>|null Null when the box draws nothing.
	 */
	private static function frame( array $style, array &$state ): ?array {
		$border = array(
			'width' => null,
			'style' => 'solid',
			'color' => '',
		);
		if ( isset( $style['border'] ) ) {
			$parts = preg_split( '/\s+(?![^(]*\))/', trim( $style['border'] ) );
			foreach ( false !== $parts ? $parts : array() as $part ) {
				if ( null !== self::px( $part ) ) {
					$border['width'] = self::px( $part );
				} elseif ( in_array( strtolower( $part ), array( 'solid', 'dashed', 'dotted', 'double' ), true ) ) {
					$border['style'] = strtolower( $part );
				} elseif ( '' !== self::color( $part ) ) {
					$border['color'] = self::color( $part );
				}
			}
		}
		if ( isset( $style['border-width'] ) ) {
			$border['width'] = self::px( $style['border-width'] );
		}
		if ( isset( $style['border-style'] ) ) {
			$border['style'] = strtolower( trim( $style['border-style'] ) );
		}
		if ( isset( $style['border-color'] ) ) {
			$border['color'] = self::color( $style['border-color'] );
		}
		$fill = self::color( $style['background-color'] ?? ( $style['background'] ?? '' ) );

		if ( ( null === $border['width'] || 0.0 === $border['width'] ) && '' === $fill ) {
			return null;
		}

		$known = array( 'border', 'border-width', 'border-style', 'border-color', 'border-radius', 'background', 'background-color' );
		self::report_dropped( $style, $known, $state );

		return array(
			'type'         => 'frame',
			'border_width' => (float) ( $border['width'] ?? 0 ),
			'border_style' => $border['style'],
			'border_color' => '' !== $border['color'] ? $border['color'] : '#000000',
			'radius'       => (float) ( self::px( $style['border-radius'] ?? '' ) ?? 0 ),
			'fill'         => $fill,
		);
	}

	/**
	 * A text element's properties: the page's style under the box's own.
	 *
	 * @param array<string, string>                                                                                                   $inherited The page's text declarations.
	 * @param array<string, string>                                                                                                   $own       The box's chain, merged.
	 * @param array{elements: array<int, array<string, mixed>>, report: list<array{status: string, label: string}>, bg_color: string} $state State, for the report.
	 * @param \DOMElement                                                                                                             $node      The box, to name in the report.
	 * @return array<string, mixed>
	 */
	private static function text_properties( array $inherited, array $own, array &$state, \DOMElement $node ): array {
		$style = array_merge( $inherited, $own );
		self::report_dropped( $own, self::TEXT, $state );

		$size = self::font_px( $style['font-size'] ?? '' ) ?? 16.0;

		$weight = strtolower( trim( $style['font-weight'] ?? '400' ) );
		$weight = 'bold' === $weight ? 700 : ( 'normal' === $weight ? 400 : (int) $weight );

		$line = trim( $style['line-height'] ?? '' );
		if ( is_numeric( $line ) ) {
			$line = (float) $line;
		} elseif ( null !== self::px( $line ) ) {
			$line = round( (float) self::px( $line ) / $size, 3 );
		} else {
			$line = 1.2;
		}

		$spacing = trim( $style['letter-spacing'] ?? '' );
		if ( 1 === preg_match( '/^(-?[\d.]+)em$/', $spacing, $m ) ) {
			$spacing = (float) $m[1] * $size;
		} else {
			$spacing = (float) ( self::px( $spacing ) ?? 0 );
		}

		$color = self::color( $style['color'] ?? '' );

		return array(
			'font_family'    => trim( $style['font-family'] ?? '' ),
			'font_size'      => round( $size, 2 ),
			'font_weight'    => $weight > 0 ? $weight : 400,
			'font_style'     => 'italic' === strtolower( trim( $style['font-style'] ?? '' ) ) ? 'italic' : 'normal',
			'color'          => '' !== $color ? $color : '#000000',
			'align'          => strtolower( trim( $style['text-align'] ?? 'left' ) ),
			'letter_spacing' => round( $spacing, 2 ),
			'line_height'    => $line,
			'text_transform' => strtolower( trim( $style['text-transform'] ?? 'none' ) ),
		);
	}

	/**
	 * Note each declaration outside the geometry and the known ones.
	 *
	 * @param array<string, string>                                                                                                   $style Declarations.
	 * @param array<int, string>                                                                                                      $known Properties the element carries.
	 * @param array{elements: array<int, array<string, mixed>>, report: list<array{status: string, label: string}>, bg_color: string} $state State, for the report.
	 * @return void
	 */
	private static function report_dropped( array $style, array $known, array &$state ): void {
		foreach ( $style as $property => $value ) {
			if ( in_array( $property, self::GEOMETRY, true ) || in_array( $property, $known, true ) ) {
				continue;
			}
			$state['report'][] = self::entry(
				self::DROPPED,
				/* translators: 1: a CSS property, 2: its value */
				sprintf( __( 'The rule %1$s: %2$s, which a visual element does not draw', 'ffcertificate' ), $property, $value )
			);
		}
	}

	/**
	 * Validate the elements into a layout.
	 *
	 * @param array{elements: array<int, array<string, mixed>>, report: list<array{status: string, label: string}>, bg_color: string} $state       State.
	 * @param string                                                                                                                  $orientation Page orientation.
	 * @return array{layout: array{version: int, page: array{orientation: string}, elements: array<int, array<string, mixed>>}, report: list<array{status: string, label: string}>, bg_color: string}
	 */
	private static function finish( array $state, string $orientation ): array {
		$layout = VisualLayout::validate(
			(string) wp_json_encode(
				array(
					'version'  => VisualLayout::SCHEMA_VERSION,
					'page'     => array( 'orientation' => $orientation ),
					'elements' => array_slice( $state['elements'], 0, VisualLayout::MAX_ELEMENTS ),
				)
			)
		);
		if ( is_wp_error( $layout ) ) {
			$layout = VisualLayout::blank();
		}
		return array(
			'layout'   => $layout,
			'report'   => $state['report'],
			'bg_color' => $state['bg_color'],
		);
	}

	/* ---- DOM helpers ---- */

	/**
	 * The template as a document, or null when nothing parses.
	 *
	 * @param string $html Template HTML.
	 */
	private static function parse( string $html ): ?\DOMDocument {
		if ( '' === trim( $html ) ) {
			return null;
		}
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// A wrapper element gives the fragment one root; the XML prolog makes
		// libxml read it as UTF-8.
		$ok = $doc->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $ok || ! $doc->documentElement instanceof \DOMElement ) {
			return null;
		}
		return $doc;
	}

	/**
	 * An element's element children.
	 *
	 * @param \DOMNode|null $node The element.
	 * @return list<\DOMElement>
	 */
	private static function element_children( ?\DOMNode $node ): array {
		$out = array();
		if ( null === $node ) {
			return $out;
		}
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	/**
	 * An element's inline declarations, by lowercase property.
	 *
	 * @param \DOMElement $node The element.
	 * @return array<string, string>
	 */
	private static function style( \DOMElement $node ): array {
		return self::style_from( $node->getAttribute( 'style' ) );
	}

	/**
	 * Declarations from a style attribute.
	 *
	 * @param string $css Inline CSS.
	 * @return array<string, string>
	 */
	private static function style_from( string $css ): array {
		$out          = array();
		$declarations = preg_split( '/;(?![^(]*\))/', $css );
		foreach ( false !== $declarations ? $declarations : array() as $declaration ) {
			$pair = explode( ':', $declaration, 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			$property = strtolower( trim( $pair[0] ) );
			$value    = trim( preg_replace( '/\s*!important\s*$/i', '', $pair[1] ) ?? '' );
			if ( '' !== $property && '' !== $value ) {
				$out[ $property ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Declarations as CSS text, kept to some properties or all but some.
	 *
	 * @param array<string, string> $style   Declarations.
	 * @param array<int, string>    $only    Keep only these (empty for all).
	 * @param array<int, string>    $without Leave these out.
	 */
	private static function declarations( array $style, array $only, array $without = array() ): string {
		$out = '';
		foreach ( $style as $property => $value ) {
			if ( ( array() !== $only && ! in_array( $property, $only, true ) ) || in_array( $property, $without, true ) ) {
				continue;
			}
			$out .= $property . ':' . $value . ';';
		}
		return $out;
	}

	/**
	 * The image a box holds alone, through single wrappers.
	 *
	 * @param \DOMElement $node The box.
	 */
	private static function only_image( \DOMElement $node ): ?\DOMElement {
		$children = self::element_children( $node );
		if ( 1 !== count( $children ) || '' !== trim( $node->textContent ) ) {
			return null;
		}
		$child = $children[0];
		if ( 'img' === strtolower( $child->tagName ) ) {
			return $child;
		}
		return in_array( strtolower( $child->tagName ), self::TEXT_WRAPPERS, true ) ? self::only_image( $child ) : null;
	}

	/**
	 * The chain of single wrappers from a box to its words, or null when
	 * the content branches or holds anything but text and line breaks.
	 *
	 * @param \DOMElement $node The box.
	 * @return list<\DOMElement>|null
	 */
	private static function text_chain( \DOMElement $node ): ?array {
		$chain = array( $node );
		while ( true ) {
			$current  = end( $chain );
			$children = array_values(
				array_filter( self::element_children( $current ), static fn( \DOMElement $c ): bool => 'br' !== strtolower( $c->tagName ) )
			);
			if ( array() === $children ) {
				return self::only_text( $current ) ? $chain : null;
			}
			if ( 1 !== count( $children ) || ! in_array( strtolower( $children[0]->tagName ), self::TEXT_WRAPPERS, true ) ) {
				return null;
			}
			// Text beside the wrapper means the content branches.
			foreach ( $current->childNodes as $child ) {
				if ( $child instanceof \DOMText && '' !== trim( $child->wholeText ) ) {
					return null;
				}
			}
			$chain[] = $children[0];
		}
	}

	/**
	 * Whether an element holds only text and line breaks.
	 *
	 * @param \DOMElement $node The element.
	 */
	private static function only_text( \DOMElement $node ): bool {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'br' !== strtolower( $child->tagName ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * An element's words, with each line break as a new line and runs of
	 * white space collapsed, as a browser shows them.
	 *
	 * @param \DOMElement $node The element.
	 */
	private static function text_of( \DOMElement $node ): string {
		$lines = array( '' );
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'br' === strtolower( $child->tagName ) ) {
				$lines[] = '';
			} elseif ( $child instanceof \DOMText ) {
				$lines[ count( $lines ) - 1 ] .= $child->wholeText;
			}
		}
		$lines = array_map( static fn( string $line ): string => trim( (string) preg_replace( '/\s+/u', ' ', $line ) ), $lines );
		return implode( "\n", $lines );
	}

	/**
	 * An element's markup, or only its content.
	 *
	 * @param \DOMElement $node        The element.
	 * @param bool        $strip_place Whether to drop its placing declarations.
	 */
	private static function outer_html( \DOMElement $node, bool $strip_place = false ): string {
		if ( $strip_place ) {
			$node = $node->cloneNode( true );
			if ( $node instanceof \DOMElement ) {
				$kept = self::declarations( self::style( $node ), array(), self::GEOMETRY );
				$node->setAttribute( 'style', $kept );
			}
		}
		return (string) $node->ownerDocument?->saveHTML( $node );
	}

	/**
	 * Markup in a box that fills its element, carrying a style.
	 *
	 * @param string $html  Markup.
	 * @param string $style CSS text.
	 */
	private static function wrap( string $html, string $style ): string {
		return '<div style="' . esc_attr( $style . 'width:100%;height:100%' ) . '">' . $html . '</div>';
	}

	/**
	 * An HTML block element.
	 *
	 * @param string $html      Markup.
	 * @param float  $x         Left.
	 * @param float  $y         Top.
	 * @param float  $w         Width.
	 * @param float  $h         Height.
	 * @param string $inherited The page's text declarations.
	 * @return array<string, mixed>
	 */
	private static function html_element( string $html, float $x, float $y, float $w, float $h, string $inherited ): array {
		return array(
			'type'     => 'html',
			'x'        => round( $x, 2 ),
			'y'        => round( $y, 2 ),
			'w'        => round( max( 1, $w ), 2 ),
			'h'        => round( max( 1, $h ), 2 ),
			'rotation' => 0,
			'html'     => '' !== $inherited ? self::wrap( $html, $inherited ) : $html,
		);
	}

	/**
	 * A report line.
	 *
	 * @param string $status CONVERTED, KEPT or DROPPED.
	 * @param string $label  What it was.
	 * @return array{status: string, label: string}
	 */
	private static function entry( string $status, string $label ): array {
		return array(
			'status' => $status,
			'label'  => $label,
		);
	}

	/**
	 * What an element is, for the report: its words, or its kind.
	 *
	 * @param \DOMElement $node The element.
	 */
	private static function describe( \DOMElement $node ): string {
		$words = trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );
		if ( '' !== $words ) {
			$short = function_exists( 'mb_substr' ) && mb_strlen( $words ) > 48 ? mb_substr( $words, 0, 47 ) . '…' : $words;
			/* translators: %s: the first words of an element */
			return sprintf( __( '“%s”', 'ffcertificate' ), $short );
		}
		if ( 'img' === strtolower( $node->tagName ) || null !== self::only_image( $node ) || $node->getElementsByTagName( 'img' )->length > 0 ) {
			return __( 'An image', 'ffcertificate' );
		}
		return __( 'A shape (border or fill)', 'ffcertificate' );
	}

	/* ---- values ---- */

	/**
	 * A length in pixels, or null when it is not one.
	 *
	 * @param string $value CSS length.
	 */
	private static function px( string $value ): ?float {
		$value = trim( $value );
		if ( '0' === $value ) {
			return 0.0;
		}
		return 1 === preg_match( '/^(-?[\d.]+)px$/i', $value, $m ) ? (float) $m[1] : null;
	}

	/**
	 * A font size in pixels, from px or pt.
	 *
	 * @param string $value CSS font size.
	 */
	private static function font_px( string $value ): ?float {
		$value = trim( $value );
		if ( 1 === preg_match( '/^([\d.]+)pt$/i', $value, $m ) ) {
			return round( (float) $m[1] * 4 / 3, 2 );
		}
		return self::px( $value );
	}

	/**
	 * A colour as `#rrggbb`, from hex or rgb(), or '' when it is neither.
	 *
	 * @param string $value CSS colour.
	 */
	private static function color( string $value ): string {
		$value = trim( strtolower( $value ) );
		if ( 1 === preg_match( '/^#([0-9a-f]{3})$/', $value, $m ) ) {
			return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		if ( 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ) {
			return $value;
		}
		if ( 1 === preg_match( '/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*1(?:\.0+)?\s*)?\)$/', $value, $m ) ) {
			return sprintf( '#%02x%02x%02x', min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) );
		}
		return '';
	}
}
