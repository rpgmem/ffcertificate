<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLogSubscriber;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;

/**
 * Regression guard for the user attributed to an appointment activity-log row.
 *
 * `ActivityLog::log()` takes `$user_id` as its fourth argument. The appointment
 * subscriber passed `$appointment_id` there, so every booking was logged
 * against whichever user happened to hold that id — and once
 * `MigrationForeignKeys` added `fk_ffc_activity_log_user`, MySQL rejected the
 * insert outright and the row was lost rather than merely wrong. Observed in
 * production as `Cannot add or update a child row` on every booking.
 *
 * The real ActivityLog runs, and the row's `user_id` is read from its write
 * buffer (#2117).
 *
 * @covers \FreeFormCertificate\Core\ActivityLogSubscriber
 */
class ActivityLogAppointmentAttributionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		Functions\when( 'get_option' )->alias(
			static fn( $key, $fallback = false ) => 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $fallback
		);
		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Appointment data as the handler passes it.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function bookingData( array $overrides = array() ): array {
		return array_merge(
			array(
				'calendar_id'      => 1,
				'appointment_date' => '2030-01-15',
				'start_time'       => '10:00',
				'status'           => 'confirmed',
				'user_id'          => 42,
				'user_ip'          => '127.0.0.1',
			),
			$overrides
		);
	}

	public function test_logs_the_booking_user_not_the_appointment_id(): void {
		// 777 is the appointment id; 42 is the visitor who booked.
		( new ActivityLogSubscriber() )->on_appointment_created( 777, $this->bookingData(), array() );

		$rows = ActivityLogBuffer::entries();
		$this->assertCount( 1, $rows );
		$this->assertSame( 42, $rows[0]['user_id'], 'the fourth argument is $user_id, not the appointment' );
	}

	public function test_falls_back_to_anonymous_when_the_booking_has_no_user(): void {
		// A guest booking carries no user_id. 0 is the anonymous sentinel the
		// column allows; the appointment id would be a foreign-key violation.
		( new ActivityLogSubscriber() )->on_appointment_created(
			777,
			$this->bookingData( array( 'user_id' => null ) ),
			array()
		);

		$rows = ActivityLogBuffer::entries();
		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]['user_id'] );
	}
}
