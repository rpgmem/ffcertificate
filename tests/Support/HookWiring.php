<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Whether both ends of a plugin-internal hook exist and agree on a name.
 *
 * SHARED, FOR THE REASON `CssSelectors` AND `PoCatalogue` ARE SHARED
 *
 * Two guards asking the same question about two hooks must not disagree
 * about what counts as a registration. The first version of the scan this
 * came from matched `add_action( '` as text and reported a real listener as
 * missing, because a registration long enough to wrap -- callback array,
 * priority, argument count -- puts the hook name on its own line. That trap
 * is worth fixing once.
 *
 * It reads the source as TEXT and boots no WordPress: what it proves is that
 * both ends of the wire exist, never that the handler runs -- the limit
 * `AjaxWiringTest` states for itself.
 */
final class HookWiring {

	/**
	 * Every PHP file under `includes/`, keyed by absolute path.
	 *
	 * @return array<string, string>
	 */
	public static function sources(): array {
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$out   = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			$path = $file->getPathname();
			if ( ! is_string( $path ) || 'php' !== strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				continue;
			}

			$source = file_get_contents( $path );
			if ( is_string( $source ) ) {
				$out[ $path ] = $source;
			}
		}

		return $out;
	}

	/**
	 * Files containing a call of the given kind on the given hook.
	 *
	 * The whitespace between the call and its first argument is a REGEX and
	 * not a literal -- see the class note.
	 *
	 * @param string $hook Hook name.
	 * @param string $call `do_action` or `add_action`.
	 * @return array<int, string> Paths, relative to the repository root.
	 */
	public static function files_calling( string $hook, string $call ): array {
		$out     = array();
		$pattern = '/\b' . preg_quote( $call, '/' ) . "\s*\(\s*'" . preg_quote( $hook, '/' ) . "'/";

		foreach ( self::sources() as $path => $source ) {
			if ( 1 === preg_match( $pattern, $source ) ) {
				$out[] = str_replace( dirname( __DIR__, 2 ) . '/', '', $path );
			}
		}

		return $out;
	}
}
