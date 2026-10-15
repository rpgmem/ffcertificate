<?php
/**
 * The single owner of the test `wp-admin/includes/upgrade.php` stub.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Writes the `upgrade.php` the activators `require_once` from `ABSPATH`
 * (#1803).
 *
 * Unit tests run without WordPress, so the file the activators load before
 * calling `dbDelta()` has to be provided. Four activator tests used to write
 * their own copy when the file was missing, and their copy was EMPTY: had any
 * of them run before the bootstrap's, `require_once` would have spent the
 * file with `dbDelta` still undefined, and later tests would fail depending
 * on run order. So the stub is written here and only here, by the bootstrap,
 * and it always declares `dbDelta()`.
 *
 * An existing file is rewritten when its contents differ, so a stub left
 * behind by an older checkout — the empty one included — cannot survive into
 * the run. The no-op is declared only if nothing declared `dbDelta` first,
 * which is what lets a test's own `Functions\when( 'dbDelta' )` take effect.
 *
 * The file lives under the bootstrap's fixed `ABSPATH` (`/tmp/wordpress/`),
 * shared by every run on the machine, and that is deliberate (#1811). It is
 * the only file anything creates there: `includes/` requires other files
 * under `ABSPATH` (`formatting.php`, `class-wp-list-table.php`, …) only
 * behind a guard the tests satisfy by stubbing, and the tests read the
 * constant as an opaque string. Concurrent runs cannot corrupt it either:
 * they write the same bytes, a reader sees a whole file because the write
 * goes through a rename in the same directory, and a run from another
 * checkout whose stub differs still declares the same no-op `dbDelta()`.
 * A per-run `ABSPATH` would buy nothing for that and cost a directory per
 * isolated test, because a test in a separate process re-runs the
 * bootstrap. A directory another user owns makes `ensure()` throw, so the
 * one failure the shared path can cause is loud.
 */
final class UpgradeStub {

	/**
	 * The stub's exact contents.
	 */
	public const CONTENTS = "<?php\n// Stub for unit tests, written by tests/Support/UpgradeStub.php.\nif ( ! function_exists( 'dbDelta' ) ) {\n\tfunction dbDelta( \$queries = '', \$execute = true ) { return array(); }\n}\n";

	/**
	 * Path of the stub under the given WordPress root.
	 *
	 * @param string $abspath WordPress root, with a trailing slash.
	 * @return string
	 */
	public static function path( string $abspath ): string {
		return $abspath . 'wp-admin/includes/upgrade.php';
	}

	/**
	 * Writes the stub unless the file already holds exactly these contents.
	 *
	 * The write goes through a temporary file and a rename, so a process
	 * that reads the file while another one writes it sees either the old
	 * complete file or the new one, never a partial one.
	 *
	 * @param string $abspath WordPress root, with a trailing slash.
	 * @return string The stub's path.
	 */
	public static function ensure( string $abspath ): string {
		$file = self::path( $abspath );
		if ( is_file( $file ) && self::CONTENTS === file_get_contents( $file ) ) {
			return $file;
		}

		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Cannot create ' . $dir . ' for the upgrade.php stub.' );
		}

		$tmp = $file . '.' . getmypid() . '.tmp';
		if ( false === file_put_contents( $tmp, self::CONTENTS ) || ! rename( $tmp, $file ) ) {
			throw new \RuntimeException( 'Cannot write the upgrade.php stub at ' . $file . '.' );
		}

		return $file;
	}
}
