<?php
/**
 * Inventory of the form controls an HTML fragment carries.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Reads every `<input>`, `<select>`, `<textarea>` and `<button>` out of a
 * rendered fragment, with every attribute it carries, so a test can state
 * that a reorganised screen posts exactly what it posted before (#1761).
 *
 * Every attribute is kept, not a chosen few: a field that keeps its name and
 * loses its `min`, its `required` or its `checked` posts something different,
 * and a list of attributes to compare is a list somebody forgets to extend.
 * A select also records each option's value and whether it is selected; a
 * textarea records its text.
 *
 * The result is sorted, because order on screen is exactly what a
 * reorganisation changes and what such a comparison must not care about.
 */
final class FormControls {

	/**
	 * Every form control in the fragment.
	 *
	 * @param string $html Rendered markup.
	 * @return list<array<string, mixed>> One entry per control, sorted.
	 */
	public static function inventory( string $html ): array {
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath    = new \DOMXPath( $doc );
		$controls = array();
		foreach ( $xpath->query( '//input|//select|//textarea|//button' ) ?: array() as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$entry = array( 'tag' => $node->tagName );
			$attrs = array();
			foreach ( $node->attributes ?? array() as $attr ) {
				$attrs[ $attr->name ] = $attr->value;
			}
			ksort( $attrs );
			$entry['attrs'] = $attrs;

			if ( 'select' === $node->tagName ) {
				$options = array();
				foreach ( $node->getElementsByTagName( 'option' ) as $option ) {
					$options[] = array(
						'value'    => $option->getAttribute( 'value' ),
						'selected' => $option->hasAttribute( 'selected' ),
						'text'     => trim( $option->textContent ),
					);
				}
				$entry['options'] = $options;
			}
			if ( 'textarea' === $node->tagName || 'button' === $node->tagName ) {
				$entry['text'] = trim( $node->textContent );
			}
			$controls[] = $entry;
		}

		usort(
			$controls,
			static function ( array $a, array $b ): int {
				return strcmp( (string) json_encode( $a ), (string) json_encode( $b ) );
			}
		);
		return $controls;
	}

	/**
	 * Every distinct piece of text the fragment shows, sorted.
	 *
	 * The labels, the help texts and the headings of a screen, so a test can
	 * state that a reorganisation kept each of them word for word, wherever
	 * it now sits. Whitespace inside a text node is collapsed; the text of a
	 * `<textarea>` or a `<script>` is a value, not a label, and is skipped.
	 *
	 * @param string $html Rendered markup.
	 * @return list<string>
	 */
	public static function texts( string $html ): array {
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$texts = array();
		foreach ( ( new \DOMXPath( $doc ) )->query( '//text()[not(ancestor::textarea) and not(ancestor::script)]' ) ?: array() as $node ) {
			$text = trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );
			if ( '' !== $text ) {
				$texts[ $text ] = true;
			}
		}
		$texts = array_keys( $texts );
		sort( $texts );
		return array_map( 'strval', $texts );
	}
}
