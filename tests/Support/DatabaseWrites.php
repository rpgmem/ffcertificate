<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Every `$wpdb->insert`/`update`/`replace`, with the table and columns it names (#1447).
 *
 * TOKENS AND BRACKET MATCHING, NEVER A WINDOW
 *
 * A first prototype scanned a fixed character window after the call and matched
 * `'k' =>` inside it. That reads the NEIGHBOURING code: index names from an
 * adjacent `ALTER`, row arrays belonging to another statement. It reported 23
 * findings of which none was the defect it was written for, because
 * `ActivityLog`'s two undeclared columns are written by a conditional
 * `$row_data['col'] = …` assignment rather than an array literal. Arguments are
 * split here by counting depth over `token_get_all()`, so an argument ends where
 * PHP says it ends.
 *
 * SCOPE, AND THE LAST ASSIGNMENT BEFORE THE CALL
 *
 * The second prototype resolved `$table` by the FIRST matching assignment in the
 * file. In `ReregistrationRepository` that is `self::get_audiences_table_name()`
 * at line 103, while the insert at line 366 uses the `self::get_table_name()`
 * assigned at line 350 — so eleven columns of `ffc_reregistrations` were
 * reported as missing from `ffc_reregistration_audiences`. Twenty-five of the
 * twenty-seven findings were that one bug. A variable is resolved here inside
 * its OWN function body, truncated at the call, and the LAST assignment wins,
 * which is what PHP does.
 *
 * @package FreeFormCertificate\Tests\Support
 */
final class DatabaseWrites {

	/**
	 * Write calls in one file, with top-level arguments as source text.
	 *
	 * @param string $path File path.
	 * @return list<array{index: int, line: int, method: string, args: list<string>}>
	 */
	public static function calls( string $path ): array {
		$tokens = token_get_all( (string) file_get_contents( $path ) );
		$out    = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_VARIABLE !== $token[0] || '$wpdb' !== $token[1] ) {
				continue;
			}

			$j = self::skip_space( $tokens, $i + 1 );

			if ( ! ( is_array( $tokens[ $j ] ) && T_OBJECT_OPERATOR === $tokens[ $j ][0] ) ) {
				continue;
			}

			$j = self::skip_space( $tokens, $j + 1 );

			if ( ! ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) ) {
				continue;
			}

			$method = strtolower( $tokens[ $j ][1] );

			if ( ! in_array( $method, array( 'insert', 'update', 'replace' ), true ) ) {
				continue;
			}

			$j = self::skip_space( $tokens, $j + 1 );

			if ( '(' !== $tokens[ $j ] ) {
				continue;
			}

			$out[] = array(
				'index'  => $i,
				'line'   => (int) $token[2],
				'method' => $method,
				'args'   => self::top_level_args( $tokens, $j ),
			);
		}

		return $out;
	}

	/**
	 * The table an expression names, or null when no static reading resolves it.
	 *
	 * @param string      $expr         The first argument, as source text.
	 * @param string|null $scope        The enclosing function body up to the call.
	 * @param string      $text         The whole file, for a method's `return`.
	 * @param string      $includes_dir Absolute path to `includes/`.
	 * @return string|null
	 */
	public static function resolve_table( string $expr, ?string $scope, string $text, string $includes_dir ): ?string {
		$expr = trim( $expr );

		if ( '' === $expr ) {
			return null;
		}

		if ( preg_match( '/\$wpdb->prefix\s*\.\s*[\'"]([a-z0-9_]+)[\'"]/', $expr, $inline ) ) {
			return $inline[1];
		}

		if ( preg_match( '/^(self|static|\$this|\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Z][A-Za-z0-9_]*)(?:::|->)([a-z_]+)\(\s*\)$/', $expr, $call ) ) {
			return self::method_literal( $call[2], self::receiver( $call[1] ), $text, $includes_dir );
		}

		if ( ! preg_match( '/^(\$this->[a-z_]+|\$[a-z_][a-z0-9_]*)$/i', $expr ) ) {
			return null;
		}

		$quoted = preg_quote( $expr, '/' );

		if ( null !== $scope && preg_match_all( '/' . $quoted . '\s*=\s*([^;]+);/', $scope, $all, PREG_SET_ORDER ) ) {
			$resolved = self::from_rhs( trim( end( $all )[1] ), $scope, $text, $includes_dir );

			if ( null !== $resolved ) {
				return $resolved;
			}
		}

		// A property assigned in the constructor sits outside the scope slice.
		if ( 0 === strpos( $expr, '$this->' ) && preg_match( '/' . $quoted . '\s*=\s*([^;]+);/', $text, $property ) ) {
			return self::from_rhs( trim( $property[1] ), null, $text, $includes_dir );
		}

		return null;
	}

	/**
	 * Column names a data argument carries, or null when none can be read.
	 *
	 * Null is not "no columns": it is *this scan could not tell*, which the
	 * caller must register rather than skip.
	 *
	 * @param string      $arg   The data argument, as source text.
	 * @param string|null $scope The enclosing function body up to the call.
	 * @return list<string>|null
	 */
	public static function data_columns( string $arg, ?string $scope ): ?array {
		$arg = trim( $arg );

		if ( '' === $arg ) {
			return array();
		}

		if ( 0 === strpos( $arg, 'array(' ) || 0 === strpos( $arg, '[' ) ) {
			preg_match_all( "/'([a-z_][a-z0-9_]*)'\s*=>/", $arg, $keys );

			return self::lowered( $keys[1] );
		}

		if ( null === $scope || ! preg_match( '/^\$[a-z_][a-z0-9_]*$/i', $arg ) ) {
			return null;
		}

		$quoted  = preg_quote( $arg, '/' );
		$columns = array();

		if ( preg_match_all( '/' . $quoted . '\s*=\s*array\((.*?)\n\s*\);/s', $scope, $literal, PREG_SET_ORDER ) ) {
			preg_match_all( "/'([a-z_][a-z0-9_]*)'\s*=>/", end( $literal )[1], $keys );
			$columns = array_merge( $columns, $keys[1] );
		}

		// The shape that hid #1444: a key assigned onto the array afterwards.
		if ( preg_match_all( '/' . $quoted . "\[\s*'([a-z_][a-z0-9_]*)'\s*\]\s*=/", $scope, $assigned ) ) {
			$columns = array_merge( $columns, $assigned[1] );
		}

		return array() === $columns ? null : self::lowered( $columns );
	}

	/**
	 * The enclosing function's body, truncated at the call.
	 *
	 * @param array<int, mixed> $tokens Token list.
	 * @param int               $call   Token index of the call.
	 * @return string|null
	 */
	public static function scope_before( array $tokens, int $call ): ?string {
		foreach ( self::function_ranges( $tokens ) as $range ) {
			if ( $call >= $range[0] && $call <= $range[1] ) {
				return self::source_of( $tokens, $range[0], $call );
			}
		}

		return null;
	}

	/**
	 * Byte-free token ranges of every function body in a token list.
	 *
	 * @param array<int, mixed> $tokens Token list.
	 * @return list<array{0: int, 1: int}>
	 */
	private static function function_ranges( array $tokens ): array {
		$out   = array();
		$count = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! ( is_array( $tokens[ $i ] ) && T_FUNCTION === $tokens[ $i ][0] ) ) {
				continue;
			}

			$depth = 0;
			$start = null;

			for ( $k = $i; $k < $count; $k++ ) {
				$text = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];

				if ( '{' === $text ) {
					if ( null === $start ) {
						$start = $k;
					}
					++$depth;
					continue;
				}

				if ( '}' === $text ) {
					--$depth;
					if ( 0 === $depth && null !== $start ) {
						$out[] = array( $start, $k );
						break;
					}
					continue;
				}

				// An abstract or interface method has no body.
				if ( ';' === $text && null === $start ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Split the top-level arguments of a call whose `(` sits at `$open`.
	 *
	 * @param array<int, mixed> $tokens Token list.
	 * @param int               $open   Index of the opening paren.
	 * @return list<string>
	 */
	private static function top_level_args( array $tokens, int $open ): array {
		$depth   = 0;
		$args    = array();
		$current = '';
		$count   = count( $tokens );

		for ( $k = $open; $k < $count; $k++ ) {
			$text = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];

			if ( '(' === $text || '[' === $text ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( ')' === $text || ']' === $text ) {
				--$depth;
				if ( 0 === $depth ) {
					$args[] = $current;
					break;
				}
			} elseif ( ',' === $text && 1 === $depth ) {
				$args[]  = $current;
				$current = '';
				continue;
			}

			$current .= $text;
		}

		return array_map( 'trim', $args );
	}

	/**
	 * Resolve the right-hand side of an assignment to a table name.
	 */
	private static function from_rhs( string $rhs, ?string $scope, string $text, string $includes_dir ): ?string {
		if ( preg_match( '/\$wpdb->prefix\s*\.\s*[\'"]([a-z0-9_]+)[\'"]/', $rhs, $inline ) ) {
			return $inline[1];
		}

		if ( preg_match( '/^(self|static|\$this|\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Z][A-Za-z0-9_]*)(?:::|->)([a-z_]+)\(\s*\)$/', $rhs, $call ) ) {
			return self::method_literal( $call[2], self::receiver( $call[1] ), $text, $includes_dir );
		}

		if ( preg_match( '/^(\$this->[a-z_]+|\$[a-z_][a-z0-9_]*)$/i', $rhs ) ) {
			return self::resolve_table( $rhs, $scope, $text, $includes_dir );
		}

		return null;
	}

	/**
	 * The literal a named method returns, through the shared create-statement parser.
	 *
	 * `$class` is the receiver when the call names one. IT MATTERS: asking
	 * `ffc_literal_returned_anywhere()` for `get_table_name` returns whichever
	 * class in the tree declares it FIRST, and dozens do. That made
	 * `CandidatePersister`'s `RecruitmentCandidateReader::get_table_name()`
	 * resolve to `ffc_reregistration_submissions` and report `pcd_hash` as
	 * undeclared -- a confident wrong answer, which is worse than a null this
	 * guard would register. So a named receiver is resolved in ITS OWN file, and
	 * a receiver whose file cannot be found stays unresolved.
	 *
	 * @param string      $method       Method name.
	 * @param string|null $class        Receiver class, or null for self/static/$this.
	 * @param string      $text         The calling file.
	 * @param string      $includes_dir Absolute path to `includes/`.
	 */
	private static function method_literal( string $method, ?string $class, string $text, string $includes_dir ): ?string {
		require_once dirname( __DIR__, 2 ) . '/.github/scripts/ffc-create-statements.php';

		if ( null === $class ) {
			return ffc_literal_returned_by( $text, $method );
		}

		$source = self::source_declaring( $class, $includes_dir );

		return null === $source ? null : ffc_literal_returned_by( $source, $method );
	}

	/**
	 * The file that declares a class, by its short name.
	 *
	 * Null when no file declares it, or when MORE THAN ONE does -- an ambiguous
	 * answer is the failure mode this exists to avoid.
	 */
	private static function source_declaring( string $class, string $includes_dir ): ?string {
		$short = substr( (string) strrchr( '\\' . $class, '\\' ), 1 );

		if ( '' === $short ) {
			return null;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $includes_dir, \FilesystemIterator::SKIP_DOTS )
		);

		$found = null;

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			$source = (string) file_get_contents( $path );

			if ( ! preg_match( '/^\s*(?:final\s+|abstract\s+)?class\s+' . preg_quote( $short, '/' ) . '\b/m', $source ) ) {
				continue;
			}

			if ( null !== $found ) {
				return null;
			}

			$found = $source;
		}

		return $found;
	}

	/**
	 * Null for a same-class receiver, the class name otherwise.
	 */
	private static function receiver( string $prefix ): ?string {
		return in_array( $prefix, array( 'self', 'static', '$this' ), true ) ? null : $prefix;
	}

	/**
	 * @param array<int, mixed> $tokens Token list.
	 */
	private static function source_of( array $tokens, int $from, int $to ): string {
		$source = '';

		for ( $i = $from; $i <= $to; $i++ ) {
			$source .= is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
		}

		return $source;
	}

	/**
	 * @param array<int, mixed> $tokens Token list.
	 */
	private static function skip_space( array $tokens, int $i ): int {
		$count = count( $tokens );

		while ( $i < $count && is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
			++$i;
		}

		return $i;
	}

	/**
	 * @param array<int, string> $names Raw names.
	 * @return list<string>
	 */
	private static function lowered( array $names ): array {
		$out = array();

		foreach ( $names as $name ) {
			$out[] = strtolower( $name );
		}

		return array_values( array_unique( $out ) );
	}
}
