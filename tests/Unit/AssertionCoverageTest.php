<?php
/**
 * Vacuous-test ratchet (#997).
 *
 * A test that verifies nothing still runs, still counts toward coverage and
 * still shows green — it only fails to notice when the code breaks. The suite
 * has 108 such methods today, out of 7,285. Every one is the same shape: its
 * only assertion is a literal `assertTrue( true )`, with no mock expectation to
 * carry the meaning. `ActivatorMigrationsTest` is the clearest case — it calls
 * a migration and asserts `true`, so it proves the call did not fatal and
 * nothing else. (No method in the suite is assertion-free outright; a first
 * measurement suggesting otherwise was an artefact of a checker that could not
 * see `Functions\expect()`.)
 *
 * A sweep of all 108 was measured and deliberately NOT done: 94 sit in
 * admin/render tests, where "it rendered without fatal" is close to all a
 * mocked WordPress can honestly assert, and the yield does not justify the
 * churn. The 13 in schema/activation and security are worth fixing, and that is
 * tracked as stage 2 of #997.
 *
 * So this guard does not sweep — it stops the bleeding. The current 131 are
 * frozen in a baseline; a NEW vacuous test fails CI. Same ratchet shape as
 * `ModuleBoundaryTest`: an entry that disappears also fails, so fixing one is
 * locked in rather than silently re-earned.
 *
 * WHAT IT CANNOT SEE. It reasons about assertion *presence*, never strength. A
 * test asserting the wrong thing, or asserting against a mock that supplies the
 * very value under test, passes this guard — that class is only reachable by
 * reading the test, or by crossing the boundary it mocks. Presence is the cheap
 * floor, not the ceiling.
 *
 * Regenerate the baseline after an INTENTIONAL change:
 *   FFC_UPDATE_VACUOUS_BASELINE=1 vendor/bin/phpunit --filter AssertionCoverage
 * Review the diff — new entries mean a new test that verifies nothing (write a
 * real assertion instead); removed entries mean one was fixed (good, lock it
 * in).
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey — like the module
 * boundary, AJAX wiring, settings-default and activator-SQL guards. It reads
 * source text only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class AssertionCoverageTest extends TestCase {

	private const BASELINE_FILE = __DIR__ . '/../fixtures/vacuous-tests-baseline.php';

	/**
	 * Anything that makes a test method verify something.
	 *
	 * Covers PHPUnit's own assertions and expectations, Mockery's message
	 * expectations (including the negative `shouldNotReceive`, which is a real
	 * assertion) and Brain\Monkey's `Functions\expect()` family. A checker that
	 * knew only `$this->assert*` would report most of this suite as vacuous.
	 */
	private const EXPECTATION_PATTERNS = array(
		'shouldReceive',
		'shouldNotReceive',
		'shouldHaveReceived',
		'->expects(',
		'Functions\\expect(',
		'Actions\\expect',
		'Filters\\expect',
		'expectException',
		'expectOutput',
		'expectDeprecation',
		'->once()',
		'->never()',
		'->twice()',
		'->times(',
		'->atLeast(',
	);

	/**
	 * Absolute path to the repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The body of a method, from its opening brace to its matching close.
	 *
	 * Brace-balanced rather than "up to the next closing line": test bodies are
	 * full of closures and array literals, and a naive scan cuts at the first
	 * one — which reports methods as assertion-free when their assertions sit
	 * after a `Functions\when(…)->alias( function () { … } )` block.
	 *
	 * @param string $code   Full file source.
	 * @param int    $offset Offset of the method's opening brace.
	 */
	private static function method_body( string $code, int $offset ): string {
		$depth  = 0;
		$length = strlen( $code );

		for ( $i = $offset; $i < $length; $i++ ) {
			if ( '{' === $code[ $i ] ) {
				++$depth;
			} elseif ( '}' === $code[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $code, $offset, $i - $offset );
				}
			}
		}

		return substr( $code, $offset );
	}

	/**
	 * Every test method that verifies nothing, as `File.php::method`.
	 *
	 * @return list<string> Sorted.
	 */
	public static function vacuous_methods(): array {
		$out  = array();
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root() . '/tests', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iter as $file ) {
			$path = $file->getPathname();
			if ( substr( $path, -8 ) !== 'Test.php' ) {
				continue;
			}
			$code = (string) file_get_contents( $path );

			if ( ! preg_match_all( '/\n[ \t]+public function (test_[a-zA-Z0-9_]+)[^\n{]*\{/', $code, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[0] as $index => $match ) {
				$brace = (int) $match[1] + strlen( (string) $match[0] ) - 1;
				$body  = self::method_body( $code, $brace );

				$has_expectation = false;
				foreach ( self::EXPECTATION_PATTERNS as $needle ) {
					if ( strpos( $body, $needle ) !== false ) {
						$has_expectation = true;
						break;
					}
				}
				if ( $has_expectation ) {
					continue;
				}

				$assertions = preg_match_all( '/\$this->assert[A-Za-z]+\(/', $body );
				// A method that only ever asserts a literal `true` is asserting
				// nothing at all — the call reached the end without fatalling.
				$literal_true = preg_match_all( '/\$this->assertTrue\(\s*true\s*(?:,[^)]*)?\)/i', $body );

				if ( 0 === $assertions || $assertions === $literal_true ) {
					$out[] = basename( $path ) . '::' . $matches[1][ $index ][0];
				}
			}
		}

		sort( $out );
		return $out;
	}

	/**
	 * The guard's own input must be readable, or it proves nothing.
	 *
	 * A parser that stops matching would report zero vacuous methods and pass
	 * forever while the ratchet quietly did nothing.
	 */
	public function test_the_guard_can_see_the_suite(): void {
		$seen = 0;
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root() . '/tests', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iter as $file ) {
			$path = $file->getPathname();
			if ( substr( $path, -8 ) === 'Test.php' ) {
				$seen += preg_match_all(
					'/\n[ \t]+public function test_[a-zA-Z0-9_]+/',
					(string) file_get_contents( $path )
				);
			}
		}

		$this->assertGreaterThan(
			5000,
			$seen,
			'Found almost no test methods — the pattern probably stopped matching.'
		);
	}

	/**
	 * No test method may verify nothing unless it is already in the baseline.
	 */
	public function test_no_new_test_verifies_nothing(): void {
		$current = self::vacuous_methods();

		if ( getenv( 'FFC_UPDATE_VACUOUS_BASELINE' ) ) {
			$body = "<?php\n/**\n * Vacuous-test baseline — generated.\n"
				. " * Regenerate: FFC_UPDATE_VACUOUS_BASELINE=1 vendor/bin/phpunit --filter AssertionCoverage\n"
				. " * Each entry is a test method that currently verifies nothing (no assertion\n"
				. " * and no mock expectation, or only a literal assertTrue(true)) and is\n"
				. " * therefore tolerated. The guard fails on any method NOT listed here (a new\n"
				. " * test that proves nothing) and on any listed method that no longer\n"
				. " * qualifies (it was fixed — lock the win in).\n"
				. " *\n * This list is a debt register, not a target to grow.\n */\n\nreturn array(\n";
			foreach ( $current as $entry ) {
				$body .= "\t'" . $entry . "',\n";
			}
			$body .= ");\n";
			file_put_contents( self::BASELINE_FILE, $body );
			$this->markTestSkipped( 'Vacuous-test baseline regenerated (' . count( $current ) . ' entries).' );
		}

		$this->assertFileExists(
			self::BASELINE_FILE,
			'Run with FFC_UPDATE_VACUOUS_BASELINE=1 to generate the baseline.'
		);
		/** @var list<string> $baseline */
		$baseline = require self::BASELINE_FILE;
		sort( $baseline );

		$added = array_values( array_diff( $current, $baseline ) );
		$this->assertSame(
			array(),
			$added,
			"A new test method verifies nothing:\n  "
			. implode( "\n  ", $added )
			. "\n\nIt has no assertion and no mock expectation, or asserts only a literal"
			. "\ntrue — so it passes whether or not the code under test works. Assert the"
			. "\nbehaviour instead. If the call genuinely has no observable effect, assert"
			. "\nthat: a mock expectation, a returned value, a thrown exception."
		);

		$fixed = array_values( array_diff( $baseline, $current ) );
		$this->assertSame(
			array(),
			$fixed,
			"A baselined test now verifies something — lock the win in:\n  "
			. implode( "\n  ", $fixed )
			. "\n\nRegenerate: FFC_UPDATE_VACUOUS_BASELINE=1 vendor/bin/phpunit --filter AssertionCoverage"
		);
	}
}
