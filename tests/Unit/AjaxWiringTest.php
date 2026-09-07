<?php
/**
 * AJAX wiring guard (Tier 0 — static reachability).
 *
 * Catches the "built but never wired" defect class by cross-checking, in both
 * directions, the `wp_ajax_*` handlers registered in `includes/` against the
 * places the client side actually asks for them:
 *
 *   A. Every registered action must be referenced somewhere OTHER than the file
 *      that registers it. A handler nobody can reach is dead weight at best and
 *      an unaudited request entry point at worst — the #935 sweep removed ten of
 *      them, each of which had lived for months.
 *   B. Every action the client asks for must be registered — on `wp_ajax_*`,
 *      `admin_post_*` or `admin_action_*`, WordPress's three entry points for
 *      the same client-side `action` field. A typo or a renamed handler
 *      produces a silent 400 that no unit test with a mocked WordPress sees.
 *
 * A third check covers the sibling defect from #936: a plugin option that is
 * read but that nothing ever writes (there, `ffc_cleanup_days` — so age-based
 * submission cleanup never ran, regardless of the configured retention).
 *
 * Two registration idioms exist and both are resolved: the literal
 * `add_action( 'wp_ajax_ffc_x', … )` and the endpoint-class
 * `add_action( 'wp_ajax_' . self::AJAX_ACTION, … )` with the action in a class
 * constant. A regex that knows only the first is blind to every `*AjaxEndpoint`
 * class, so it would report their actions as unregistered.
 *
 * This guard is deliberately dependency-free — no WordPress, no Brain\Monkey,
 * no bootstrapping the plugin — so it runs in milliseconds and cannot itself
 * fail for environmental reasons.
 *
 * What it does NOT prove: that a reachable handler is reached from the right
 * screen, that the button carrying it is rendered, or that the request
 * succeeds. Those need a real WordPress and a browser. This is the cheap layer
 * underneath — it only certifies that both ends of the wire exist and agree on
 * a name. The KNOWN_* allowlists hold the exceptions; each needs its reason
 * written where it is declared.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class AjaxWiringTest extends TestCase {

	/**
	 * Actions that legitimately have no caller in this repository.
	 *
	 * Add an entry ONLY with the reason inline. An action reachable from a
	 * theme, a sibling plugin or an external integration belongs here; one
	 * that simply lost its caller does not — delete the handler instead.
	 *
	 * @var array<string, string> Action name => justification.
	 */
	private const KNOWN_CALLERLESS_ACTIONS = array();

	/**
	 * Option keys read without a literal write site in this repository.
	 *
	 * @var array<string, string> Option name => justification.
	 */
	private const KNOWN_EXTERNALLY_WRITTEN_OPTIONS = array();

	/**
	 * Client-side directories scanned for action references.
	 *
	 * @var array<int, string>
	 */
	private const CLIENT_ROOTS = array( 'assets/js', 'templates', 'includes' );

	/**
	 * Comment-stripped source, keyed by absolute path.
	 *
	 * Direction A asks "is this token mentioned anywhere?" once per action, over
	 * the same ~500 files each time. Tokenizing them once takes the guard from
	 * ~10s to well under a second.
	 *
	 * @var array<string, string>
	 */
	private static array $code_cache = array();

	// ---------------------------------------------------------------- helpers

	/**
	 * Absolute path to the repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every scannable source file under a repo-relative directory.
	 *
	 * Minified bundles are skipped — they are generated from the sources
	 * already scanned, and would double every match.
	 *
	 * @param string             $relative Repo-relative directory.
	 * @param array<int, string> $suffixes Accepted file suffixes.
	 * @return array<int, string> Absolute paths.
	 */
	private static function files_in( string $relative, array $suffixes ): array {
		$dir = self::root() . '/' . $relative;
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$out  = array();
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iter as $file ) {
			$path = $file->getPathname();
			if ( strpos( $path, '/libraries/' ) !== false || strpos( $path, '/vendor/' ) !== false ) {
				continue;
			}
			if ( substr( $path, -7 ) === '.min.js' ) {
				continue;
			}
			foreach ( $suffixes as $suffix ) {
				if ( substr( $path, -strlen( $suffix ) ) === $suffix ) {
					$out[] = $path;
					break;
				}
			}
		}

		sort( $out );
		return $out;
	}

	/**
	 * Read a file with its comments removed.
	 *
	 * Comments are stripped so a docblock that merely NAMES an action (the
	 * dispatcher's header lists all three export phases, for instance) is not
	 * mistaken for a caller.
	 *
	 * @param string $path Absolute file path.
	 */
	private static function code_of( string $path ): string {
		if ( isset( self::$code_cache[ $path ] ) ) {
			return self::$code_cache[ $path ];
		}

		$text = file_get_contents( $path );
		if ( false === $text ) {
			self::$code_cache[ $path ] = '';
			return '';
		}

		if ( substr( $path, -4 ) === '.php' ) {
			$code = '';
			foreach ( token_get_all( $text ) as $token ) {
				if ( is_array( $token ) ) {
					if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
						continue;
					}
					$code .= $token[1];
					continue;
				}
				$code .= $token;
			}
			self::$code_cache[ $path ] = $code;
			return $code;
		}

		// JS: block comments, then whole-line `//` and continuation `*` lines.
		// Deliberately conservative — a `//` inside a URL string keeps its line.
		$text  = (string) preg_replace( '#/\*.*?\*/#s', '', $text );
		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$trimmed = ltrim( $line );
			if ( strpos( $trimmed, '//' ) === 0 || strpos( $trimmed, '*' ) === 0 ) {
				continue;
			}
			$lines[] = $line;
		}
		self::$code_cache[ $path ] = implode( "\n", $lines );
		return self::$code_cache[ $path ];
	}

	/**
	 * Action name => `Class::CONSTANT`, for actions registered through one.
	 *
	 * Filled by {@see registered_ajax_actions()}, which is what parses the
	 * registration in the first place.
	 *
	 * @var array<string, string>
	 */
	private static array $constant_aliases = array();

	/**
	 * Action name => the abstract registrar that hooks it (idiom 3).
	 *
	 * @var array<string, string>
	 */
	private static array $abstract_registrars = array();

	// ------------------------------------------------------------- collectors

	/**
	 * Registered AJAX actions, mapped to the file that registers them.
	 *
	 * @return array<string, string> Action name => absolute registrar path.
	 */
	public static function registered_ajax_actions(): array {
		$actions = array();

		foreach ( self::files_in( 'includes', array( '.php' ) ) as $path ) {
			$code = self::code_of( $path );

			// Idiom 1 — literal: add_action( 'wp_ajax_ffc_x', … ).
			//
			// The prefix quantifier is possessive on purpose. Greedy, against
			// the endpoint-class form `'wp_ajax_nopriv_' . self::ACTION` the
			// engine gives `nopriv_` back, captures it as the action name and
			// registers a handler called `nopriv_` that nothing can ever call.
			// (An atomic group is not enough here: it forbids backtracking
			// *inside* the group, while the `?` may still discard it whole.)
			// Possessive, the capture fails and idiom 2 below takes the line —
			// which is what it is for.
			if ( preg_match_all( "/add_action\(\s*'wp_ajax_(?:nopriv_)?+([a-z0-9_]+)'/", $code, $matches ) ) {
				foreach ( $matches[1] as $action ) {
					$actions[ $action ] = $path;
				}
			}

			// Idiom 2 — endpoint class: add_action( 'wp_ajax_' . self::CONST, … ).
			// `self::`/`static::` always resolves in the declaring file, so the
			// constant is read from this same file; no autoloading required.
			if ( preg_match_all( "/add_action\(\s*'wp_ajax_(?:nopriv_)?'\s*\.\s*(?:self|static)::([A-Z0-9_]+)/", $code, $matches ) ) {
				foreach ( $matches[1] as $constant ) {
					if ( preg_match( "/const\s+" . preg_quote( $constant, '/' ) . "\s*=\s*'([a-z0-9_]+)'/", $code, $value ) ) {
						$actions[ $value[1] ] = $path;

						// An endpoint class that holds its action in a constant
						// is normally called through that constant, never
						// through the literal — so direction A has to look for
						// `TheClass::THE_CONST` as well, or every such handler
						// reads as an orphan.
						if ( preg_match( '/\bclass\s+([A-Za-z0-9_]+)/', $code, $class ) ) {
							self::$constant_aliases[ $value[1] ] = $class[1] . '::' . $constant;
						}
					}
				}
			}

			// Idiom 3 — abstract base: add_action( 'wp_ajax_' . static::action(), … ).
			//
			// `AbstractDismissibleNotice` registers the hook once, in the base
			// class, and each subclass supplies the name from its own
			// `action()`. `static::` therefore resolves in the SUBCLASS, not
			// here — so the base file yields no action at all and every child's
			// action is invisible to both directions of this guard. Four
			// notices were in exactly that state until #1087 passo 6, and the
			// self-check below is what surfaced them: the wiring happened to be
			// correct, but nothing had ever verified it.
			//
			// Resolution is the other way round from idiom 2: find the files
			// whose `action()` returns a constant, and read the constant there.
			if ( preg_match( "/add_action\(\s*'wp_ajax_(?:nopriv_)?'\s*\.\s*static::action\(\)/", $code ) ) {
				foreach ( self::subclass_actions( $path ) as $action => $subclass_path ) {
					$actions[ $action ]                   = $subclass_path;
					self::$abstract_registrars[ $action ] = $path;
				}
			}
		}

		ksort( $actions );
		return $actions;
	}

	/**
	 * Actions supplied by the subclasses of an abstract registrar.
	 *
	 * The base class hooks `'wp_ajax_' . static::action()`; each subclass
	 * implements `action()`, normally by returning one of its own constants.
	 * Reads the constant out of the subclass file, the same way idiom 2 reads
	 * it out of the declaring file.
	 *
	 * @param string $base_path Absolute path of the abstract registrar.
	 * @return array<string, string> Action name => absolute subclass path.
	 */
	private static function subclass_actions( string $base_path ): array {
		$found = array();

		if ( ! preg_match( '/\babstract\s+class\s+([A-Za-z0-9_]+)/', self::code_of( $base_path ), $base ) ) {
			return $found;
		}

		foreach ( self::files_in( 'includes', array( '.php' ) ) as $path ) {
			if ( $path === $base_path ) {
				continue;
			}

			$code = self::code_of( $path );

			if ( ! preg_match( '/\bextends\s+' . preg_quote( $base[1], '/' ) . '\b/', $code ) ) {
				continue;
			}

			// `action()` returning a constant of the same class, or a literal.
			if ( preg_match( "/function\s+action\(\s*\)\s*:\s*string\s*\{\s*return\s+(?:self|static)::([A-Z0-9_]+)\s*;/", $code, $const )
				&& preg_match( "/const\s+" . preg_quote( $const[1], '/' ) . "\s*=\s*'([a-z0-9_]+)'/", $code, $value ) ) {
				$found[ $value[1] ] = $path;
				continue;
			}

			if ( preg_match( "/function\s+action\(\s*\)\s*:\s*string\s*\{\s*return\s+'([a-z0-9_]+)'\s*;/", $code, $literal ) ) {
				$found[ $literal[1] ] = $path;
			}
		}

		return $found;
	}

	/**
	 * Actions registered on WordPress's two non-AJAX entry points.
	 *
	 * `admin_post_{$action}` handles a form POST to `admin-post.php`;
	 * `admin_action_{$action}` handles a `?action=` request that lands on any
	 * `wp-admin` screen (the form/calendar "Duplicate" links use it). Both read
	 * the same client-side `action` field as `wp_ajax_*`, so direction B has to
	 * accept them or it reports every one of them as unhandled.
	 *
	 * @return array<int, string>
	 */
	public static function registered_admin_entry_actions(): array {
		$actions = array();

		foreach ( self::files_in( 'includes', array( '.php' ) ) as $path ) {
			$code = self::code_of( $path );

			$patterns = array(
				"/add_action\(\s*'admin_(?:post_nopriv|post|action)_([a-z0-9_]+)'/",
				"/add_action\(\s*'admin_(?:post_nopriv|post|action)_'\s*\.\s*(?:self|static)::([A-Z0-9_]+)/",
			);

			if ( preg_match_all( $patterns[0], $code, $matches ) ) {
				foreach ( $matches[1] as $action ) {
					$actions[ $action ] = true;
				}
			}
			if ( preg_match_all( $patterns[1], $code, $matches ) ) {
				foreach ( $matches[1] as $constant ) {
					if ( preg_match( "/const\s+" . preg_quote( $constant, '/' ) . "\s*=\s*'([a-z0-9_]+)'/", $code, $value ) ) {
						$actions[ $value[1] ] = true;
					}
				}
			}
		}

		$out = array_keys( $actions );
		sort( $out );
		return $out;
	}

	/**
	 * Files mentioning a given action token, excluding one path.
	 *
	 * Used loosely and on purpose: direction A only needs to prove a handler is
	 * *reachable at all*, and the client side names its action in at least six
	 * different syntactic shapes (a `FFC.request` argument, an `action:` key, a
	 * `'action=…'` query fragment, a `FormData.append` pair, a hidden input's
	 * `value`, a ternary branch). Matching each shape exactly would report all
	 * six as orphans; matching the bare token reports none, and still fails on
	 * a handler whose name appears nowhere else — the actual defect.
	 *
	 * @param string $action  Action token.
	 * @param string $exclude Absolute path to skip (the registrar).
	 * @return array<int, string> Repo-relative paths.
	 */
	private static function files_mentioning( string $action, string $exclude ): array {
		$hits    = array();
		$pattern = '/\b' . preg_quote( $action, '/' ) . '\b/';

		foreach ( self::CLIENT_ROOTS as $relative ) {
			foreach ( self::files_in( $relative, array( '.php', '.js' ) ) as $path ) {
				if ( $path === $exclude ) {
					continue;
				}
				if ( preg_match( $pattern, self::code_of( $path ) ) ) {
					$hits[] = ltrim( str_replace( self::root(), '', $path ), '/' );
				}
			}
		}

		return $hits;
	}

	/**
	 * Whether the registrar file itself wires the action to a client.
	 *
	 * An endpoint class often never leaks its action name as a literal: it
	 * holds the name in a constant and hands it to the browser through a
	 * `wp_localize_script` payload (`'toggleAction' => self::AJAX_TOGGLE_ACTION`).
	 * The token then appears in no other file, so direction A would call a
	 * perfectly live handler an orphan.
	 *
	 * A reference counts as wiring when it is neither the registration itself,
	 * nor the constant's declaration, nor the handler's own nonce check — those
	 * three exist in a dead handler too, so counting them would make the check
	 * unable to fail.
	 *
	 * @param string $action    Action token.
	 * @param string $registrar Absolute path to the registering file.
	 */
	/**
	 * Whether an abstract registrar hands its action to the client through a
	 * data attribute that some script actually reads.
	 *
	 * Idiom 3 defeats direction A's premise. `AbstractDismissibleNotice` puts
	 * `static::action()` into `data-ffc-action` and a shared script posts
	 * whatever that attribute holds — so the client **never names the action**,
	 * and no lexical search can find a caller. Three of the four notices read as
	 * orphans for exactly that reason, while being correctly wired.
	 *
	 * This verifies the link instead of exempting it: the base must emit the
	 * action into an attribute, and some JS must read that same attribute. Drop
	 * the script and the guard goes back to reporting the orphans.
	 *
	 * @param string $base_path Absolute path of the abstract registrar.
	 * @return bool
	 */
	private static function dispatched_through_data_attribute( string $base_path ): bool {
		$base = self::code_of( $base_path );

		// The attribute the base fills with the action name.
		if ( ! preg_match( "/'(data-[a-z0-9-]+)'\s*=>\s*static::action\(\)/", $base, $attribute ) ) {
			return false;
		}

		foreach ( self::files_in( 'assets/js', array( '.js' ) ) as $script ) {
			if ( false !== strpos( self::code_of( $script ), $attribute[1] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function registrar_wires_a_client( string $action, string $registrar ): bool {
		$code = self::code_of( $registrar );

		// The constant, if this file registers via `self::CONST`.
		$alias = null;
		if ( preg_match( "/const\s+([A-Z0-9_]+)\s*=\s*'" . preg_quote( $action, '/' ) . "'/", $code, $match ) ) {
			$alias = $match[1];
		}

		$needle = '/(?:\b' . preg_quote( $action, '/' ) . '\b'
			. ( null === $alias ? '' : '|(?:self|static)::' . preg_quote( $alias, '/' ) )
			. ')/';

		$structural = array(
			'/add_action\(/',
			'/\bconst\s+[A-Z0-9_]+\s*=/',
			'/check_ajax_referer\(/',
			'/wp_verify_nonce\(/',
		);

		foreach ( explode( "\n", $code ) as $line ) {
			if ( ! preg_match( $needle, $line ) ) {
				continue;
			}
			foreach ( $structural as $pattern ) {
				if ( preg_match( $pattern, $line ) ) {
					continue 2;
				}
			}
			return true;
		}

		return false;
	}

	/**
	 * Action names the client side asks for, in unambiguous request positions.
	 *
	 * Precise by design — direction B compares this against the registered set,
	 * so a loose match here would flag nonce names and capability slugs.
	 *
	 * @return array<string, string> Action name => repo-relative caller path.
	 */
	public static function client_requested_actions(): array {
		$patterns = array(
			// FFC.request( 'ffc_x', … ) — the canonical chokepoint.
			"/FFC\.request\(\s*['\"]([a-z0-9_]+)['\"]/",
			// { action: 'ffc_x' } — raw $.post / $.ajax payloads.
			"/\baction\s*:\s*['\"]([a-z0-9_]+)['\"]/",
			// 'action=ffc_x' — hand-built query strings and URL fragments.
			'/\baction=([a-z0-9_]+)/',
			// FormData: append( 'action', 'ffc_x' ).
			"/append\(\s*['\"]action['\"]\s*,\s*['\"]([a-z0-9_]+)['\"]/",
			// <input type="hidden" name="action" value="ffc_x">
			'/name="action"[^>]*value="([a-z0-9_]+)"/',
		);

		$found = array();
		foreach ( self::CLIENT_ROOTS as $relative ) {
			foreach ( self::files_in( $relative, array( '.php', '.js' ) ) as $path ) {
				$code = self::code_of( $path );
				foreach ( $patterns as $pattern ) {
					if ( ! preg_match_all( $pattern, $code, $matches ) ) {
						continue;
					}
					foreach ( $matches[1] as $action ) {
						if ( strpos( $action, 'ffc' ) !== 0 ) {
							continue;
						}
						if ( ! isset( $found[ $action ] ) ) {
							$found[ $action ] = ltrim( str_replace( self::root(), '', $path ), '/' );
						}
					}
				}
			}
		}

		ksort( $found );
		return $found;
	}

	// ------------------------------------------------------------- direction A

	/**
	 * Every registered AJAX handler must be reachable from somewhere.
	 */
	public function test_no_orphan_ajax_handlers(): void {
		$orphans = array();

		foreach ( self::registered_ajax_actions() as $action => $registrar ) {
			if ( isset( self::KNOWN_CALLERLESS_ACTIONS[ $action ] ) ) {
				continue;
			}
			$base = self::$abstract_registrars[ $action ] ?? null;
			if ( null !== $base && self::dispatched_through_data_attribute( $base ) ) {
				continue;
			}

			if ( self::registrar_wires_a_client( $action, $registrar ) ) {
				continue;
			}
			$alias = self::$constant_aliases[ $action ] ?? null;
			if ( null !== $alias && array() !== self::files_mentioning( $alias, $registrar ) ) {
				continue;
			}
			if ( array() === self::files_mentioning( $action, $registrar ) ) {
				$orphans[] = $action . '  (registered in ' . ltrim( str_replace( self::root(), '', $registrar ), '/' ) . ')';
			}
		}

		$this->assertSame(
			array(),
			$orphans,
			"AJAX handlers registered with no caller anywhere in the plugin:\n  "
			. implode( "\n  ", $orphans )
			. "\n\nEach is an unreachable request entry point. Delete the handler, or — if it is"
			. "\nreached from outside this repository — add it to KNOWN_CALLERLESS_ACTIONS with"
			. "\nthe reason."
		);
	}

	// ------------------------------------------------------------- direction B

	/**
	 * Every action the client asks for must have a handler.
	 */
	public function test_no_ajax_calls_into_the_void(): void {
		$registered = array_keys( self::registered_ajax_actions() );
		$registered = array_merge( $registered, self::registered_admin_entry_actions() );

		$unhandled = array();
		foreach ( self::client_requested_actions() as $action => $caller ) {
			if ( ! in_array( $action, $registered, true ) ) {
				$unhandled[] = $action . '  (requested from ' . $caller . ')';
			}
		}

		$this->assertSame(
			array(),
			$unhandled,
			"Client code requests actions that no handler is registered for:\n  "
			. implode( "\n  ", $unhandled )
			. "\n\nEach of these returns HTTP 400 with WordPress's bare `0` body — the feature"
			. "\nsimply does nothing. Check for a typo or a handler that was renamed."
		);
	}

	// ------------------------------------------------------------------- #936

	/**
	 * Every plugin option read must have a write path.
	 */
	public function test_no_option_read_without_a_write_path(): void {
		$read  = array();
		$write = array();

		$sources = array_merge(
			self::files_in( 'includes', array( '.php' ) ),
			array( self::root() . '/ffcertificate.php', self::root() . '/uninstall.php' )
		);

		foreach ( $sources as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$code = self::code_of( $path );

			if ( preg_match_all( "/get_option\(\s*'(ffc[a-z0-9_]*)'/", $code, $matches ) ) {
				foreach ( $matches[1] as $key ) {
					$read[ $key ] = ltrim( str_replace( self::root(), '', $path ), '/' );
				}
			}

			// Three legitimate write paths: the direct calls, the settings
			// autosave allowlist (`'option' => 'ffc_x'`), and the WordPress
			// Settings API, which persists the option on the plugin's behalf.
			$writers = array(
				"/(?:update_option|add_option)\(\s*'(ffc[a-z0-9_]*)'/",
				"/'option'\s*=>\s*'(ffc[a-z0-9_]*)'/",
				"/register_setting\(\s*[^,]+,\s*'(ffc[a-z0-9_]*)'/",
			);
			foreach ( $writers as $pattern ) {
				if ( preg_match_all( $pattern, $code, $matches ) ) {
					foreach ( $matches[1] as $key ) {
						$write[ $key ] = true;
					}
				}
			}
		}

		$dangling = array();
		foreach ( $read as $key => $reader ) {
			if ( isset( $write[ $key ] ) || isset( self::KNOWN_EXTERNALLY_WRITTEN_OPTIONS[ $key ] ) ) {
				continue;
			}
			$dangling[] = $key . '  (read in ' . $reader . ')';
		}

		sort( $dangling );

		$this->assertSame(
			array(),
			$dangling,
			"Options are read that nothing in the plugin ever writes:\n  "
			. implode( "\n  ", $dangling )
			. "\n\nSuch a read always returns its default, so the feature behind it is dormant"
			. "\nno matter what an operator configures — the #936 defect. Either the key is"
			. "\nwrong (the value may live under a sub-key of `ffc_settings`) or the write"
			. "\nside was never built."
		);
	}

	/**
	 * Every `wp_ajax_*` registration must be resolved by one of the two idioms.
	 *
	 * **Why this test exists.** The three assertions above all compare `array()`
	 * against a list of offenders, so a scan that stopped matching would find no
	 * offenders and the guard would pass in silence — reporting that the wiring
	 * is sound because it looked at nothing. That is not hypothetical: the
	 * row-shape ruler measured 45 classes when 53 read rows for exactly that
	 * reason, printing "Every class that reads a row declares what the row
	 * holds" with thirty errors standing (#1087 passo 3).
	 *
	 * **And it checks resolution, not emptiness.** The ruler's failure was
	 * *partial* — it found most of the population, not none — so an assertion
	 * that the scan found *something* would have passed straight through it.
	 * This one fails the day a third registration idiom appears (a variable
	 * action, an interpolated string, an array of hooks) by naming the site the
	 * parser could not resolve. It is the shape `ActivatorSqlTest` already uses
	 * for CREATE statements: count independently, then demand agreement.
	 *
	 * @return void
	 */
	public function test_every_wp_ajax_registration_is_resolved_by_a_known_idiom(): void {
		$unresolved = array();

		foreach ( self::files_in( 'includes', array( '.php' ) ) as $path ) {
			$code = self::code_of( $path );

			// Every registration site, however the action name is built.
			if ( ! preg_match_all( "/add_action\(\s*['\"]wp_ajax(_nopriv)?_[^,]*/", $code, $sites ) ) {
				continue;
			}

			foreach ( $sites[0] as $site ) {
				$literal  = (bool) preg_match( "/add_action\(\s*'wp_ajax_(?:nopriv_)?+[a-z0-9_]+'/", $site );
				$constant = (bool) preg_match( "/add_action\(\s*'wp_ajax_(?:nopriv_)?'\s*\.\s*(?:self|static)::[A-Z][A-Z0-9_]*/", $site );
				$abstract = (bool) preg_match( "/add_action\\(\\s*'wp_ajax_(?:nopriv_)?'\\s*\\.\\s*static::action\\(\\)/", $site );

				if ( ! $literal && ! $constant && ! $abstract ) {
					$unresolved[] = str_replace( self::root() . '/', '', $path ) . ' :: ' . trim( $site );
				}
			}
		}

		$this->assertSame(
			array(),
			$unresolved,
			"A wp_ajax_* registration that neither idiom resolves. The parser would skip it,\n"
			. "and every assertion in this file would then pass without ever seeing that action:\n\n  "
			. implode( "\n  ", $unresolved )
			. "\n\nTeach registered_ajax_actions() the new idiom rather than leaving it invisible."
		);
	}

	/**
	 * The population itself must not collapse.
	 *
	 * The check above proves every site the scan *found* was resolved; this one
	 * proves the scan still finds sites at all — the cheaper half of the same
	 * failure, for the day `files_in()` or `code_of()` stops returning anything.
	 *
	 * @return void
	 */
	public function test_the_registration_scan_still_finds_a_population(): void {
		$this->assertNotEmpty(
			self::registered_ajax_actions(),
			'No wp_ajax_* registration found anywhere in includes/ — the scan is broken, not the plugin.'
		);
	}
}
