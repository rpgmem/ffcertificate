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
 * The class-level runTestsInSeparateProcesses annotation starts a fresh PHP
 * process per test, which
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
 * The runClassInSeparateProcess annotation is no cheaper: under PHPUnit 9.6 it
 * starts one process per test, exactly like the other, so it is not the
 * alternative. It is refused outright, because this guard once read only the
 * other spelling and 31 classes isolated every test under it, unseen (#2181).
 * A single test that needs its own process carries the method-level
 * runInSeparateProcess annotation itself.
 *
 * This docblock names those annotations without their at-sign on purpose:
 * PHPUnit reads an annotation anywhere in a docblock, prose included, so
 * writing one here would isolate this very class.
 *
 * It sees presence, never need: a file that declares an `overload:` mock in
 * one of sixty tests passes, although the other fifty-nine do not need the
 * process. The files that isolate every test are therefore frozen in
 * tests/fixtures/class-isolation-baseline.php, a ratchet both ways: a new
 * file that isolates its whole class fails (isolate the tests that need it,
 * or argue it in the baseline), and one that stopped doing so fails until it
 * leaves the list.
 *
 * The single tests that keep their own process are frozen the same way, per
 * file, in tests/fixtures/method-isolation-baseline.php (#2179): a file that
 * isolates one more test fails, and one that isolates fewer fails until its
 * count is lowered. Each of those tests also writes its reason in its own
 * docblock, beside the annotation. Regenerate both registers after an
 * intentional change:
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

	private const METHOD_BASELINE_FILE = __DIR__ . '/../fixtures/method-isolation-baseline.php';

	/**
	 * Whether the class isolates every test, read the way PHPUnit reads it.
	 *
	 * PHPUnit takes an annotation from anywhere in the class docblock, prose
	 * included, so a sentence that merely names the annotation isolates the
	 * class too. That happened twice before this read it so (#2091).
	 *
	 * @param string $source PHP source of one test file.
	 */
	private static function isolates_every_test( string $source ): bool {
		$tokens = token_get_all( $source );
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_CLASS !== $token[0] ) {
				continue;
			}
			for ( $j = $i - 1; $j >= 0; $j-- ) {
				$prev = $tokens[ $j ];
				if ( is_array( $prev ) && in_array( $prev[0], array( T_WHITESPACE, T_FINAL, T_ABSTRACT ), true ) ) {
					continue;
				}
				return is_array( $prev ) && T_DOC_COMMENT === $prev[0]
					&& 1 === preg_match( '/@runTestsInSeparateProcesses\b/', $prev[1] );
			}
			return false;
		}
		return false;
	}

	/**
	 * Whether the source isolates every test without a reason it shows.
	 *
	 * @param string $source PHP source of one test file.
	 */
	private static function isolates_without_reason( string $source ): bool {
		if ( ! self::isolates_every_test( $source ) ) {
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
	 * process (a $wpdb double instead of an alias, or the method-level
	 * annotation on the few tests that need it) must leave it, so the gain is locked in.
	 */
	public function test_class_level_isolation_matches_the_baseline(): void {
		$current      = array();
		$method_level = 0;
		foreach ( self::test_files() as $file ) {
			$source = (string) file_get_contents( $file );
			if ( self::isolates_every_test( $source ) ) {
				$current[] = basename( $file );
			}
			if ( 1 === preg_match( '/@runInSeparateProcess\b/', $source ) ) {
				++$method_level;
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

		// The register is empty since #2174, so an empty scan is the expected
		// answer and cannot prove the scan read anything. The same read must
		// find every method-level annotation an independent traversal finds.
		$recount = 0;
		foreach ( (array) scandir( dirname( __DIR__ ) . '/Unit' ) as $entry ) {
			if ( is_string( $entry ) && str_ends_with( $entry, '.php' )
				&& 1 === preg_match( '/@runInSeparateProcess(?![A-Za-z])/', (string) file_get_contents( dirname( __DIR__ ) . '/Unit/' . $entry ) ) ) {
				++$recount;
			}
		}
		$this->assertGreaterThan( 0, $recount, 'The recount found no method-level isolation; it read nothing.' );
		$this->assertSame( $recount, $method_level, 'The scan and the recount disagree about what the test files contain.' );
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
	 * The docblocks of the test methods that run in their own process.
	 *
	 * Read from the tokens, as PHPUnit reads it: the doc comment directly
	 * before a function, modifiers aside. A string that merely contains the
	 * annotation, like the canaries in this file, is not a doc comment.
	 *
	 * @param string $source PHP source of one test file.
	 * @return list<string>
	 */
	private static function isolated_method_docblocks( string $source ): array {
		$found  = array();
		$tokens = token_get_all( $source );
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_DOC_COMMENT !== $token[0]
				|| 1 !== preg_match( '/@runInSeparateProcess(?![A-Za-z])/', $token[1] ) ) {
				continue;
			}
			$count = count( $tokens );
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$next = $tokens[ $j ];
				if ( is_array( $next ) && in_array( $next[0], array( T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT ), true ) ) {
					continue;
				}
				if ( is_array( $next ) && T_FUNCTION === $next[0] ) {
					$found[] = $token[1];
				}
				break;
			}
		}
		return $found;
	}

	/**
	 * Whether a docblock says anything besides its annotations.
	 *
	 * @param string $docblock One doc comment.
	 */
	private static function carries_a_reason( string $docblock ): bool {
		foreach ( explode( "\n", $docblock ) as $line ) {
			$text = trim( $line, " \t/*" );
			if ( '' !== $text && '@' !== $text[0] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The single tests that run in their own process match the frozen count per file.
	 *
	 * Why it exists: the class-level register reached zero, and the 564 tests
	 * that still isolated one method at a time were moved to the shared
	 * process in waves until only those with a reason the process cannot undo
	 * were left (#2179). Nothing counted them, so the debt could grow back
	 * one annotation at a time. The count per file only shrinks: a file that
	 * gains one fails, and a file that loses one fails until the count is
	 * lowered, so the gain is locked in.
	 */
	public function test_method_level_isolation_matches_the_baseline(): void {
		$current = array();
		$total   = 0;
		foreach ( self::test_files() as $file ) {
			$count = count( self::isolated_method_docblocks( (string) file_get_contents( $file ) ) );
			if ( $count > 0 ) {
				$current[ basename( $file ) ] = $count;
				$total                       += $count;
			}
		}
		ksort( $current );

		if ( getenv( 'FFC_UPDATE_ISOLATION_BASELINE' ) ) {
			$body = "<?php\n/**\n * Method-level isolation baseline — generated (#2179).\n"
				. " * Regenerate: FFC_UPDATE_ISOLATION_BASELINE=1 vendor/bin/phpunit --filter ProcessIsolationScope\n"
				. " * Each entry is a test file and the number of its tests that run in their\n"
				. " * own process, each with the reason written in its docblock.\n"
				. " * A count that grows fails; a count that shrinks fails until it is lowered.\n"
				. " *\n * This list is a debt register, not a target to grow.\n */\n\nreturn array(\n";
			foreach ( $current as $entry => $count ) {
				$body .= "\t'" . $entry . "' => " . $count . ",\n";
			}
			$body .= ");\n";
			file_put_contents( self::METHOD_BASELINE_FILE, $body );
			$this->markTestSkipped( 'Method-level isolation baseline regenerated (' . $total . ' tests).' );
		}

		// An independent recount: annotation lines inside comments, read with
		// a line-anchored pattern that a one-line string literal cannot match.
		$recount = 0;
		foreach ( (array) scandir( dirname( __DIR__ ) . '/Unit' ) as $entry ) {
			if ( is_string( $entry ) && str_ends_with( $entry, '.php' ) ) {
				$recount += (int) preg_match_all(
					'/^\s*\*\s*@runInSeparateProcess(?![A-Za-z])/m',
					(string) file_get_contents( dirname( __DIR__ ) . '/Unit/' . $entry )
				);
			}
		}
		$this->assertGreaterThan( 0, $recount, 'The recount found no method-level isolation; it read nothing.' );
		$this->assertSame( $recount, $total, 'The scan and the recount disagree about how many tests isolate themselves.' );

		/** @var array<string, int> $baseline */
		$baseline = require self::METHOD_BASELINE_FILE;
		ksort( $baseline );

		$this->assertSame(
			$baseline,
			$current,
			"The tests that run in their own process changed. A new one moves to the shared process,\n"
			. "or keeps the annotation with its reason and the baseline is regenerated; a count that\n"
			. "dropped is regenerated to lock the gain in:\n"
			. "  FFC_UPDATE_ISOLATION_BASELINE=1 vendor/bin/phpunit --filter ProcessIsolationScope"
		);
	}

	/**
	 * Every test that runs in its own process says why, beside the annotation.
	 */
	public function test_every_isolated_test_states_its_reason(): void {
		$silent = array();
		foreach ( self::test_files() as $file ) {
			foreach ( self::isolated_method_docblocks( (string) file_get_contents( $file ) ) as $docblock ) {
				if ( ! self::carries_a_reason( $docblock ) ) {
					$silent[] = basename( $file );
				}
			}
		}

		$this->assertSame(
			array(),
			array_values( array_unique( $silent ) ),
			'These files isolate a test whose docblock gives no reason; write why it needs its own process.'
		);
	}

	/**
	 * No test file uses the class-level spelling this guard does not count.
	 *
	 * PHPUnit 9.6 runs every test of such a class in its own process, the
	 * same cost as the counted annotation, so a class using it would carry
	 * the debt off the register (#2181). PHPUnit reads the annotation from
	 * prose too, so the at-sign form is refused anywhere in the file.
	 */
	public function test_no_class_uses_the_uncounted_class_annotation(): void {
		$found = array();
		foreach ( self::test_files() as $file ) {
			if ( self::uses_uncounted_class_annotation( (string) file_get_contents( $file ) ) ) {
				$found[] = basename( $file );
			}
		}

		$this->assertSame(
			array(),
			$found,
			"These files name the class-level runClassInSeparateProcess annotation with its at-sign.\n"
			. "It isolates every test like @runTestsInSeparateProcesses, which the baseline counts; use that one:\n  "
			. implode( "\n  ", $found )
		);
	}

	/**
	 * Whether the source carries the class-level spelling the register does not count.
	 *
	 * @param string $source PHP source of one test file.
	 */
	private static function uses_uncounted_class_annotation( string $source ): bool {
		return 1 === preg_match( '/@' . 'runClassInSeparateProcess(?![A-Za-z])/', $source );
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
		$this->assertTrue( self::uses_uncounted_class_annotation( "<?php\n/**\n * @" . "runClassInSeparateProcess\n */\nclass ProbeTest {}\n" ) );
		$this->assertFalse( self::uses_uncounted_class_annotation( $bare ) );
		$this->assertFalse( self::uses_uncounted_class_annotation( "<?php\n/**\n * The runClassInSeparateProcess annotation, named without its at-sign.\n */\nclass ProbeTest {}\n" ) );
		$this->assertFalse( self::isolates_without_reason( "<?php\nclass ProbeTest {\n\t/**\n\t * @runInSeparateProcess\n\t */\n\tpublic function test_x() {}\n}\n" ) );
		$this->assertSame( 1, count( self::isolated_method_docblocks( "<?php\nclass ProbeTest {\n\t/**\n\t * Defines a constant.\n\t * @runInSeparateProcess\n\t */\n\tpublic static function test_x() {}\n}\n" ) ) );
		$this->assertSame( array(), self::isolated_method_docblocks( "<?php\n\$probe = \"/**\\n * @runInSeparateProcess\\n */\";\n" ) );
		$this->assertTrue( self::carries_a_reason( "/**\n * Defines a constant.\n * @runInSeparateProcess\n */" ) );
		$this->assertFalse( self::carries_a_reason( "/**\n * @runInSeparateProcess\n * @preserveGlobalState disabled\n */" ) );
		$this->assertTrue(
			self::isolates_without_reason( "<?php\n/**\n * Avoids an alias mock, which would force `@runTestsInSeparateProcesses`.\n */\nclass ProbeTest {}\n" ),
			'A prose mention in the class docblock isolates the class too, and must be read as such.'
		);
	}
}
