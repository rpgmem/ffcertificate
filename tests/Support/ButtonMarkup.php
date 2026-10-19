<?php
/**
 * Reads the classes of a rendered button by the hook class it carries.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Helpers for asserting on `.ffc-btn` markup a renderer printed (#1880).
 *
 * A converted button keeps its old class as the hook scripts read, so the
 * test finds it by that hook and then checks the family's classes on the
 * same element: the category, the icon, and any layout modifier.
 */
final class ButtonMarkup {

	/**
	 * The class lists of every `<button>` whose class attribute holds the hook.
	 *
	 * @param string $html Rendered markup.
	 * @param string $hook A class the button must carry.
	 * @return array<int, array<int, string>> One class list per matching button.
	 */
	public static function classes_by_hook( string $html, string $hook ): array {
		$found = array();
		if ( false === preg_match_all( '/<button\b[^>]*\bclass="([^"]*)"/', $html, $matches ) ) {
			return $found;
		}
		foreach ( $matches[1] as $attribute ) {
			$classes = preg_split( '/\s+/', trim( $attribute ) );
			$classes = is_array( $classes ) ? $classes : array();
			if ( in_array( $hook, $classes, true ) ) {
				$found[] = $classes;
			}
		}

		return $found;
	}

	/**
	 * A view's source with its `Core\Button` arguments read as attributes.
	 *
	 * A button built from arguments names its id and its data attributes as
	 * array keys -- `'id' => 'ffc-x'`, `'data-ffc-input' => 'ffc-relink-' . $id`
	 * -- where a hand-written one printed `id="ffc-x"`. A static check written
	 * against the attribute reads both once this rewrites a key given a string
	 * literal into the attribute it prints (#1904): a complete literal closes
	 * its quote, a literal concatenated onward stays an open prefix, which is
	 * the form those checks already match.
	 *
	 * @param string $source PHP view source.
	 * @return string
	 */
	public static function args_as_attributes( string $source ): string {
		return (string) preg_replace_callback(
			"/'((?:data-[a-z0-9-]+)|id)'\\s*=>\\s*'([^'\\\\]*)'(\\s*\\.)?/",
			static function ( array $m ): string {
				return $m[1] . '="' . $m[2] . ( isset( $m[3] ) && '' !== $m[3] ? '' : '"' );
			},
			$source
		);
	}
}
