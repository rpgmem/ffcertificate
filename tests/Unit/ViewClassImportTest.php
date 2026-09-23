<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every class a view names is one it imports (#1397).
 *
 * A VIEW DOES NOT INHERIT THE IMPORTS OF WHATEVER `require`d IT.
 *
 * `IdentityResolutionPage::render_page()` is in `FreeFormCertificate\Admin`
 * and its view declares no namespace, so every unqualified class name in that
 * file resolves against the GLOBAL namespace. A name missing from the view's
 * own `use` list is a fatal the moment the line runs.
 *
 * NOTHING ELSE SEES IT, WHICH IS WHY THIS EXISTS.
 *
 * `phpstan.neon.dist` excludes `includes/admin/views` and
 * `includes/settings/views` as markup, and `phpunit.xml.dist` excludes them
 * from coverage — the carve-out `CLAUDE.md` justifies by those directories
 * carrying no `$wpdb`, no `update_option`, no nonce and no capability check.
 * That justification is about SECURITY surface and says nothing about
 * resolvability, so an unimported class in a view is invisible to every gate
 * the repository runs.
 *
 * It was not hypothetical: `IdentityQueuePanels::ARG_AT` went into this
 * screen's view with #1399 and was never imported, so the screen fatalled on
 * the testes site for four merges — through sprints 3, 4, 5 and 6, none of
 * which anybody could therefore have looked at. The sibling
 * `test_the_view_is_handed_every_variable_it_declares()` covers the same file
 * for the same class of invisibility, one symbol kind over.
 *
 * It reads TOKENS rather than raw lines, so a class named inside a comment,
 * a string or inline HTML is never mistaken for a reference — the parsing
 * rule `CommentLanguageTest` and the suppression guards already follow.
 *
 * @coversNothing
 */
class ViewClassImportTest extends TestCase {

	/**
	 * Names that resolve without an import: the language's own, and the
	 * WordPress classes a view may reach through the global namespace, where
	 * an unqualified name is already correct.
	 *
	 * @var array<int, string>
	 */
	private const GLOBAL_NAMES = array(
		'self',
		'static',
		'parent',
	);

	/**
	 * Every view and template in the plugin.
	 *
	 * `templates/` is included as well as the two `views/` directories: it is
	 * the same shape — markup `include`d into a caller's scope — and the same
	 * coverage carve-out, so the same defect is possible there.
	 *
	 * @return array<int, string>
	 */
	private static function files(): array {
		$root  = dirname( __DIR__, 2 );
		$found = array();

		foreach ( array( 'includes', 'templates' ) as $dir ) {
			$path = $root . '/' . $dir;

			if ( ! is_dir( $path ) ) {
				continue;
			}

			/** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $walk */
			$walk = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) );

			foreach ( $walk as $file ) {
				$name = (string) $file;

				if ( 'php' !== pathinfo( $name, PATHINFO_EXTENSION ) ) {
					continue;
				}

				if ( false !== strpos( $name, '/views/' ) || false !== strpos( $name, '/templates/' ) ) {
					$found[] = $name;
				}
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * What one file imports, by the short name each `use` makes available.
	 *
	 * @param string $source The file.
	 * @return array<int, string>
	 */
	private static function imported( string $source ): array {
		preg_match_all( '/^use\s+([^;]+);/m', $source, $uses );

		$short = array();

		foreach ( $uses[1] as $statement ) {
			$statement = trim( $statement );

			// An alias is what the file then writes, so it is the name that
			// counts — `use A\B as C` makes `C`, never `B`.
			if ( preg_match( '/\bas\s+(\w+)$/i', $statement, $alias ) ) {
				$short[] = $alias[1];
				continue;
			}

			$parts   = explode( '\\', $statement );
			$short[] = (string) end( $parts );
		}

		return $short;
	}

	/**
	 * The file with comments, strings and inline HTML removed.
	 *
	 * @param string $source The file.
	 * @return string
	 */
	private static function code_only( string $source ): string {
		$out = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) ) {
				$out .= $token;
				continue;
			}

			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML ), true ) ) {
				continue;
			}

			$out .= $token[1];
		}

		return $out;
	}

	/**
	 * EVERY `ClassName::` IN A VIEW RESOLVES, OR THE LINE IS A FATAL.
	 *
	 * Blocks at zero. A view that genuinely needs a global class gets the
	 * import or the leading backslash, never an entry in an allowlist: both
	 * fixes are one character to a handful, and an allowlist here would be a
	 * register of lines waiting to fatal.
	 */
	public function test_every_class_a_view_names_is_one_it_can_resolve(): void {
		$missing = array();
		$scanned = 0;
		$named   = 0;

		foreach ( self::files() as $path ) {
			$source = (string) file_get_contents( $path );

			// A view that declares its own namespace resolves against it and
			// is a different question; none does today, and one that did
			// would need its own reading rather than this one.
			if ( preg_match( '/^\s*namespace\s+/m', $source ) ) {
				continue;
			}

			++$scanned;

			$imported = self::imported( $source );

			// `(?<![\\\w$>])` keeps this off an already-qualified name, a
			// method call and a property — only a bare `Foo::` counts.
			preg_match_all( '/(?<![\\\\\w$>])([A-Z]\w*)::/', self::code_only( $source ), $found );

			foreach ( array_unique( $found[1] ) as $class ) {
				++$named;

				if ( in_array( $class, $imported, true ) || in_array( $class, self::GLOBAL_NAMES, true ) ) {
					continue;
				}

				// A class that genuinely lives in the global namespace — WP's
				// own — resolves unqualified and is correct as written.
				if ( class_exists( $class ) || interface_exists( $class ) ) {
					continue;
				}

				$missing[] = sprintf( '%s names %s and imports nothing by that name', $path, $class );
			}
		}

		$this->assertGreaterThan( 50, $scanned, 'The scan found almost no views, so it proves nothing.' );
		$this->assertGreaterThan( 0, $named, 'The scan found no class reference at all, so it proves nothing.' );
		$this->assertSame(
			array(),
			$missing,
			"A view resolves unqualified names against the GLOBAL namespace, so each of these is a fatal when its line runs:\n" . implode( "\n", $missing )
		);
	}
}
