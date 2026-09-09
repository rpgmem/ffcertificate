<?php
/**
 * Settings-default consistency guard (#993).
 *
 * A key's default currently lives in two kinds of place: declared once in
 * {@see \FreeFormCertificate\Admin\Settings::get_default_settings()}, and
 * repeated as the second argument of every `SettingsReader::get*()` read. Ten
 * of the thirty-three declared keys repeat it that way, and nothing forces the
 * copies to agree — so they drifted:
 *
 *   obsolete_shortcode_days   declared 90   read as 30
 *   qr_default_size           declared 200  read as 256
 *   public_csv_default_limit  declared 1    read as 100, and as 0 twice
 *
 * Three of those five lived inside `SettingsReader` itself, in the typed
 * accessors CLAUDE.md tells callers to prefer — so the documented path returned
 * the wrong default. All three accessors happened to be caller-less, so nothing
 * was broken in practice; the first caller would have inherited the bug (a
 * public-download quota of 100 where the declared default is 1).
 *
 * This guard makes that class fail in CI on the PR that introduces it. The
 * stronger fix is a registry where each key is declared once and the default
 * cannot be restated at all — designed and parked in #993, with the triggers
 * that would justify building it. Until then, detection is the cheap 90%.
 *
 * SCOPE — the default comparison covers only keys that appear in BOTH places.
 * The keys read without being declared at all are covered by the **second**
 * guard in this file, a ratchet over `tests/fixtures/undeclared-settings-keys-baseline.php`
 * that can only shrink. That gap was 17 keys when #993 was opened and 40 ten
 * days later, growing invisibly precisely because the comparison above cannot
 * see a key that only exists on one side. #1123 took it to 32: seven of the
 * forty were accessors on `SettingsReader` reading keys that live in *other*
 * options (nothing ever writes them into `ffc_settings`, so each returned its
 * hardcoded default forever), and the eighth was `notify_capability_grant`,
 * read to gate the "Access granted" email and written by nothing at all — the
 * #936 shape, which is #993's own third revisit trigger.
 *
 * WHAT THE RATCHET MEASURED, and it is not what the issue assumed —
 * `get_default_settings()` is not an incomplete registry, it is a list **no
 * runtime path reads**. Its only consumer is `Settings::get_option()`, a public
 * method with no caller: the `Settings` instance is constructed fire-and-forget
 * in `Loader` and never retained. Every effective default in the plugin comes
 * from a read-site literal or from `SettingsReader::get()`'s own `$default`
 * parameter, because `SettingsReader::all()` reads the raw option and merges
 * nothing. So declaring a key here today buys guard coverage, not behaviour —
 * which is exactly why the ratchet freezes the gap instead of the gap being
 * closed by a sweep. Closing it for real is the #993 registry decision.
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey — like the module
 * boundary and AJAX wiring guards. It reads source text only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SettingsDefaultsTest extends TestCase {

	/**
	 * Read sites whose default may legitimately differ from the declared one.
	 *
	 * Add an entry ONLY with the reason inline, and only when two consumers
	 * genuinely need different fallbacks for the same key — which is also the
	 * first revisit trigger on #993, so an entry here is a signal, not a
	 * shortcut. A default that is merely stale does not belong here: fix it.
	 *
	 * @var array<string, string> Key => justification.
	 */
	private const KNOWN_DIVERGENT_DEFAULTS = array();

	/**
	 * Absolute path to the repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every `includes/` file the two scanners are allowed to look at.
	 *
	 * Shared on purpose: two scanners over one population is how a guard ends
	 * up measuring less than it claims, and the exclusion below is the kind of
	 * thing that gets applied to one of them and forgotten on the other.
	 *
	 * @return array<int, string> Absolute paths, sorted.
	 */
	private static function scannable_files(): array {
		$out  = array();
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iter as $file ) {
			$path = $file->getPathname();
			if ( substr( $path, -4 ) !== '.php' || strpos( $path, '/libraries/' ) !== false ) {
				continue;
			}
			// `TabUserAccess` overrides `get_option()` to read a different
			// option entirely (`ffc_user_access_settings`), so its defaults
			// are not `ffc_settings` defaults and must not be compared.
			if ( strpos( $path, 'class-ffc-tab-user-access.php' ) !== false ) {
				continue;
			}
			$out[] = $path;
		}

		sort( $out );
		return $out;
	}

	/**
	 * The alternation of read forms that actually reach `ffc_settings`.
	 *
	 * It is a method rather than a constant because two of the forms are
	 * file-dependent, and both were live false positives before #1123:
	 *
	 *   - `SettingsReader::` needs the lookbehind or it also matches
	 *     `GeolocationSettingsReader::`, `RateLimitSettingsReader::` and
	 *     `IpDiagnosticsSettingsReader::` — sibling facades over *different*
	 *     options, whose keys are not `ffc_settings` keys and must never be
	 *     compared against, or frozen into, anything here.
	 *   - `self::get*()` only means `ffc_settings` inside `SettingsReader`
	 *     itself. The three sibling readers use the identical idiom for their
	 *     own option, so counting it everywhere imports their whole key set.
	 *
	 * @param string $path Absolute path of the file being scanned.
	 */
	private static function call_prefix( string $path ): string {
		$forms = array(
			'(?<![A-Za-z])SettingsReader::get(?:_int|_bool|_string|_array)?',
			'\\$this->get_option',
			'\\$ffcertificate_get_option',
			'\\$settings->get_option',
		);

		if ( strpos( $path, '/settings/class-ffc-settings-reader.php' ) !== false ) {
			$forms[] = 'self::get(?:_int|_bool|_string|_array)?';
		}

		return '(?:' . implode( '|', $forms ) . ')';
	}

	/**
	 * Compare two PHP scalar literals as written in source.
	 *
	 * `false` and `0` mean the same thing to `get_int()`, and a quoted string is
	 * the same default as its bare form here, so the comparison normalises both
	 * rather than reporting cosmetic differences as drift.
	 *
	 * @param string $literal Source text of the default.
	 */
	private static function normalise( string $literal ): string {
		$value = trim( $literal );
		$value = trim( $value, "'\"" );

		if ( 'true' === $value ) {
			return '1';
		}
		if ( 'false' === $value ) {
			return '0';
		}
		return $value;
	}

	/**
	 * The declared defaults, parsed out of `Settings::get_default_settings()`.
	 *
	 * @return array<string, string> Key => default as written.
	 */
	public static function declared_defaults(): array {
		$path = self::root() . '/includes/admin/class-ffc-settings.php';
		$text = (string) file_get_contents( $path );

		if ( ! preg_match( '/function get_default_settings\(\): array \{(.*?)\n\t\}/s', $text, $body ) ) {
			return array();
		}

		$out = array();
		if ( preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*([^,\n]+)/", $body[1], $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$out[ $match[1] ] = trim( $match[2] );
			}
		}

		ksort( $out );
		return $out;
	}

	/**
	 * Every read site that restates a scalar default.
	 *
	 * Three forms, all of which end up in `SettingsReader::get()`:
	 *
	 * - the qualified `SettingsReader::get*()` used by consumers;
	 * - the bare `self::get*()` used by the typed accessors inside the reader
	 *   — where the drift actually happened, so a pattern that only saw
	 *   external callers would have missed it;
	 * - **the settings-tab wrapper** `$tab->get_option( 'key', 'default' )`,
	 *   which the tab views reach through a closure named
	 *   `$ffcertificate_get_option`. `SettingsTab::get_option()` is one line
	 *   over `SettingsReader::get()`, so a default restated there is the same
	 *   defect — and it hid three of them in a single view (#1076): the QR
	 *   size, margin and error level each disagreed with the declared value,
	 *   so a fresh install showed settings the generator was not using.
	 *
	 * @return array<int, array{key: string, default: string, file: string}>
	 */
	public static function restated_defaults(): array {
		$out = array();

		foreach ( self::scannable_files() as $path ) {
			$text = (string) file_get_contents( $path );

			if ( ! preg_match_all(
				'/' . self::call_prefix( $path ) . "\(\s*'([a-z0-9_]+)'\s*,\s*([^,)]+?)\s*\)/",
				$text,
				$matches,
				PREG_SET_ORDER
			) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				// Non-scalar fallbacks (array(), a constant expression, a method
				// call) are not literals to compare — skip rather than guess.
				if ( strpos( $match[2], '(' ) !== false ) {
					continue;
				}
				$out[] = array(
					'key'     => $match[1],
					'default' => $match[2],
					'file'    => ltrim( str_replace( self::root(), '', $path ), '/' ),
				);
			}
		}

		return $out;
	}

	/**
	 * Resolve a `Class::CONSTANT` read-site default to the literal it holds.
	 *
	 * A read site that names a constant is not restating a default — it is
	 * referencing one — but the constant is still the effective fallback, so
	 * a constant that drifts from the declared value is the same defect. The
	 * resolver is deliberately narrow: it takes the constant's short name,
	 * requires exactly ONE `const NAME = '<literal>'` in `includes/`, and
	 * returns null when there is none or more than one. Guessing which class
	 * a repeated name belongs to would be worse than not looking.
	 *
	 * @param string $expression Read-site default as written.
	 * @return string|null Literal, or null when it is not a resolvable constant.
	 */
	private static function resolve_constant( string $expression ): ?string {
		if ( ! preg_match( '/::([A-Z][A-Z0-9_]*)$/', trim( $expression ), $name ) ) {
			return null;
		}

		$found = array();
		$iter  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iter as $file ) {
			$path = $file->getPathname();
			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}
			if ( preg_match_all(
				"/const\s+" . preg_quote( $name[1], '/' ) . "\s*=\s*('[^']*'|\d+|true|false)\s*;/",
				(string) file_get_contents( $path ),
				$hits
			) ) {
				foreach ( $hits[1] as $hit ) {
					$found[] = $hit;
				}
			}
		}

		$found = array_unique( $found );

		return 1 === count( $found ) ? reset( $found ) : null;
	}

	/**
	 * Keys the scan sees but that are not `ffc_settings` keys.
	 *
	 * `activity_log_cat_` is a **prefix**: the read is
	 * `self::get( 'activity_log_cat_' . $area )`, so the literal the scanner
	 * captures is half a key name and there is nothing to declare. Add here
	 * only for that reason — a key that is merely undeclared belongs in the
	 * baseline, where the ratchet can watch it.
	 *
	 * @var array<int, string>
	 */
	private const NOT_A_KEY = array( 'activity_log_cat_' );

	/**
	 * Every `ffc_settings` key read anywhere, declared or not.
	 *
	 * Same three read forms and same exclusions as {@see restated_defaults()},
	 * deliberately: two scanners over one population is how a guard ends up
	 * measuring less than it claims. This one differs in one way only — the
	 * default argument is optional, because a read with no default is still a
	 * read, and those are the majority of the undeclared set.
	 *
	 * @return array<int, string> Sorted, unique.
	 */
	public static function read_keys(): array {
		$out = array();

		foreach ( self::scannable_files() as $path ) {
			$text = (string) file_get_contents( $path );

			if ( ! preg_match_all(
				'/' . self::call_prefix( $path ) . "\(\s*'([a-z0-9_]+)'/",
				$text,
				$matches
			) ) {
				continue;
			}

			foreach ( $matches[1] as $key ) {
				if ( ! in_array( $key, self::NOT_A_KEY, true ) ) {
					$out[ $key ] = true;
				}
			}
		}

		$keys = array_keys( $out );
		sort( $keys );
		return $keys;
	}

	/**
	 * Keys read somewhere but absent from `get_default_settings()`.
	 *
	 * @return array<int, string> Sorted.
	 */
	public static function undeclared_read_keys(): array {
		return array_values( array_diff( self::read_keys(), array_keys( self::declared_defaults() ) ) );
	}

	/**
	 * The frozen gap.
	 *
	 * @return array<int, string>
	 */
	private static function baseline(): array {
		$file = self::root() . '/tests/fixtures/undeclared-settings-keys-baseline.php';
		$rows = is_readable( $file ) ? require $file : array();
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The gap may only shrink (#993).
	 *
	 * Both directions, like every other ratchet here. A **new** undeclared key
	 * fails: declare it, or add it deliberately and say why in the PR. A key
	 * that left the baseline also fails: it was declared, or its last read was
	 * removed — either way the win gets locked in rather than leaving room to
	 * drift back.
	 *
	 * Regenerate after an intentional change and read the diff:
	 * `FFC_UPDATE_UNDECLARED_KEYS_BASELINE=1 vendor/bin/phpunit --filter SettingsDefaults`
	 */
	public function test_the_undeclared_key_gap_only_shrinks(): void {
		$current = self::undeclared_read_keys();

		if ( getenv( 'FFC_UPDATE_UNDECLARED_KEYS_BASELINE' ) ) {
			$body = "<?php\n/**\n * Keys read from `ffc_settings` that `Settings::get_default_settings()`\n"
				. " * does not declare (#993). A ratchet that may only shrink — see\n"
				. " * tests/Unit/SettingsDefaultsTest.php for what the gap means and why\n"
				. " * closing it is a decision rather than a sweep.\n"
				. " *\n * Regenerate: FFC_UPDATE_UNDECLARED_KEYS_BASELINE=1 vendor/bin/phpunit --filter SettingsDefaults\n"
				. " *\n * @package FreeFormCertificate\\Tests\n */\n\nreturn array(\n";
			foreach ( $current as $key ) {
				$body .= "\t'" . $key . "',\n";
			}
			$body .= ");\n";
			file_put_contents( self::root() . '/tests/fixtures/undeclared-settings-keys-baseline.php', $body );
		}

		$baseline = self::baseline();

		$added = array_values( array_diff( $current, $baseline ) );
		$this->assertSame(
			array(),
			$added,
			"A settings key is read but never declared in get_default_settings():\n  " . implode( "\n  ", $added )
		);

		$closed = array_values( array_diff( $baseline, $current ) );
		$this->assertSame(
			array(),
			$closed,
			"A key left the gap — tighten the baseline to lock the win in:\n  " . implode( "\n  ", $closed )
		);
	}

	/**
	 * The three files that can put a key *into* `ffc_settings`.
	 *
	 * @return array<string, string> Repo-relative path => contents.
	 */
	private static function writer_sources(): array {
		$paths = array(
			'includes/admin/class-ffc-settings-save-handler.php',
			'includes/admin/class-ffc-settings-ajax-endpoint.php',
			'includes/admin/class-ffc-settings.php',
		);

		$out = array();
		foreach ( $paths as $rel ) {
			$out[ $rel ] = (string) file_get_contents( self::root() . '/' . $rel );
		}
		return $out;
	}

	/**
	 * Every key read from `ffc_settings` must be writable into it (#1123).
	 *
	 * This is the guard that would have caught all eight findings of #1123,
	 * and it blocks at zero — there is no baseline, because a key nothing can
	 * write is never a state to freeze. Two shapes fail it:
	 *
	 *   - A typed accessor added to `SettingsReader` for a key that belongs to
	 *     a sibling option (`ffc_geolocation_settings`,
	 *     `ffc_user_access_settings`, …). It compiles, it reads, and it
	 *     returns its hardcoded default for the life of the install.
	 *   - A feature gated on a key with no field, no allowlist entry and no
	 *     declaration — the #936 shape, which left the "Access granted" email
	 *     unsendable on every install that ever ran this plugin.
	 *
	 * Deliberately a literal-occurrence test rather than a parse: the debug
	 * flags are written through `$clean[ $flag ]` in a loop, so no structural
	 * scan sees the assignment, but the flag names are right there in the
	 * array the loop walks. A key named in one of these three files can be
	 * written; a key named in none of them cannot.
	 */
	public function test_every_key_read_from_ffc_settings_can_be_written_to_it(): void {
		$writers = implode( "\n", self::writer_sources() );
		$orphans = array();

		foreach ( self::read_keys() as $key ) {
			if ( strpos( $writers, "'" . $key . "'" ) === false ) {
				$orphans[] = $key;
			}
		}

		$this->assertSame(
			array(),
			$orphans,
			"Read from `ffc_settings` but nothing can write it there:\n  " . implode( "\n  ", $orphans )
			. "\n\nEither the key belongs to another option (move the read to that option's"
			. "\nreader), or the feature reading it is dormant and needs a field, an"
			. "\nallowlist entry and a declared default."
		);
	}

	/**
	 * The write-path guard's own self-check.
	 *
	 * Its assertion is `array() === $orphans`, which an empty read set also
	 * satisfies — so the scan and the writer texts are asserted non-trivial
	 * before the green is believed.
	 */
	public function test_the_write_path_guard_reads_both_sides(): void {
		foreach ( self::writer_sources() as $rel => $text ) {
			$this->assertNotSame( '', $text, "Could not read $rel — the write-path guard has no writer side." );
		}

		$this->assertGreaterThan( 60, count( self::read_keys() ), 'The read scan collapsed — check the pattern and the excluded paths.' );
	}

	/**
	 * The ratchet's own self-check.
	 *
	 * A scan that collapses turns `array_diff()` into two empty sets and the
	 * ratchet passes while measuring nothing — the failure mode #1094 found in
	 * four guards at once, and the one #1090 found in the level-9 ruler.
	 */
	public function test_the_read_scan_cannot_collapse_in_silence(): void {
		$this->assertGreaterThan( 60, count( self::read_keys() ), 'The read scan collapsed — check the pattern and the excluded paths.' );
		$this->assertNotEmpty( self::baseline(), 'The baseline is empty; regenerate it deliberately or the ratchet guards nothing.' );
	}

	/**
	 * The guard's own inputs must be readable, or it proves nothing.
	 *
	 * A parser that silently returns an empty set turns a green run into a lie,
	 * so the shape of both sources is asserted before they are compared.
	 */
	public function test_the_guard_can_see_both_sides(): void {
		$this->assertNotSame(
			array(),
			self::declared_defaults(),
			'Could not parse Settings::get_default_settings() — fix this parser rather than hardcoding a list.'
		);
		$this->assertNotSame(
			array(),
			self::restated_defaults(),
			'Found no SettingsReader reads with a literal default — the pattern probably stopped matching.'
		);
	}

	/**
	 * A restated default must equal the declared one.
	 */
	public function test_read_site_defaults_match_the_declared_default(): void {
		$declared = self::declared_defaults();
		$drift    = array();

		foreach ( self::restated_defaults() as $read ) {
			$key = $read['key'];

			// Not declared: covered by #993, not by this guard (see SCOPE).
			if ( ! isset( $declared[ $key ] ) || isset( self::KNOWN_DIVERGENT_DEFAULTS[ $key ] ) ) {
				continue;
			}

			// A named constant is the effective default too; resolve it when
			// the name is unambiguous, and skip when it is not.
			$read_default = $read['default'];
			if ( strpos( $read_default, '::' ) !== false ) {
				$resolved = self::resolve_constant( $read_default );
				if ( null === $resolved ) {
					continue;
				}
				$read_default = $resolved;
			}

			if ( self::normalise( $declared[ $key ] ) !== self::normalise( $read_default ) ) {
				$drift[] = sprintf(
					'%s  declared %s, read as %s  (%s)',
					$key,
					$declared[ $key ],
					$read['default'],
					$read['file']
				);
			}
		}

		sort( $drift );

		$this->assertSame(
			array(),
			$drift,
			"A settings default is restated at a read site with a different value:\n  "
			. implode( "\n  ", $drift )
			. "\n\nThe declared default in Settings::get_default_settings() is authoritative."
			. "\nAlign the read site with it. If two consumers genuinely need different"
			. "\nfallbacks, that is the first revisit trigger on #993 — record it in"
			. "\nKNOWN_DIVERGENT_DEFAULTS with the reason."
		);
	}

	/**
	 * Neither scan may collapse without the guard noticing.
	 *
	 * **Why.** The assertion below compares `array()` against a list of drifting
	 * defaults, so a scan that stopped matching would find no drift and this
	 * file would report that every default agrees — because it read none. And
	 * the collapse has TWO independent triggers here, either of which is
	 * enough:
	 *
	 *   1. `declared_defaults()` returns `array()` outright when the
	 *      `get_default_settings(): array {` pattern misses — a changed
	 *      signature, an added parameter, a reformat. Every read site then
	 *      falls through the `! isset( $declared[ $key ] )` skip.
	 *   2. `restated_defaults()` yields nothing when the accessor pattern
	 *      misses, and the loop has nothing to compare.
	 *
	 * This is the shape the row-shape ruler lacked: it printed "Every class
	 * that reads a row declares what the row holds" while measuring 45 of the
	 * 53 that do (#1090). The check below anchors the declared side against an
	 * independent fact — the method exists in the file — rather than against a
	 * number that would need updating with every new setting.
	 *
	 * @return void
	 */
	public function test_neither_scan_can_collapse_in_silence(): void {
		$settings_path = self::root() . '/includes/admin/class-ffc-settings.php';
		$source        = (string) file_get_contents( $settings_path );

		$this->assertStringContainsString(
			'function get_default_settings',
			$source,
			'Settings::get_default_settings() is gone — this guard has no declared side left to compare against.'
		);

		$this->assertNotEmpty(
			self::declared_defaults(),
			'get_default_settings() exists but the parse yielded no key: the body pattern no longer matches its'
			. ' signature, so every read site would be skipped as "not declared" and the guard would pass blind.'
		);

		$this->assertNotEmpty(
			self::restated_defaults(),
			'No read site restates a default anywhere in includes/ — the accessor pattern is broken, not the codebase.'
		);
	}
}
