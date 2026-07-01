<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Submissions\SubmissionLifecycleService;

/**
 * Tests for SubmissionLifecycleService: trash/restore/delete/bulk lifecycle
 * operations extracted from SubmissionHandler.
 *
 * The service reads its repository through the owning handler's
 * get_repository() at call-time, so we mock the handler and hand it a mocked
 * SubmissionRepository. ActivityLog is alias-mocked so its class_exists() gate
 * is satisfied and its static log/disable_logging/enable_logging calls are
 * stubbed.
 *
 * @covers \FreeFormCertificate\Submissions\SubmissionLifecycleService
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SubmissionLifecycleServiceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface */
    private $handler;

    /** @var \Mockery\MockInterface */
    private $repository;

    /** @var SubmissionLifecycleService */
    private SubmissionLifecycleService $service;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        class_exists( '\\FreeFormCertificate\\Submissions\\SubmissionLifecycleService' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( 0 );

        // Mock $wpdb — used by the direct-query maintenance methods.
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix  = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( $q ) { return $q; } )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();

        // Repository behind the handler.
        $this->repository = Mockery::mock( '\\FreeFormCertificate\\Repositories\\SubmissionRepository' );

        $this->handler = Mockery::mock( '\\FreeFormCertificate\\Submissions\\SubmissionHandler' );
        $this->handler->shouldReceive( 'get_repository' )->andReturn( $this->repository )->byDefault();

        $this->service = new SubmissionLifecycleService( $this->handler );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * The SUT gates its logging on class_exists( ActivityLog ) and then calls
     * the real ActivityLog::log/disable_logging/enable_logging (which reference
     * ActivityLog::LEVEL_* constants). Rather than alias-mock ActivityLog —
     * which can't carry those constants — we let the real class load and make
     * ActivityLog::log() short-circuit to a no-op by stubbing SettingsReader so
     * activity_log_enabled() returns false. disable_logging/enable_logging are
     * pure static setters, safe to run for real.
     *
     * @return void
     */
    private function stubActivityLog(): void {
        $reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
        $reader->shouldReceive( 'activity_log_enabled' )->andReturn( false )->byDefault();
        $reader->shouldReceive( 'activity_log_min_level' )->andReturn( 'info' )->byDefault();
        $reader->shouldReceive( 'activity_log_category_enabled' )->andReturn( true )->byDefault();
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
        $this->stubActivityLog();
        $this->repository->shouldReceive( 'bulkUpdateStatus' )->once()->with( array( 1, 2 ), 'trash' )->andReturn( 2 );

        $this->assertSame( 2, $this->service->bulk_trash_submissions( array( 1, 2 ) ) );
    }

    // ==================================================================
    // bulk_restore_submissions()
    // ==================================================================

    public function test_bulk_restore_returns_zero_on_empty(): void {
        $this->assertSame( 0, $this->service->bulk_restore_submissions( array() ) );
    }

    public function test_bulk_restore_delegates_and_logs(): void {
        $this->stubActivityLog();
        $this->repository->shouldReceive( 'bulkUpdateStatus' )->once()->with( array( 3, 4 ), 'publish' )->andReturn( 2 );

        $this->assertSame( 2, $this->service->bulk_restore_submissions( array( 3, 4 ) ) );
    }

    // ==================================================================
    // move_submissions_between_forms()
    // ==================================================================

    public function test_move_between_forms_delegates_and_logs(): void {
        $this->stubActivityLog();
        $expected = array( 'moved' => array( 1, 2 ), 'conflicts' => array( 3 ) );
        $this->repository->shouldReceive( 'moveBetweenForms' )
            ->once()->with( 10, 20, array( 1, 2, 3 ) )->andReturn( $expected );

        $result = $this->service->move_submissions_between_forms( 10, 20, array( 1, 2, 3 ) );

        $this->assertSame( $expected, $result );
    }

    // ==================================================================
    // bulk_delete_submissions()
    // ==================================================================

    public function test_bulk_delete_returns_zero_on_empty(): void {
        $this->assertSame( 0, $this->service->bulk_delete_submissions( array() ) );
    }

    public function test_bulk_delete_delegates_and_logs(): void {
        $this->stubActivityLog();
        $this->repository->shouldReceive( 'bulkDelete' )->once()->with( array( 5, 6 ) )->andReturn( 2 );

        $this->assertSame( 2, $this->service->bulk_delete_submissions( array( 5, 6 ) ) );
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
        $this->stubActivityLog();
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

    public function test_run_data_cleanup_returns_zero_when_disabled(): void {
        Functions\when( 'get_option' )->justReturn( 0 );

        $this->assertSame( 0, $this->service->run_data_cleanup() );
    }

    public function test_run_data_cleanup_deletes_and_logs(): void {
        $this->stubActivityLog();
        Functions\when( 'get_option' )->justReturn( 30 );

        global $wpdb;
        $wpdb->shouldReceive( 'query' )->once()->andReturn( 8 );

        $result = $this->service->run_data_cleanup();

        $this->assertSame( 8, $result );
    }

    public function test_run_data_cleanup_query_false_returns_zero(): void {
        Functions\when( 'get_option' )->justReturn( 30 );

        global $wpdb;
        $wpdb->shouldReceive( 'query' )->once()->andReturn( false );

        $this->assertSame( 0, $this->service->run_data_cleanup() );
    }
}
