<?php
/**
 * Ids the JavaScript looks for, and who emits them.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Cross-checks the id the JS LOOKS FOR against the id somebody EMITS (#1220).
 *
 * The defect class: a hand-written fixture ages in silence. When the product
 * changes, it stays green describing a world that no longer exists -- that is
 * how the user dashboard stopped loading any panel at all (#1204,
 * `.ffc-tab.active` after #1170's rename) and how the dual-post group never hid
 * itself (#1219, `#ffc_rereg_acumulo`, which no PHP emits).
 *
 * **Why scanning PHP is not enough.** Measured: of the ids the JS looks for, 42
 * appear in no PHP at all -- and most are DOM the **JS itself creates**
 * (`#ffc-pdf-overlay`, `#ffc-template-modal`, `#ffc-migrations-overlay`…). A
 * guard that only looked at PHP would report dozens of false positives and be
 * switched off in the first week. The emission scan covers PHP **and** JS,
 * exactly as `CssClassEmitters` already does for classes.
 *
 * **Four emission shapes the first version did not know**, each found through a
 * false positive it produced:
 *
 * 1. `wp_nonce_field( $action, 'name' )` emits `id="name"` -- the id is the
 *    SECOND argument, and the first is the action, which has a different value.
 *    Three nonce fields showed up as orphans because of this.
 * 2. A literal held in a variable before becoming an id
 *    (`var inputId = 'x'; input.id = inputId`). Following the variable would
 *    need data flow; the scan accepts the bare literal in JS as a possible
 *    emission, which is the same side `CssClassEmitters` errs towards.
 * 3. WordPress's own markup (`#wpbody-content`, `#title`) -- a legitimate
 *    exception, of the same kind as `ClassNamingIdiomTest`'s `VENDOR_CLASSES`.
 * 4. An id assembled at runtime, on the EMISSION side (`id="row-<?php echo ...`)
 *    and on the CONSUMPTION side (`'#ffc-tabpanel-' + tab`). A name that never
 *    exists as a literal can only be recognised by prefix, the same limitation
 *    `CssClassEmitters` records.
 *
 * **What it does not see**, and is worth writing down: whether a test's fixture
 * matches the real markup. In the three cases that produced it the defect was in
 * the product AND in the fixture; this sees only the product half.
 */
final class JsIdSelectors {

	/** Directories scanned for whoever EMITS an id. */
	private const EMITTER_ROOTS = array( 'includes', 'templates', 'assets/js', 'libs/js' );

	/** Directory scanned for whoever LOOKS FOR an id. */
	private const CONSUMER_ROOT = 'assets/js';

	/**
	 * One WHOLE literal at a time, escapes included.
	 *
	 * Matching by loose alternation loses quote parity at the first apostrophe
	 * inside a double-quoted string and reads the rest of the file shifted -- the
	 * same reason `CssSelectors` has to be quote-aware.
	 */
	private const STRING_LITERAL = '/"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'/';

	/** Calls that take a selector as their first argument. */
	private const LOOKUP = '/(?:\$\(|jQuery\(|\.find\(|\.closest\(|\.is\(|\.filter\(|\.not\(|\.parents\(|\.siblings\(|\.children\(|\.has\(|querySelector\(|querySelectorAll\()\s*/';

	/**
	 * @var array<string, array<int, string>>|null Consumption: id => files.
	 */
	private static ?array $consumers = null;

	/**
	 * @var array{ids: array<string, bool>, prefixes: array<int, string>}|null
	 */
	private static ?array $emitters = null;

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @param array<int, string> $dirs
	 * @param string             $ext  Regex fragment for the extension.
	 * @return array<string, string> Relative path => contents.
	 */
	private static function files( array $dirs, string $ext ): array {
		$out = array();
		foreach ( $dirs as $dir ) {
			$base = self::root() . '/' . $dir;
			if ( ! is_dir( $base ) ) {
				continue;
			}
			$walk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $walk as $file ) {
				$path = $file->getPathname();
				if ( ! preg_match( '/\.' . $ext . '$/', $path ) || str_contains( $path, '.min.' ) ) {
					continue;
				}
				$out[ str_replace( self::root() . '/', '', $path ) ] = (string) file_get_contents( $path );
			}
		}
		ksort( $out );

		return $out;
	}

	/**
	 * The ids the JS looks for, keyed by id.
	 *
	 * @return array<string, array<int, string>> Id (without '#') => files.
	 */
	public static function consumers(): array {
		if ( null !== self::$consumers ) {
			return self::$consumers;
		}

		$found = array();
		foreach ( self::files( array( self::CONSUMER_ROOT ), 'js' ) as $path => $src ) {
			// A usage example inside a docblock is NOT a lookup. The header of
			// `ffc-admin-autosave.js` documents
			// `FFC.Admin.autoSaveField($('#admin_bypass_geo'), …)`, and without
			// stripping comments the scan reads it as consumption and demands an
			// emitter for an id no screen looks for. Same distinction CLAUDE.md
			// makes for suppression annotations: prose naming the token is not it.
			$src = self::strip_comments( $src );

			foreach ( self::selector_literals( $src ) as $selector ) {
				$id = self::simple_id( $selector );
				if ( null !== $id ) {
					$found[ $id ][] = $path;
				}
			}
			// `getElementById()` takes the id WITHOUT the '#'; without this
			// branch, half the dashboard's consumption would go unseen.
			if ( preg_match_all( '/getElementById\(\s*(["\'])([^"\']*)\1/', $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $one ) {
					$id = self::simple_id( '#' . $one[2] );
					if ( null !== $id ) {
						$found[ $id ][] = $path;
					}
				}
			}
		}

		foreach ( $found as $id => $paths ) {
			$found[ $id ] = array_values( array_unique( $paths ) );
		}
		ksort( $found );
		self::$consumers = $found;

		return $found;
	}

	/**
	 * Strips comments from a JS source, preserving strings.
	 *
	 * It has to be quote-aware: the `//` in `'https://example'` does not open a
	 * comment, and a naive scan would erase the rest of the line -- and with it
	 * any lookup that came after.
	 */
	private static function strip_comments( string $src ): string {
		$out    = '';
		$len    = strlen( $src );
		$quote  = '';
		$i      = 0;

		while ( $i < $len ) {
			$ch   = $src[ $i ];
			$next = $i + 1 < $len ? $src[ $i + 1 ] : '';

			if ( '' !== $quote ) {
				$out .= $ch;
				if ( '\\' === $ch ) {
					$out .= $next;
					$i   += 2;
					continue;
				}
				if ( $ch === $quote ) {
					$quote = '';
				}
				++$i;
				continue;
			}

			if ( '"' === $ch || "'" === $ch || '`' === $ch ) {
				$quote = $ch;
				$out  .= $ch;
				++$i;
				continue;
			}

			if ( '/' === $ch && '/' === $next ) {
				while ( $i < $len && "\n" !== $src[ $i ] ) {
					++$i;
				}
				continue;
			}

			if ( '/' === $ch && '*' === $next ) {
				$end = strpos( $src, '*/', $i + 2 );
				$i   = false === $end ? $len : $end + 2;
				continue;
			}

			$out .= $ch;
			++$i;
		}

		return $out;
	}

	/**
	 * Literals opening immediately after a lookup call.
	 *
	 * @return array<int, string>
	 */
	private static function selector_literals( string $src ): array {
		if ( ! preg_match_all( self::LOOKUP, $src, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$out = array();
		foreach ( $m[0] as $hit ) {
			$tail = substr( $src, $hit[1] + strlen( $hit[0] ), 300 );
			if ( ! preg_match( self::STRING_LITERAL, $tail, $lm, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			// The literal has to be the FIRST argument. Without this check,
			// `$( el ).attr( 'foo' )` would count `'foo'` as a selector.
			if ( $lm[0][1] > 0 ) {
				continue;
			}
			$out[] = '' !== $lm[1][0] ? $lm[1][0] : $lm[2][0];
		}

		return $out;
	}

	/**
	 * The id of a selector that is ONLY a simple id, or null.
	 *
	 * A literal ending in `-` or `_` is the start of a name assembled at runtime
	 * (`'#ffc-tabpanel-' + tab`), not a whole id: demanding an emitter for it
	 * would report an orphan that never existed as a name.
	 */
	private static function simple_id( string $selector ): ?string {
		if ( ! preg_match( '/^#([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $m ) ) {
			return null;
		}
		if ( str_ends_with( $m[1], '-' ) || str_ends_with( $m[1], '_' ) ) {
			return null;
		}

		return $m[1];
	}

	/**
	 * Everything that can emit an id.
	 *
	 * @return array{ids: array<string, bool>, prefixes: array<int, string>}
	 */
	private static function emitters(): array {
		if ( null !== self::$emitters ) {
			return self::$emitters;
		}

		$ids      = array();
		$prefixes = array();

		foreach ( self::files( self::EMITTER_ROOTS, '(php|js)' ) as $path => $src ) {
			$is_js = str_ends_with( $path, '.js' );

			// `id="foo"` in the markup.
			self::collect( '/\bid\s*=\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $src, 2, $ids );
			// `'id' => 'foo'` e `id: 'foo'`.
			self::collect( '/[\'"]?id[\'"]?\s*(?:=>|:)\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $src, 2, $ids );
			// `.attr( 'id', 'foo' )`.
			self::collect( '/\.attr\(\s*(["\'])id\1\s*,\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2/', $src, 3, $ids );
			// `el.id = 'foo'`.
			self::collect( '/\.id\s*=\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $src, 2, $ids );
			// `wp_nonce_field( $action, 'name' )` -- the id is the SECOND argument.
			self::collect( '/wp_nonce_field\(\s*[^,]+,\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $src, 2, $ids );

			// Prefix: a name followed by a PHP echo or by concatenation.
			if ( preg_match_all( '/\bid\s*=\s*["\']([A-Za-z][A-Za-z0-9_-]*[-_])(?=\s*(?:\.|\+|<)|\{|\$)/', $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $one ) {
					$prefixes[] = $one[1];
				}
			}

			// In JS a literal becomes an id through a variable:
			// `var inputId = 'x'; input.id = inputId`. Accepting ANY bare
			// literal would solve the case, and that is what the first version
			// did -- at the cost of inflating the emitter set from 578 to 2,082
			// names and swallowing a real finding (`#admin_bypass_geo`, whose
			// emitted id is `ffc_admin_bypass_geo`). The variable is followed by
			// ONE hop, which is what the case requires and nothing more.
			if ( $is_js ) {
				self::collect_via_variable( $src, $ids );
			}
		}

		self::$emitters = array(
			'ids'      => $ids,
			'prefixes' => array_values( array_unique( $prefixes ) ),
		);

		return self::$emitters;
	}

	/**
	 * A literal that reaches an id through a variable, one hop.
	 *
	 * @param array<string, bool> $into
	 */
	private static function collect_via_variable( string $src, array &$into ): void {
		// Names that afterwards appear as an id. Two shapes, and the second is
		// the one the real case uses: `'<input id="' + inputId + '"'` -- the
		// attribute is OPENED in a literal and the name arrives through the
		// variable, which is the exact mirror of the shape `CssClassEmitters`
		// records for classes.
		$as_id = array();
		$forms = array(
			'/(?:\.id\s*=\s*|\.attr\(\s*["\']id["\']\s*,\s*)([A-Za-z_$][A-Za-z0-9_$]*)\s*[;),]/',
			// No `\\?` here: inside a single-quoted PHP string `\\?` collapses to
			// `\?`, which the regex reads as a LITERAL `?` -- and then the
			// pattern never matches. It cost a measurement.
			'/\bid\s*=\s*["\']["\']\s*\+\s*([A-Za-z_$][A-Za-z0-9_$]*)/',
		);
		foreach ( $forms as $form ) {
			if ( preg_match_all( $form, $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $one ) {
					$as_id[ $one[1] ] = true;
				}
			}
		}
		if ( empty( $as_id ) ) {
			return;
		}

		// And the literal each of those variables was given.
		if ( preg_match_all( '/(?:var|let|const)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2/', $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $one ) {
				if ( isset( $as_id[ $one[1] ] ) ) {
					$into[ $one[3] ] = true;
				}
			}
		}
	}

	/**
	 * @param array<string, bool> $into
	 */
	private static function collect( string $pattern, string $src, int $group, array &$into ): void {
		if ( preg_match_all( $pattern, $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $one ) {
				$into[ $one[ $group ] ] = true;
			}
		}
	}

	/**
	 * Whether anything in the repository emits this id.
	 */
	public static function is_emitted( string $id ): bool {
		$e = self::emitters();
		if ( isset( $e['ids'][ $id ] ) ) {
			return true;
		}
		foreach ( $e['prefixes'] as $prefix ) {
			if ( str_starts_with( $id, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How many distinct ids the emission scan knows.
	 *
	 * It exists for the self-check: a scan that came back empty must not read as
	 * "clean" (the #1071 / #1094 lesson).
	 */
	public static function emitted_count(): int {
		return count( self::emitters()['ids'] );
	}
}
