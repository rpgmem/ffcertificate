<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The part of `BatchedExportSourceInterface` that is the CONTRACT's, not any
 * one source's.
 *
 * WRITTEN ONCE BECAUSE WRITTEN SIX TIMES IT WAS WRITTEN THIRTY-ONE.
 *
 * Six behaviours below were byte-identical in every file that carried them --
 * the bodies match because each file builds its own `$this->source` in
 * `setUp()` and the assertion is about the interface, not the implementation.
 * Measured before the move: thirty-one copies across six files.
 *
 * AND FIVE OF THE THIRTY-SIX WERE NEVER WRITTEN AT ALL, which is the reason
 * this is a base class rather than a tidy-up. `authorize_download` was
 * unasserted on the user fence for the appointment and reregistration
 * sources; `cursor_of` for submissions; `job_owner_fields` for submissions
 * and the url shortener. Each of those four sources implements the behaviour
 * exactly as its siblings do -- they were coverage gaps, not defects -- but a
 * per-file convention only ever covers what somebody remembered to copy, and
 * the submissions source is the one that decrypts PII onto a temp file, so
 * its `user_id` fence is the last one anybody should be guessing about.
 *
 * Inheriting them closes that by construction: a seventh source gets all six
 * the moment it extends this.
 *
 * WHAT DOES NOT BELONG HERE. `build_context`, `count`, `filename`,
 * `format_row` and `fetch_page` differ per source by design -- the columns,
 * the filters and the query are exactly what a source owns -- so they stay in
 * their own files. This holds only what the engine can assume of every source
 * it drives.
 *
 * @coversNothing The subject is an interface's contract; each concrete test
 * keeps its own `@covers` on the source it builds, and the inherited methods
 * run under that class, so attribution stays per source.
 */
abstract class BatchedExportSourceContractTestCase extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The source under test, as its own file built it.
	 *
	 * Named apart from the `$source` property every concrete file already
	 * holds, so nothing here depends on a property name those files are free
	 * to change.
	 *
	 * @return object
	 */
	abstract protected function export_source();

	/**
	 * Make the two functions that END a request throw instead.
	 *
	 * `wp_send_json_error()` and `wp_die()` do not return in WordPress, so a
	 * refusal can only be observed as an exception here. The message is what
	 * says WHICH terminator ran, and the two are not interchangeable: the AJAX
	 * phases answer with JSON and the download phase answers with `wp_die`.
	 *
	 * `protected` rather than `private` because one source needs two more
	 * stubs (see `SubmissionsExportSourceTest`), and adding them here for
	 * everybody would teach Patchwork two functions in five processes that do
	 * not need them -- which `CLAUDE.md` records as breaking tests that run
	 * later and never touched the change.
	 */
	protected function stub_terminators(): void {
		Functions\when( 'wp_send_json_error' )->alias(
			static function () {
				throw new \RuntimeException( 'json_error' );
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
	}

	/**
	 * Starting a job needs the capability.
	 */
	public function test_authorize_start_rejects_without_capability(): void {
		$this->stub_terminators();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		// Capabilities::current_user_can_admin_or() delegates to current_user_can().
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->export_source()->authorize_start();
	}

	/**
	 * A batch belongs to the operator who started the job.
	 *
	 * The capability alone is not the fence: two operators may both hold it,
	 * and a job carries a temp file of somebody's rows.
	 */
	public function test_authorize_batch_rejects_on_user_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->export_source()->authorize_batch( array( 'user_id' => 99 ) );
	}

	/**
	 * The download phase verifies its own nonce.
	 */
	public function test_authorize_download_rejects_on_bad_nonce(): void {
		$this->stub_terminators();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->export_source()->authorize_download( array( 'user_id' => 1 ) );
	}

	/**
	 * And the download phase keeps the same fence the batch phase has.
	 */
	public function test_authorize_download_rejects_on_user_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->export_source()->authorize_download( array( 'user_id' => 99 ) );
	}

	/**
	 * The keyset cursor is the row id, and a row without one reads as zero.
	 *
	 * Zero is what makes the engine stop rather than loop: `fetch_page()` asks
	 * for rows below the cursor, so a cursor that never moved would fetch the
	 * same page forever.
	 */
	public function test_cursor_of_reads_id(): void {
		$this->assertSame( 4, $this->export_source()->cursor_of( array( 'id' => 4 ) ) );
		$this->assertSame( 0, $this->export_source()->cursor_of( array() ) );
	}

	/**
	 * The job records who owns it.
	 */
	public function test_job_owner_fields_returns_user_id(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		$this->assertSame( array( 'user_id' => 42 ), $this->export_source()->job_owner_fields() );
	}
}
