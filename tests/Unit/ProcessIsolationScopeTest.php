<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A class runs every test in its own process only for a reason it carries (#2091).
 *
 * The purpose is to keep the number of tests that pay for their own process
 * as small as the tests themselves allow, and never let it grow unnoticed:
 * the cost below is paid on every CI run and every local full run.
 *
 * `@runTestsInSeparateProcesses` starts a fresh PHP process per test, which
 * cost about a quarter of a second each when this guard was written: 3,363
 * tests ran that way, and three files measured with and without it took
 * 10.9 s against 0.26 s, 5.2 s against 0.13 s and 3.9 s against 0.37 s.
 * The annotation is needed when a test does something the process cannot
 * undo: declares a Mockery `overload:` or `alias:` mock, whose real class
 * must not be loaded yet; `define()`s a constant; stubs a namespaced function
 * (`Functions\when( 'Ns\fn' )`), which then shadows the global one for
 * every later caller in that namespace; or declares a class through
 * `class_alias()` or `eval()`. A file with none of those runs in the shared
 * process unless `ALLOWED` names it with the reason the source cannot show.
 *
 * Taking the annotation off fourteen such files measured the reasons the
 * source does not show: three of them broke 53 tests in later files. Two
 * stub a namespaced function, which this guard now reads; the third stubbed
 * a function the code under test guards with function_exists(), and was
 * fixed by having every test that reaches it stub it too.
 *
 * `@runClassInSeparateProcess` is no cheaper: under PHPUnit 9.6 it measured
 * the same as one process per test, so it is not the alternative. A single
 * test that needs its own process carries `@runInSeparateProcess` itself.
 *
 * It sees presence, never need: a file that declares an `overload:` mock in
 * one of sixty tests passes, although the other fifty-nine do not need the
 * process. The files that isolate every test are therefore frozen in
 * tests/fixtures/class-isolation-baseline.php, a ratchet both ways: a new
 * file that isolates its whole class fails (isolate the tests that need it,
 * or argue it in the baseline), and one that stopped doing so fails until it
 * leaves the list. Regenerate after an intentional change:
 *   FFC_UPDATE_ISOLATION_BASELINE=1 vendor/bin/phpunit --filter ProcessIsolationScope
 */
class ProcessIsolationScopeTest extends TestCase {

	/**
	 * Files that isolate every test for a reason no token in them shows.
	 *
	 * Empty since RecruitmentAdminPageRendererTest moved to the real classes
	 * and its stand-in fixture was deleted (#2091). An entry added here is a
	 * reason to defend, not a line to add.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array();

	private const BASELINE_FILE = __DIR__ . '/../fixtures/class-isolation-baseline.php';

	/**
	 * Whether the source isolates every test without a reason it shows.
	 *
	 * @param string $source PHP source of one test file.
	 */
	private static function isolates_without_reason( string $source ): bool {
		if ( ! preg_match( '/^ \* @runTestsInSeparateProcesses$/m', $source ) ) {
			return false;
		}
		$reasons = array(
			'/[\'"](?:overload|alias):/',
			'/\bdefine\s*\(/',
			'/Functions\\\\(?:when|expect)\(\s*\'\w+\\\\/',
			'/\b(?:class_alias|eval)\s*\(/',
		);
		foreach ( $reasons as $reason ) {
			if ( preg_match( $reason, $source ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return list<string>
	 */
	private static function test_files(): array {
		$files = glob( dirname( __DIR__ ) . '/Unit/*.php' );
		return false === $files ? array() : $files;
	}

	public function test_no_class_isolates_every_test_without_a_reason(): void {
		$found = array();
		foreach ( self::test_files() as $file ) {
			$name = basename( $file );
			if ( ! isset( self::ALLOWED[ $name ] ) && self::isolates_without_reason( (string) file_get_contents( $file ) ) ) {
				$found[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$found,
			"These classes run every test in its own process with no overload:, alias: or define() in them.\n"
			. "Drop the annotation, isolate the one test that needs it with @runInSeparateProcess,\n"
			. "or name the file in ALLOWED with the reason:\n  " . implode( "\n  ", $found )
		);
	}

	/**
	 * The files that isolate every test match the frozen register exactly.
	 *
	 * Why it exists: each listed class pays a fresh PHP process for every one
	 * of its tests, almost always because setUp() declares an alias: mock. The
	 * register is that debt, and the ratchet only lets it shrink — a new class
	 * cannot join it unnoticed, and a class that moved its tests to the shared
	 * process (a $wpdb double instead of an alias, or @runInSeparateProcess on
	 * the few tests that need it) must leave it, so the gain is locked in.
	 */
	public function test_class_level_isolation_matches_the_baseline(): void {
		$current = array();
		foreach ( self::test_files() as $file ) {
			if ( preg_match( '/^ \* @runTestsInSeparateProcesses$/m', (string) file_get_contents( $file ) ) ) {
				$current[] = basename( $file );
			}
		}
		sort( $current );

		if ( getenv( 'FFC_UPDATE_ISOLATION_BASELINE' ) ) {
			$body = "<?php\n/**\n * Class-level isolation baseline — generated (#2091).\n"
				. " * Regenerate: FFC_UPDATE_ISOLATION_BASELINE=1 vendor/bin/phpunit --filter ProcessIsolationScope\n"
				. " * Each entry is a test file that runs every test in its own process,\n"
				. " * almost always because setUp() declares an alias: or overload: mock.\n"
				. " * The purpose is to reduce the tests that pay for their own process:\n"
				. " * this list may only shrink.\n"
				. " * A file not listed here fails; a listed file that stopped isolating\n"
				. " * fails until it is removed.\n"
				. " *\n * This list is a debt register, not a target to grow.\n */\n\nreturn array(\n";
			foreach ( $current as $entry ) {
				$body .= "\t'" . $entry . "',\n";
			}
			$body .= ");\n";
			file_put_contents( self::BASELINE_FILE, $body );
			$this->markTestSkipped( 'Isolation baseline regenerated (' . count( $current ) . ' entries).' );
		}

		/** @var list<string> $baseline */
		$baseline = require self::BASELINE_FILE;

		$this->assertNotEmpty( $current, 'The scan found no isolated class; it read nothing.' );
		$this->assertSame(
			array(),
			array_values( array_diff( $current, $baseline ) ),
			"These classes newly run every test in its own process. Isolate only the tests that need it\n"
			. "with @runInSeparateProcess, or add the file to the baseline with the reason in its docblock."
		);
		$this->assertSame(
			array(),
			array_values( array_diff( $baseline, $current ) ),
			'These classes no longer isolate every test; remove them from the baseline to lock the win in.'
		);
	}

	/**
	 * An entry whose file stopped isolating, or now shows its reason, is stale.
	 */
	public function test_every_allowed_entry_still_needs_its_place(): void {
		$stale = array();
		foreach ( array_keys( self::ALLOWED ) as $name ) {
			$path = dirname( __DIR__ ) . '/Unit/' . $name;
			if ( ! is_file( $path ) || ! self::isolates_without_reason( (string) file_get_contents( $path ) ) ) {
				$stale[] = $name;
			}
		}

		$this->assertSame( array(), $stale, 'Remove these from ALLOWED: ' . implode( ', ', $stale ) );
	}

	/**
	 * The scan must read the whole directory: a glob that came back short
	 * would read as clean. Recounted by a different traversal.
	 */
	public function test_the_scan_reads_every_test_file(): void {
		$recount = 0;
		foreach ( (array) scandir( dirname( __DIR__ ) . '/Unit' ) as $entry ) {
			if ( is_string( $entry ) && str_ends_with( $entry, '.php' ) ) {
				++$recount;
			}
		}

		$this->assertGreaterThan( 0, $recount );
		$this->assertCount( $recount, self::test_files() );
	}

	/**
	 * The detector: isolation with no reason is reported; each reason, and a
	 * method-level annotation, is not.
	 */
	public function test_the_detector_reports_only_isolation_without_a_reason(): void {
		$bare = "<?php\n/**\n * @runTestsInSeparateProcesses\n * @preserveGlobalState disabled\n */\nclass ProbeTest {}\n";

		$this->assertTrue( self::isolates_without_reason( $bare ) );
		$this->assertFalse( self::isolates_without_reason( $bare . "Mockery::mock( 'overload:Foo' );\n" ) );
		$this->assertFalse( self::isolates_without_reason( $bare . "Mockery::mock( 'alias:Foo' );\n" ) );
		$this->assertFalse( self::isolates_without_reason( $bare . "define( 'FOO', 1 );\n" ) );
		$this->assertFalse( self::isolates_without_reason( $bare . "Functions\\when( 'Ns\\fn' )->justReturn( 1 );\n" ) );
		$this->assertFalse( self::isolates_without_reason( $bare . "class_alias( 'A', 'B' );\n" ) );
		$this->assertFalse( self::isolates_without_reason( $bare . "eval( 'class A {}' );\n" ) );
		$this->assertTrue( self::isolates_without_reason( $bare . "Functions\\when( 'fn' )->justReturn( 1 );\n" ) );
		$this->assertFalse( self::isolates_without_reason( "<?php\nclass ProbeTest {\n\t/**\n\t * @runInSeparateProcess\n\t */\n\tpublic function test_x() {}\n}\n" ) );
	}
}
