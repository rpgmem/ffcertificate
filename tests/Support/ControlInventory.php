<?php
/**
 * The controls a rendered screen offers, read off its markup.
 *
 * A reframe (a postbox that becomes a card, a filter row that moves into the
 * table navigation) must change how a screen looks and nothing it does. What it
 * does lives in its controls: the fields a form posts, the links an operator
 * can follow, the buttons a script binds to and the `data-*` they carry. This
 * reads exactly those, in document order, and ignores every class, so a test
 * can freeze the inventory a screen had before the reframe and compare.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Control inventory.
 */
final class ControlInventory {

	/**
	 * Elements that carry behaviour.
	 */
	private const TAGS = array( 'form', 'input', 'select', 'option', 'textarea', 'button', 'a' );

	/**
	 * Attributes that carry behaviour; `data-*` is added on top.
	 */
	private const ATTRIBUTES = array( 'action', 'method', 'enctype', 'name', 'id', 'type', 'value', 'href', 'accept', 'required', 'checked', 'disabled', 'onclick' );

	/**
	 * One line per control, in document order.
	 *
	 * @param string $html Rendered markup.
	 * @return list<string>
	 */
	public static function of( string $html ): array {
		$doc = new \DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );

		$lines = array();
		$all   = $doc->getElementsByTagName( '*' );
		foreach ( $all as $el ) {
			if ( ! $el instanceof \DOMElement || ! in_array( $el->tagName, self::TAGS, true ) ) {
				continue;
			}
			$parts = array( $el->tagName );
			$attrs = array();
			foreach ( $el->attributes as $attr ) {
				$name = $attr->nodeName;
				if ( in_array( $name, self::ATTRIBUTES, true ) || str_starts_with( $name, 'data-' ) ) {
					// Whitespace is the template's indentation, not the control.
					$attrs[ $name ] = trim( (string) preg_replace( '/\s+/', ' ', (string) $attr->nodeValue ) );
				}
			}
			ksort( $attrs );
			foreach ( $attrs as $name => $value ) {
				$parts[] = $name . '=' . $value;
			}
			$lines[] = implode( ' ', $parts );
		}
		return $lines;
	}
}
