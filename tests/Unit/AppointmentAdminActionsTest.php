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
		$this->appointments->shouldReceive( 'begin_transaction' )->once();
		$this->appointments->shouldReceive( 'rollback' )->once();
		$this->appointments->shouldReceive( 'isSlotAvailable' )->never();
		$this->appointments->shouldReceive( 'confirmPending' )->never();

		$this->assertSame( 'slot_full', $this->actions->confirm( 5, 1 )->get_error_code() );
	}

	public function test_confirm_rolls_back_on_a_database_exception(): void {
		$this->appointments->shouldReceive( 'findById' )->andReturn( self::pending() );
		$this->calendars->shouldReceive( 'findById' )->andReturn( array( 'max_appointments_per_slot' => 2 ) );
		$this->appointments->shouldReceive( 'begin_transaction' )->once();
		$this->appointments->shouldReceive( 'isSlotAvailable' )->with( 7, '2032-05-10', '09:00:00', 3, true )->andThrow( new \Exception( 'lock wait' ) );
		$this->appointments->shouldReceive( 'rollback' )->once();
		$this->notifier->shouldReceive( 'schedule_email_notifications' )->never();

		$this->assertSame( 'confirmation_failed', $this->actions->confirm( 5, 1 )->get_error_code() );
	}

	public function test_confirm_notifies_with_the_confirmed_row(): void {
		$this->appointments->shouldReceive( 'findById' )->andReturn( self::pending() );
		$calendar = array( 'max_appointments_per_slot' => 1 );
		$this->calendars->shouldReceive( 'findById' )->andReturn( $calendar );
		$this->appointments->shouldReceive( 'begin_transaction', 'commit' )->once();
		$this->appointments->shouldReceive( 'isSlotAvailable' )->with( 7, '2032-05-10', '09:00:00', 2, true )->andReturn( true );
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
}
