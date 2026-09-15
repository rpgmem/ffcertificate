<?php
/**
 * Selector reader for the sheets in `assets/css/`.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * The CSS parser shared by the stylesheet guards.
 *
 * It exists for the same reason `.github/scripts/ffc-create-statements.php`
 * does: two guards measuring the same thing must not disagree about the set
 * they measure. `CssNamespaceAnchorTest` (#1152) counts anchorless selectors,
 * `StylesheetOwnershipTest` (#1162) counts bare class declarations -- both need
 * the same definition of "what a selector is in this sheet".
 *
 * Three things the parser has to do that a `([^{]+)\{` does not:
 *
 * 1. **Skip at-rule preludes and `@keyframes` steps.** `0%` and `from` are not
 *    selectors; a scan that read them would report debt in every animated
 *    sheet.
 * 2. **Never read a `{`, `}` or `;` from inside a string.** The `;` in
 *    `img[src^="data:image/png;base64"]` cuts the selector in half and produces
 *    a phantom entry called `base64"]`.
 * 3. **Split the list on top-level commas**, so a compound (`.a.b`) counts once
 *    and a list (`.a, .b`) counts twice.
 */
final class CssSelectors {

	/**
	 * The non-minified sheets in `assets/css/`, in order.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	public static function sheets(): array {
		$found = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/assets/css/*.css' ) ?: array() as $path ) {
			if ( ! str_ends_with( $path, '.min.css' ) ) {
				$found[] = $path;
			}
		}

		return $found;
	}

	/**
	 * A sheet's style rules, with their bodies.
	 *
	 * It sees INSIDE `@media` and `@supports`, as `of()` always has — and that
	 * is not a detail. A single-use parser written to measure `forced-colors`
	 * exposure (#1165) read only the top level and saw 270 of the codebase's
	 * 2,111 rules, because almost all the responsive CSS lives inside `@media`.
	 * The mistake was the script's, not this class's; it is recorded here
	 * because it is the reason this function exists instead of every guard
	 * writing its own.
	 *
	 * @param string $css Sheet contents.
	 * @return array<int, array{selector: string, body: string}>
	 */
	public static function rules( string $css ): array {
		$out = array();
		foreach ( self::walk( $css ) as $rule ) {
			$out[] = $rule;
		}

		return $out;
	}

	/**
	 * Extracts a sheet's selectors, one per list entry.
	 *
	 * @param string $css Sheet contents.
	 * @return array<int, string>
	 */
	public static function of( string $css ): array {
		$out = array();
		foreach ( self::walk( $css ) as $rule ) {
			foreach ( self::split_list( $rule['selector'] ) as $one ) {
				$out[] = $one;
			}
		}

		return $out;
	}

	/**
	 * Walks the rules, returning the raw selector plus body.
	 *
	 * @param string $css Sheet contents.
	 * @return array<int, array{selector: string, body: string}>
	 */
	private static function walk( string $css ): array {
		$css   = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$out   = array();
		$buf   = '';
		$stack = array();
		$quote = '';
		$len   = strlen( $css );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $css[ $i ];

			if ( '' !== $quote ) {
				$buf .= $ch;
				if ( '\\' === $ch && $i + 1 < $len ) {
					$buf .= $css[ ++$i ];
					continue;
				}
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				$buf  .= $ch;
				continue;
			}

			if ( '{' === $ch ) {
				$prelude = trim( $buf );
				$buf     = '';
				if ( str_starts_with( $prelude, '@' ) ) {
					$name    = strtolower( strtok( $prelude, " \t\n(" ) ?: '' );
					$stack[] = array( 'kind' => str_contains( $name, 'keyframes' ) ? 'keyframes' : 'at' );
					continue;
				}
				$parent  = end( $stack );
				$stack[] = array(
					'kind'     => ( false !== $parent && 'keyframes' === $parent['kind'] ) ? 'step' : 'rule',
					'selector' => $prelude,
				);
				continue;
			}

			if ( '}' === $ch ) {
				$frame = array_pop( $stack );
				if ( is_array( $frame ) && 'rule' === $frame['kind'] && '' !== $frame['selector'] ) {
					$out[] = array(
						'selector' => $frame['selector'],
						'body'     => $buf,
					);
				}
				$buf = '';
				continue;
			}

			$buf .= $ch;
		}

		return $out;
	}

	/**
	 * Splits a selector list on its top-level commas.
	 *
	 * @param string $list The rule's prelude.
	 * @return array<int, string>
	 */
	public static function split_list( string $list ): array {
		$parts = array();
		$cur   = '';
		$depth = 0;
		$quote = '';
		$len   = strlen( $list );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $list[ $i ];

			if ( '' !== $quote ) {
				$cur .= $ch;
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				$cur  .= $ch;
				continue;
			}
			if ( '(' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch ) {
				--$depth;
			}
			if ( ',' === $ch && 0 === $depth ) {
				$parts[] = $cur;
				$cur     = '';
				continue;
			}
			$cur .= $ch;
		}

		$parts[] = $cur;

		$clean = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) preg_replace( '/\s+/', ' ', $part ) );
			if ( '' !== $part ) {
				$clean[] = $part;
			}
		}

		return $clean;
	}

	/**
	 * The class name when the whole selector is a single class.
	 *
	 * "Bare" = one class, no ancestor, no second class, no element -- only
	 * pseudo-classes and pseudo-elements are tolerated. It is the form that
	 * reaches any element carrying the class, whichever component it came from.
	 *
	 * @param string $selector A single selector.
	 * @return string|null The class name, or null if it is not a bare declaration.
	 */
	public static function bare_class( string $selector ): ?string {
		$matched = preg_match(
			'/^\.(-?[_a-zA-Z][\w-]*)(?:::?[\w-]+(?:\([^)]*\))?)*$/',
			trim( $selector ),
			$m
		);

		return 1 === $matched ? $m[1] : null;
	}
}
