<?php
/**
 * Module-boundary guard (#563 / #591 B3).
 *
 * Captures the current cross-module dependency graph of `includes/` as a
 * committed baseline and fails when a NEW edge (sourceModule → targetModule)
 * appears — so cross-module coupling can only shrink, never grow, without a
 * deliberate, reviewed baseline update. This is the "enforce module boundaries"
 * step of B3: no per-module facade is required yet, but the graph is frozen so
 * new violations are caught in CI and the boundaries can be tightened
 * incrementally.
 *
 * A "module" is the first namespace segment after `FreeFormCertificate\`
 * (root-level classes are `Root`). An edge is recorded when a file declared in
 * module A references `FreeFormCertificate\B\...` with B !== A.
 *
 * Regenerate the baseline after an INTENTIONAL change:
 *   FFC_UPDATE_BOUNDARY_BASELINE=1 vendor/bin/phpunit --filter ModuleBoundary
 * Review the diff — new edges mean new coupling (justify it); removed edges
 * mean coupling was eliminated (good — lock it in).
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ModuleBoundaryTest extends TestCase {

	private const BASELINE_FILE = __DIR__ . '/../fixtures/module-boundary-baseline.php';

	/**
	 * Compute the current set of cross-module edges.
	 *
	 * @return array<string, true> Set keyed by "Source>Target".
	 */
	public static function compute_edges(): array {
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$edges = array();

		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iter as $file ) {
			$path = $file->getPathname();
			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}
			if ( strpos( $path, '/libraries/' ) !== false ) {
				continue;
			}
			$text = file_get_contents( $path );
			if ( false === $text ) {
				continue;
			}
			// Declared module = first namespace segment (Root when bare).
			if ( ! preg_match( '/namespace\s+FreeFormCertificate(?:\\\\([A-Za-z0-9_]+))?(?:\\\\[A-Za-z0-9_]+)*\s*;/', $text, $m ) ) {
				continue;
			}
			$src = empty( $m[1] ) ? 'Root' : $m[1];

			// Referenced modules = first segment after FreeFormCertificate\.
			if ( preg_match_all( '/FreeFormCertificate\\\\([A-Za-z0-9_]+)\\\\/', $text, $mm ) ) {
				foreach ( $mm[1] as $tgt ) {
					if ( $tgt === $src ) {
						continue;
					}
					$edges[ $src . '>' . $tgt ] = true;
				}
			}
		}

		ksort( $edges );
		return $edges;
	}

	/**
	 * The cross-module dependency graph must not grow new edges.
	 */
	public function test_no_new_cross_module_coupling(): void {
		$current = self::compute_edges();

		// Update mode: rewrite the baseline from the current graph.
		if ( getenv( 'FFC_UPDATE_BOUNDARY_BASELINE' ) ) {
			$keys = array_keys( $current );
			sort( $keys );
			$body  = "<?php\n/**\n * Module-boundary baseline (#563/#591 B3) — generated.\n"
				. " * Regenerate: FFC_UPDATE_BOUNDARY_BASELINE=1 vendor/bin/phpunit --filter ModuleBoundary\n"
				. " * Each entry is a \"SourceModule>TargetModule\" cross-module edge that\n"
				. " * currently exists and is therefore allowed. The guard fails on any edge\n"
				. " * NOT listed here (new coupling) or any listed edge that no longer exists\n"
				. " * (coupling removed — tighten the baseline).\n */\n\nreturn array(\n";
			foreach ( $keys as $k ) {
				$body .= "\t'" . $k . "',\n";
			}
			$body .= ");\n";
			file_put_contents( self::BASELINE_FILE, $body );
			$this->markTestSkipped( 'Boundary baseline regenerated (' . count( $keys ) . ' edges).' );
		}

		$this->assertFileExists( self::BASELINE_FILE, 'Run with FFC_UPDATE_BOUNDARY_BASELINE=1 to generate the baseline.' );
		/** @var list<string> $baseline_list */
		$baseline_list = require self::BASELINE_FILE;
		$baseline      = array_fill_keys( $baseline_list, true );

		$new_edges = array_values( array_diff( array_keys( $current ), array_keys( $baseline ) ) );
		$removed   = array_values( array_diff( array_keys( $baseline ), array_keys( $current ) ) );

		$this->assertSame(
			array(),
			$new_edges,
			"New cross-module coupling introduced (not in the B3 baseline):\n  "
			. implode( "\n  ", $new_edges )
			. "\nIf this coupling is intentional, regenerate the baseline:\n"
			. '  FFC_UPDATE_BOUNDARY_BASELINE=1 vendor/bin/phpunit --filter ModuleBoundary'
		);

		$this->assertSame(
			array(),
			$removed,
			"Cross-module coupling was removed — tighten the B3 baseline to lock it in:\n  "
			. implode( "\n  ", $removed )
			. "\nRegenerate: FFC_UPDATE_BOUNDARY_BASELINE=1 vendor/bin/phpunit --filter ModuleBoundary"
		);
	}
}
