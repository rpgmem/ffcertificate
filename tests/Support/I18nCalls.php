<?php
/**
 * The translatable strings the plugin source actually emits.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Extracts every literal i18n call, the way WP's own extractor would (#1284).
 *
 * The catalogue guards before this one compare `languages/` with itself. That
 * is internal consistency, and `languages/` was internally consistent for the
 * entire time 79 on-screen strings had no entry at all and rendered in English
 * on a pt_BR install (#1282). This is the other side of the comparison.
 *
 * THE RULE FOR WHAT COUNTS, which is not this class's invention
 *
 * A call is extracted only when its **textdomain** is a literal `ffcertificate`
 * and its **msgid** is a literal. `__( $label )` has nothing to extract, and
 * WP's own tooling has exactly the same limit -- which is why the seeded field
 * labels are written as literal calls in the first place. A call with no
 * textdomain argument resolves against `default` and is not ours: there is one
 * in the tree (`__( 'Appointment_Receipt' )`), and it is deliberately NOT
 * claimed here, because treating it as ours would demand a catalogue entry that
 * would never be read.
 *
 * Concatenated literals ARE resolved (`'a' . 'b'`), since that is still a
 * compile-time constant. A variable anywhere in the argument disqualifies it.
 *
 * THE MISS THAT MAKES THIS CLASS DANGEROUS TO WRITE, and it already happened
 *
 * The first version of this scan matched only `T_STRING`, so a call written
 * with a leading backslash was invisible:
 *
 *     \__( 'I am not a robot', 'ffcertificate' )
 *
 * PHP 8 tokenizes that as `T_NAME_FULLY_QUALIFIED`, a single token whose text
 * includes the backslash. It is **30 strings** in this repository, the whole
 * ALTCHA block among them. Every one was already translated, so the "missing
 * from the catalogue" direction was unaffected -- but the ORPHAN direction
 * reported 52 entries instead of 22. Had a guard been written on that scan, 30
 * live strings would have been frozen into its register as dead, and a register
 * is believed. {@see \FreeFormCertificate\Tests\Unit\TranslationSourceCoverageTest}
 * pins the shape with a canary for that reason.
 *
 * WHAT IT DOES NOT SEE, deliberately
 *
 * A msgid assembled at runtime; a call whose domain is a constant rather than a
 * literal (there are none today, and a guard would have to resolve the constant
 * to see one); and whether the string is correct, or on the right screen. The
 * #1260 rule holds: a scan sees presence, never truth.
 */
final class I18nCalls {

	/** The plugin's textdomain. A call naming any other domain is not ours. */
	public const DOMAIN = 'ffcertificate';

	/**
	 * Argument positions per function: singular, plural, context, domain.
	 *
	 * `null` means the function has no such argument. These are WordPress's own
	 * signatures; getting one wrong reads the domain out of the wrong slot and
	 * silently drops every call to that function.
	 *
	 * @var array<string, array{0: int, 1: int|null, 2: int|null, 3: int}>
	 */
	private const FUNCTIONS = array(
		'__'         => array( 0, null, null, 1 ),
		'_e'         => array( 0, null, null, 1 ),
		'esc_html__' => array( 0, null, null, 1 ),
		'esc_html_e' => array( 0, null, null, 1 ),
		'esc_attr__' => array( 0, null, null, 1 ),
		'esc_attr_e' => array( 0, null, null, 1 ),
		'_x'         => array( 0, null, 1, 2 ),
		'_ex'        => array( 0, null, 1, 2 ),
		'esc_html_x' => array( 0, null, 1, 2 ),
		'esc_attr_x' => array( 0, null, 1, 2 ),
		'_n'         => array( 0, 1, null, 3 ),
		'_nx'        => array( 0, 1, 2, 4 ),
		'_n_noop'    => array( 0, 1, null, 2 ),
		'_nx_noop'   => array( 0, 1, 2, 3 ),
	);

	/** Directories that never hold shipped source. */
	private const SKIP = '#/(vendor|node_modules|tests|libs|coverage-js|\.git)/#';

	/**
	 * @var array<string, array{key: string, msgctxt: string|null, msgid: string, msgid_plural: string|null, refs: array<int, string>}>|null
	 */
	private static ?array $calls = null;

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every PHP file that ships, relative to the repository root.
	 *
	 * @return array<int, string>
	 */
	public static function files(): array {
		$out  = array();
		$walk = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root(), \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $walk as $file ) {
			$path = $file->getPathname();
			if ( 'php' !== strtolower( $file->getExtension() ) || preg_match( self::SKIP, $path ) ) {
				continue;
			}
			$out[] = str_replace( self::root() . '/', '', $path );
		}
		sort( $out );

		return $out;
	}

	/**
	 * Every literal i18n call, keyed by `(msgctxt, msgid)`.
	 *
	 * @return array<string, array{key: string, msgctxt: string|null, msgid: string, msgid_plural: string|null, refs: array<int, string>}>
	 */
	public static function all(): array {
		if ( null !== self::$calls ) {
			return self::$calls;
		}

		$found = array();
		foreach ( self::files() as $relative ) {
			foreach ( self::in_file( $relative ) as $call ) {
				$key = $call['key'];
				if ( ! isset( $found[ $key ] ) ) {
					$found[ $key ] = $call;
					continue;
				}
				// The same string reached from several sites: one entry, many references.
				$found[ $key ]['refs'] = array_merge( $found[ $key ]['refs'], $call['refs'] );
				if ( null === $found[ $key ]['msgid_plural'] ) {
					$found[ $key ]['msgid_plural'] = $call['msgid_plural'];
				}
			}
		}
		ksort( $found );

		self::$calls = $found;

		return $found;
	}

	/**
	 * The calls in one file.
	 *
	 * @param string $relative Path relative to the repository root.
	 * @return array<int, array{key: string, msgctxt: string|null, msgid: string, msgid_plural: string|null, refs: array<int, string>}>
	 */
	public static function in_file( string $relative ): array {
		$tokens = token_get_all( (string) file_get_contents( self::root() . '/' . $relative ) );
		$count  = count( $tokens );
		$out    = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			// A leading backslash makes it T_NAME_FULLY_QUALIFIED, one token
			// whose text carries the slash -- the #1282 miss.
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
				continue;
			}
			$name = ltrim( $token[1], '\\' );
			if ( ! isset( self::FUNCTIONS[ $name ] ) ) {
				continue;
			}
			// `$obj->__()`, `Klass::__()` and `function __()` are not the
			// WordPress function, whatever they are named.
			$before = self::previous( $tokens, $i );
			if ( null !== $before && is_array( $before ) && in_array(
				$before[0],
				array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ),
				true
			) ) {
				continue;
			}

			$open = self::next_index( $tokens, $i );
			if ( null === $open || '(' !== $tokens[ $open ] ) {
				continue;
			}

			$call = self::resolve( self::arguments( $tokens, $open ), self::FUNCTIONS[ $name ] );
			if ( null === $call ) {
				continue;
			}
			$call['refs'] = array( $relative . ':' . $token[2] );
			$out[]        = $call;
		}

		return $out;
	}

	/**
	 * Turns a resolved argument list into an entry, or null when it is not ours.
	 *
	 * @param array<int, array<int, array{0: int, 1: string, 2: int}|string>> $args      Top-level arguments.
	 * @param array{0: int, 1: int|null, 2: int|null, 3: int}                 $positions Singular, plural, context, domain.
	 * @return array{key: string, msgctxt: string|null, msgid: string, msgid_plural: string|null, refs: array<int, string>}|null
	 */
	private static function resolve( array $args, array $positions ): ?array {
		[ $singular, $plural, $context, $domain ] = $positions;

		if ( ! isset( $args[ $domain ] ) || self::DOMAIN !== self::literal( $args[ $domain ] ) ) {
			return null;
		}
		$msgid = isset( $args[ $singular ] ) ? self::literal( $args[ $singular ] ) : null;
		if ( null === $msgid || '' === $msgid ) {
			return null;
		}

		$msgctxt = ( null !== $context && isset( $args[ $context ] ) ) ? self::literal( $args[ $context ] ) : null;

		return array(
			'key'          => PoCatalogue::key( $msgctxt, $msgid ),
			'msgctxt'      => $msgctxt,
			'msgid'        => $msgid,
			'msgid_plural' => ( null !== $plural && isset( $args[ $plural ] ) ) ? self::literal( $args[ $plural ] ) : null,
			'refs'         => array(),
		);
	}

	/**
	 * Splits a call's top-level arguments, starting at its `(`.
	 *
	 * Depth-aware so a nested `sprintf( … , … )` does not cut the list in the
	 * wrong place -- the same reason `StylesheetOwnershipTest`'s enqueue reader
	 * cannot `explode( ',', … )`.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Token stream.
	 * @param int                                                 $open   Index of the opening parenthesis.
	 * @return array<int, array<int, array{0: int, 1: string, 2: int}|string>>
	 */
	private static function arguments( array $tokens, int $open ): array {
		$depth = 0;
		$args  = array();
		$cur   = array();
		$count = count( $tokens );

		for ( $i = $open; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( '(' === $token || '[' === $token || '{' === $token ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( ')' === $token || ']' === $token || '}' === $token ) {
				--$depth;
				if ( 0 === $depth ) {
					$args[] = $cur;
					break;
				}
			} elseif ( ',' === $token && 1 === $depth ) {
				$args[] = $cur;
				$cur    = array();
				continue;
			}

			if ( $depth >= 1 ) {
				$cur[] = $token;
			}
		}

		return $args;
	}

	/**
	 * The constant string an argument evaluates to, or null when it is not one.
	 *
	 * Accepts a single literal or literals joined by `.`; anything else -- a
	 * variable, a call, a constant -- disqualifies the argument, which is the
	 * same answer WP's extractor gives.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens One argument's tokens.
	 * @return string|null
	 */
	private static function literal( array $tokens ): ?string {
		$value    = '';
		$expects  = true;
		$anything = false;

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( $expects ) {
				if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
					return null;
				}
				$value   .= self::unquote( $token[1] );
				$expects  = false;
				$anything = true;
				continue;
			}
			if ( '.' === $token ) {
				$expects = true;
				continue;
			}

			return null;
		}

		return ( $anything && ! $expects ) ? $value : null;
	}

	/**
	 * The value of a PHP string literal, single- or double-quoted.
	 *
	 * The two quote styles escape DIFFERENTLY -- `'\n'` is a backslash and an
	 * `n`, `"\n"` is a newline -- and a reader that treats them alike reports a
	 * phantom mismatch against a catalogue that holds the real character.
	 *
	 * @param string $raw The literal including its quotes.
	 * @return string
	 */
	private static function unquote( string $raw ): string {
		$body = substr( $raw, 1, -1 );

		return "'" === $raw[0]
			? str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $body )
			: stripcslashes( $body );
	}

	/**
	 * The previous meaningful token, skipping whitespace.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Token stream.
	 * @param int                                                 $index  Current index.
	 * @return array{0: int, 1: string, 2: int}|string|null
	 */
	private static function previous( array $tokens, int $index ) {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
				continue;
			}

			return $tokens[ $i ];
		}

		return null;
	}

	/**
	 * Index of the next meaningful token, skipping whitespace.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Token stream.
	 * @param int                                                 $index  Current index.
	 * @return int|null
	 */
	private static function next_index( array $tokens, int $index ): ?int {
		$count = count( $tokens );
		for ( $i = $index + 1; $i < $count; $i++ ) {
			if ( is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
				continue;
			}

			return $i;
		}

		return null;
	}
}
