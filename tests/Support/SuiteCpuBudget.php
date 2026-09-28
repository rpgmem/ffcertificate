<?php
/**
 * The suite's execution-time budget, kept out of product code's hands.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

use PHPUnit\Runner\BeforeTestHook;

/**
 * Every test begins with no execution-time limit (#1493).
 *
 * `Core\BatchedCsvExport` calls the real `set_time_limit()` twice, and both
 * calls are right in production, where each method is one HTTP request:
 * `handle_start()` removes the limit, `handle_batch()` caps the rest of the
 * request at sixty seconds. In the test process there is only ONE process for
 * the whole suite, nothing stubs the `Core` namespace's `set_time_limit`, and
 * `BatchedCsvExportTest` sits about a fifth of the way in -- so from there the
 * remaining seven thousand tests shared a sixty-second budget that a test had
 * set on the way past.
 *
 * MEASURED, because the mechanism is easy to describe wrongly. Running
 * `--filter 'BatchedCsvExport|…'` and reading `ini_get( 'max_execution_time' )`
 * afterwards reports `60`; running the probe alone reports `0`, which is the
 * CLI SAPI's own default. So the limit is real, it is inherited, and it lasts
 * for the rest of the process.
 *
 * WHAT THE BUDGET COUNTS IS NARROWER THAN CPU, and this is the part that
 * explains why the suite survived at all. The full suite burns about 953
 * seconds of CPU (`user` 14m23s plus `sys` 1m30s) against that sixty-second
 * limit, so PHP's own accounting is not the CPU a process manager reports: it
 * excludes time in system calls and stream operations, which is most of what a
 * test suite does. The margin was therefore unmeasured rather than large, and
 * one guard reading ~250 files through Brain\Monkey's Patchwork stream wrapper
 * was enough to spend it.
 *
 * THE FAILURE SHAPE IS WHY THIS IS A HOOK AND NOT A STUB. When the budget goes,
 * the run dies hundreds of tests later, in a file the change never touched,
 * with a message naming Patchwork's `Stream.php` -- cost, casualty and error in
 * three different places. Diagnosing it once took two full suite runs plus a
 * control.
 *
 * A HOOK RATHER THAN A CALL IN `tests/bootstrap.php`, WHICH WOULD NOT WORK.
 * `set_time_limit()` restarts the counter, so the LAST caller wins: a call at
 * bootstrap runs before `BatchedCsvExportTest` and is overwritten by it. The
 * reset has to happen between tests, which is what this is.
 *
 * BEFORE rather than after, because the invariant worth stating is about the
 * test that is starting: it begins with no cap, whatever the one before it did.
 * The test that legitimately drives `handle_batch()` still runs its own body
 * under the limit its subject set, which is correct -- it is exercising that
 * code.
 *
 * It does not stub, mock or otherwise lie to product code: the call happens,
 * for real, and is then undone for the next test.
 *
 * @since 6.31.0
 */
final class SuiteCpuBudget implements BeforeTestHook {

	/**
	 * Remove any execution-time limit a previous test left behind.
	 *
	 * @param string $test The test about to run, as PHPUnit names it.
	 * @return void
	 */
	public function executeBeforeTest( string $test ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- `set_time_limit` can be disabled by the host, and a suite that cannot raise its own limit is not a failure to report.
		@set_time_limit( 0 );
	}
}
