<?php
/**
 * Vendored-bundle inventory guard (#1208).
 *
 * `libs/js/` carries the third-party JavaScript that actually ships: four
 * hand-vendored bundles, all enqueued at runtime, all inside the distributable
 * zip, and **none of them in any manifest**. The plugin declares zero managed
 * runtime dependencies (`composer.json`'s `require` is `php` alone,
 * `package.json`'s `dependencies` is empty) and `.distignore` drops `/vendor`
 * and `/node_modules`, so everything Dependabot can see is dev/CI tooling that
 * never ships, and everything that ships is invisible to it. A CVE in any of
 * the four will never open a PR here — a person has to notice.
 *
 * #1208 parked the three ways out (audit by hand, declare them in
 * `package.json` purely so Dependabot sees them, or build them from npm and
 * lose byte-for-byte verification against upstream) because none is obviously
 * right, and named two triggers for deciding rather than deferring: a
 * published CVE in one of the four, or a **fifth bundle landing here**. The
 * second trigger was detected by nobody. This is that detector.
 *
 * So REGISTER below is frozen on purpose, and a bundle upgrade is meant to
 * cost an edit to it — that edit is the moment a human is looking at what
 * ships. A fifth entry is not a line to add: it is the trigger, and the
 * decision belongs in #1208 before the register grows.
 *
 * It also holds the shape #1203 installed. The version lives in the filename
 * and the path is built from the constant, so the two halves cannot drift:
 * bumping `FFC_JSPDF_VERSION` without renaming the file 404s the script and
 * breaks PDF generation loudly. With a literal path the constant is only the
 * `?ver=` argument, so the same bump silently serves the OLD bundle under a
 * new cache key and nothing anywhere disagrees. That was live — the
 * appointment-receipt enqueue announced `?ver=2.5.1` for a 4.2.1 bundle — so
 * direction D refuses a version literal anywhere in a `libs/js/` enqueue.
 *
 * WHAT THIS CANNOT SEE: whether a vendored bundle has a CVE, or whether its
 * bytes match upstream. Both need the network and a human. What it guarantees
 * is that the inventory cannot change without somebody saying so.
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey — like the module
 * boundary, AJAX wiring and suppression guards. It reads source text only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class VendoredBundleInventoryTest extends TestCase {

	/**
	 * Everything `libs/js/` is allowed to contain, frozen.
	 *
	 * Four bundles plus one licence companion. ALTCHA is the only one whose
	 * licence ships beside it; the other three carry their terms inside the
	 * minified file. Read the failure message before editing this list.
	 *
	 * @var array<int, string>
	 */
	private const REGISTER = array(
		'altcha-3.2.2.LICENSE.txt',
		'altcha-3.2.2.umd.js',
		'html2canvas-1.4.1.min.js',
		'jspdf-4.2.1.umd.min.js',
		'thumbmark-1.10.1.umd.js',
	);

	/**
	 * A quoted version-like literal — `'2.5.1'`, `'1.4'`.
	 */
	private const VERSION_LITERAL = "/'\\d+\\.\\d+(\\.\\d+)*'/";

	/**
	 * Absolute path to the repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Repository-relative path, for readable failure messages.
	 */
	private static function relative( string $path ): string {
		return ltrim( str_replace( self::root(), '', $path ), '/' );
	}

	/**
	 * Everything actually sitting in `libs/js/`, sorted.
	 *
	 * @return array<int, string>
	 */
	private static function present(): array {
		$found = array();

		foreach ( (array) scandir( self::root() . '/libs/js' ) as $entry ) {
			if ( is_string( $entry ) && '.' !== $entry && '..' !== $entry ) {
				$found[] = $entry;
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * The library version constants, keyed by the bundle prefix they imply.
	 *
	 * `FFC_HTML2CANVAS_VERSION` implies `html2canvas-`, which is how every
	 * filename here is built. The plugin's own `FFC_VERSION` has no middle
	 * segment and so cannot match.
	 *
	 * @return array<string, string> prefix => version
	 */
	private static function library_versions(): array {
		$source = (string) file_get_contents( self::root() . '/ffcertificate.php' );

		preg_match_all(
			"/define\\(\\s*'FFC_([A-Z0-9]+)_VERSION'\\s*,\\s*'([^']+)'/",
			$source,
			$matches,
			PREG_SET_ORDER
		);

		$versions = array();

		foreach ( $matches as $match ) {
			$versions[ strtolower( $match[1] ) ] = $match[2];
		}

		return $versions;
	}

	/**
	 * Every PHP file under includes/, plus the bootstrap.
	 *
	 * @return array<int, string>
	 */
	private static function sources(): array {
		$found = array( self::root() . '/ffcertificate.php' );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$found[] = $file->getPathname();
			}
		}

		return $found;
	}

	/**
	 * Every statement that names a file under `libs/js/`.
	 *
	 * A reference is reported with the whole statement it sits in — from the
	 * `libs/js/` line up to the `);` that closes the call — because the defect
	 * this catches lives in a LATER argument (the `$ver` one), not on the line
	 * carrying the path.
	 *
	 * It matches the opening quote of the PATH LITERAL (`'libs/js/`), never the
	 * bare directory name: the `define()` lines that declare the four constants
	 * mention `libs/js/` in their trailing comment, so a looser match reports the
	 * version declarations themselves as hard-coded literals.
	 *
	 * @return array<int, array{where: string, statement: string}>
	 */
	private static function libs_js_statements(): array {
		$found = array();

		foreach ( self::sources() as $file ) {
			$lines = explode( "\n", (string) file_get_contents( $file ) );

			foreach ( $lines as $i => $line ) {
				if ( ! str_contains( $line, "'libs/js/" ) ) {
					continue;
				}

				$statement = $line;

				for ( $j = $i; $j < min( $i + 15, count( $lines ) ); $j++ ) {
					if ( $j > $i ) {
						$statement .= "\n" . $lines[ $j ];
					}

					if ( str_contains( $lines[ $j ], ');' ) ) {
						break;
					}
				}

				$found[] = array(
					'where'     => self::relative( $file ) . ':' . ( $i + 1 ),
					'statement' => $statement,
				);
			}
		}

		return $found;
	}

	public function test_libs_js_holds_exactly_the_registered_inventory(): void {
		$register = self::REGISTER;
		sort( $register );

		$this->assertSame(
			$register,
			self::present(),
			"`libs/js/` no longer matches the frozen register.\n\n"
			. "A NEW entry is trigger 2 of #1208 — a fifth hand-vendored bundle is the point at\n"
			. "which auditing by hand starts compounding, and #1208 says to DECIDE between\n"
			. "declaring the bundles in `package.json` and building them from npm rather than\n"
			. "defer again. Nothing here ever opens a Dependabot PR, so this list is the only\n"
			. "record of what third-party code actually ships.\n\n"
			. "A CHANGED entry is a bundle upgrade. That is fine, and the register is meant to\n"
			. "cost this edit: it is the moment somebody looks at what ships. Rename the file,\n"
			. 'bump the matching FFC_*_VERSION constant, and update the line here.'
		);
	}

	public function test_every_bundle_filename_carries_its_constant(): void {
		$versions = self::library_versions();
		$offenders = array();

		foreach ( self::present() as $entry ) {
			if ( ! str_ends_with( $entry, '.js' ) ) {
				continue;
			}

			$prefix = strstr( $entry, '-', true );

			if ( ! is_string( $prefix ) || ! isset( $versions[ $prefix ] ) ) {
				$offenders[] = $entry . ' — no FFC_' . strtoupper( (string) $prefix ) . '_VERSION constant declares it';
				continue;
			}

			if ( ! str_starts_with( $entry, $prefix . '-' . $versions[ $prefix ] . '.' ) ) {
				$offenders[] = $entry . ' — FFC_' . strtoupper( $prefix ) . '_VERSION says ' . $versions[ $prefix ];
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"A vendored bundle's filename disagrees with the constant that builds its path.\n"
			. "This is not cosmetic: the enqueue is `'libs/js/jspdf-' . FFC_JSPDF_VERSION . '.umd.min.js'`,\n"
			. "so a filename the constant does not name is a script that 404s. Rename the file and\n"
			. "the constant together (#1203):\n\n  "
			. implode( "\n  ", $offenders )
		);
	}

	public function test_every_library_constant_has_a_bundle(): void {
		$present = self::present();
		$orphans = array();

		foreach ( self::library_versions() as $prefix => $version ) {
			$matched = false;

			foreach ( $present as $entry ) {
				if ( str_starts_with( $entry, $prefix . '-' . $version . '.' ) && str_ends_with( $entry, '.js' ) ) {
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				$orphans[] = 'FFC_' . strtoupper( $prefix ) . "_VERSION = '" . $version . "' — no matching bundle in libs/js/";
			}
		}

		$this->assertSame(
			array(),
			$orphans,
			"A library version constant names a bundle that is not there. Either the file was\n"
			. "renamed without the constant, or the constant was bumped without swapping the file —\n"
			. "the second is the silent half, because it serves the OLD bundle under a new cache key\n"
			. "wherever a path is built from a literal instead (#1203):\n\n  "
			. implode( "\n  ", $orphans )
		);
	}

	public function test_no_libs_js_enqueue_names_a_version_literal(): void {
		$offenders = array();

		foreach ( self::libs_js_statements() as $reference ) {
			if ( preg_match( self::VERSION_LITERAL, $reference['statement'] ) ) {
				$offenders[] = $reference['where'];
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"A `libs/js/` enqueue carries a hard-coded version literal. Pass the FFC_*_VERSION\n"
			. "constant instead, for the path AND for the `\$ver` argument — the appointment-receipt\n"
			. "enqueue hard-coded '2.5.1' and was left behind when jsPDF moved to 4.2.1, so that\n"
			. "screen announced a cache key for a bundle it was not serving (#1203):\n\n  "
			. implode( "\n  ", $offenders )
		);
	}

	public function test_the_scan_finds_the_bundles_and_their_enqueues(): void {
		$this->assertNotSame(
			array(),
			self::present(),
			'libs/js/ scanned empty — the directory moved, and an empty scan must never read as clean.'
		);

		$statements = self::libs_js_statements();

		$this->assertGreaterThanOrEqual(
			4,
			count( $statements ),
			'Fewer than four `libs/js/` references found in PHP. Every vendored bundle is enqueued '
			. 'from somewhere, so a collapsed scan here would silently approve direction D.'
		);

		$multiline = 0;

		foreach ( $statements as $reference ) {
			if ( str_contains( $reference['statement'], "\n" ) ) {
				++$multiline;
			}
		}

		$this->assertGreaterThan(
			0,
			$multiline,
			'No multi-line enqueue was captured. The `$ver` argument sits on its own line in the '
			. 'multi-line form, so a scan that only ever sees one line cannot catch the defect '
			. 'direction D exists for.'
		);
	}
}
