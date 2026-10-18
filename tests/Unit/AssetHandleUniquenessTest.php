<?php
/**
 * Guard: one handle per asset file (#1922).
 *
 * WordPress de-duplicates scripts and styles by HANDLE, never by file. One file
 * registered under two handles therefore loads twice on a page that reaches
 * both, and a script loaded twice runs twice: `ffc-frontend-helpers.js` was
 * `ffc-rate-limit` for the certificate form and `ffc-frontend-helpers` for the
 * personal calendar, so a page carrying both shortcodes rebound
 * `window.FFC.Frontend`, `window.ffcUtils` and `window.FFCRateLimit` and ran
 * two copies of the rate-limit and mask state. `ffc-frontend.css` had the same
 * split (`ffc-frontend-css` / `ffc-frontend`) between the same two shortcodes.
 *
 * The scan is `Support\AssetEnqueues`, which reads the token stream, so a call
 * quoted in a comment is not a call. A call whose handle it cannot resolve
 * fails here rather than being skipped: an empty or partial reading must never
 * look like a clean one.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\AssetEnqueues;
use FreeFormCertificate\Tests\Support\PhpSource;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class AssetHandleUniquenessTest extends TestCase {

	/**
	 * Files deliberately enqueued under more than one handle, each with its reason.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		// CLAUDE.md "Page scope on the admin `wrap`": `ffc-admin` on users.php,
		// `ffc-admin-css` on FFC screens, with different dependency lists. The
		// two never meet on one screen -- users.php is not an FFC page.
		'assets/css/ffc-admin.css' => 'users.php and the FFC screens each enqueue it with their own dependencies; no screen is both',
	);

	public function test_no_asset_file_is_enqueued_under_two_handles(): void {
		$this->assertSame(
			array(),
			array_diff_key( $this->files_with_several_handles(), self::ALLOWED ),
			'WordPress loads one file under two handles twice. Enqueue the handle that already exists for it.'
		);
	}

	public function test_every_allowed_file_still_has_several_handles(): void {
		$several = $this->files_with_several_handles();
		foreach ( array_keys( self::ALLOWED ) as $asset ) {
			$this->assertArrayHasKey( $asset, $several, $asset . ' is down to one handle: drop it from ALLOWED.' );
		}
	}

	public function test_every_asset_call_has_a_handle_the_scan_can_read(): void {
		$unread = array();
		foreach ( $this->asset_calls() as $call ) {
			if ( null === $call['handle'] && ! $call['self_named'] ) {
				$unread[] = $call['file'] . ':' . $call['line'] . ' ' . $call['asset'];
			}
		}

		$this->assertSame(
			array(),
			$unread,
			'Use a literal handle or a class constant, or teach Support\AssetEnqueues the new shape.'
		);
	}

	public function test_the_scan_counts_every_call_a_second_reading_finds(): void {
		$pattern = '/(?<![\w$>:])\\\\?(?:' . implode( '|', AssetEnqueues::FUNCTIONS ) . ')\s*\(/';
		$count   = 0;
		foreach ( PhpSource::files_under( 'includes' ) as $file ) {
			$count += preg_match_all( $pattern, PhpSource::code( $file ) );
		}

		$this->assertSame( $count, count( AssetEnqueues::calls() ) );
		$this->assertGreaterThan( (int) ( 0.9 * $count ), count( $this->asset_calls() ), 'Most calls name a file of ours; far fewer means the path reader broke.' );
	}

	public function test_the_path_reader_normalises_every_suffix_shape(): void {
		$this->assertSame( 'assets/js/ffc-core.js', AssetEnqueues::asset( 'FFC_PLUGIN_URL."assets/js/ffc-core{$s}.js"' ) );
		$this->assertSame( 'assets/js/ffc-core.js', AssetEnqueues::asset( 'FFC_PLUGIN_URL."assets/js/ffc-core{$ffc_s}.js"' ) );
		$this->assertSame( 'assets/css/ffc-common.css', AssetEnqueues::asset( "FFC_PLUGIN_URL.'assets/css/ffc-common.min.css'" ) );
		$this->assertSame( 'assets/css/ffc-common.css', AssetEnqueues::asset( "FFC_PLUGIN_URL.'assets/css/ffc-common'.\$s.'.css'" ) );
		$this->assertSame(
			'libs/js/jspdf-{FFC_JSPDF_VERSION}.umd.min.js',
			AssetEnqueues::asset( "FFC_PLUGIN_URL.'libs/js/jspdf-'.FFC_JSPDF_VERSION.'.umd.min.js'" )
		);
		$this->assertNull( AssetEnqueues::asset( "'https://example.test/x.js'" ) );
	}

	public function test_the_two_shortcodes_share_the_form_handles(): void {
		$handles = array();
		foreach ( $this->asset_calls() as $call ) {
			if ( null !== $call['handle'] ) {
				$handles[ $call['asset'] ][ $call['handle'] ] = true;
			}
		}

		$this->assertSame( array( 'ffc-rate-limit' ), array_keys( $handles['assets/js/ffc-frontend-helpers.js'] ) );
		$this->assertSame( array( 'ffc-frontend-css' ), array_keys( $handles['assets/css/ffc-frontend.css'] ) );
	}

	/**
	 * @return list<array{file: string, line: int, function: string, handle: ?string, asset: ?string, self_named: bool}>
	 */
	private function asset_calls(): array {
		return array_values( array_filter( AssetEnqueues::calls(), static fn ( array $c ): bool => null !== $c['asset'] ) );
	}

	/**
	 * Asset file => its handles, for the files that have more than one.
	 *
	 * @return array<string, list<string>>
	 */
	private function files_with_several_handles(): array {
		$handles = array();
		foreach ( $this->asset_calls() as $call ) {
			if ( null !== $call['handle'] ) {
				$handles[ (string) $call['asset'] ][ $call['handle'] ] = true;
			}
		}

		$several = array();
		foreach ( $handles as $asset => $set ) {
			if ( count( $set ) > 1 ) {
				$several[ $asset ] = array_keys( $set );
			}
		}

		return $several;
	}
}
