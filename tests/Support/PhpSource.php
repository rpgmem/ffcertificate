<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Reading the plugin's PHP the way a guard has to read it.
 *
 * WHY COMMENTS ARE BLANKED RATHER THAN MATCHED AROUND
 *
 * Every guard built on this asks "does this file DO X", and prose that
 * MENTIONS X is not an occurrence of it. The files most likely to quote a
 * forbidden idiom verbatim are the ones documenting why it is forbidden --
 * including the guards' own registers -- so a text scan reports exactly the
 * files that explain themselves best. `DeprecationDueTest` records the same
 * rule from the other side: it reads comment tokens BECAUSE the marker it
 * looks for is a comment.
 *
 * Line numbers survive: a blanked comment leaves its newlines behind, so a
 * reported line is the real one and a reader can open the file at it.
 *
 * THIS IS SHARED FOR THE REASON `CssSelectors` AND `ffc-create-statements.php`
 * ARE SHARED: two guards measuring the same files must not disagree about what
 * the files say.
 *
 * @package FreeFormCertificate\Tests\Support
 * @since 6.26.0
 */
final class PhpSource {

	/**
	 * Every PHP file under a directory, repository-relative and sorted.
	 *
	 * @param string $relative_dir Repository-relative directory, e.g. `includes`.
	 * @return list<string>
	 */
	public static function files_under( string $relative_dir ): array {
		$root = self::root();
		$base = $root . '/' . trim( $relative_dir, '/' );

		if ( ! is_dir( $base ) ) {
			return array();
		}

		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = str_replace( $root . '/', '', $file->getPathname() );
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * A file's code with every comment blanked and line numbers preserved.
	 *
	 * @param string $relative Repository-relative path.
	 * @return list<string> One entry per source line, 0-indexed.
	 */
	public static function code_lines( string $relative ): array {
		return explode( "\n", self::code( $relative ) );
	}

	/**
	 * A file's code with every comment blanked.
	 *
	 * @param string $relative Repository-relative path.
	 * @return string
	 */
	public static function code( string $relative ): string {
		$source = (string) file_get_contents( self::root() . '/' . $relative );
		$code   = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) ) {
				$code .= $token;
				continue;
			}

			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				$code .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}

			$code .= $token[1];
		}

		return $code;
	}

	/**
	 * Files whose code matches a pattern, with the offending lines.
	 *
	 * @param list<string> $files   Repository-relative paths.
	 * @param string       $pattern PCRE applied per line.
	 * @return array<string, list<string>> Keyed by path; each entry `line: code`.
	 */
	public static function lines_matching( array $files, string $pattern ): array {
		$hits = array();

		foreach ( $files as $relative ) {
			foreach ( self::code_lines( $relative ) as $number => $line ) {
				if ( 1 === preg_match( $pattern, $line ) ) {
					$hits[ $relative ][] = ( $number + 1 ) . ': ' . trim( $line );
				}
			}
		}

		return $hits;
	}

	/**
	 * Calls to a named function, read from the token stream.
	 *
	 * A LINE SCAN CANNOT DO THIS, and the difference is not theoretical. The
	 * name may appear inside a translated string -- the migration registry's
	 * description literally says "the salted Encryption::hash()" -- and a
	 * sibling function may merely start with the same letters:
	 * `wp_create_user_request()` is WordPress's GDPR request builder, nothing
	 * to do with creating a user. Tokens distinguish both by construction: a
	 * call is a `T_STRING` holding the WHOLE name, followed by `(`, and a name
	 * inside a literal is a `T_CONSTANT_ENCAPSED_STRING`.
	 *
	 * @param string       $relative Repository-relative path.
	 * @param list<string> $names    Bare function names, e.g. `wp_create_user`.
	 * @return list<array{line: int, name: string}>
	 */
	public static function function_calls( string $relative, array $names ): array {
		$tokens = self::tokens( $relative );
		$out    = array();

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}
			if ( ! in_array( $token[1], $names, true ) ) {
				continue;
			}
			if ( '(' !== self::next_meaningful( $tokens, $index ) ) {
				continue;
			}
			// `Foo::wp_create_user()` and `$obj->wp_create_user()` are not this
			// function; only a bare call is.
			$previous = self::previous_meaningful( $tokens, $index );
			if ( '::' === $previous || '->' === $previous || 'function' === $previous ) {
				continue;
			}

			$out[] = array(
				'line' => (int) $token[2],
				'name' => (string) $token[1],
			);
		}

		return $out;
	}

	/**
	 * Calls to a static method of a named class, read from the token stream.
	 *
	 * The class is matched on its LAST namespace segment, so
	 * `\FreeFormCertificate\Core\Encryption::hash()`, an imported
	 * `Encryption::hash()` and `self::hash()` inside the class itself are the
	 * same call -- which is what a guard about a boundary needs, since the
	 * spelling of the import changes nothing about what is called.
	 *
	 * @param string $relative Repository-relative path.
	 * @param string $class    Bare class name, e.g. `Encryption`.
	 * @param string $method   Method name, e.g. `hash`.
	 * @return list<int> Line numbers.
	 */
	public static function static_calls( string $relative, string $class, string $method ): array {
		$tokens = self::tokens( $relative );
		$out    = array();

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || $method !== $token[1] ) {
				continue;
			}
			if ( '(' !== self::next_meaningful( $tokens, $index ) ) {
				continue;
			}
			if ( '::' !== self::previous_meaningful( $tokens, $index ) ) {
				continue;
			}

			$owner = self::previous_meaningful( $tokens, $index, 2 );
			if ( $class !== self::last_segment( $owner ) ) {
				continue;
			}

			$out[] = (int) $token[2];
		}

		return $out;
	}

	/**
	 * A file's tokens, with comments and whitespace left in place.
	 *
	 * @param string $relative Repository-relative path.
	 * @return list<array{0: int, 1: string, 2: int}|string>
	 */
	private static function tokens( string $relative ): array {
		return token_get_all( (string) file_get_contents( self::root() . '/' . $relative ) );
	}

	/**
	 * The next token that is not whitespace or a comment, as text.
	 *
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens Token stream.
	 * @param int                                           $from   Index to search after.
	 * @return string
	 */
	private static function next_meaningful( array $tokens, int $from ): string {
		$count = count( $tokens );

		for ( $i = $from + 1; $i < $count; $i++ ) {
			$text = self::meaningful_text( $tokens[ $i ] );
			if ( null !== $text ) {
				return $text;
			}
		}

		return '';
	}

	/**
	 * The Nth previous token that is not whitespace or a comment, as text.
	 *
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens Token stream.
	 * @param int                                           $from   Index to search before.
	 * @param int                                           $back   How many to step back.
	 * @return string
	 */
	private static function previous_meaningful( array $tokens, int $from, int $back = 1 ): string {
		$seen = 0;

		for ( $i = $from - 1; $i >= 0; $i-- ) {
			$text = self::meaningful_text( $tokens[ $i ] );
			if ( null === $text ) {
				continue;
			}

			++$seen;
			if ( $seen === $back ) {
				return $text;
			}
		}

		return '';
	}

	/**
	 * A token's text, or null when it carries no meaning here.
	 *
	 * @param array{0: int, 1: string, 2: int}|string $token Token.
	 * @return string|null
	 */
	private static function meaningful_text( $token ): ?string {
		if ( ! is_array( $token ) ) {
			return $token;
		}

		if ( T_WHITESPACE === $token[0] || T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
			return null;
		}

		return $token[1];
	}

	/**
	 * The last segment of a possibly-qualified class name.
	 *
	 * @param string $name Class reference as written.
	 * @return string
	 */
	private static function last_segment( string $name ): string {
		$parts = explode( '\\', $name );

		return (string) end( $parts );
	}

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}
}
