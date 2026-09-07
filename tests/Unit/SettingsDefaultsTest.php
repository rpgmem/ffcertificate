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
 * SCOPE — it compares only keys that appear in BOTH places. Seventeen keys are
 * read through `SettingsReader` without being declared at all, so
 * `get_default_settings()` is an incomplete list rather than a registry;
 * requiring declaration for all of them is the registry's job, not this guard's.
 * That gap is recorded in #993.
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
		$dir  = self::root() . '/includes';
		$out  = array();
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
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

			$text = (string) file_get_contents( $path );

			if ( ! preg_match_all(
				"/(?:(?:SettingsReader::|self::)get(?:_int|_bool|_string|_array)?|\\\$this->get_option|\\\$ffcertificate_get_option|\\\$settings->get_option)\(\s*'([a-z0-9_]+)'\s*,\s*([^,)]+?)\s*\)/",
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
