<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard: ffc-device-signals.js MUST disable thumbmarkjs's
 * default-on telemetry beacon before any fingerprint collection.
 *
 * thumbmarkjs ships with `logging: true` in its default options, which
 * fires a sampling POST to api.thumbmarkjs.com once per session. For an
 * LGPD-sensitive plugin this is unacceptable. Our JS bootstrap calls
 * `ThumbmarkJS.setOption('logging', false)` immediately after loading
 * the lib; if someone deletes that line in a refactor, this test catches
 * it before merge.
 *
 * This test lives in the unit suite because it has no WP/runtime
 * dependencies — it just reads the source file from disk.
 *
 * @since 6.3.1
 */
class DeviceSignalsLoggingOffTest extends TestCase {

	/**
	 * The exact substring we expect to find. Match the source verbatim
	 * so a benign whitespace change isn't enough to silently disable it.
	 */
	private const EXPECTED_CALL = "window.ThumbmarkJS.setOption( 'logging', false );";

	public function test_collector_disables_thumbmarkjs_logging(): void {
		$path     = dirname( __DIR__, 2 ) . '/assets/js/ffc-device-signals.js';
		$contents = file_get_contents( $path );

		$this->assertNotFalse( $contents, 'Could not read ffc-device-signals.js' );
		$this->assertStringContainsString(
			self::EXPECTED_CALL,
			$contents,
			'ffc-device-signals.js must call setOption(logging, false) to suppress thumbmarkjs telemetry. '
			. 'Removing this would let the third-party library beacon to api.thumbmarkjs.com.'
		);
	}

	public function test_collector_uses_get_fingerprint_data_not_combined_hash(): void {
		// getFingerprint() returns a single combined hash, useless for
		// our N-of-M algorithm. Only getFingerprintData() exposes per-
		// component values. Make sure the latter is what we call.
		$path     = dirname( __DIR__, 2 ) . '/assets/js/ffc-device-signals.js';
		$contents = file_get_contents( $path );

		$this->assertNotFalse( $contents );
		$this->assertStringContainsString( 'getFingerprintData()', $contents );
		// getFingerprint() (no Data) would also match the substring above,
		// so we additionally assert the bare combined-hash call is absent.
		$this->assertDoesNotMatchRegularExpression(
			'/\bgetFingerprint\s*\(/',
			preg_replace( '/getFingerprintData\s*\(/', 'getFingerprintData_(', (string) $contents ) ?? '',
			'ffc-device-signals.js must use getFingerprintData() exclusively, not getFingerprint().'
		);
	}

	public function test_vendored_thumbmarkjs_matches_pinned_version(): void {
		$root = dirname( __DIR__, 2 );

		// Read the pinned version from the plugin header constant so this test
		// tracks a version bump automatically (no hard-coded version to drift),
		// and so a bump that forgets to swap the vendored file is caught.
		$plugin = file_get_contents( $root . '/ffcertificate.php' );
		$this->assertNotFalse( $plugin, 'Could not read ffcertificate.php' );
		$this->assertSame(
			1,
			preg_match( "/define\(\s*'FFC_THUMBMARK_VERSION',\s*'([^']+)'/", (string) $plugin, $m ),
			'FFC_THUMBMARK_VERSION constant not found in ffcertificate.php'
		);
		$version = $m[1];

		$path = $root . "/libs/js/thumbmark-{$version}.umd.js";
		$this->assertFileExists(
			$path,
			"Vendored thumbmarkjs bundle for the pinned FFC_THUMBMARK_VERSION ({$version}) is missing. "
			. "Re-download from https://cdn.jsdelivr.net/npm/@thumbmarkjs/thumbmarkjs@{$version}/dist/thumbmark.umd.js"
		);
		$this->assertGreaterThan( 10000, (int) filesize( $path ), 'Vendored bundle looks truncated.' );

		// Exactly one bundle — a stale older version must not be left behind.
		$bundles = glob( $root . '/libs/js/thumbmark-*.umd.js' );
		$this->assertCount(
			1,
			(array) $bundles,
			'Exactly one vendored thumbmarkjs bundle expected — a stale version was left behind by a bump.'
		);
	}
}
