<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Icons;
use FreeFormCertificate\Generators\QrPayload;

/**
 * The plugin's icon registry (#1613, formerly the QR screens' set of #1570):
 * every drawing is self-contained, in the text colour, built from a closed set
 * of SVG elements, and every utility class points at a drawing that exists.
 *
 * @covers \FreeFormCertificate\Core\Icons
 */
class IconsTest extends TestCase {

	/** Elements a drawing may use; anything else (script, style, image, use, foreignObject) is refused. */
	private const ELEMENTS = array( 'path', 'circle', 'rect', 'line', 'polyline' );

	/** Attributes a drawing may use; no event handler, href, style or class. */
	private const ATTRIBUTES = array( 'd', 'cx', 'cy', 'r', 'x', 'y', 'width', 'height', 'rx', 'x1', 'y1', 'x2', 'y2', 'points', 'fill', 'stroke' );

	public function test_every_qr_content_type_has_an_icon(): void {
		foreach ( QrPayload::TYPES as $type ) {
			$this->assertTrue( Icons::has( $type ), $type );
		}
	}

	public function test_every_qr_design_section_and_action_has_an_icon(): void {
		foreach ( array( 'pattern', 'eyes', 'colors', 'logo', 'frame', 'advanced', 'download', 'print' ) as $name ) {
			$this->assertTrue( Icons::has( $name ), $name );
		}
	}

	public function test_an_icon_is_inline_decorative_and_in_the_text_colour(): void {
		$this->assertNotEmpty( Icons::names() );
		foreach ( Icons::names() as $name ) {
			$svg = Icons::svg( $name, 24 );
			$this->assertNotFalse( simplexml_load_string( $svg ), $name . ' is well-formed XML' );
			$this->assertStringContainsString( 'aria-hidden="true"', $svg, $name );
			$this->assertStringContainsString( 'focusable="false"', $svg, $name );
			$this->assertStringContainsString( 'stroke="currentColor"', $svg, $name );
			$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,6}\b|href|url\(/i', $svg, $name . ' carries no colour of its own and fetches nothing' );
		}
	}

	public function test_a_drawing_uses_only_the_allowed_elements_and_attributes(): void {
		foreach ( Icons::names() as $name ) {
			$doc = new \DOMDocument();
			$this->assertTrue( $doc->loadXML( '<g>' . Icons::paths( $name ) . '</g>' ), $name );
			$children = $doc->documentElement->getElementsByTagName( '*' );
			$this->assertGreaterThan( 0, $children->length, $name . ' draws something' );
			foreach ( $children as $node ) {
				$this->assertContains( $node->nodeName, self::ELEMENTS, $name . ' uses <' . $node->nodeName . '>' );
				foreach ( $node->attributes as $attribute ) {
					$this->assertContains( $attribute->nodeName, self::ATTRIBUTES, $name . ' uses ' . $attribute->nodeName . '=' );
				}
				$fill = $node->getAttribute( 'fill' );
				$this->assertContains( $fill, array( '', 'none', 'currentColor' ), $name . ' fills in a colour of its own' );
			}
		}
	}

	public function test_every_utility_class_draws_an_icon_that_exists(): void {
		$this->assertNotEmpty( Icons::classes() );
		foreach ( Icons::classes() as $class => $icon ) {
			$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9-]*$/', $class );
			$this->assertTrue( Icons::has( $icon ), '.ffc-icon-' . $class . ' points at "' . $icon . '"' );
		}
	}

	public function test_a_modifier_never_shadows_a_drawing_class(): void {
		$this->assertContains( 'badge', Icons::modifiers() );
		$this->assertContains( 'tone-warning', Icons::modifiers() );
		$this->assertContains( 'badge-danger', Icons::modifiers() );
		$this->assertNotContains( 'badge-neutral', Icons::modifiers(), 'the plain badge is the neutral one' );
		$this->assertSame( array(), array_intersect( Icons::modifiers(), array_keys( Icons::classes() ) ) );
	}

	public function test_every_tone_reads_palette_tokens_and_has_its_rules(): void {
		$css = Icons::stylesheet();
		$this->assertCount( 6, Icons::tones() );
		foreach ( Icons::tones() as $tone => $tokens ) {
			foreach ( $tokens as $token ) {
				$this->assertMatchesRegularExpression( '/^--ffc-[a-z-]+$/', $token, $tone );
			}
			$this->assertStringContainsString( '.ffc-icon-tone-' . $tone . " {\n    --ffc-icon-tone: var(" . $tokens[0] . ');', $css, $tone );
			if ( 'neutral' !== $tone ) {
				$this->assertStringContainsString( '.ffc-icon-badge.ffc-icon-badge-' . $tone . " {\n    background: var(" . $tokens[1] . ");\n    color: var(" . $tokens[2] . ');', $css, $tone );
			}
		}
		$this->assertSame( '--ffc-warning-text', Icons::tones()['warning'][0], 'the warning signal colour is 3.04:1 on a light card' );
		$this->assertStringContainsString( 'background-color: var(--ffc-icon-tone, currentColor);', $css, 'the mask reads the tone' );
		$this->assertStringContainsString( ".ffc-svg-icon {\n    color: var(--ffc-icon-tone, currentColor);", $css, 'the inline SVG reads the tone' );
		$this->assertStringContainsString( ".ffc-icon-badge {\n    --ffc-icon-tone: currentColor;", $css, 'a badge resets an inherited tone' );
	}

	public function test_forced_colors_keeps_every_icon_and_badge_visible(): void {
		$css = Icons::stylesheet();
		$at  = strpos( $css, '@media (forced-colors: active) {' );
		$this->assertIsInt( $at, 'forced-colors mode replaces backgrounds, which erases a mask fill' );
		$block = substr( $css, (int) $at );
		foreach ( array_keys( Icons::classes() ) as $class ) {
			$this->assertStringContainsString( '.ffc-icon-' . $class . '::before', $block, $class );
		}
		$this->assertStringContainsString( "forced-color-adjust: none;\n        background-color: CanvasText;", $block );
		$this->assertStringContainsString( ".ffc-icon-badge.ffc-icon-badge-danger {\n        border: 1px solid CanvasText;", $block, 'a badge loses its ground and needs a contour' );
	}

	public function test_size_and_unknown_names(): void {
		$this->assertStringContainsString( 'width="18" height="18"', Icons::svg( 'url', 18 ) );
		$this->assertStringContainsString( 'width="1" height="1"', Icons::svg( 'url', -5 ) );
		$this->assertSame( '', Icons::svg( 'nope' ) );
		$this->assertSame( '', Icons::paths( 'nope' ) );
		$this->assertFalse( Icons::has( 'nope' ) );
	}

	public function test_the_stylesheet_masks_every_class_and_fetches_nothing(): void {
		$css = Icons::stylesheet();
		$this->assertStringStartsWith( Icons::CSS_BEGIN, $css );
		$this->assertStringEndsWith( Icons::CSS_END, $css );
		foreach ( array_keys( Icons::classes() ) as $class ) {
			$this->assertMatchesRegularExpression( '/\.ffc-icon-' . preg_quote( $class, '/' ) . '::before \{\n    -webkit-mask-image: url\("data:image\/svg\+xml,/', $css, $class );
		}
		$this->assertStringNotContainsString( 'http://' . 'example', $css );
		$this->assertDoesNotMatchRegularExpression( '/url\("(?!data:)/', $css, 'every url() is an inline data URI' );
		$this->assertDoesNotMatchRegularExpression( '/[<>#]/', preg_replace( '/^\/\*.*\*\/$/m', '', $css ), 'the data URIs are percent-encoded' );
	}
}
