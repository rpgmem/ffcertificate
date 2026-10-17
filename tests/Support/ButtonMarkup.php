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
}
