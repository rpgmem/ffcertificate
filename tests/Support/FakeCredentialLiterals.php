<?php
/**
 * Finds credential literals in tests, seed scripts and workflows.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * The scan behind `FakeCredentialStandardTest` (#2637).
 *
 * A credential literal is a string given to a credential-like key: an array or
 * object key such as `password` or `smtp_pass`, a `define()` of a constant such
 * as `AUTH_KEY`, a YAML key such as `MARIADB_ROOT_PASSWORD`, or a command-line
 * flag such as `--admin_password=`, an assignment to such a key
 * (`$_POST['ffc_password'] = …`), a class constant named like one, a stub of a
 * function that hands one out (`wp_salt`, `wp_generate_password`), and a
 * password field filled by a JS test. What it reads is the literal only: a value
 * built from a variable, a constant or a secret is not a literal, and is not
 * judged here.
 */
final class FakeCredentialLiterals {

	/**
	 * The prefix every fake credential carries.
	 */
	public const PREFIX = 'fake-';

	/**
	 * Below this many characters a value is the test's subject (a password
	 * too short to accept, a `'0'` flag), not a credential.
	 */
	public const SUBJECT_MAX_LENGTH = 3;

	/**
	 * A key that holds a credential: the word is the whole key or its last
	 * segment, so `smtp_pass` and `new_password` match while `password_reset_url`
	 * and `passed` do not.
	 *
	 * A record's own link tokens (`magic_token`, `confirmation_token`) are not
	 * in it: the plugin generates them per record, nobody holds one as a login,
	 * and a test's value names the record it opens. An access or refresh token
	 * is a credential and is in.
	 */
	public const KEY = '/(?:^|[_-])(?:password|passwd|pass|pwd|secret|api_?key|salt|auth_?key|nonce_?key|logged_in_?key|encryption_?key|access_?token|refresh_?token|dbpass)$/i';

	/**
	 * Directories scanned, with the extensions read in each.
	 *
	 * @var array<string, list<string>>
	 */
	public const SCOPE = array(
		'tests'              => array( 'php', 'js' ),
		'.github/scripts'    => array( 'php', 'js', 'sh' ),
		'.github/workflows'  => array( 'yml', 'yaml' ),
	);

	/**
	 * Every file the scan reads, repository-relative and sorted.
	 *
	 * @return list<string>
	 */
	public static function files(): array {
		$root  = self::root();
		$files = array();
		foreach ( self::SCOPE as $dir => $extensions ) {
			$base = $root . '/' . $dir;
			if ( ! is_dir( $base ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && in_array( strtolower( $file->getExtension() ), $extensions, true ) && ! str_contains( $file->getPathname(), '/fixtures/' ) ) {
					$files[] = str_replace( $root . '/', '', $file->getPathname() );
				}
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Every credential literal in one source, with whether it is fake.
	 *
	 * @param string $source    File contents.
	 * @param string $extension `php`, `js`, `sh` or `yml`.
	 * @return list<array{line: int, key: string, value: string}>
	 */
	public static function literals( string $source, string $extension ): array {
		return 'php' === $extension ? self::php_literals( $source ) : self::text_literals( $source, 'js' === $extension );
	}

	/**
	 * Whether a literal is acceptable: fake, a subject value, or empty.
	 *
	 * @param string $value The literal's value.
	 */
	public static function is_acceptable( string $value ): bool {
		return strlen( $value ) <= self::SUBJECT_MAX_LENGTH || 0 === stripos( $value, self::PREFIX );
	}

	/**
	 * Every literal in the scope that is not acceptable.
	 *
	 * @return list<string> `file:line key => value`.
	 */
	public static function findings(): array {
		$out = array();
		foreach ( self::files() as $file ) {
			$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			$extension = 'yaml' === $extension ? 'yml' : $extension;
			foreach ( self::literals( (string) file_get_contents( self::root() . '/' . $file ), $extension ) as $hit ) {
				if ( ! self::is_acceptable( $hit['value'] ) ) {
					$out[] = sprintf( '%s:%d %s => %s', $file, $hit['line'], $hit['key'], $hit['value'] );
				}
			}
		}
		return $out;
	}

	/**
	 * Credential literals in PHP, read from tokens so a comment or a docblock
	 * that mentions a password is never one.
	 *
	 * @param string $source PHP source.
	 * @return list<array{line: int, key: string, value: string}>
	 */
	private static function php_literals( string $source ): array {
		$tokens = array_values(
			array_filter(
				token_get_all( $source ),
				static fn( $t ): bool => ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
			)
		);
		$out   = array();
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) ) {
				continue;
			}
			// 'key' => 'value'.
			if ( T_CONSTANT_ENCAPSED_STRING === $token[0]
				&& isset( $tokens[ $i + 2 ] )
				&& is_array( $tokens[ $i + 1 ] ) && T_DOUBLE_ARROW === $tokens[ $i + 1 ][0]
				&& is_array( $tokens[ $i + 2 ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $i + 2 ][0]
				&& 1 === preg_match( self::KEY, self::unquote( $token[1] ) )
			) {
				$out[] = array(
					'line'  => (int) $tokens[ $i + 2 ][2],
					'key'   => self::unquote( $token[1] ),
					'value' => self::unquote( $tokens[ $i + 2 ][1] ),
				);
			}
			// $_POST['key'] = 'value', $config['smtp_pass'] = 'value'.
			if ( T_CONSTANT_ENCAPSED_STRING === $token[0]
				&& isset( $tokens[ $i + 3 ] )
				&& ']' === $tokens[ $i + 1 ] && '=' === $tokens[ $i + 2 ]
				&& is_array( $tokens[ $i + 3 ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $i + 3 ][0]
				&& 1 === preg_match( self::KEY, self::unquote( $token[1] ) )
			) {
				$out[] = array(
					'line'  => (int) $tokens[ $i + 3 ][2],
					'key'   => self::unquote( $token[1] ),
					'value' => self::unquote( $tokens[ $i + 3 ][1] ),
				);
			}
			// const API_KEY = 'value'.
			if ( T_CONST === $token[0]
				&& isset( $tokens[ $i + 3 ] )
				&& is_array( $tokens[ $i + 1 ] ) && T_STRING === $tokens[ $i + 1 ][0]
				&& '=' === $tokens[ $i + 2 ]
				&& is_array( $tokens[ $i + 3 ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $i + 3 ][0]
				&& 1 === preg_match( self::KEY, $tokens[ $i + 1 ][1] )
			) {
				$out[] = array(
					'line'  => (int) $tokens[ $i + 3 ][2],
					'key'   => $tokens[ $i + 1 ][1],
					'value' => self::unquote( $tokens[ $i + 3 ][1] ),
				);
			}
			// Functions\when( 'wp_salt' )->justReturn( 'value' ): a stub of a
			// function that hands out a credential returns one.
			if ( in_array( $token[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true )
				&& in_array( strtolower( substr( (string) strrchr( '\\' . $token[1], '\\' ), 1 ) ), array( 'when', 'expect' ), true )
				&& isset( $tokens[ $i + 7 ] ) && '(' === $tokens[ $i + 1 ]
				&& is_array( $tokens[ $i + 2 ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $i + 2 ][0]
				&& ')' === $tokens[ $i + 3 ]
				&& is_array( $tokens[ $i + 4 ] ) && T_OBJECT_OPERATOR === $tokens[ $i + 4 ][0]
				&& is_array( $tokens[ $i + 5 ] ) && in_array( strtolower( $tokens[ $i + 5 ][1] ), array( 'justreturn', 'andreturn' ), true )
				&& '(' === $tokens[ $i + 6 ]
				&& is_array( $tokens[ $i + 7 ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $i + 7 ][0]
				&& 1 === preg_match( self::KEY, self::unquote( $tokens[ $i + 2 ][1] ) )
			) {
				$out[] = array(
					'line'  => (int) $tokens[ $i + 7 ][2],
					'key'   => self::unquote( $tokens[ $i + 2 ][1] ),
					'value' => self::unquote( $tokens[ $i + 7 ][1] ),
				);
			}
			// define( 'NAME', 'value' ).
			if ( T_STRING === $token[0] && 'define' === strtolower( $token[1] )
				&& isset( $tokens[ $i + 4 ] ) && '(' === $tokens[ $i + 1 ]
				&& is_array( $tokens[ $i + 2 ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $i + 2 ][0]
				&& ',' === $tokens[ $i + 3 ]
				&& is_array( $tokens[ $i + 4 ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $i + 4 ][0]
				&& 1 === preg_match( self::KEY, self::unquote( $tokens[ $i + 2 ][1] ) )
			) {
				$out[] = array(
					'line'  => (int) $tokens[ $i + 4 ][2],
					'key'   => self::unquote( $tokens[ $i + 2 ][1] ),
					'value' => self::unquote( $tokens[ $i + 4 ][1] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Credential literals in JS, shell and YAML, read line by line: an object
	 * or YAML key followed by a quoted or bare value, a `define()` written
	 * inside a string, and a `--flag=value` on a command line.
	 *
	 * In JS a key in capitals is a constant's NAME used as a map key (the
	 * encryption-key card maps `FFC_ENCRYPTION_KEY` to its description), never
	 * the constant itself; there a constant is only defined inside a `define()`
	 * snippet, which the second pattern reads.
	 *
	 * @param string $source Source text.
	 * @param bool   $is_js  Whether the source is JavaScript.
	 * @return list<array{line: int, key: string, value: string}>
	 */
	private static function text_literals( string $source, bool $is_js ): array {
		$out      = array();
		$patterns = array(
			// key: 'value', "key": "value", KEY: value (YAML), key = 'value'.
			'/(?<![\w$.-])["\']?([A-Za-z_][\w-]*)["\']?\s*[:=]\s*(?:"([^"\n]*)"|\'([^\'\n]*)\'|([^\s\'",}{)\]$][^\s,}{)\]]*))/',
			// define( 'NAME', 'value' ), however it is quoted.
			'/define\(\s*["\']([A-Z_]+)["\']\s*,\s*["\']([^"\']*)["\']/',
			// --flag=value on a command line.
			'/--([a-z_-]+)=(?:"([^"\n]*)"|\'([^\'\n]*)\'|([^\s\\\\]+))/i',
			// getElementById( 'smtp_pass' ).value = 'value', querySelector( '#x' ).value = 'value'.
			'/(?:getElementById|querySelector)\(\s*["\']#?([\w-]+)["\']\s*\)\.value\s*=\s*(?:"([^"\n]*)"|\'([^\'\n]*)\')/',
			// $( '#smtp_pass' ).val( 'value' ).
			'/\$\(\s*["\']#([\w-]+)["\']\s*\)\.val\(\s*(?:"([^"\n]*)"|\'([^\'\n]*)\')/',
		);
		foreach ( preg_split( '/\R/', $source ) as $index => $line ) {
			$trimmed = ltrim( $line );
			if ( str_starts_with( $trimmed, '#' ) || str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' ) ) {
				continue;
			}
			foreach ( $patterns as $index_pattern => $pattern ) {
				if ( ! preg_match_all( $pattern, $line, $matches, PREG_SET_ORDER ) ) {
					continue;
				}
				foreach ( $matches as $m ) {
					$key = $m[1];
					if ( 1 !== preg_match( self::KEY, $key ) || ( $is_js && 0 === $index_pattern && strtoupper( $key ) === $key ) ) {
						continue;
					}
					$value = '';
					foreach ( array_slice( $m, 2 ) as $part ) {
						if ( '' !== $part ) {
							$value = $part;
							break;
						}
					}
					// A variable, a secret, or a JS or YAML scalar that is not a string.
					if ( str_starts_with( $value, '$' ) || 1 === preg_match( '/^(?:true|false|null|\d+)$/i', $value ) ) {
						continue;
					}
					$out[] = array(
						'line'  => $index + 1,
						'key'   => $key,
						'value' => $value,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * A PHP string literal's value without its quotes.
	 *
	 * @param string $literal The literal as written.
	 */
	private static function unquote( string $literal ): string {
		return stripcslashes( substr( $literal, 1, -1 ) );
	}

	/**
	 * The repository root.
	 */
	public static function root(): string {
		return dirname( __DIR__, 2 );
	}
}
