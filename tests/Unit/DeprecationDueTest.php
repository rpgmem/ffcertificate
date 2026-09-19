<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A removal release is a promise, and this is what makes it fire (#1309).
 *
 * The repository runs real deprecation cycles: an old filter kept alive through
 * `apply_filters_deprecated()`, a method that still answers while
 * `_deprecated_function()` warns, an old AJAX `action` name still registered
 * beside the new one. Each carries a release at which it goes. What none of
 * them carried was anything that FIRES when that release arrives -- the removal
 * depended on somebody re-reading the issue at the right moment.
 *
 * A cycle that outlives its own release stops being a cycle: it becomes
 * permanent legacy nobody decided to keep, which is the whole subject of
 * `CLAUDE.md` "Legacy and tech debt" and the reason that section keeps an audit
 * log with exit conditions.
 *
 * **The date is a field, not a sentence.** The first version of this guard read
 * the prose that was already there -- `@deprecated X … to be removed in Y` and
 * the `until Y` beside an `apply_filters_deprecated()` call -- and it reported
 * two sites in `Security` that are the exact opposite of a promise: past-tense
 * history about the `$token` argument that WAS removed in 6.24.0. A sentence
 * saying `until 6.24.0` is a live promise or a finished story depending on the
 * tense of a verb several clauses away, and the classifier that tells those
 * apart is the "dictionary describes itself" trap `CommentLanguageTest` already
 * records. So the prose stays prose, and `@removal <version>` -- a tag, in a
 * docblock or a `//` comment, on the code that is still there -- is the only
 * thing read here.
 *
 * That marker is written ONCE, at the site, which is what keeps it out of the
 * #1261 class: there is no second list to fall out of step, and the prose beside
 * it no longer restates the number.
 *
 * Two directions, both blocking at zero:
 *
 * - **A** -- no `@removal` may be outlived by the plugin's own version. It
 *   fails in the release that must act, naming every site, and it does not
 *   decide the outcome: deferring a cycle deliberately means moving the date in
 *   the file, which is where that argument belongs.
 * - **B** -- every WordPress deprecation call carries a marker. Without it, A
 *   would only ever see what somebody remembered to mark, and a new cycle could
 *   ship with no end date at all. B is what makes A's zero mean something.
 *
 * What neither sees: a thing kept alive by nothing but an extra `add_action()`
 * -- there is no call for B to anchor on, so the marker there is voluntary. The
 * `ffc_generate_ficha` alias carries one because it was written by hand.
 *
 * @coversNothing
 */
class DeprecationDueTest extends TestCase {

	/**
	 * The WordPress functions that keep a deprecated surface answering.
	 *
	 * Every one of them takes the release it was DEPRECATED in as an argument
	 * -- none of them takes the release it will be REMOVED in, which is exactly
	 * the gap `@removal` fills.
	 */
	private const DEPRECATION_CALLS = array(
		'apply_filters_deprecated',
		'do_action_deprecated',
		'_deprecated_function',
		'_deprecated_hook',
		'_deprecated_argument',
		'_deprecated_file',
		'_deprecated_class',
	);

	/**
	 * The plugin version every date is compared against.
	 *
	 * Read from the header rather than the constant, because the constant needs
	 * the plugin bootstrapped and the header is what WordPress itself parses
	 * before any PHP runs -- the two are kept in step by the release process
	 * (`CLAUDE.md` "Versioning"), and this file only needs one of them.
	 */
	private function plugin_version(): string {
		$header = (string) file_get_contents( $this->root() . '/ffcertificate.php' );

		return 1 === preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9.]*)\s*$/m', $header, $m ) ? $m[1] : '';
	}

	/**
	 * Repository root.
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every `.php` file under `includes/`.
	 *
	 * @return list<string>
	 */
	private function php_files(): array {
		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();
			if ( '.php' === substr( $path, -4 ) ) {
				$files[] = $path;
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Path relative to the repository root, for a readable failure message.
	 */
	private function relative( string $path ): string {
		return ltrim( str_replace( $this->root(), '', $path ), '/' );
	}

	/**
	 * Every `@removal <version>` marker, as `file:line => version`.
	 *
	 * @return array<string, string>
	 */
	private function markers(): array {
		$found = array();

		foreach ( $this->php_files() as $path ) {
			foreach ( $this->markers_in( (string) file_get_contents( $path ) ) as $line => $version ) {
				$found[ $this->relative( $path ) . ':' . $line ] = $version;
			}
		}

		return $found;
	}

	/**
	 * The markers of one source, as `line => version`.
	 *
	 * Read from PHP COMMENT TOKENS rather than from the raw lines, so a version
	 * inside a string literal or a piece of markup can never be mistaken for a
	 * marker -- `\@removal` is a word, and this file is not the only place the
	 * word could appear. Both comment kinds count: a docblock tag where the
	 * deprecated thing is a method or a documented hook, a `//` line where it is
	 * a bare registration with no docblock of its own.
	 *
	 * Taking a source string rather than a path is what makes the paragraph
	 * above falsifiable -- see `test_the_marker_scan_reads_comments_only()`.
	 *
	 * @return array<int, string>
	 */
	private function markers_in( string $source ): array {
		$found = array();

		foreach ( $this->comments_in( $source ) as $comment ) {
			foreach ( explode( "\n", (string) $comment['text'] ) as $offset => $line ) {
				if ( 1 === preg_match( '/@removal\s+([0-9][0-9.]*[0-9])/', $line, $m ) ) {
					$found[ (int) $comment['line'] + (int) $offset ] = $m[1];
				}
			}
		}

		return $found;
	}

	/**
	 * The comments of one source, as `array{line:int, text:string}`.
	 *
	 * @return list<array{line:int, text:string}>
	 */
	private function comments_in( string $source ): array {
		$comments = array();

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$comments[] = array(
					'line' => (int) $token[2],
					'text' => (string) $token[1],
				);
			}
		}

		return $comments;
	}

	/**
	 * Every deprecation call site, as `file:line => function`.
	 *
	 * @return array<string, string>
	 */
	private function deprecation_calls(): array {
		$found = array();

		foreach ( $this->php_files() as $path ) {
			foreach ( $this->calls_in( (string) file_get_contents( $path ) ) as $line => $function ) {
				$found[ $this->relative( $path ) . ':' . $line ] = $function;
			}
		}

		return $found;
	}

	/**
	 * The deprecation calls of one source, as `line => function`.
	 *
	 * Two token shapes, and the second is the #1284 trap: `\\_deprecated_function(`
	 * is ONE token (`T_NAME_FULLY_QUALIFIED`) carrying the backslash, so a scan
	 * that knows only `T_STRING` reports a leading-backslash call as absent --
	 * which is how 30 live i18n strings were nearly declared dead there. No call
	 * site is written that way today; the canary is what keeps it that way
	 * cheaply if one ever is.
	 *
	 * The name must be FOLLOWED by an argument list, because a name is not a
	 * call: `use function _deprecated_hook;` imports it and never invokes it.
	 *
	 * @return array<int, string>
	 */
	private function calls_in( string $source ): array {
		$found  = array();
		$tokens = token_get_all( $source );
		$count  = count( $tokens );

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
				continue;
			}

			$name = ltrim( (string) $token[1], '\\' );
			if ( ! in_array( $name, self::DEPRECATION_CALLS, true ) ) {
				continue;
			}

			$next = $index + 1;
			while ( $next < $count && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
				++$next;
			}

			if ( $next < $count && '(' === $tokens[ $next ] ) {
				$found[ (int) $token[2] ] = $name;
			}
		}

		return $found;
	}

	/**
	 * The comment a deprecation call is covered by, if any.
	 *
	 * Two shapes, because the codebase has two and a check that knew one would
	 * report the other as undated. The call may sit directly under the hook
	 * docblock that documents it (`apply_filters_deprecated()`), or deep inside
	 * a method whose own docblock carries the tag (`_deprecated_function()`,
	 * which is 47 lines below its `@deprecated`). So: the nearest comment
	 * ending above the call, or the docblock of the function enclosing it.
	 *
	 * @return list<string> The comment texts that could cover this call.
	 */
	private function covering_comments( string $path, int $line ): array {
		$tokens   = token_get_all( (string) file_get_contents( $path ) );
		$covering = array();
		$nearest  = null;
		$last_doc = null;
		$enclosing_doc = null;

		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( (int) $token[2] >= $line ) {
				break;
			}

			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$nearest = (string) $token[1];

				if ( T_DOC_COMMENT === $token[0] ) {
					$last_doc = (string) $token[1];
				}

				continue;
			}

			if ( T_FUNCTION === $token[0] ) {
				$enclosing_doc = $last_doc;
			}
		}

		foreach ( array( $nearest, $enclosing_doc ) as $text ) {
			if ( null !== $text ) {
				$covering[] = $text;
			}
		}

		return $covering;
	}

	/**
	 * Self-check: an empty scan must fail rather than read as clean.
	 *
	 * The #1071 / #1094 rule every guard here carries. A renamed directory, a
	 * tokenizer that stopped resolving, or a marker spelling that drifted would
	 * otherwise turn both directions green for as long as nobody looked.
	 */
	public function test_the_scan_finds_the_live_cycles(): void {
		$this->assertNotSame( '', $this->plugin_version(), 'The plugin header version did not parse.' );

		$markers = $this->markers();
		$calls   = $this->deprecation_calls();

		$this->assertNotEmpty( $markers, 'No @removal marker was found anywhere -- the comment scan is broken.' );
		$this->assertNotEmpty( $calls, 'No deprecation call was found anywhere -- the token scan is broken.' );

		// Named rather than counted, so a shrinking cycle does not have to
		// touch this file -- except when the cycle NAMED here is the one that
		// closes, which is what #1245 did in 6.27.0: naming a version is itself
		// a claim about a value another file owns, so it goes stale exactly the
		// way `CLAUDE.md` says a number does. Prefer the OLDEST open cycle here,
		// because that is the one a reader most needs to see the scan still
		// reaching, and re-point it when it closes.
		$versions = array_values( $markers );
		$this->assertContains( '6.28.0', $versions, 'The #1264 / #1313 cycles lost their @removal markers.' );

		// Both call shapes stay covered: the filter aliases are #1264's, and
		// the method notices are #1313's, which outlived #1245's.
		$this->assertContains( 'apply_filters_deprecated', array_values( $calls ), 'The #1264 filter aliases stopped resolving as calls.' );
		$this->assertContains( '_deprecated_function', array_values( $calls ), 'The #1313 method notices stopped resolving as calls.' );
	}

	/**
	 * Canary: the call scan reads both name shapes, and a name is not a call.
	 *
	 * Against a synthetic source, because neither shape it has to survive is in
	 * the tree: no call site is written with a leading backslash, and nothing
	 * imports one with `use function`. A scan tuned against only what exists
	 * freezes a wrong baseline, and a baseline is believed (#1284).
	 */
	public function test_the_call_scan_reads_both_name_shapes(): void {
		$source = "<?php\n"
			. "use function _deprecated_hook;\n"
			. "\$name = '_deprecated_argument';\n"
			. "apply_filters_deprecated( 'x', array(), '1.0' );\n"
			. "\\_deprecated_function( __METHOD__, '1.0' );\n";

		$this->assertSame(
			array(
				4 => 'apply_filters_deprecated',
				5 => '_deprecated_function',
			),
			$this->calls_in( $source ),
			'The call scan must read T_STRING and T_NAME_FULLY_QUALIFIED alike, and must not read an import or a string literal as a call.'
		);
	}

	/**
	 * Canary: a marker is a comment, never a literal or a piece of markup.
	 *
	 * The word could appear in a translated sentence about the cycle, or in the
	 * documentation page that describes it. Only the comment counts, so the date
	 * the guard acts on is the one an author wrote AS a date.
	 */
	public function test_the_marker_scan_reads_comments_only(): void {
		$source = "<?php\n"
			. "/**\n"
			. " * @removal 9.9.9\n"
			. " */\n"
			. "\$copy = 'the old names keep firing -- @removal 8.8.8';\n"
			. "// @removal 7.7.7\n"
			. "?>\n"
			. "<p>@removal 6.6.6</p>\n";

		$this->assertSame(
			array(
				3 => '9.9.9',
				6 => '7.7.7',
			),
			$this->markers_in( $source ),
			'A marker must come from a docblock or a line comment -- never from a string literal or inline HTML.'
		);
	}

	/**
	 * Direction A -- a cycle may not outlive its own release.
	 *
	 * Clearing it means deleting the deprecated code. If the cycle is being
	 * extended on purpose, move the date in the file: that is the decision, and
	 * it is worth arguing where the code is.
	 */
	public function test_no_deprecation_has_outlived_its_removal_version(): void {
		$version = $this->plugin_version();
		$due     = array();

		foreach ( $this->markers() as $where => $removal ) {
			if ( version_compare( $version, $removal, '>=' ) ) {
				$due[] = $where . ' (@removal ' . $removal . ')';
			}
		}

		sort( $due );

		$this->assertSame(
			array(),
			$due,
			sprintf(
				'The plugin is at %s and these deprecations were promised to be gone by now. Remove the deprecated code, or move the date deliberately and say why: a cycle that outlives its own release stops being a cycle and becomes legacy nobody chose to keep.',
				$version
			)
		);
	}

	/**
	 * Direction B -- a deprecation call must declare when it ends.
	 *
	 * This is what stops direction A from measuring only what somebody
	 * remembered to mark. A new `apply_filters_deprecated()` with no `@removal`
	 * above it is a cycle with no end, which is the state this whole guard
	 * exists to make impossible.
	 */
	public function test_every_deprecation_call_declares_its_removal_version(): void {
		$undated = array();

		foreach ( $this->deprecation_calls() as $where => $function ) {
			list( $relative, $line ) = explode( ':', $where );

			$dated = false;
			foreach ( $this->covering_comments( $this->root() . '/' . $relative, (int) $line ) as $text ) {
				if ( 1 === preg_match( '/@removal\s+[0-9][0-9.]*[0-9]/', $text ) ) {
					$dated = true;
					break;
				}
			}

			if ( ! $dated ) {
				$undated[] = $where . ' (' . $function . ')';
			}
		}

		sort( $undated );

		$this->assertSame(
			array(),
			$undated,
			'These deprecation calls declare no removal release. Add `@removal X.Y.Z` to the docblock of the hook or method they belong to -- a cycle without an end date is not a cycle.'
		);
	}
}
