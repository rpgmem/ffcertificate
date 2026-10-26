<?php
/**
 * "Preflight" means one thing: the visitor pre-flight (#2030).
 *
 * The word used to name three unrelated things — the checks a visitor's
 * browser runs before a form, a submission stage that checks consent and
 * e-mail presence, and a dry run of an identifier correction on the identity
 * screen. The second is `RequiredPresenceGuard` now and the third
 * `IdentityCorrectionPreview*`; this guard keeps the old names from coming
 * back, in code, comments, scripts and tests alike.
 *
 * What stays on purpose, and is not scanned for: the visitor pre-flight's
 * own names (`PreflightTelemetry`, `ffc_log_preflight_bail`, `FFC.Preflight`)
 * and `preflight_blocked`, a stored Activity Log value. `CHANGELOG.md` is not
 * scanned either: it records the old names as history.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Old names of the renamed "preflight" concepts are gone.
 *
 * @coversNothing
 */
class PreflightSingleMeaningTest extends TestCase {

	/**
	 * Directories scanned, relative to the repository root.
	 */
	private const DIRS = array( 'includes', 'assets/js', 'templates', 'tests', '.github/scripts' );

	/**
	 * Names that must not appear, case-insensitively, each with what replaced it.
	 */
	private const RETIRED = array(
		'PreflightGuard'                    => 'RequiredPresenceGuard',
		'preflight_errors'                  => '$errors in RequiredPresenceGuard',
		'preflight-guard'                   => 'class-ffc-required-presence-guard.php',
		'IdentityPreflight'                 => 'IdentityCorrectionPreview*',
		'identity-preflight'                => 'ffc-identity-correction-preview',
		'identity_preflight'                => 'ffc_identity_correction_preview',
		'FormProcessorPreflightOrderTest'   => 'FormProcessorRequiredPresenceOrderTest',
	);

	/**
	 * Every scanned file, as a path relative to the repository root.
	 *
	 * @return list<string>
	 */
	private static function files(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array();
		foreach ( self::DIRS as $dir ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				$path = $file->getPathname();
				if ( ! preg_match( '/\.(php|js|mjs|css)$/', $path ) || preg_match( '/\.min\.(js|css)$/', $path ) || basename( $path ) === basename( __FILE__ ) ) {
					continue;
				}
				$files[] = substr( $path, strlen( $root ) + 1 );
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * No scanned file mentions a retired name.
	 */
	public function test_no_retired_name_is_left(): void {
		$root  = dirname( __DIR__, 2 );
		$found = array();
		foreach ( self::files() as $file ) {
			$source = (string) file_get_contents( $root . '/' . $file );
			foreach ( self::RETIRED as $old => $new ) {
				if ( false !== stripos( $source, $old ) ) {
					$found[] = $file . ': "' . $old . '" (now ' . $new . ')';
				}
			}
		}
		$this->assertSame( array(), $found );
	}

	/**
	 * The new names are where the old ones were: a scan that found nothing
	 * because it read nothing must not pass.
	 */
	public function test_the_new_names_are_in_place(): void {
		$root = dirname( __DIR__, 2 );
		$this->assertFileExists( $root . '/includes/frontend/submission/class-ffc-required-presence-guard.php' );
		$this->assertFileExists( $root . '/includes/admin/class-ffc-identity-correction-preview-ajax-endpoint.php' );
		$this->assertFileExists( $root . '/assets/js/ffc-identity-correction-preview.js' );

		$files = self::files();
		$this->assertContains( 'includes/frontend/class-ffc-form-processor.php', $files );
		$this->assertContains( 'assets/js/ffc-identity-correction-preview.js', $files );
		$this->assertContains( '.github/scripts/preflight-e2e/run.mjs', $files );

		// An independent recount of the PHP files under includes/.
		$recount = 0;
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$recount += (int) ( 'php' === $file->getExtension() );
		}
		$scanned = count( array_filter( $files, static fn( $f ) => str_starts_with( $f, 'includes/' ) && str_ends_with( $f, '.php' ) ) );
		$this->assertSame( $recount, $scanned, 'The scan reads every PHP file under includes/.' );
	}
}
