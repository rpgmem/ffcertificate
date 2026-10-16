<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Every place the plugin writes CSS outside a stylesheet (#1837).
 *
 * An inline declaration is invisible to every CSS guard -- the namespace
 * anchor, the palette, the scales, the contrast meter all read `assets/css/`
 * -- and it beats the tokenized class on specificity, so it does not follow
 * the dark theme either. `InlineStyleTest` freezes what is left, and this is
 * the scan it freezes.
 *
 * WHAT COUNTS, on comment-stripped source (a docblock that MENTIONS
 * `style=` is prose, not an occurrence -- the `PhpSource` rule):
 *
 *  - a `style=` attribute, in PHP markup, a PHP string or a JS string. The
 *    look-behind refuses `data-ffc-confirm-style=`, which is a data attribute
 *    that happens to end in the word;
 *  - a `<style` element, and `wp_add_inline_style()`;
 *  - a PHP attributes array carrying CSS, `'style' => 'margin: 12px 0;'` --
 *    the shape `wp_admin_notice()` takes. A value without a `:` is not CSS
 *    (`'style' => 'primary'` names a confirm-dialog variant);
 *  - a jQuery `.css()` SETTER -- an object, or a property plus a value. A
 *    getter (`.css( 'width' )`) reads, it writes nothing;
 *  - a DOM `.style.<prop> =` assignment, `.style.setProperty()` and
 *    `.style.cssText =`.
 *
 * WHAT DOES NOT: a script toggling `display` (`.style.display = 'none'`,
 * `.css( 'display', … )`). That is `.show()` / `.hide()` spelled by hand --
 * state, not styling -- and freezing every toggle would bury the register
 * under entries that all carry the same reason. Static `style="display:none"`
 * in MARKUP is still counted: there it is the initial state, and the register
 * says why each one stays.
 *
 * @package FreeFormCertificate\Tests\Support
 */
final class InlineStyles {

	/**
	 * Directories under `templates/` that are inline by design, with why.
	 *
	 * Excluded by NAME, so a file moving between them and the rest of the tree
	 * changes the scan visibly.
	 */
	public const EXCLUDED_DIRS = array(
		'templates/emails'               => 'Gmail and Outlook ignore external sheets; the single email layout is inline by design (CLAUDE.md "Email architecture").',
		'templates/documents'            => 'admin-editable document bodies stored in the database and rasterised by html2canvas; no plugin sheet reaches the canvas.',
		'templates/certificate-defaults' => 'the shipped certificate bodies, seeded into the database and rasterised like any other certificate.',
	);

	/**
	 * The roots the scan walks.
	 */
	private const PHP_ROOTS = array( 'includes', 'templates' );
	private const JS_ROOT   = 'assets/js';

	/**
	 * Patterns over code with its comments removed. Kind => PCRE.
	 *
	 * Each match's text, up to the first quote or line end, is the key the
	 * register freezes, so two different declarations in one file are two
	 * entries, and the same one written three times is one entry counted three.
	 */
	private const PHP_PATTERNS = array(
		'style='              => '/(?<![\w-])style\s*=\s*\\\\?["\']([^"\'\n\\\\]{0,70})/i',
		'<style>'             => '/<style\b[^>\n]{0,40}/i',
		'wp_add_inline_style' => '/\bwp_add_inline_style\s*\(\s*([^,\n]{0,40})/',
		'attributes'          => '/["\']style["\']\s*=>\s*["\']([^"\'\n]*:[^"\'\n]*)/',
	);

	private const JS_PATTERNS = array(
		'style='       => '/(?<![\w-])style\s*=\s*\\\\?["\']([^"\'\n\\\\]{0,70})/i',
		'<style>'      => '/<style\b[^>\n]{0,40}/i',
		'.css()'       => '/\.css\(\s*(\{|["\'](?!display["\'])[\w-]+["\']\s*,[^)\n]{0,50})/',
		'.style.x ='   => '/\.style\.((?!display\b)[a-zA-Z]+)\s*=(?!=)\s*([^;\n]{0,50})/',
		'.style.setProperty' => '/\.style\.setProperty\(\s*([^)\n]{0,50})/',
	);

	/**
	 * Every PHP file the scan reads, repository-relative and sorted.
	 *
	 * @return list<string>
	 */
	public static function php_files(): array {
		$out = array();
		foreach ( self::PHP_ROOTS as $root ) {
			foreach ( PhpSource::files_under( $root ) as $file ) {
				if ( ! self::is_excluded( $file ) ) {
					$out[] = $file;
				}
			}
		}
		sort( $out );

		return $out;
	}

	/**
	 * Every non-minified script the scan reads, repository-relative and sorted.
	 *
	 * @return list<string>
	 */
	public static function js_files(): array {
		$out  = array();
		$base = self::root() . '/' . self::JS_ROOT;
		$walk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $walk as $file ) {
			$path = $file->getPathname();
			if ( '.js' === substr( $path, -3 ) && ! str_contains( basename( $path ), '.min.' ) ) {
				$out[] = str_replace( self::root() . '/', '', $path );
			}
		}
		sort( $out );

		return $out;
	}

	/**
	 * Whether a repository-relative path sits under an excluded directory.
	 *
	 * @param string $file Repository-relative path.
	 */
	public static function is_excluded( string $file ): bool {
		foreach ( array_keys( self::EXCLUDED_DIRS ) as $dir ) {
			if ( str_starts_with( $file, $dir . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every occurrence, per file.
	 *
	 * @return array<string, array<string, int>> File => `kind: text` => count.
	 */
	public static function found(): array {
		$out = array();

		foreach ( self::php_files() as $file ) {
			self::collect( $out, $file, PhpSource::code( $file ), self::PHP_PATTERNS );
		}
		foreach ( self::js_files() as $file ) {
			$code = JsIdSelectors::strip_comments( (string) file_get_contents( self::root() . '/' . $file ) );
			self::collect( $out, $file, $code, self::JS_PATTERNS );
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Occurrences of each kind in a piece of code, keyed like `found()`.
	 *
	 * Public so the guard can prove the patterns on synthetic source.
	 *
	 * @param string $code   Code with its comments already removed.
	 * @param bool   $is_php Which pattern set applies.
	 * @return array<string, int>
	 */
	public static function in_code( string $code, bool $is_php ): array {
		$out = array();
		self::collect( $out, 'x', $code, $is_php ? self::PHP_PATTERNS : self::JS_PATTERNS );

		return $out['x'] ?? array();
	}

	/**
	 * How many PHP occurrences there are, counted another way.
	 *
	 * `php_strip_whitespace()` removes comments with PHP's own lexer, in a
	 * code path that shares nothing with the token loop in `PhpSource::code()`,
	 * and the files are listed by `glob()` rather than the recursive iterator.
	 * Two readings that agree on the total cannot both have lost the same file.
	 *
	 * @return int
	 */
	public static function php_total_by_second_reading(): int {
		$total = 0;
		foreach ( self::PHP_ROOTS as $root ) {
			foreach ( self::glob_recursive( self::root() . '/' . $root, '*.php' ) as $path ) {
				$relative = str_replace( self::root() . '/', '', $path );
				if ( self::is_excluded( $relative ) ) {
					continue;
				}
				$code = php_strip_whitespace( $path );
				foreach ( self::PHP_PATTERNS as $pattern ) {
					$total += (int) preg_match_all( $pattern, $code );
				}
			}
		}

		return $total;
	}

	/**
	 * How many JS occurrences there are, counted another way.
	 *
	 * The scan strips comments with `JsIdSelectors::strip_comments()`, a
	 * character loop. This reading strips them with one regex whose
	 * alternatives each consume a WHOLE literal, escapes included -- so a `//`
	 * inside `'https://…'` is a string, not a comment -- and lists the files
	 * with `glob()`. Two strippers written differently that agree on the
	 * total have not both dropped the same occurrence.
	 *
	 * @return int
	 */
	public static function js_total_by_second_reading(): int {
		$total = 0;
		foreach ( glob( self::root() . '/' . self::JS_ROOT . '/*.js' ) ?: array() as $path ) {
			if ( str_contains( basename( $path ), '.min.' ) ) {
				continue;
			}
			$code = (string) preg_replace_callback(
				'~("(?:\\\\.|[^"\\\\\n])*"|\'(?:\\\\.|[^\'\\\\\n])*\'|`(?:\\\\.|[^`\\\\])*`)|//[^\n]*|/\*.*?\*/~s',
				static fn ( array $m ): string => '' !== ( $m[1] ?? '' ) ? $m[1] : ' ',
				(string) file_get_contents( $path )
			);
			foreach ( self::JS_PATTERNS as $pattern ) {
				$total += (int) preg_match_all( $pattern, $code );
			}
		}

		return $total;
	}

	/**
	 * Files matching a pattern under a directory, by `glob()` level by level.
	 *
	 * @param string $dir     Absolute directory.
	 * @param string $pattern Glob pattern for the file name.
	 * @return list<string> Absolute paths, sorted.
	 */
	public static function glob_recursive( string $dir, string $pattern ): array {
		$out = glob( $dir . '/' . $pattern ) ?: array();
		foreach ( glob( $dir . '/*', GLOB_ONLYDIR ) ?: array() as $sub ) {
			$out = array_merge( $out, self::glob_recursive( $sub, $pattern ) );
		}
		sort( $out );

		return $out;
	}

	/**
	 * Adds the matches of each pattern to `$out[ $file ]`.
	 *
	 * @param array<string, array<string, int>> $out      Accumulator.
	 * @param string                            $file     Key.
	 * @param string                            $code     Comment-free code.
	 * @param array<string, string>             $patterns Kind => PCRE.
	 */
	private static function collect( array &$out, string $file, string $code, array $patterns ): void {
		foreach ( $patterns as $kind => $pattern ) {
			if ( ! preg_match_all( $pattern, $code, $m ) ) {
				continue;
			}
			foreach ( $m[0] as $text ) {
				// One quote character, so a key reads the same whichever quote the
				// source used, and a PHP `\"` reads as the `"` it prints.
				$text = str_replace( array( '\\"', '\\\'', "'" ), '"', $text );
				$key  = $kind . ': ' . trim( (string) preg_replace( '/\s+/', ' ', $text ) );
				$out[ $file ][ $key ] = ( $out[ $file ][ $key ] ?? 0 ) + 1;
			}
		}
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}
}
