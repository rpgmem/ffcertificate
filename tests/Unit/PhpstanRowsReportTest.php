<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for `.github/scripts/phpstan-rows-report.php`, the level-9 report
 * narrowed to the classes that read rows out of `$wpdb` (#1060).
 *
 * The script is a gate, and the failure that matters most for a gate is
 * passing when it should not: an analysis that died, a report that never got
 * written, a JSON shape that changed under it. Read as "no errors" any of
 * those turns a broken measurement into a green check — which is the same
 * defect class the issue itself is about, one level up.
 *
 * It runs as a process rather than being required, because it is a procedural
 * CI script: requiring it would execute it.
 */
class PhpstanRowsReportTest extends TestCase {

	/**
	 * Absolute path to the script under test.
	 *
	 * @var string
	 */
	private string $script;

	/**
	 * Scratch files to remove afterwards.
	 *
	 * @var list<string>
	 */
	private array $temp = array();

	protected function setUp(): void {
		parent::setUp();
		$this->script = dirname( __DIR__, 2 ) . '/.github/scripts/phpstan-rows-report.php';
	}

	protected function tearDown(): void {
		foreach ( $this->temp as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp = array();
		parent::tearDown();
	}

	public function test_a_report_within_the_allowance_passes(): void {
		$report = $this->write_report( array( $this->a_row_reading_file() => 2 ) );

		$run = $this->run_script( $report, 5 );

		$this->assertSame( 0, $run['status'] );
		$this->assertStringContainsString( 'Level-9 errors in them: 2', $run['output'] );
		$this->assertStringContainsString( 'PASS', $run['output'] );
	}

	public function test_a_report_over_the_allowance_fails_and_names_the_file(): void {
		$file   = $this->a_row_reading_file();
		$report = $this->write_report( array( $file => 4 ) );

		$run = $this->run_script( $report, 1 );

		$this->assertSame( 1, $run['status'] );
		$this->assertStringContainsString( 'FAIL: 3 error(s) over the allowance of 1', $run['output'] );
		$this->assertStringContainsString( basename( $file ), $run['output'] );
	}

	public function test_errors_outside_the_row_reading_classes_are_not_counted(): void {
		// The whole repository is analysed at level 9 — 2948 errors when this
		// was written — and 92% of them have nothing to do with the database.
		// Counting those would make the number meaningless.
		$outsider = dirname( __DIR__, 2 ) . '/includes/class-ffc-loader.php';
		$report   = $this->write_report( array( $outsider => 40 ) );

		$run = $this->run_script( $report, 0 );

		$this->assertSame( 0, $run['status'] );
		$this->assertStringContainsString( 'Level-9 errors in them: 0', $run['output'] );
	}

	public function test_a_malformed_report_fails_loudly_instead_of_reading_as_zero(): void {
		// An analysis that died leaves a truncated or empty file behind. Read
		// as "no errors" that is a broken gate reporting success.
		$path = sys_get_temp_dir() . '/ffc-rows-' . uniqid() . '.json';
		file_put_contents( $path, 'not json at all' );
		$this->temp[] = $path;

		$run = $this->run_script( $path, 0 );

		$this->assertSame( 2, $run['status'] );
	}

	/**
	 * The blind spot #1087 found, frozen so it cannot come back.
	 *
	 * The discovery scan used to require the literal substring `$wpdb->`, so
	 * every repository binding wpdb as a property — `$this->wpdb->get_results()`
	 * — was never measured. Eight files, thirty level-9 errors, while the gate
	 * printed "0 (allowed: 0)" and "Every class that reads a row declares what
	 * the row holds". The count was honest; the denominator was not.
	 *
	 * This asserts the property-bound file is counted, against a real file from
	 * the tree rather than an invented path — an invented one would simply not
	 * be discovered and the test would pass vacuously.
	 */
	public function test_a_property_bound_wpdb_repository_is_counted(): void {
		$file   = $this->a_property_bound_row_reading_file();
		$report = $this->write_report( array( $file => 3 ) );

		$run = $this->run_script( $report, 0 );

		$this->assertSame( 1, $run['status'], 'A repository calling $this->wpdb->get_results() must be inside the scan.' );
		$this->assertStringContainsString( 'Level-9 errors in them: 3', $run['output'] );
		$this->assertStringContainsString( basename( $file ), $run['output'] );
	}

	/**
	 * `WP_User_Query::get_results()` must NOT drag a file into the scan.
	 *
	 * It is a core object with its own typed return, not a row read off one of
	 * the plugin's tables. A pattern loose enough to match every
	 * `->get_results` would count it and measure the wrong thing — so the
	 * receiver is named in the pattern, and this pins that choice.
	 */
	public function test_a_wp_user_query_result_is_not_mistaken_for_a_row_read(): void {
		$fixture = sys_get_temp_dir() . '/ffc-rows-user-query-' . uniqid() . '.php';
		file_put_contents(
			$fixture,
			"<?php\n\$user_query = new WP_User_Query( array() );\n\$rows = \$user_query->get_results();\n"
		);
		$this->temp[] = $fixture;

		$report = $this->write_report( array( $fixture => 9 ) );

		$run = $this->run_script( $report, 0 );

		$this->assertSame( 0, $run['status'] );
		$this->assertStringContainsString( 'Level-9 errors in them: 0', $run['output'] );
	}

	public function test_a_missing_report_fails_loudly(): void {
		$run = $this->run_script( sys_get_temp_dir() . '/ffc-rows-does-not-exist.json', 0 );

		$this->assertSame( 2, $run['status'] );
	}

	/**
	 * A real file from the tree that reads rows straight from `$wpdb`.
	 *
	 * Taken from the tree rather than invented: the script discovers its own
	 * file list by scanning `includes/`, so a made-up path would simply not
	 * be counted and every assertion here would pass vacuously.
	 *
	 * @return string
	 */
	private function a_row_reading_file(): string {
		$root  = dirname( __DIR__, 2 );
		$paths = glob( $root . '/includes/*/*.php' ) ?: array();

		foreach ( $paths as $path ) {
			$source = (string) file_get_contents( $path );

			if ( preg_match( '/\$wpdb->(get_row|get_results|get_col)\b/', $source ) ) {
				return $path;
			}
		}

		$this->fail( 'No class in includes/ reads rows from $wpdb — the fixture premise is gone.' );
	}

	/**
	 * A real file from the tree that reads rows through a wpdb PROPERTY.
	 *
	 * @return string
	 */
	private function a_property_bound_row_reading_file(): string {
		$root  = dirname( __DIR__, 2 );
		$paths = glob( $root . '/includes/*/*.php' ) ?: array();

		foreach ( $paths as $path ) {
			$source = (string) file_get_contents( $path );

			if ( preg_match( '/\$this->wpdb->(get_row|get_results|get_col)\b/', $source ) ) {
				return $path;
			}
		}

		$this->fail( 'No repository in includes/ binds wpdb as a property — the fixture premise is gone.' );
	}

	/**
	 * Write a PHPStan-shaped JSON report with N messages per file.
	 *
	 * @param array<string, int> $files Absolute path => message count.
	 * @return string Path to the report.
	 */
	private function write_report( array $files ): string {
		$payload = array( 'totals' => array( 'errors' => 0, 'file_errors' => 0 ), 'files' => array() );

		foreach ( $files as $path => $count ) {
			$messages = array();

			for ( $i = 0; $i < $count; $i++ ) {
				$messages[] = array( 'message' => 'Cannot cast mixed to int.', 'line' => $i + 1 );
			}

			$payload['files'][ $path ]            = array( 'errors' => $count, 'messages' => $messages );
			$payload['totals']['file_errors']    += $count;
		}

		$path = sys_get_temp_dir() . '/ffc-rows-' . uniqid() . '.json';
		file_put_contents( $path, (string) wp_json_encode_fallback( $payload ) );
		$this->temp[] = $path;

		return $path;
	}

	/**
	 * Run the script as a process.
	 *
	 * @param string $report     Path to the JSON report.
	 * @param int    $max_errors Allowance to pass through.
	 * @return array{status: int, output: string}
	 */
	private function run_script( string $report, int $max_errors ): array {
		$command = sprintf(
			'%s %s %s %d 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( $this->script ),
			escapeshellarg( $report ),
			$max_errors
		);

		$lines  = array();
		$status = 0;
		exec( $command, $lines, $status );

		return array( 'status' => $status, 'output' => implode( "\n", $lines ) );
	}
}

/**
 * `wp_json_encode()` is not available in this suite's bootstrap for every
 * test class, and the payload here is plain data.
 *
 * @param mixed $data Data to encode.
 * @return string
 */
function wp_json_encode_fallback( $data ): string {
	return (string) json_encode( $data );
}
