<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Every place the plugin's own markup names a WordPress core button class
 * (#1904).
 *
 * The `.ffc-btn` family (CLAUDE.md "Buttons") is the plugin's one way to draw
 * an action. A core class -- `button`, `button-primary`, `page-title-action`,
 * a `submit_button()` call -- draws WordPress's button instead, which ignores
 * the family's categories, icons and dark theme. `CoreButtonClassRatchetTest`
 * froze what was left, per file, until #1904 took it to zero; it blocks at
 * zero now, and this is the scan it reads.
 *
 * WHY NOT `CssClassEmitters`. That scan answers "is this `ffc-*` class emitted
 * anywhere", so it records a set of names, never a count, and it reads a bare
 * token only inside a literal `class="…"` attribute. A core class mostly
 * arrives through the shapes it does not count: `'class' => 'button'`,
 * `$btn_class = $x ? 'button button-primary' : 'button'`, a `sprintf()`
 * argument (`' button-primary'`), a jQuery selector (`'a.button-small'`), and
 * the `submit_button()` call, which carries no class at all in the source.
 * A per-file ratchet needs a count, so the shapes are read here, on the same
 * comment-stripped source (`PhpSource::code()` for PHP, `strip_js_comments()`
 * for JS).
 *
 * WHAT COUNTS -- each occurrence of a refused token, at most once per offset:
 *
 *  1. inside a `class="…"` attribute value, in markup, a PHP string or a JS
 *     string, PHP blocks in the value included;
 *  2. inside a string literal of the VALUE given to a class: a `'class' =>`
 *     array key, a JS `class:` / `className:` key, a variable whose name
 *     contains `class` (`$btn_class =`, `$class .=`, `className +=`), the class
 *     API (`addClass()`, `toggleClass()`, `classList.add()`, `.attr( 'class', … )`);
 *  3. a string literal made of class tokens only, every one a refused token or
 *     an `ffc-*` class, that is not the bare word `'button'` -- `' button-primary'`
 *     as a `sprintf()` argument has no class context to read;
 *  4. a refused token written as a selector, `.button-small`, in a string;
 *  5. a `submit_button()` / `get_submit_button()` call.
 *
 * WHAT DOES NOT, by construction: `type="button"`, `'type' => 'button'`,
 * `role="button"`, `<button>`, `FFC.button()`, `Button::html()`, `event.button`,
 * `config.button`, `data-button=`, `.ffc-btn`, and prose that says "button".
 *
 * WordPress-printed controls on our screens (list-table "Apply", pagination,
 * `search_box()`, the media modal) are not in the source and so are never
 * read; the dark-mode rules for them in `ffc-common.css` stay, and sheets are
 * not scanned at all.
 *
 * @package FreeFormCertificate\Tests\Support
 */
final class CoreButtonClasses {

	/**
	 * The WordPress core button classes the family replaces.
	 */
	public const TOKENS = array(
		'button',
		'button-primary',
		'button-secondary',
		'button-link',
		'button-link-delete',
		'button-small',
		'button-large',
		'button-hero',
		'page-title-action',
	);

	/**
	 * The key the register uses for a `submit_button()` / `get_submit_button()` call.
	 */
	public const SUBMIT_CALL = 'submit_button()';

	/**
	 * Files whose core buttons stay, with why. Excluded by name, so a file
	 * moving out of them is scanned again at once.
	 *
	 * E-mail bodies, the rasterised documents and the family's own helpers
	 * (`Core\Button`, `FFC.button()`) need no entry: none of them carries a
	 * core class token, and the guard's canary pins that `'type' => 'button'`,
	 * `createElement( 'button' )` and `FFC.button()` never count. An entry is
	 * added only for a file that does print one on purpose.
	 */
	public const EXCLUDED = array(
		'includes/admin/class-ffc-admin-user-capabilities.php' => 'core screen: the capability grid on profile.php / user-edit.php, where the buttons around it are WordPress\'s own',
		'includes/admin/class-ffc-admin-user-columns.php'      => 'core screen: the "View as user" link in the users.php list, beside core\'s row actions',
		'includes/admin/class-ffc-admin-user-custom-fields.php' => 'core screen: the plugin\'s fields on profile.php / user-edit.php, beside core\'s own buttons',
		'includes/date-messages/class-ffc-unsubscribe.php'     => 'a wp_die() page: no plugin stylesheet loads there, and wp_die\'s own CSS is what styles .button',
	);

	private const PHP_ROOTS = array( 'includes', 'templates' );
	private const JS_ROOT   = 'assets/js';

	/**
	 * A complete JS string literal, escapes included, so a quote of the other
	 * kind is content. A quoted string ends at its line, as JS requires, so a
	 * regex literal holding a quote (`/'/g`) cannot shift every quote after
	 * it; a template literal may span lines. The content is group 2 or 3.
	 */
	private const JS_LITERAL = '/(["\'])((?:\\\\.|(?!\1)[^\\\\\n])*)\1|`((?:\\\\.|[^`\\\\])*)`/';

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
	 * Whether a repository-relative path is excluded.
	 *
	 * @param string $file Repository-relative path.
	 */
	public static function is_excluded( string $file ): bool {
		foreach ( array_keys( self::EXCLUDED ) as $path ) {
			if ( $file === $path || str_starts_with( $file, $path . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every occurrence in the scanned files.
	 *
	 * @return array<string, array<string, int>> File => token => count.
	 */
	public static function found(): array {
		$out = array();

		foreach ( self::php_files() as $file ) {
			$hits = self::in_code( PhpSource::code( $file ), true );
			if ( array() !== $hits ) {
				$out[ $file ] = $hits;
			}
		}
		foreach ( self::js_files() as $file ) {
			$hits = self::in_code( self::strip_js_comments( (string) file_get_contents( self::root() . '/' . $file ) ), false );
			if ( array() !== $hits ) {
				$out[ $file ] = $hits;
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Occurrences in the excluded files, so the guard can tell an exclusion
	 * that still excludes something from one that went stale.
	 *
	 * @return array<string, int> Excluded path => occurrences under it.
	 */
	public static function found_in_excluded(): array {
		$out = array_fill_keys( array_keys( self::EXCLUDED ), 0 );

		foreach ( self::PHP_ROOTS as $root ) {
			foreach ( PhpSource::files_under( $root ) as $file ) {
				foreach ( array_keys( self::EXCLUDED ) as $path ) {
					if ( $file === $path || str_starts_with( $file, $path . '/' ) ) {
						$out[ $path ] += array_sum( self::in_code( PhpSource::code( $file ), true ) );
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Occurrences in a piece of comment-free code, per token.
	 *
	 * Public so the guard can prove the shapes on synthetic source.
	 *
	 * @param string $code   Code with its comments already removed.
	 * @param bool   $is_php Whether `submit_button()` calls are read.
	 * @return array<string, int> Token (or `submit_button()`) => count, sorted.
	 */
	public static function in_code( string $code, bool $is_php ): array {
		$hits = array();
		$tok  = self::token_pattern();

		$record = static function ( string $token, int $offset ) use ( &$hits ): void {
			$hits[ $offset ] = $token;
		};

		$literals = self::literals( $code, $is_php );

		// Shape 1: the class attribute.
		if ( preg_match_all( '/(?<![\w-])class\s*=\s*(\\\\?)(["\'])/i', $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $hit ) {
				$start = $hit[0][1] + strlen( $hit[0][0] );
				$close = $hit[1][0] . $hit[2][0];
				$end   = self::attribute_end( $code, $start, $close );
				$value = substr( $code, $start, $end - $start );
				if ( preg_match_all( $tok, $value, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[1] as $token ) {
						$record( $token[0], $start + $token[1] );
					}
				}
			}
		}

		// Shape 2: the value given to a class.
		$contexts = array(
			'/(["\'])class\1\s*=>\s*/i',
			'/(?<![\w$.-])(?:class|className)\s*:\s*(?![:])/',
			'/\[\s*(["\'])class\1\s*\]\s*\.?=(?![=>])\s*/i',
			'/\$\w*class\w*(?:\[\s*\])?\s*\.?=(?![=>])\s*/i',
			'/(?<![\w$])(?!class\s*=)\w*[Cc]lass\w*\s*\+?=(?![=>])\s*/',
			'/(?:(?:add|remove|toggle|has)Class|classList\.\w+)\s*\(/',
			'/(?:\.attr|setAttribute)\s*\(\s*(["\'])class\1\s*,\s*/',
		);
		foreach ( $contexts as $pattern ) {
			if ( ! preg_match_all( $pattern, $code, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $m[0] as $hit ) {
				$start = $hit[1] + strlen( $hit[0] );
				$end   = self::expression_end( $code, $start );
				foreach ( $literals as $literal ) {
					if ( $literal[1] < $start || $literal[1] >= $end ) {
						continue;
					}
					if ( preg_match_all( $tok, $literal[0], $t, PREG_OFFSET_CAPTURE ) ) {
						foreach ( $t[1] as $token ) {
							$record( $token[0], $literal[1] + $token[1] );
						}
					}
				}
			}
		}

		foreach ( $literals as $literal ) {
			// Shape 3: a literal made of class tokens only.
			$words = preg_split( '/\s+/', trim( $literal[0] ) ) ?: array();
			$all   = array() !== $words && '' !== $words[0];
			$core  = false;
			foreach ( $words as $word ) {
				if ( in_array( $word, self::TOKENS, true ) ) {
					$core = true;
				} elseif ( ! preg_match( '/^ffc-[a-z0-9_-]+$/', $word ) ) {
					$all = false;
				}
			}
			if ( $all && $core && array( 'button' ) !== $words && preg_match_all( $tok, $literal[0], $t, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $t[1] as $token ) {
					$record( $token[0], $literal[1] + $token[1] );
				}
			}

			// Shape 4: a selector.
			if ( preg_match_all( '/(?<=\.)(' . self::alternation() . ')(?![\w-])/', $literal[0], $t, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $t[1] as $token ) {
					$record( $token[0], $literal[1] + $token[1] );
				}
			}
		}

		$out = array();
		foreach ( $hits as $token ) {
			$out[ $token ] = ( $out[ $token ] ?? 0 ) + 1;
		}

		// Shape 5: the core helper that prints a button.
		if ( $is_php ) {
			$calls = (int) preg_match_all( '/(?<![\w$>:])(?<!function )\\\\?(?:get_)?submit_button\s*\(/', $code );
			if ( $calls > 0 ) {
				$out[ self::SUBMIT_CALL ] = $calls;
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * How many occurrences there are, read another way.
	 *
	 * The scan lists files with the recursive iterator and strips comments
	 * with the PHP tokenizer and a JS character loop. This reading lists the
	 * files with `glob()` and strips comments with `php_strip_whitespace()`
	 * and one regex -- code paths that share nothing with the scan's -- so two
	 * readings that agree on the total have not both lost the same file or
	 * the same occurrence.
	 *
	 * @return int
	 */
	public static function total_by_second_reading(): int {
		$total = 0;

		foreach ( self::PHP_ROOTS as $root ) {
			foreach ( InlineStyles::glob_recursive( self::root() . '/' . $root, '*.php' ) as $path ) {
				if ( self::is_excluded( str_replace( self::root() . '/', '', $path ) ) ) {
					continue;
				}
				$total += array_sum( self::in_code( php_strip_whitespace( $path ), true ) );
			}
		}

		foreach ( glob( self::root() . '/' . self::JS_ROOT . '/*.js' ) ?: array() as $path ) {
			if ( str_contains( basename( $path ), '.min.' ) ) {
				continue;
			}
			$code   = (string) preg_replace_callback(
				'~("(?:\\\\.|[^"\\\\\n])*"|\'(?:\\\\.|[^\'\\\\\n])*\'|`(?:\\\\.|[^`\\\\])*`)|//[^\n]*|/\*.*?\*/~s',
				static fn ( array $m ): string => '' !== ( $m[1] ?? '' ) ? $m[1] : ' ',
				(string) file_get_contents( $path )
			);
			$total += array_sum( self::in_code( $code, false ) );
		}

		return $total;
	}

	/**
	 * A refused token, delimited as a class name: not part of a longer name,
	 * not a PHP or JS variable (`$button`), not a property (`config.button`).
	 */
	private static function token_pattern(): string {
		return '/(?<![\w$.-])(' . self::alternation() . ')(?![\w-])/';
	}

	private static function alternation(): string {
		$tokens = self::TOKENS;
		usort( $tokens, static fn ( string $a, string $b ): int => strlen( $b ) - strlen( $a ) );

		return implode( '|', array_map( static fn ( string $t ): string => preg_quote( $t, '/' ), $tokens ) );
	}

	/**
	 * Every string literal, as content => offset of the content.
	 *
	 * PHP is read with its own tokenizer, so an apostrophe in the inline HTML
	 * between the tags ("don't") can never open a literal and shift every
	 * quote after it; JS is read with a regex whose alternatives each consume
	 * a WHOLE literal, escapes included.
	 *
	 * @param string $code   Code.
	 * @param bool   $is_php Which reader applies.
	 * @return list<array{0: string, 1: int}>
	 */
	private static function literals( string $code, bool $is_php ): array {
		$out = array();

		if ( $is_php ) {
			$offset = 0;
			foreach ( token_get_all( $code ) as $token ) {
				$text = is_array( $token ) ? $token[1] : $token;
				if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$out[] = array( substr( $text, 1, -1 ), $offset + 1 );
				} elseif ( is_array( $token ) && T_ENCAPSED_AND_WHITESPACE === $token[0] ) {
					$out[] = array( $text, $offset );
				}
				$offset += strlen( $text );
			}

			return $out;
		}

		if ( preg_match_all( self::JS_LITERAL, $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $hit ) {
				$content = ( isset( $hit[3] ) && -1 !== $hit[3][1] ) ? $hit[3] : $hit[2];
				$out[]   = array( $content[0], $content[1] );
			}
		}

		return $out;
	}

	/**
	 * Where an attribute value opened at `$start` closes: the next `$close`
	 * outside a PHP block, or 400 characters on, whichever comes first.
	 *
	 * @param string $code  Code.
	 * @param int    $start Offset of the first character of the value.
	 * @param string $close The closing quote, `\"` when the opener was escaped.
	 */
	private static function attribute_end( string $code, int $start, string $close ): int {
		$limit = min( strlen( $code ), $start + 400 );
		$i     = $start;

		while ( $i < $limit ) {
			if ( '<?' === substr( $code, $i, 2 ) ) {
				$end = strpos( $code, '?>', $i + 2 );
				$i   = false === $end ? $limit : $end + 2;
				continue;
			}
			if ( substr( $code, $i, strlen( $close ) ) === $close ) {
				return $i;
			}
			++$i;
		}

		return $limit;
	}

	/**
	 * Where an expression starting at `$start` ends: the first `,` `;` or
	 * unbalanced closing bracket outside a string, a PHP close tag, or 400
	 * characters on.
	 *
	 * @param string $code  Code.
	 * @param int    $start Offset.
	 */
	private static function expression_end( string $code, int $start ): int {
		$limit = min( strlen( $code ), $start + 400 );
		$depth = 0;
		$quote = '';

		for ( $i = $start; $i < $limit; $i++ ) {
			$ch = $code[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $ch ) {
					++$i;
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $ch || "'" === $ch || '`' === $ch ) {
				$quote = $ch;
			} elseif ( '(' === $ch || '[' === $ch || '{' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch || ']' === $ch || '}' === $ch ) {
				if ( 0 === $depth ) {
					return $i;
				}
				--$depth;
			} elseif ( 0 === $depth && ( ',' === $ch || ';' === $ch ) ) {
				return $i;
			} elseif ( '?' === $ch && '>' === ( $code[ $i + 1 ] ?? '' ) ) {
				return $i;
			}
		}

		return $limit;
	}

	/**
	 * Strips comments from a JS source, preserving strings.
	 *
	 * Not `JsIdSelectors::strip_comments()`, whose quote state runs across
	 * lines: a regex literal holding a quote (`.replace(/'/g, '&#39;')`) opens
	 * a "string" there that never closes on its line, and every comment after
	 * it is read as code -- which is how a `// … FFC.button() …` sentence in
	 * `ffc-user-dashboard-cal-export.js` first counted as a `.button` selector.
	 * A quoted JS string cannot cross a line, so this one ends it at the
	 * newline; a template literal can, and keeps going.
	 *
	 * @param string $src JS source.
	 */
	public static function strip_js_comments( string $src ): string {
		$out   = '';
		$len   = strlen( $src );
		$quote = '';

		for ( $i = 0; $i < $len; $i++ ) {
			$ch   = $src[ $i ];
			$next = $i + 1 < $len ? $src[ $i + 1 ] : '';

			if ( '' !== $quote ) {
				$out .= $ch;
				if ( '\\' === $ch ) {
					$out .= $next;
					++$i;
				} elseif ( $ch === $quote || ( "\n" === $ch && '`' !== $quote ) ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $ch || "'" === $ch || '`' === $ch ) {
				$quote = $ch;
				$out  .= $ch;
			} elseif ( '/' === $ch && '/' === $next ) {
				while ( $i + 1 < $len && "\n" !== $src[ $i + 1 ] ) {
					++$i;
				}
				$out .= ' ';
			} elseif ( '/' === $ch && '*' === $next ) {
				$end  = strpos( $src, '*/', $i + 2 );
				$i    = false === $end ? $len : $end + 1;
				$out .= ' ';
			} else {
				$out .= $ch;
			}
		}

		return $out;
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}
}
