<?php
/**
 * The identity-resolution view's source with its partials inlined.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Reads the identity-resolution page as one text, in render order (#1925).
 *
 * The view includes its panels from `templates/admin/identity/`, so a test
 * asserting what the screen says -- or the ORDER it says it in -- has to read
 * the files the way PHP renders them. Each `include` of a partial is replaced
 * by that partial's text, recursively, which keeps every ordering assertion
 * meaningful: what came before a panel in the old single file still comes
 * before it here.
 *
 * A partial the view names and the disk does not hold FAILS rather than
 * reading as empty -- an assertion that a sentence is absent must never pass
 * because the file it lives in was not read.
 */
final class IdentityViewSource {

	/**
	 * The view, every partial inlined where it is included.
	 *
	 * @return string
	 */
	public static function inlined(): string {
		$root = dirname( __DIR__, 2 );

		return self::expand( (string) file_get_contents( $root . '/includes/admin/views/identity-resolution-page.php' ), $root, 0 );
	}

	/**
	 * Replace each partial include with the partial's own text.
	 *
	 * @param string $source A file's text.
	 * @param string $root   The repository root.
	 * @param int    $depth  How deep the recursion is, as a bound.
	 * @return string
	 */
	private static function expand( string $source, string $root, int $depth ): string {
		if ( $depth > 4 ) {
			throw new \RuntimeException( 'Identity partials nest deeper than any of them should.' );
		}

		return (string) preg_replace_callback(
			"/(?:include|require) FFC_PLUGIN_DIR \\. '(templates\\/admin\\/identity\\/[a-z-]+\\.php)';/",
			static function ( array $match ) use ( $root, $depth ): string {
				$path = $root . '/' . $match[1];

				if ( ! is_file( $path ) ) {
					throw new \RuntimeException( 'The view includes a partial that does not exist: ' . $match[1] );
				}

				return self::expand( (string) file_get_contents( $path ), $root, $depth + 1 );
			},
			$source
		);
	}
}
