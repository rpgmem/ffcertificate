<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentReminderScanner;

/**
 * Tests for the appointment-reminder cron scanner (#650): the missing driver
 * that fires the reminder email for due appointments.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentReminderScanner
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AppointmentReminderScannerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_emails_disabled( bool $disabled ): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'emails_disabled' )->andReturn( $disabled );
	}

	/** @param array<int, array<string, mixed>> $calendars */
	private function stub_calendars( array $calendars ): void {
		Mockery::mock( 'overload:\FreeFormCertificate\Repositories\CalendarRepository' )
			->shouldReceive( 'getActiveCalendars' )->andReturn( $calendars )->byDefault();
	}

	private static function cfg( int $send, int $hours ): string {
		return (string) json_encode( array( 'send_reminder' => $send, 'reminder_hours_before' => $hours ) );
	}

	public function test_skips_entirely_when_emails_globally_disabled(): void {
		$this->stub_emails_disabled( true );
		$cal = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\CalendarRepository' );
		$cal->shouldNotReceive( 'getActiveCalendars' );

		AppointmentReminderScanner::run();

		$this->assertTrue( true ); // reached here without querying calendars
	}

	public function test_reminds_due_appointment_and_marks_sent(): void {
		$this->stub_emails_disabled( false );
		$this->stub_calendars(
			array( array( 'id' => 5, 'email_config' => self::cfg( 1, 24 ) ) )
		);

		$appointment = array(
			'id'               => 99,
			'calendar_id'      => 5,
			'calendar_title'   => 'Clinic',
			'email_config'     => self::cfg( 1, 24 ),
			'appointment_date' => '2026-05-20',
			'start_time'       => '09:00:00',
		);

		$marked = null;
		$repo   = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\AppointmentRepository' );
		$repo->shouldReceive( 'getUpcomingForReminders' )->with( 24 )->andReturn( array( $appointment ) );
		$repo->shouldReceive( 'markReminderSent' )->andReturnUsing(
			function ( $id ) use ( &$marked ) {
				$marked = $id;
				return true;
			}
		);

		Actions\expectDone( 'ffcertificate_self_scheduling_appointment_reminder_email' )->once();

		AppointmentReminderScanner::run();

		$this->assertSame( 99, $marked, 'the due appointment must be marked reminded' );
	}

	public function test_skips_calendars_with_reminders_off(): void {
		$this->stub_emails_disabled( false );
		$this->stub_calendars(
			array( array( 'id' => 5, 'email_config' => self::cfg( 0, 24 ) ) )
		);

		// No calendar wants reminders → the appointment repo is never queried.
		$repo = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\AppointmentRepository' );
		$repo->shouldNotReceive( 'getUpcomingForReminders' );

		AppointmentReminderScanner::run();

		$this->assertTrue( true );
	}

	public function test_does_not_remind_appointment_from_other_hours_window(): void {
		$this->stub_emails_disabled( false );
		// Calendar 5 wants a 24h reminder.
		$this->stub_calendars(
			array( array( 'id' => 5, 'email_config' => self::cfg( 1, 24 ) ) )
		);

		// The 24h scan returns an appointment for calendar 7 (not in the
		// reminder-enabled set) — it must be ignored, not reminded.
		$foreign = array( 'id' => 42, 'calendar_id' => 7, 'calendar_title' => 'X', 'email_config' => self::cfg( 1, 48 ) );
		$repo    = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\AppointmentRepository' );
		$repo->shouldReceive( 'getUpcomingForReminders' )->with( 24 )->andReturn( array( $foreign ) );
		$repo->shouldNotReceive( 'markReminderSent' );

		Actions\expectDone( 'ffcertificate_self_scheduling_appointment_reminder_email' )->never();

		AppointmentReminderScanner::run();

		$this->assertTrue( true );
	}
}
