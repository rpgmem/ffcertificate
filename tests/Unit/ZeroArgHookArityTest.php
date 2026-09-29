<?php
/**
 * Zero-argument hook arity guard (#1521).
 *
 * WordPress's request entry points — `admin_post_*`, `wp_ajax_*` and
 * `admin_action_*` — all fire with NO arguments. Core then does this, in
 * `wp-includes/plugin.php`:
 *
 *     function do_action( $hook_name, ...$arg ) {
 *         …
 *         if ( empty( $arg ) ) { $arg[] = ''; }
 *
 * and `add_action()`'s `$accepted_args` defaults to **1**, so `WP_Hook` takes
 * its `$accepted_args >= $num_args` branch and hands that literal `''` to the
 * callback. A handler whose first parameter is TYPED therefore receives a
 * string it cannot accept, and under `declare(strict_types=1)` that is a
 * TypeError: the request dies before emitting a byte.
 *
 * That is not hypothetical. Two handlers shipped in this state, each carrying a
 * typed parameter as a test-injection seam:
 *
 *   - `RecruitmentNoticeEditPage::handle_download_csv_example( ?CsvStreamer )`
 *     — the "download example CSV" link did nothing, for as long as it existed.
 *   - `PublicCsvDownload::handle_export_log_request( ?SyncCsvExport )` — the
 *     public-download audit-log export, dead and never reported.
 *
 * **Why the suite could not see either, which is the part worth keeping.** The
 * tests call these handlers the way a TEST calls them — with no argument, or
 * with the double they exist to inject — and a separate test asserts only that
 * `add_action` was *called* with the hook name. Presence on both sides, with
 * the thing in between (what WordPress actually passes) supplied by neither.
 * This guard is that missing middle, and it is static: the fix is expressed in
 * the registration, so the registration is what gets checked.
 *
 * The fix is `accepted_args = 0`, which is also the truth about the hook —
 * `WP_Hook` then takes its `call_user_func( $callback )` branch with no
 * arguments at all. Dropping the parameter would work too, but it would delete
 * a seam the tests legitimately use.
 *
 * Deliberately dependency-free — no WordPress, no Brain\Monkey, no reflection
 * and so no autoloading. It reads the tree with `token_get_all()`, which means
 * it cannot fail for environmental reasons and cannot be fooled by a signature
 * spread over several lines.
 *
 * What it does NOT prove: that the handler is reachable, capability-gated, or
 * correct. `AjaxWiringTest` covers reachability by name; this covers the call.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ZeroArgHookArityTest extends TestCase {

	/**
	 * Hook prefixes whose `do_action()` carries no arguments.
	 *
	 * Verified against core rather than assumed: `wp-admin/admin-post.php` fires
	 * `do_action( "admin_post_{$action}" )`, `wp-admin/admin-ajax.php` fires
	 * `do_action( "wp_ajax_{$action}" )`, and `wp-admin/admin.php` fires
	 * `do_action( "admin_action_{$action}" )` — none with an argument.
	 *
	 * @var array<int, string>
	 */
	private const ZERO_ARG_PREFIXES = array(
		'admin_post_nopriv_',
		'admin_post_',
		'wp_ajax_nopriv_',
		'wp_ajax_',
		'admin_action_',
	);

	/**
	 * First-parameter types that a literal `''` can legitimately satisfy.
	 *
	 * `string` and `?string` accept it outright; `mixed` accepts anything. A
	 * handler declaring one of these is odd but not broken, so the guard says
	 * nothing about it. Everything else — a class, an interface, `array`, `int`,
	 * `bool`, `callable` — rejects `''` under `strict_types`.
	 *
	 * @var array<int, string>
	 */
	private const STRING_COMPATIBLE = array( 'string', '?string', 'mixed' );

	// ------------------------------------------------------------------ tests

	/**
	 * No zero-argument hook hands a typed parameter to its callback.
	 *
	 * The main direction. A registration that would receive `''` in a typed slot
	 * must declare `accepted_args = 0`.
	 */
	public function test_no_zero_argument_hook_hands_a_typed_parameter_to_its_callback(): void {
		$offenders = array();

		foreach ( self::registrations() as $registration ) {
			$type = self::first_parameter_type_of( $registration );

			if ( null === $type || in_array( $type, self::STRING_COMPATIBLE, true ) ) {
				continue;
			}

			if ( '0' === $registration['accepted_args'] ) {
				continue;
			}

			$offenders[] = sprintf(
				'%s → %s::%s( %s $… ) registered with accepted_args=%s',
				$registration['hook'],
				$registration['class'],
				$registration['method'],
				$type,
				'' === $registration['accepted_args'] ? '1 (the default)' : $registration['accepted_args']
			);
		}

		sort( $offenders );

		$this->assertSame(
			array(),
			$offenders,
			"These handlers will receive WordPress's literal '' in a typed parameter and die with a\n"
			. "TypeError before emitting anything — the request simply does nothing.\n\n"
			. "Pass accepted_args = 0 on the registration: `add_action( \$hook, \$cb, 10, 0 )`. That is\n"
			. "also the truth about the hook, which fires with no arguments at all.\n\n"
			. implode( "\n", array_map( static fn ( string $o ): string => '  - ' . $o, $offenders ) )
		);
	}

	/**
	 * Every callback resolves to a method this guard could actually read.
	 *
	 * A registration whose class or method the scan cannot find is NOT skipped:
	 * an unreadable callback is indistinguishable from a compliant one, and
	 * "never count as clean what you did not look at" is the rule the schema
	 * gates already state. Teach the resolver the new idiom; do not let it pass.
	 */
	public function test_every_registered_callback_resolves_to_a_readable_method(): void {
		$unresolved = array();

		foreach ( self::registrations() as $registration ) {
			if ( '' === $registration['class'] ) {
				$unresolved[] = sprintf(
					'%s in %s — callback target %s could not be resolved to a class',
					$registration['hook'],
					$registration['file'],
					$registration['target']
				);
				continue;
			}

			if ( null === self::declaring_file_of( $registration['class'] ) ) {
				$unresolved[] = sprintf(
					'%s — no file in includes/ declares class %s',
					$registration['hook'],
					$registration['class']
				);
				continue;
			}

			if ( ! self::method_exists_in_source( $registration['class'], $registration['method'] ) ) {
				$unresolved[] = sprintf(
					'%s — %s::%s not found in the class source',
					$registration['hook'],
					$registration['class'],
					$registration['method']
				);
			}
		}

		sort( $unresolved );

		$this->assertSame(
			array(),
			$unresolved,
			"This guard could not read these callbacks, so it cannot vouch for them.\n"
			. "Teach the resolver the idiom rather than allowlisting the registration.\n\n"
			. implode( "\n", array_map( static fn ( string $u ): string => '  - ' . $u, $unresolved ) )
		);
	}

	/**
	 * The scan agrees with an independent recount.
	 *
	 * A regex that stops matching reports a clean tree, which is the failure
	 * mode this repository has been bitten by. So the population is counted a
	 * second time by a DIFFERENT traversal — `token_get_all()` finding every
	 * `add_action` call whose first argument is a zero-arg hook literal —
	 * and the two must agree exactly. A floor would decay as the tree grows;
	 * a recount that drifts fails loudly, which is the failure mode to buy.
	 */
	public function test_the_scan_agrees_with_an_independent_recount(): void {
		$by_regex = count( self::registrations() );
		$by_token = self::recount_by_tokens();

		$this->assertGreaterThan(
			0,
			$by_token,
			'The independent recount found no zero-argument hook registrations at all, which cannot '
			. 'be true of this plugin — the scan, not the tree, is what broke.'
		);

		$this->assertSame(
			$by_token,
			$by_regex,
			"The regex scan and the token recount disagree about how many zero-argument hook\n"
			. "registrations exist. One of them stopped seeing an idiom: find which, and teach it,\n"
			. 'rather than reconciling the numbers.'
		);
	}

	// -------------------------------------------------------------- collectors

	/**
	 * Every `add_action` on a zero-argument hook, resolved.
	 *
	 * @return array<int, array{file: string, hook: string, target: string, class: string, method: string, accepted_args: string}>
	 */
	private static function registrations(): array {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$prefixes = implode( '|', array_map( 'preg_quote', self::ZERO_ARG_PREFIXES ) );

		// The hook may be a bare literal or a literal prefix concatenated with a
		// constant (`'admin_post_' . self::SAVE_ACTION`), which is the idiom half
		// this codebase uses; a regex that knows only the first is blind to it.
		$pattern = '/add_action\(\s*'
			. "'((?:" . $prefixes . ')[a-z0-9_]*)\''
			. '(\s*\.\s*[^,]+?)?'
			. '\s*,\s*array\(\s*([^,]+?)\s*,\s*\'([A-Za-z0-9_]+)\'\s*\)'
			. '\s*(?:,\s*([^,)]+?)\s*)?(?:,\s*([^)]+?)\s*)?\)/s';

		$found = array();

		foreach ( self::php_files( 'includes' ) as $path ) {
			$code = self::code_of( $path );

			if ( ! preg_match_all( $pattern, $code, $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$target = trim( $match[3] );
				$hook   = $match[1] . ( '' !== ( $match[2] ?? '' ) ? trim( $match[2] ) : '' );

				$found[] = array(
					'file'          => $path,
					'hook'          => $hook,
					'target'        => $target,
					'class'         => self::resolve_target_class( $path, $code, $target ),
					'method'        => $match[4],
					'accepted_args' => trim( $match[6] ?? '' ),
				);
			}
		}

		$cache = $found;

		return $found;
	}

	/**
	 * Count the same population by tokenising, for the self-check.
	 *
	 * @return int
	 */
	private static function recount_by_tokens(): int {
		$count = 0;

		foreach ( self::php_files( 'includes' ) as $path ) {
			$tokens = token_get_all( (string) file_get_contents( $path ) );
			$total  = count( $tokens );

			for ( $i = 0; $i < $total; $i++ ) {
				$token = $tokens[ $i ];

				if ( ! self::is_add_action_token( $token ) ) {
					continue;
				}

				// The first argument is the next string literal; anything else
				// (a variable, a constant) is not a literal hook name and is not
				// this guard's population.
				for ( $j = $i + 1; $j < $total; $j++ ) {
					$next = $tokens[ $j ];

					if ( '(' === $next || ( is_array( $next ) && T_WHITESPACE === $next[0] ) ) {
						continue;
					}

					if ( is_array( $next ) && T_CONSTANT_ENCAPSED_STRING === $next[0] ) {
						$literal = trim( $next[1], "'\"" );

						foreach ( self::ZERO_ARG_PREFIXES as $prefix ) {
							if ( 0 === strpos( $literal, $prefix ) ) {
								++$count;
								break;
							}
						}
					}

					break;
				}
			}
		}

		return $count;
	}

	/**
	 * Whether a token opens an `add_action` call.
	 *
	 * **Both token shapes, because one of them is invisible to the other.**
	 * `add_action(` is a `T_STRING`, while `\add_action(` — the fully qualified
	 * form — is a single `T_NAME_FULLY_QUALIFIED` token carrying the backslash.
	 * A pass that knows only `T_STRING` silently drops every qualified call.
	 *
	 * This is not a hypothetical, and it is not a new lesson: the same trap cost
	 * #1284 thirty source strings, and the deprecation guard records resolving
	 * both shapes for exactly this reason. It bit this guard on its FIRST run —
	 * the recount read 115 against the regex's 117, and the two missing were
	 * `AltchaChallengeEndpoint`'s two `\add_action(` registrations. The
	 * self-check earned its place before the guard had shipped.
	 *
	 * @param array{0: int, 1: string}|string $token Token from `token_get_all()`.
	 * @return bool
	 */
	private static function is_add_action_token( $token ): bool {
		if ( ! is_array( $token ) ) {
			return false;
		}

		if ( T_STRING === $token[0] && 'add_action' === $token[1] ) {
			return true;
		}

		return defined( 'T_NAME_FULLY_QUALIFIED' )
			&& T_NAME_FULLY_QUALIFIED === $token[0]
			&& '\\add_action' === $token[1];
	}

	// --------------------------------------------------------------- resolvers

	/**
	 * Resolve an `array( <target>, 'method' )` callback target to a class name.
	 *
	 * Six idioms name the file's own class and three name a property's. A form
	 * this does not know returns '' and the resolution test fails, by design.
	 *
	 * @param string $path   File holding the registration.
	 * @param string $code   Its comment-free source.
	 * @param string $target The callback's first array element, verbatim.
	 * @return string Short class name, or '' when unresolved.
	 */
	private static function resolve_target_class( string $path, string $code, string $target ): string {
		$own = array( 'self::class', 'static::class', '__CLASS__', '$this' );

		if ( in_array( $target, $own, true ) ) {
			return self::class_declared_in( $code );
		}

		// `array( $this->collaborator, 'method' )`. The property's class comes
		// from its declared type when it has one, and otherwise from the `new`
		// that fills it — two of the three in this tree are untyped, so reading
		// only the declaration would leave them unresolved.
		if ( 1 === preg_match( '/^\$this->([A-Za-z0-9_]+)$/', $target, $property ) ) {
			$name = preg_quote( $property[1], '/' );

			if ( 1 === preg_match( '/(?:private|protected|public)\s+\??([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s+\$' . $name . '\b/', $code, $typed ) ) {
				return self::short_name( $typed[1] );
			}

			if ( 1 === preg_match( '/\$this->' . $name . '\s*=\s*new\s+\\\\?([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\(/', $code, $assigned ) ) {
				return self::short_name( $assigned[1] );
			}
		}

		return '';
	}

	/**
	 * The first parameter's declared type for a registration's callback.
	 *
	 * Read with `token_get_all()` rather than a regex, so a signature broken
	 * across lines reads the same as one on a single line.
	 *
	 * @param array{class: string, method: string} $registration Resolved registration.
	 * @return string|null Declared type, or null when the parameter is absent or untyped.
	 */
	private static function first_parameter_type_of( array $registration ): ?string {
		$file = self::declaring_file_of( $registration['class'] );

		if ( null === $file ) {
			return null;
		}

		$tokens = token_get_all( (string) file_get_contents( $file ) );
		$total  = count( $tokens );

		for ( $i = 0; $i < $total; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
				continue;
			}

			$name = self::next_significant( $tokens, $i + 1, $total );

			if ( null === $name || ! is_array( $tokens[ $name ] ) || T_STRING !== $tokens[ $name ][0] ) {
				continue;
			}

			if ( $tokens[ $name ][1] !== $registration['method'] ) {
				continue;
			}

			return self::first_parameter_type_at( $tokens, $name + 1, $total );
		}

		return null;
	}

	/**
	 * Read the first parameter's type from an open parameter list.
	 *
	 * @param array<int, mixed> $tokens Token stream.
	 * @param int               $from   Index just after the method name.
	 * @param int               $total  Token count.
	 * @return string|null
	 */
	private static function first_parameter_type_at( array $tokens, int $from, int $total ): ?string {
		$open = false;
		$type = '';

		for ( $i = $from; $i < $total; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! $open ) {
				if ( '(' === $token ) {
					$open = true;
				}
				continue;
			}

			// An empty parameter list, or the type collected so far belongs to
			// the first parameter and we have reached its variable.
			if ( ')' === $token ) {
				return null;
			}

			if ( is_array( $token ) && T_VARIABLE === $token[0] ) {
				return '' === $type ? null : $type;
			}

			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				continue;
			}

			if ( '?' === $token ) {
				$type .= '?';
				continue;
			}

			if ( is_array( $token ) ) {
				$type .= $token[1];
			}
		}

		return null;
	}

	/**
	 * The file declaring a short class name, or null when none does.
	 *
	 * @param string $class Short class name.
	 * @return string|null
	 */
	private static function declaring_file_of( string $class ): ?string {
		static $index = null;

		if ( null === $index ) {
			$index = array();

			foreach ( self::php_files( 'includes' ) as $path ) {
				$declared = self::class_declared_in( self::code_of( $path ) );

				if ( '' !== $declared ) {
					$index[ $declared ] = $path;
				}
			}
		}

		return $index[ $class ] ?? null;
	}

	/**
	 * Whether a class's source declares a method of this name.
	 *
	 * @param string $class  Short class name.
	 * @param string $method Method name.
	 * @return bool
	 */
	private static function method_exists_in_source( string $class, string $method ): bool {
		$file = self::declaring_file_of( $class );

		if ( null === $file ) {
			return false;
		}

		return 1 === preg_match(
			'/function\s+' . preg_quote( $method, '/' ) . '\s*\(/',
			self::code_of( $file )
		);
	}

	// ----------------------------------------------------------------- helpers

	/**
	 * The class, interface or trait a file declares.
	 *
	 * @param string $code Comment-free source.
	 * @return string Short name, or '' when the file declares none.
	 */
	private static function class_declared_in( string $code ): string {
		if ( 1 === preg_match( '/\b(?:final\s+|abstract\s+)*class\s+([A-Za-z0-9_]+)/', $code, $match ) ) {
			return $match[1];
		}

		return '';
	}

	/**
	 * Drop any namespace qualification.
	 *
	 * @param string $name Possibly qualified name.
	 * @return string
	 */
	private static function short_name( string $name ): string {
		$parts = explode( '\\', $name );

		return (string) end( $parts );
	}

	/**
	 * Index of the next token that is not whitespace.
	 *
	 * @param array<int, mixed> $tokens Token stream.
	 * @param int               $from   Start index.
	 * @param int               $total  Token count.
	 * @return int|null
	 */
	private static function next_significant( array $tokens, int $from, int $total ): ?int {
		for ( $i = $from; $i < $total; $i++ ) {
			if ( is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
				continue;
			}

			return $i;
		}

		return null;
	}

	/**
	 * Every `.php` file under a plugin-relative directory.
	 *
	 * @param string $relative Directory relative to the plugin root.
	 * @return array<int, string>
	 */
	private static function php_files( string $relative ): array {
		$root = dirname( __DIR__, 2 ) . '/' . $relative;
		$out  = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$out[] = $file->getPathname();
			}
		}

		sort( $out );

		return $out;
	}

	/**
	 * A file's source with comments blanked out.
	 *
	 * A registration quoted inside a docblock is documentation, not a
	 * registration — the same reason the deprecation guard reads comment tokens
	 * rather than raw lines.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function code_of( string $path ): string {
		static $cache = array();

		if ( isset( $cache[ $path ] ) ) {
			return $cache[ $path ];
		}

		$out = '';

		foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}

			$out .= is_array( $token ) ? $token[1] : $token;
		}

		$cache[ $path ] = $out;

		return $out;
	}
}
