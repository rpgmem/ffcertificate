<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\SelfScheduling\AppointmentAdminActions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The error branches of the admin Confirm and Cancel (#2244). The happy paths
 * run through the real repositories in AppointmentsListViewTest.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentAdminActions
 */
class AppointmentAdminActionsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $appointments;

	/** @var Mockery\MockInterface */
	private $calendars;

	/** @var Mockery\MockInterface */
	private $notifier;

	private AppointmentAdminActions $actions;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );

		$this->appointments = Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' );
		$this->calendars    = Mockery::mock( 'FreeFormCertificate\Repositories\CalendarRepository' );
		$this->notifier     = Mockery::mock( 'FreeFormCertificate\SelfScheduling\AppointmentHandler' );
		$this->actions      = new AppointmentAdminActions( $this->appointments, $this->calendars, $this->notifier );
	}

	protected function tearDown(): void {
		\FreeFormCertificate\Tests\Support\ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private static function pending(): array {
		return array( 'id' => 5, 'calendar_id' => 7, 'status' => 'pending', 'appointment_date' => '2032-05-10', 'start_time' => '09:00:00' );
	}

	public function test_confirm_refuses_a_missing_row_and_a_missing_calendar(): void {
		$this->appointments->shouldReceive( 'findById' )->with( 4 )->andReturn( null );
		$this->appointments->shouldReceive( 'findById' )->with( 5 )->andReturn( self::pending() );
		$this->calendars->shouldReceive( 'findById' )->with( 7 )->andReturn( null );
		$this->appointments->shouldReceive( 'confirmPending' )->never();

		$this->assertSame( 'not_found', $this->actions->confirm( 4, 1 )->get_error_code() );
		$this->assertSame( 'calendar_not_found', $this->actions->confirm( 5, 1 )->get_error_code() );
	}

	public function test_confirm_refuses_a_custom_slot_that_no_longer_exists(): void {
		$this->appointments->shouldReceive( 'findById' )->andReturn( self::pending() );
		$this->calendars->shouldReceive( 'findById' )->andReturn( array( 'schedule_type' => 'custom', 'custom_slots' => '[]' ) );
		$this->appointments->shouldReceive( 'begin_transaction' )->once()->andReturn( true );
		$this->appointments->shouldReceive( 'rollback' )->once();
		$this->appointments->shouldReceive( 'isSlotAvailable' )->never();
		$this->appointments->shouldReceive( 'confirmPending' )->never();

		$this->assertSame( 'slot_full', $this->actions->confirm( 5, 1 )->get_error_code() );
	}

	public function test_confirm_rolls_back_on_a_database_exception(): void {
		$this->appointments->shouldReceive( 'findById' )->andReturn( self::pending() );
		$this->calendars->shouldReceive( 'findById' )->andReturn( array( 'max_appointments_per_slot' => 2 ) );
		$this->appointments->shouldReceive( 'begin_transaction' )->once()->andReturn( true );
		$this->appointments->shouldReceive( 'isSlotAvailable' )->with( 7, '2032-05-10', '09:00:00', 3, true, null )->andThrow( new \Exception( 'lock wait' ) );
		$this->appointments->shouldReceive( 'rollback' )->once();
		$this->notifier->shouldReceive( 'schedule_email_notifications' )->never();

		$this->assertSame( 'confirmation_failed', $this->actions->confirm( 5, 1 )->get_error_code() );
	}

	public function test_confirm_rolls_back_on_an_error(): void {
		$this->appointments->shouldReceive( 'findById' )->andReturn( self::pending() );
		$this->calendars->shouldReceive( 'findById' )->andReturn( array( 'max_appointments_per_slot' => 2 ) );
		$this->appointments->shouldReceive( 'begin_transaction' )->once()->andReturn( true );
		$this->appointments->shouldReceive( 'isSlotAvailable' )->andThrow( new \TypeError( 'bad row' ) );
		$this->appointments->shouldReceive( 'rollback' )->once();

		$this->assertSame( 'confirmation_failed', $this->actions->confirm( 5, 1 )->get_error_code() );
	}

	public function test_confirm_refuses_when_the_transaction_does_not_start(): void {
		$this->appointments->shouldReceive( 'findById' )->andReturn( self::pending() );
		$this->calendars->shouldReceive( 'findById' )->andReturn( array( 'max_appointments_per_slot' => 2 ) );
		$this->appointments->shouldReceive( 'begin_transaction' )->once()->andReturn( false );
		$this->appointments->shouldReceive( 'isSlotAvailable' )->never();
		$this->appointments->shouldReceive( 'confirmPending' )->never();

		$this->assertSame( 'confirmation_failed', $this->actions->confirm( 5, 1 )->get_error_code() );
	}

	public function test_confirm_notifies_with_the_confirmed_row(): void {
		$this->appointments->shouldReceive( 'findById' )->andReturn( self::pending() );
		$calendar = array( 'max_appointments_per_slot' => 1 );
		$this->calendars->shouldReceive( 'findById' )->andReturn( $calendar );
		$this->appointments->shouldReceive( 'begin_transaction' )->once()->andReturn( true );
		$this->appointments->shouldReceive( 'commit' )->once();
		$this->appointments->shouldReceive( 'isSlotAvailable' )->with( 7, '2032-05-10', '09:00:00', 2, true, null )->andReturn( true );
		$this->appointments->shouldReceive( 'confirmPending' )->with( 5, 9 )->andReturn( 1 );
		$this->notifier->shouldReceive( 'schedule_email_notifications' )->once()->with(
			Mockery::on( static fn( $row ) => 'confirmed' === $row['status'] && 9 === $row['approved_by'] ),
			$calendar,
			'confirmed'
		);

		$this->assertTrue( $this->actions->confirm( 5, 9 ) );
	}

	public function test_cancel_reports_a_failed_write_and_a_lost_race_without_notifying(): void {
		$this->appointments->shouldReceive( 'findById' )->andReturn( self::pending() );
		$this->appointments->shouldReceive( 'cancelFrom' )->with( 5, 'pending', 1, 'r' )->andReturn( false, 0 );
		$this->notifier->shouldReceive( 'schedule_email_notifications' )->never();
		$fired = 0;
		Functions\when( 'do_action' )->alias( static function () use ( &$fired ) {
			++$fired;
		} );

		$this->assertSame( 'cancellation_failed', $this->actions->cancel( 5, 1, 'r' )->get_error_code() );
		$this->assertSame( 'status_changed', $this->actions->cancel( 5, 1, 'r' )->get_error_code() );
		$this->assertSame( 0, $fired );
	}

	public function test_cancel_without_a_calendar_still_fires_the_shared_action(): void {
		$this->appointments->shouldReceive( 'findById' )->andReturn( self::pending() );
		$this->appointments->shouldReceive( 'cancelFrom' )->andReturn( 1 );
		$this->calendars->shouldReceive( 'findById' )->andReturn( null );
		$this->notifier->shouldReceive( 'schedule_email_notifications' )->never();
		$fired = array();
		Functions\when( 'do_action' )->alias( static function ( $hook ) use ( &$fired ) {
			$fired[] = $hook;
		} );

		$this->assertTrue( $this->actions->cancel( 5, 1, 'r' ) );
		$this->assertSame( array( 'ffcertificate_appointment_cancelled' ), $fired );
	}

	public function test_bulk_no_show_marks_only_confirmed_appointments_whose_date_has_come(): void {
		Functions\when( 'wp_date' )->justReturn( '2032-05-10' );
		$rows = array(
			1 => array( 'id' => 1, 'status' => 'confirmed', 'appointment_date' => '2032-05-10' ),
			2 => array( 'id' => 2, 'status' => 'confirmed', 'appointment_date' => '2032-05-11' ),
			3 => array( 'id' => 3, 'status' => 'pending', 'appointment_date' => '2032-05-01' ),
			4 => array( 'id' => 4, 'status' => 'confirmed', 'appointment_date' => '2032-05-02' ),
		);
		$this->appointments->shouldReceive( 'findById' )->andReturnUsing( static fn( $id ) => $rows[ $id ] ?? null );
		$this->appointments->shouldReceive( 'markNoShow' )->once()->with( 1 )->andReturn( 1 );
		$this->appointments->shouldReceive( 'markNoShow' )->once()->with( 4 )->andReturn( 0 );

		$result = $this->actions->mark_no_show_many( array( 1, 2, 3, 4, 4, 9, 0 ) );

		// 4 lost a race (0 rows), 9 is missing, 0 and the duplicate are dropped.
		$this->assertSame( array( 'marked' => 1, 'skipped' => 4 ), $result );
	}

	/**
	 * The real ActivityLog, on, writing to its buffer.
	 */
	private function log_on(): void {
		\FreeFormCertificate\Tests\Support\ActivityLogBuffer::reset();
		\FreeFormCertificate\Tests\Support\ActivityLogBuffer::stub_functions();
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'ffc_settings' === $key ? \FreeFormCertificate\Tests\Support\ActivityLogBuffer::ENABLED_SETTINGS : $default
		);
	}

	public function test_delete_permanently_takes_only_appointments_that_are_over_and_logs_once(): void {
		$rows = array(
			1 => array( 'id' => 1, 'calendar_id' => 7, 'status' => 'cancelled' ),
			2 => array( 'id' => 2, 'calendar_id' => 7, 'status' => 'completed' ),
			3 => array( 'id' => 3, 'calendar_id' => 8, 'status' => 'no_show' ),
			4 => array( 'id' => 4, 'calendar_id' => 7, 'status' => 'confirmed' ),
			5 => array( 'id' => 5, 'calendar_id' => 7, 'status' => 'pending' ),
		);
		$this->appointments->shouldReceive( 'findById' )->andReturnUsing( static fn( $id ) => $rows[ $id ] ?? null );
		$this->appointments->shouldReceive( 'deleteByIds' )->once()->with( array( 1, 2, 3 ) )->andReturn( 3 );
		$this->log_on();

		$result = $this->actions->delete_permanently( array( 1, 2, 3, 4, 5, 9, 3, 0 ), 42 );

		$this->assertSame( array( 'deleted' => 3, 'skipped' => 3 ), $result, 'Confirmed, pending and a missing row are skipped.' );
		$entries = \FreeFormCertificate\Tests\Support\ActivityLogBuffer::entries();
		$logged  = \FreeFormCertificate\Tests\Support\ActivityLogBuffer::contexts();
		$this->assertCount( 1, $logged );
		$this->assertSame( 'appointment_deleted', $logged[0]['action'] );
		$this->assertSame( 42, (int) $entries[0]['user_id'] );
		$this->assertSame( array( 1, 2, 3 ), $logged[0]['context']['appointment_ids'] );
		$this->assertSame( array( 7, 8 ), $logged[0]['context']['calendar_ids'] );
	}

	public function test_delete_permanently_deletes_and_logs_nothing_when_nothing_is_over(): void {
		$this->appointments->shouldReceive( 'findById' )->andReturn( array( 'id' => 4, 'status' => 'confirmed' ) );
		$this->appointments->shouldNotReceive( 'deleteByIds' );
		$this->log_on();

		$this->assertSame( array( 'deleted' => 0, 'skipped' => 1 ), $this->actions->delete_permanently( array( 4 ), 42 ) );
		$this->assertSame( array(), \FreeFormCertificate\Tests\Support\ActivityLogBuffer::entries() );
	}

	public function test_a_row_the_database_did_not_delete_counts_as_skipped(): void {
		$this->appointments->shouldReceive( 'findById' )->andReturn( array( 'id' => 1, 'status' => 'cancelled', 'calendar_id' => 7 ) );
		$this->appointments->shouldReceive( 'deleteByIds' )->andReturn( 0 );
		$this->log_on();

		$this->assertSame( array( 'deleted' => 0, 'skipped' => 1 ), $this->actions->delete_permanently( array( 1 ), 42 ) );
		$this->assertSame( array(), \FreeFormCertificate\Tests\Support\ActivityLogBuffer::entries() );
	}
}
