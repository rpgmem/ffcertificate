<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * No test sets an expectation on a function its own setUp() already stubbed (#1699).
 *
 * With Brain\Monkey, `Functions\expect( 'x' )` does not take effect when
 * `setUp()` stubbed `x` with `Functions\when()`: the stub keeps answering
 * and the expectation is never consulted. So `expect( 'x' )->never()` passes
 * whether or not `x` runs. Measured when this guard was written: twelve
 * `never()` sites, and a direct call injected after each one left every test
 * green. `AssertionCoverageTest` counts an `expect()` as an assertion, so
 * those tests were vacuous without being reported as such.
 *
 * Two shapes are fine and stay out of the scan: a method that resets
 * Brain\Monkey (`Monkey\tearDown()`) before it sets the expectation, which
 * drops the stub, and a function setUp() never stubbed. Everything else
 * counts calls with `Tests\Support\CallLog` instead.
 *
 * It reads `setUp()` only. A stub installed by a helper that setUp() calls
 * is invisible to it, which is the gap to remember when a test looks
 * covered by this guard and is not.
 */
class ExpectAfterSetUpStubTest extends TestCase {

	/**
	 * Every `expect()` on a function the same file's setUp() stubbed, as
	 * "File.php:line function".
	 *
	 * @param string $source PHP source of one test file.
	 * @param string $name   File name used in the report.
	 * @return list<string>
	 */
	private static function findings( string $source, string $name ): array {
		if ( ! preg_match( '/function setUp\(\)[^{]*\{(.*?)\n\t\}/s', $source, $setup ) ) {
			return array();
		}
		preg_match_all( "/Functions\\\\when\(\s*'([^']+)'/", $setup[1], $stubbed );
		$stubbed = array_flip( $stubbed[1] );
		if ( array() === $stubbed ) {
			return array();
		}

		$found = array();
		preg_match_all( "/Functions\\\\expect\(\s*'([^']+)'/", $source, $expects, PREG_OFFSET_CAPTURE );
		foreach ( $expects[1] as $hit ) {
			if ( ! isset( $stubbed[ $hit[0] ] ) ) {
				continue;
			}
			// The method this expectation sits in, up to the expectation. A
			// method declaration, not the last `function` keyword: closures
			// passed to Mockery::on() sit between expectations.
			$before = substr( $source, 0, $hit[1] );
			$body   = '';
			if ( preg_match_all( '/\n\t(?:public|protected|private)(?: static)? function /', $before, $decl, PREG_OFFSET_CAPTURE ) ) {
				$body = substr( $before, (int) end( $decl[0] )[1] );
			}
			if ( str_contains( $body, 'Monkey\\tearDown()' ) ) {
				continue;
			}
			$found[] = $name . ':' . ( substr_count( $before, "\n" ) + 1 ) . ' ' . $hit[0];
		}
		return $found;
	}

	/**
	 * @return list<string>
	 */
	private static function test_files(): array {
		$files = glob( dirname( __DIR__ ) . '/Unit/*.php' );
		return false === $files ? array() : $files;
	}

	public function test_no_expectation_targets_a_function_setup_already_stubbed(): void {
		$found = array();
		foreach ( self::test_files() as $file ) {
			$found = array_merge( $found, self::findings( (string) file_get_contents( $file ), basename( $file ) ) );
		}

		$this->assertSame(
			array(),
			$found,
			"These expectations are never consulted, because setUp() already stubbed the function.\n"
			. "Count the calls with Tests\\Support\\CallLog::record() and assert the count after the act.\n"
			. implode( "\n", $found )
		);
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
	 * The detector itself: the shape it exists for is reported, the two
	 * legitimate shapes are not.
	 */
	public function test_the_detector_reports_the_shape_and_spares_the_two_safe_ones(): void {
		$source = <<<'PHP'
<?php
class ProbeTest {
	protected function setUp(): void {
		Functions\when( 'update_option' )->justReturn( true );
	}

	public function test_vacuous(): void {
		Functions\expect( 'update_option' )->never();
	}

	public function test_reset_first(): void {
		Monkey\tearDown();
		Monkey\setUp();
		Functions\expect( 'update_option' )->once();
	}

	public function test_not_stubbed(): void {
		Functions\expect( 'wp_mail' )->never();
	}
}
PHP;

		$this->assertSame( array( 'Probe.php:8 update_option' ), self::findings( $source, 'Probe.php' ) );
	}
}
