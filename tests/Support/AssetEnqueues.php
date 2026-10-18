<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Every `wp_enqueue_*` / `wp_register_*` call in `includes/` that names one of
 * the plugin's own asset files, read from the token stream.
 *
 * A call is recorded with its handle and with the file it points at, written
 * the way the repository stores it (`assets/js/ffc-core.js`) whatever suffix
 * the call appends at run time -- `{$s}`, `{$ffc_s}`, `.min`. A vendored bundle
 * keeps its version constant in the key (`libs/js/jspdf-{FFC_JSPDF_VERSION}.umd.min.js`),
 * since that is the part that names the file.
 *
 * The handle resolves from a literal, or from a `Class::CONST` / `self::CONST`
 * declared anywhere under `includes/`. One shape is recognised without a value:
 * a variable handle whose name is also the file name (`$module` and
 * `"assets/js/{$module}{$s}.js"`), which is one handle per file by construction.
 * Anything else comes back with a null handle, so a guard can refuse it rather
 * than skip it.
 *
 * @package FreeFormCertificate\Tests\Support
 */
final class AssetEnqueues {

	public const FUNCTIONS = array( 'wp_enqueue_script', 'wp_register_script', 'wp_enqueue_style', 'wp_register_style' );

	/**
	 * @return list<array{file: string, line: int, function: string, handle: ?string, asset: ?string, self_named: bool}>
	 *         `asset` is null when the call names no file of ours (a bare
	 *         handle, a core script, an external URL).
	 */
	public static function calls(): array {
		$constants = self::constants();
		$out       = array();

		foreach ( PhpSource::files_under( 'includes' ) as $file ) {
			$tokens = array_values(
				array_filter(
					token_get_all( (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file ) ),
					static fn ( $t ): bool => ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
				)
			);
			$class  = self::class_name( $tokens );

			foreach ( $tokens as $i => $token ) {
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
					continue;
				}
				$name = ltrim( $token[1], '\\' );
				if ( ! in_array( $name, self::FUNCTIONS, true ) || '(' !== ( $tokens[ $i + 1 ] ?? null ) ) {
					continue;
				}
				$previous = $tokens[ $i - 1 ] ?? null;
				if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
					continue;
				}

				$args  = self::arguments( $tokens, $i + 1 );
				$asset = isset( $args[1] ) ? self::asset( self::text( $args[1] ) ) : null;

				$handle     = null;
				$self_named = false;
				$first      = $args[0] ?? array();
				if ( 1 === count( $first ) && is_array( $first[0] ) && T_CONSTANT_ENCAPSED_STRING === $first[0][0] ) {
					$handle = substr( $first[0][1], 1, -1 );
				} elseif ( 3 === count( $first ) && is_array( $first[2] ) && T_DOUBLE_COLON === ( is_array( $first[1] ) ? $first[1][0] : null ) ) {
					$owner  = self::last_segment( is_array( $first[0] ) ? $first[0][1] : '' );
					$owner  = in_array( strtolower( $owner ), array( 'self', 'static' ), true ) ? $class : $owner;
					$handle = $constants[ $owner ][ $first[2][1] ] ?? null;
				} elseif ( 1 === count( $first ) && is_array( $first[0] ) && T_VARIABLE === $first[0][0] && isset( $args[1] ) ) {
					$self_named = str_contains( self::text( $args[1] ), '/{' . $first[0][1] . '}' );
				}

				$out[] = array(
					'file'       => $file,
					'line'       => (int) $token[2],
					'function'   => $name,
					'handle'     => $handle,
					'asset'      => $asset,
					'self_named' => $self_named,
				);
			}
		}

		return $out;
	}

	/**
	 * The plugin file an argument names, normalised, or null.
	 *
	 * @param string $source The argument's source text.
	 * @return string|null
	 */
	public static function asset( string $source ): ?string {
		$flat = preg_replace( '/[\'"]\s*\.\s*[\'"]/', '', $source ) ?? $source;
		if ( preg_match( '#assets/(js|css)/(\{\$\w+\}|[A-Za-z0-9_-]+)(?:\{\$\w+\}|\$\w+|[\'"]\s*\.\s*\$\w+\s*\.\s*[\'"])?(?:\.min)?\.(js|css)#', $flat, $m ) ) {
			return 'assets/' . $m[1] . '/' . $m[2] . '.' . $m[3];
		}
		if ( preg_match( '#libs/js/([A-Za-z0-9._-]+)[\'"]\s*\.\s*(FFC_[A-Z0-9_]+)\s*\.\s*[\'"]([A-Za-z0-9._-]+)#', $flat, $m ) ) {
			return 'libs/js/' . $m[1] . '{' . $m[2] . '}' . $m[3];
		}

		return null;
	}

	/**
	 * Class short name => constant => string value, for every class under `includes/`.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function constants(): array {
		$out = array();
		foreach ( PhpSource::files_under( 'includes' ) as $file ) {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
			if ( ! preg_match( '/^\s*(?:final\s+|abstract\s+)*class\s+(\w+)/m', $source, $cm ) ) {
				continue;
			}
			preg_match_all( "/const\s+([A-Z_][A-Z0-9_]*)\s*=\s*'([^']+)'/", $source, $m, PREG_SET_ORDER );
			foreach ( $m as $c ) {
				$out[ $cm[1] ][ $c[1] ] = $c[2];
			}
		}

		return $out;
	}

	/**
	 * @param list<mixed> $tokens Significant tokens.
	 * @return string
	 */
	private static function class_name( array $tokens ): string {
		foreach ( $tokens as $i => $t ) {
			if ( is_array( $t ) && T_CLASS === $t[0] && is_array( $tokens[ $i + 1 ] ?? null ) && T_STRING === $tokens[ $i + 1 ][0] ) {
				return $tokens[ $i + 1 ][1];
			}
		}

		return '';
	}

	/**
	 * Top-level arguments of the call whose `(` is at `$open`.
	 *
	 * @param list<mixed> $tokens Significant tokens.
	 * @param int         $open   Index of the opening parenthesis.
	 * @return list<list<mixed>>
	 */
	private static function arguments( array $tokens, int $open ): array {
		$args  = array();
		$cur   = array();
		$depth = 0;
		$count = count( $tokens );

		for ( $i = $open; $i < $count; $i++ ) {
			$t    = $tokens[ $i ];
			$text = is_array( $t ) ? $t[1] : $t;
			if ( in_array( $text, array( '(', '[', '{' ), true ) || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( in_array( $text, array( ')', ']', '}' ), true ) ) {
				--$depth;
				if ( 0 === $depth ) {
					if ( array() !== $cur ) {
						$args[] = $cur;
					}
					return $args;
				}
			} elseif ( ',' === $text && 1 === $depth ) {
				$args[] = $cur;
				$cur    = array();
				continue;
			}
			$cur[] = $t;
		}

		return $args;
	}

	/**
	 * @param list<mixed> $tokens Tokens of one argument.
	 * @return string
	 */
	private static function text( array $tokens ): string {
		return implode( '', array_map( static fn ( $t ): string => is_array( $t ) ? $t[1] : $t, $tokens ) );
	}

	/**
	 * @param string $name A possibly qualified class name.
	 * @return string
	 */
	private static function last_segment( string $name ): string {
		$parts = explode( '\\', $name );

		return (string) end( $parts );
	}
}
