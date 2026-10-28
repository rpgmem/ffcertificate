<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Submissions\SubmissionLifecycleService;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;

/**
 * Tests for SubmissionLifecycleService: trash/restore/delete/bulk lifecycle
 * operations extracted from SubmissionHandler.
 *
 * The service reads its repository through the owning handler's
 * get_repository() at call-time, so we mock the handler and hand it a mocked
 * SubmissionRepository. The real SettingsReader reads `ffc_settings` from a
 * stubbed get_option(), and the real ActivityLog buffers what it logs, which
 * the tests read back through ActivityLogBuffer instead of replacing either
 * class.
 *
 * @covers \FreeFormCertificate\Submissions\SubmissionLifecycleService
 */
class SubmissionLifecycleServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $handler;

	/** @var \Mockery\MockInterface */
	private $repository;

	/** @var SubmissionLifecycleService */
	private SubmissionLifecycleService $service;

	/** @var array<string, mixed> What get_option( 'ffc_settings' ) answers. */
	private array $settings = ActivityLogBuffer::ENABLED_SETTINGS;

	/** @var mixed The `$wpdb` global before the test, restored in tearDown(). */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();

		Functions\when( '__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			fn( $key, $fallback = false ) => 'ffc_settings' === $key ? $this->settings : $fallback
		);

		// Mock $wpdb — used by the direct-query maintenance methods.
		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->prefix  = 'wp_';
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( $q ) { return $q; } )->byDefault();
		$GLOBALS['wpdb'] = $wpdb;

		// Repository behind the handler.
		$this->repository = Mockery::mock( '\\FreeFormCertificate\\Repositories\\SubmissionRepository' );

		$this->handler = Mockery::mock( '\\FreeFormCertificate\\Submissions\\SubmissionHandler' );
		$this->handler->shouldReceive( 'get_repository' )->andReturn( $this->repository )->byDefault();

		$this->service = new SubmissionLifecycleService( $this->handler );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The single activity-log entry a bulk operation buffered.
	 *
	 * @return array{action: string, level: string, context: mixed}
	 */
	private function onlyLogEntry(): array {
		$entries = ActivityLogBuffer::contexts();
		$this->assertCount( 1, $entries, 'A bulk operation logs exactly one entry, not one per row.' );
		return $entries[0];
	}

	/**
	 * Turn retention on with a 30-day window, in the stored settings the real
	 * SettingsReader reads.
	 */
	private function enableCleanup(): void {
		$this->settings['cleanup_enabled'] = 1;
		$this->settings['cleanup_days']    = 30;
	}

	// ==================================================================
	// trash_submission()
	// ==================================================================

	public function test_trash_submission_success_fires_action(): void {
		$this->repository->shouldReceive( 'updateStatus' )->once()->with( 5, 'trash' )->andReturn( 1 );

		$fired = false;
		Functions\when( 'do_action' )->alias( function ( $hook, $id = null ) use ( &$fired ) {
			if ( 'ffcertificate_submission_trashed' === $hook ) {
				$fired = true;
			}
		} );

		$this->assertTrue( $this->service->trash_submission( 5 ) );
		$this->assertTrue( $fired );
	}

	public function test_trash_submission_failure_returns_false(): void {
		$this->repository->shouldReceive( 'updateStatus' )->once()->with( 5, 'trash' )->andReturn( false );

		$this->assertFalse( $this->service->trash_submission( 5 ) );
	}

	// ==================================================================
	// restore_submission()
	// ==================================================================

	public function test_restore_submission_success(): void {
		$this->repository->shouldReceive( 'updateStatus' )->once()->with( 7, 'publish' )->andReturn( 1 );

		$this->assertTrue( $this->service->restore_submission( 7 ) );
	}

	public function test_restore_submission_failure(): void {
		$this->repository->shouldReceive( 'updateStatus' )->once()->with( 7, 'publish' )->andReturn( 0 );

		$this->assertFalse( $this->service->restore_submission( 7 ) );
	}

	// ==================================================================
	// delete_submission()
	// ==================================================================

	public function test_delete_submission_success(): void {
		$this->repository->shouldReceive( 'delete' )->once()->with( 9 )->andReturn( 1 );

		$this->assertTrue( $this->service->delete_submission( 9 ) );
	}

	public function test_delete_submission_failure(): void {
		$this->repository->shouldReceive( 'delete' )->once()->with( 9 )->andReturn( false );

		$this->assertFalse( $this->service->delete_submission( 9 ) );
	}

	// ==================================================================
	// bulk_trash_submissions()
	// ==================================================================

	public function test_bulk_trash_returns_zero_on_empty(): void {
		$this->assertSame( 0, $this->service->bulk_trash_submissions( array() ) );
	}

	public function test_bulk_trash_delegates_and_logs(): void {
		$this->repository->shouldReceive( 'bulkUpdateStatus' )->once()->with( array( 1, 2 ), 'trash' )->andReturn( 2 );

		$this->assertSame( 2, $this->service->bulk_trash_submissions( array( 1, 2 ) ) );

		$entry = $this->onlyLogEntry();
		$this->assertSame( 'bulk_trash', $entry['action'] );
		$this->assertSame( array( 'count' => 2 ), $entry['context'] );
	}

	// ==================================================================
	// bulk_restore_submissions()
	// ==================================================================

	public function test_bulk_restore_returns_zero_on_empty(): void {
		$this->assertSame( 0, $this->service->bulk_restore_submissions( array() ) );
	}

	public function test_bulk_restore_delegates_and_logs(): void {
		$this->repository->shouldReceive( 'bulkUpdateStatus' )->once()->with( array( 3, 4 ), 'publish' )->andReturn( 2 );

		$this->assertSame( 2, $this->service->bulk_restore_submissions( array( 3, 4 ) ) );

		$entry = $this->onlyLogEntry();
		$this->assertSame( 'bulk_restore', $entry['action'] );
		$this->assertSame( array( 'count' => 2 ), $entry['context'] );
	}

	// ==================================================================
	// move_submissions_between_forms()
	// ==================================================================

	public function test_move_between_forms_delegates_and_logs(): void {
		$expected = array( 'moved' => array( 1, 2 ), 'conflicts' => array( 3 ) );
		$this->repository->shouldReceive( 'moveBetweenForms' )
			->once()->with( 10, 20, array( 1, 2, 3 ) )->andReturn( $expected );

		$result = $this->service->move_submissions_between_forms( 10, 20, array( 1, 2, 3 ) );

		$this->assertSame( $expected, $result );

		$entry = $this->onlyLogEntry();
		$this->assertSame( 'submission_moved', $entry['action'] );
		$this->assertSame( 2, $entry['context']['moved_count'] );
		$this->assertSame( 1, $entry['context']['conflict_count'] );
	}

	// ==================================================================
	// bulk_delete_submissions()
	// ==================================================================

	public function test_bulk_delete_returns_zero_on_empty(): void {
		$this->assertSame( 0, $this->service->bulk_delete_submissions( array() ) );
	}

	public function test_bulk_delete_delegates_and_logs(): void {
		$this->repository->shouldReceive( 'bulkDelete' )->once()->with( array( 5, 6 ) )->andReturn( 2 );

		$this->assertSame( 2, $this->service->bulk_delete_submissions( array( 5, 6 ) ) );

		$entry = $this->onlyLogEntry();
		$this->assertSame( 'bulk_delete', $entry['action'] );
		$this->assertSame( 'warning', $entry['level'] );
		$this->assertSame( array( 'count' => 2 ), $entry['context'] );
	}

	// ==================================================================
	// delete_all_submissions()
	// ==================================================================

	public function test_delete_all_by_form_id_delegates_to_repository(): void {
		$this->repository->shouldReceive( 'deleteByFormId' )->once()->with( 3 )->andReturn( 4 );

		$result = $this->service->delete_all_submissions( 3, false );

		$this->assertSame( 4, $result );
	}

	public function test_delete_all_by_form_id_resets_auto_increment_when_empty(): void {
		$this->repository->shouldReceive( 'deleteByFormId' )->once()->with( 3 )->andReturn( 4 );

		global $wpdb;
		// COUNT(*) returns 0 => the ALTER TABLE AUTO_INCREMENT branch runs.
		$wpdb->shouldReceive( 'get_var' )->andReturn( 0 );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( true );

		$result = $this->service->delete_all_submissions( 3, true );

		$this->assertSame( 4, $result );
	}

	public function test_delete_all_all_forms_delete_path(): void {
		global $wpdb;
		// No form_id, no reset => DELETE FROM path.
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 12 );

		$result = $this->service->delete_all_submissions( null, false );

		$this->assertSame( 12, $result );
	}

	public function test_delete_all_all_forms_truncate_resets_counters(): void {
		global $wpdb;
		// TRUNCATE path (reset_auto_increment true, no form_id). TRUNCATE query
		// succeeds, then reset_migration_counters() runs its own DELETE query.
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );

		$result = $this->service->delete_all_submissions( null, true );

		// TRUNCATE returns 0 rows affected; false !== 0 so cast to int.
		$this->assertSame( 0, $result );
	}

	public function test_delete_all_returns_zero_when_query_false(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'query' )->andReturn( false );

		$result = $this->service->delete_all_submissions( null, false );

		$this->assertSame( 0, $result );
	}

	// ==================================================================
	// reset_submission_counter()
	// ==================================================================

	public function test_reset_submission_counter_empty_table(): void {
		global $wpdb;
		// MAX(id) null => next_id 1.
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( true );

		$this->assertTrue( $this->service->reset_submission_counter() );
	}

	public function test_reset_submission_counter_with_data(): void {
		global $wpdb;
		// MAX(id) 42 => next_id 43.
		$wpdb->shouldReceive( 'get_var' )->andReturn( '42' );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( true );

		$this->assertTrue( $this->service->reset_submission_counter() );
	}

	// ==================================================================
	// run_data_cleanup()
	// ==================================================================

	public function test_run_data_cleanup_returns_zero_when_toggle_off(): void {
		// The days are set, so only the toggle can stop the run.
		$this->settings['cleanup_days'] = 30;

		// No query expectation: a DELETE would fail the test on the strict double.
		$this->assertSame( 0, $this->service->run_data_cleanup() );
	}

	public function test_run_data_cleanup_returns_zero_when_days_below_one(): void {
		$this->settings['cleanup_enabled'] = 1;
		$this->settings['cleanup_days']    = 0;

		$this->assertSame( 0, $this->service->run_data_cleanup() );
	}

	public function test_run_data_cleanup_deletes_and_logs(): void {
		$this->enableCleanup();

		global $wpdb;
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 8 );

		$result = $this->service->run_data_cleanup();

		$this->assertSame( 8, $result );
		$entry = $this->onlyLogEntry();
		$this->assertSame( 'data_cleanup', $entry['action'] );
		$this->assertSame( 8, $entry['context']['deleted_count'] );
	}

	public function test_run_data_cleanup_query_false_returns_zero(): void {
		$this->enableCleanup();

		global $wpdb;
		$wpdb->shouldReceive( 'query' )->once()->andReturn( false );

		$this->assertSame( 0, $this->service->run_data_cleanup() );
	}

	/**
	 * The DELETE is bounded, and the bound is in the SQL (#1234).
	 *
	 * This runs on the daily cron, that is, inside a visitor's request. Without
	 * `LIMIT`, the day an administrator turns retention on in a mature install
	 * holds the table for as long as it takes to delete years of submissions --
	 * and that day is exactly when the backlog is largest, because the scan has
	 * never run before (#936).
	 */
	public function test_run_data_cleanup_bounds_each_delete(): void {
		$this->enableCleanup();

		$captured = '';
		global $wpdb;
		$wpdb->shouldReceive( 'query' )->once()->andReturnUsing(
			function ( $sql ) use ( &$captured ) {
				$captured = (string) $sql;
				return 8;
			}
		);

		$this->service->run_data_cleanup();

		$this->assertStringContainsString( 'LIMIT', $captured );
	}

	/**
	 * A FULL page continues; an incomplete one ends the run.
	 *
	 * It is the half a single-page test does not see: without the continuation, a
	 * run deletes 500 rows and stops, and the backlog takes an eternity of days
	 * to drain; without the stop, an already-clean table would cost twenty
	 * DELETEs a day to delete nothing.
	 */
	public function test_run_data_cleanup_continues_only_after_a_full_page(): void {
		$this->enableCleanup();

		global $wpdb;
		// Two full pages, then a partial one: three queries and it stops.
		$wpdb->shouldReceive( 'query' )->times( 3 )->andReturn( 500, 500, 120 );

		$this->assertSame( 1120, $this->service->run_data_cleanup() );
	}

	/**
	 * The cap ends the run even with backlog left over.
	 *
	 * Without it the loop would turn the unbounded `DELETE` back on, only in
	 * instalments -- and the visitor would pay the sum.
	 */
	public function test_run_data_cleanup_stops_at_the_chunk_cap(): void {
		$this->enableCleanup();

		global $wpdb;
		// Every page comes back FULL: only the cap ends the run.
		$wpdb->shouldReceive( 'query' )->times( 20 )->andReturn( 500 );

		$this->assertSame( 10000, $this->service->run_data_cleanup() );
	}
}
