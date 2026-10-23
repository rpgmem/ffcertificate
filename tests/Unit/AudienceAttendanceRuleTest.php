<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use FreeFormCertificate\Audience\AudienceAttendanceRule;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The attendance rule of a booking (#1947).
 *
 * `has_attended()` reads two repositories through alias mocks, so each test
 * runs in its own process.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceRule
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAttendanceRuleTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A booking row.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @return \stdClass
	 */
	private function booking( array $fields = array() ): \stdClass {
		return (object) array_merge(
			array(
				'id'           => 5,
				'status'       => 'active',
				'booking_date' => '2027-03-01',
				'end_date'     => null,
			),
			$fields
		);
	}

	public function test_normalize_reads_anything_unknown_as_single_and_clamps_the_share(): void {
		$this->assertSame( array( 'mode' => 'single', 'percent' => 0 ), AudienceAttendanceRule::normalize( 'bogus', 80 ) );
		$this->assertSame( array( 'mode' => 'total', 'percent' => 100 ), AudienceAttendanceRule::normalize( 'total', 250 ) );
		$this->assertSame( array( 'mode' => 'total', 'percent' => 1 ), AudienceAttendanceRule::normalize( 'total', 0 ) );
	}

	public function test_a_booking_with_no_rule_needs_one_day(): void {
		$range = $this->booking( array( 'end_date' => '2027-03-05' ) );

		$this->assertSame( 1, AudienceAttendanceRule::required_days( $range ) );
		$this->assertTrue( AudienceAttendanceRule::satisfied_by( $range, array( '2027-03-04' ) ) );
		$this->assertFalse( AudienceAttendanceRule::satisfied_by( $range, array() ) );
	}

	public function test_the_total_rule_rounds_up_and_holds_at_the_threshold_but_not_below(): void {
		$range = $this->booking(
			array(
				'end_date'               => '2027-03-04',
				'attendance_mode'        => 'total',
				'attendance_min_percent' => '75',
			)
		);

		$this->assertSame( 3, AudienceAttendanceRule::required_days( $range ), '75% of 4 days is 3.' );
		$this->assertTrue( AudienceAttendanceRule::satisfied_by( $range, array( '2027-03-01', '2027-03-02', '2027-03-04' ) ), 'At the threshold.' );
		$this->assertFalse( AudienceAttendanceRule::satisfied_by( $range, array( '2027-03-01', '2027-03-04' ) ), 'Below it.' );

		$three = $this->booking(
			array(
				'end_date'               => '2027-03-03',
				'attendance_mode'        => 'total',
				'attendance_min_percent' => '75',
			)
		);
		$this->assertSame( 3, AudienceAttendanceRule::required_days( $three ), '75% of 3 days is 2.25, so 3: a share below the threshold never meets it.' );
	}

	public function test_skipped_days_leave_the_count_and_never_count_for_anybody(): void {
		$range = $this->booking(
			array(
				'end_date'               => '2027-03-05',
				'skipped_dates'          => '["2027-03-03"]',
				'attendance_mode'        => 'total',
				'attendance_min_percent' => '50',
			)
		);

		$this->assertSame( array( '2027-03-01', '2027-03-02', '2027-03-04', '2027-03-05' ), AudienceAttendanceRule::counted_days( $range ) );
		$this->assertSame( 2, AudienceAttendanceRule::required_days( $range ), '50% of the four days left, not of five.' );
		$this->assertFalse(
			AudienceAttendanceRule::satisfied_by( $range, array( '2027-03-01', '2027-03-03' ) ),
			'A row on the skipped day does not tip the person over.'
		);
		$this->assertFalse(
			AudienceAttendanceRule::satisfied_by( $range, array( '2027-03-01', '2027-03-01' ) ),
			'The same day twice is one day.'
		);
		$this->assertTrue( AudienceAttendanceRule::satisfied_by( $range, array( '2027-03-01', '2027-03-05' ) ) );
	}

	public function test_has_attended_reads_the_booking_and_the_days_present(): void {
		$bookings = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$bookings->shouldReceive( 'get_by_id' )->with( 5 )->andReturn(
			$this->booking(
				array(
					'end_date'               => '2027-03-02',
					'attendance_mode'        => 'total',
					'attendance_min_percent' => '100',
				)
			)
		);
		$attendance = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceAttendanceReader' );
		$attendance->shouldReceive( 'days_present' )->with( 5, 42 )->andReturn( array( '2027-03-01', '2027-03-02' ) );
		$attendance->shouldReceive( 'days_present' )->with( 5, 43 )->andReturn( array( '2027-03-01' ) );

		$this->assertTrue( AudienceAttendanceRule::has_attended( 5, 42 ) );
		$this->assertFalse( AudienceAttendanceRule::has_attended( 5, 43 ) );
	}

	public function test_nobody_attended_a_cancelled_or_unknown_booking(): void {
		$bookings = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$bookings->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $this->booking( array( 'status' => 'cancelled' ) ) );
		$bookings->shouldReceive( 'get_by_id' )->with( 6 )->andReturn( null );
		$attendance = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceAttendanceReader' );
		$attendance->shouldNotReceive( 'days_present' );

		$this->assertFalse( AudienceAttendanceRule::has_attended( 5, 42 ) );
		$this->assertFalse( AudienceAttendanceRule::has_attended( 6, 42 ) );
	}
}
