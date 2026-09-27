<?php
/**
 * Guard for the suite's execution-time budget.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\SuiteCpuBudget;
use PHPUnit\Framework\TestCase;

/**
 * The suite's budget is not product code's to set (#1493).
 *
 * ASSERTED WITHOUT DEPENDING ON TEST ORDER, which is the whole difficulty. The
 * obvious guard -- a test asserting `max_execution_time` is `0` -- is vacuous
 * when it runs before `BatchedCsvExportTest` and meaningful only when it runs
 * after, so it would pass or fail on alphabetical luck. This repository already
 * records what that costs: a `function_exists()` guard made tests
 * order-dependent and the symptom landed in a file the change never touched.
 *
 * So the hook is exercised DIRECTLY -- set a limit, run it, read the limit --
 * and its registration is asserted against `phpunit.xml.dist`. Between them,
 * neither deleting the class nor unregistering it can pass.
 *
 * @covers \FreeFormCertificate\Tests\Support\SuiteCpuBudget
 */
final class SuiteCpuBudgetTest extends TestCase {

	/**
	 * A limit a previous test left behind is gone before the next one runs.
	 *
	 * @return void
	 */
	public function test_it_removes_a_limit_a_previous_test_left(): void {
		// The state `BatchedCsvExportTest` leaves: 60, set by the real
		// `set_time_limit()` inside `handle_batch()`. Five is the same thing in
		// less time, and small enough that a suite which somehow inherited it
		// would die loudly rather than subtly.
		@set_time_limit( 5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Can be disabled by the host; the skip below is the answer.

		if ( '5' !== (string) ini_get( 'max_execution_time' ) ) {
			$this->markTestSkipped( 'This host does not let a script set its own execution-time limit, so there is nothing here to undo.' );
		}

		( new SuiteCpuBudget() )->executeBeforeTest( __METHOD__ );

		$this->assertSame(
			'0',
			(string) ini_get( 'max_execution_time' ),
			'A test must begin with no execution-time limit, whatever the test before it set.'
		);
	}

	/**
	 * The hook is registered, or the class above protects nothing.
	 *
	 * @return void
	 */
	public function test_the_hook_is_registered_with_phpunit(): void {
		$config = (string) file_get_contents( dirname( __DIR__, 2 ) . '/phpunit.xml.dist' );

		$this->assertStringContainsString(
			'FreeFormCertificate\\Tests\\Support\\SuiteCpuBudget',
			$config,
			'The hook must be registered in phpunit.xml.dist; a class PHPUnit never loads resets nothing.'
		);
	}
}
