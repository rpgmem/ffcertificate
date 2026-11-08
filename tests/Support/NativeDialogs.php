<?php
/**
 * The scan behind `NativeDialogRatchetTest` (#2534): every native browser
 * dialog the plugin opens -- `alert()`, `confirm()`, `prompt()` -- per file
 * and per kind.
 *
 * A native dialog blocks the page, cannot carry the plugin's notice or
 * button standard, ignores the dark theme, and its text is not tied to a
 * label assistive technology can read. The plugin's own replacements are
 * `FFC.notice()` for a result and `FFC.overrideConfirm()` /
 * `FFC.confirmRangeCancel()` for a question, the reason field included.
 *
 * Read: the non-minified scripts under `assets/js/`, comments stripped, and
 * the PHP under `includes/` and `templates/`, PHP comments dropped by the
 * tokenizer -- an inline `onclick="return confirm(…)"` is a dialog too. A
 * call counts when the name is not a member or a declaration: `alert(` and
 * `window.alert(` count; `FFC.confirm(`, `$actions->confirm(`,
 * `Checkin::confirm(`, `function confirm(` and `overrideConfirm(` do not.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Native dialog calls in the plugin's own source.
 */
final class NativeDialogs {

	/**
	 * A native dialog call: the bare name or `window.` before it, and nothing
	 * that makes it a member (`.`, `->`, `::`, `$`) or a declaration.
	 */
	private const PATTERN = '/(?<![\w$.>:])(?<!function )(?:window\.)?(alert|confirm|prompt)\s*\(/';

	/**
	 * Every file scanned, repository-relative and sorted.
	 *
	 * @return list<string>
	 */
	public static function files(): array {
		$out = array();
		foreach ( array( 'assets/js', 'includes', 'templates' ) as $dir ) {
			$walk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $walk as $file ) {
				$path = $file->getPathname();
				if ( self::scanned( $path ) ) {
					$out[] = str_replace( self::root() . '/', '', $path );
				}
			}
		}
		sort( $out );

		return $out;
	}

	/**
	 * Whether a path is one the scan reads: a non-minified script under
	 * `assets/js/`, or a PHP file under `includes/` or `templates/`.
	 *
	 * @param string $path Absolute or repository-relative path.
	 */
	public static function scanned( string $path ): bool {
		$rel = str_replace( self::root() . '/', '', $path );
		if ( str_starts_with( $rel, 'assets/js/' ) ) {
			return '.js' === substr( $rel, -3 ) && ! str_contains( basename( $rel ), '.min.' );
		}

		return '.php' === substr( $rel, -4 ) && ( str_starts_with( $rel, 'includes/' ) || str_starts_with( $rel, 'templates/' ) );
	}

	/**
	 * File => kind => count, files with none left out.
	 *
	 * @return array<string, array<string, int>>
	 */
	public static function found(): array {
		$out = array();
		foreach ( self::files() as $file ) {
			$src    = (string) file_get_contents( self::root() . '/' . $file );
			$counts = self::in_code( $src, '.php' === substr( $file, -4 ) );
			if ( array() !== $counts ) {
				$out[ $file ] = $counts;
			}
		}

		return $out;
	}

	/**
	 * Kind => count in one source.
	 *
	 * @param string $src    The source.
	 * @param bool   $is_php Whether it is PHP (else JS).
	 * @return array<string, int>
	 */
	public static function in_code( string $src, bool $is_php ): array {
		$code = $is_php ? self::strip_php_comments( $src ) : CoreButtonClasses::strip_js_comments( $src );
		preg_match_all( self::PATTERN, $code, $m );
		$counts = array_count_values( $m[1] );
		ksort( $counts );

		return $counts;
	}

	/**
	 * PHP with its comments dropped and everything else kept, inline HTML
	 * and strings included: a dialog can live in either.
	 *
	 * @param string $src PHP source.
	 */
	private static function strip_php_comments( string $src ): string {
		$out = '';
		foreach ( token_get_all( $src ) as $token ) {
			if ( is_array( $token ) ) {
				$out .= ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) ? ' ' : $token[1];
			} else {
				$out .= $token;
			}
		}

		return $out;
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}
}
