<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentValidator;

/**
 * Isolated coverage for AppointmentValidator's "restrict booking to business
 * hours" branch. The appointment-time working-hours gate (step 8) and the
 * business-hours gate (step 12b) both call WorkingHoursService with the SAME
 * calendar config, so the only way to pass step 8 yet fail step 12b is to
 * alias-mock WorkingHoursService and answer per the queried date — hence a
 * separate process.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentValidator
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AppointmentValidatorBusinessHoursTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $appointmentRepo;
	/** @var \Mockery\MockInterface */
	private $blockedDateRepo;
	private AppointmentValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( fn ( $t ) => $t instanceof \WP_Error );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'current_user_can' )->alias( function ( $cap ) {
			return 'ffc_book_own_appointments' === $cap; // no bypass, holds booking cap.
		} );
		Functions\when( 'wp_timezone' )->alias( fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'current_time' )->justReturn( gmdate( 'Y-m-d H:i:s' ) );
		Functions\when( 'get_option' )->justReturn( array() );

		$this->appointmentRepo = Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' );
		$this->blockedDateRepo = Mockery::mock( 'FreeFormCertificate\Repositories\BlockedDateRepository' );
		$this->appointmentRepo->shouldReceive( 'isSlotAvailable' )->andReturn( true )->byDefault();
		$this->blockedDateRepo->shouldReceive( 'isDateBlocked' )->andReturn( false )->byDefault();

		$this->validator = new AppointmentValidator( $this->appointmentRepo, $this->blockedDateRepo );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private function data(): array {
		return array(
			'appointment_date' => '2030-01-15',
			'start_time'       => '10:00',
			'calendar_id'      => 1,
			'email'            => 'test@example.com',
			'cpf_rf'           => '1234567',
			'user_id'          => 1,
		);
	}

	/** @param array<string, mixed> $overrides */
	private function calendar( array $overrides = array() ): array {
		return array_merge( array(
			'advance_booking_min'               => 0,
			'advance_booking_max'               => 0,
			'max_appointments_per_slot'         => 10,
			'slots_per_day'                     => 0,
			'minimum_interval_between_bookings' => 0,
			'scheduling_visibility'             => 'public',
			'restrict_booking_to_hours'         => true,
			'working_hours'                     => array( array( 'day' => 2, 'start' => '00:00', 'end' => '23:59' ) ),
		), $overrides );
	}

	public function test_outside_business_hours_blocks_when_current_time_is_closed(): void {
		// Step 8 (appointment 2030-01-15) passes; step 12b (current time) fails.
		Mockery::mock( 'alias:FreeFormCertificate\Scheduling\WorkingHoursService' )
			->shouldReceive( 'is_within_working_hours' )
			->andReturnUsing( fn ( $date ) => '2030-01-15' === $date )
			->shouldReceive( 'is_working_day' )
			->andReturn( true );

		$result = $this->validator->validate( $this->data(), $this->calendar() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outside_business_hours', $result->get_error_code() );
	}

	public function test_within_business_hours_allows_booking(): void {
		// Both gates open → validation passes through to the end.
		Mockery::mock( 'alias:FreeFormCertificate\Scheduling\WorkingHoursService' )
			->shouldReceive( 'is_within_working_hours' )->andReturn( true )
			->shouldReceive( 'is_working_day' )->andReturn( true );

		$this->assertTrue( $this->validator->validate( $this->data(), $this->calendar() ) );
	}
}
