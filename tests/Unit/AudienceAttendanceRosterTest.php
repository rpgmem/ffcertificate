<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAttendanceExportSource;
use FreeFormCertificate\Audience\AudienceAttendanceRoster;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The one list the attendance screen, its poll and its CSV read (#1947).
 *
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceRoster
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceExportSource
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAttendanceRosterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'get_users' )->justReturn(
			array(
				(object) array(
					'ID'           => 7,
					'display_name' => 'Bruno',
				),
				(object) array(
					'ID'           => 8,
					'display_name' => 'ana',
				),
				(object) array(
					'ID'           => 9,
					'display_name' => 'Carla',
				),
			)
		);

		$bookings = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$bookings->shouldReceive( 'get_all_affected_users' )->with( 5 )->andReturn( array( '7', '8' ) );

		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceAttendanceReader' );
		// 9 left the booking after being recorded; 7 has a line on a day
		// the booking later skipped.
		$reader->shouldReceive( 'days_by_user' )->with( 5 )->andReturn(
			array(
				7 => array( '2027-03-01', '2027-03-02' ),
				9 => array( '2027-03-01' ),
			)
		);
		$reader->shouldReceive( 'for_day' )->with( 5, '2027-03-01' )->andReturn(
			array(
				array(
					'user_id'    => 7,
					'checked_at' => 1800000000,
					'method'     => 'qr',
				),
				array(
					'user_id'    => 9,
					'checked_at' => 1800000100,
					'method'     => 'manual',
				),
				array(
					'user_id'    => null,
					'checked_at' => 1800000200,
					'method'     => 'qr',
				),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function booking(): object {
		return (object) array(
			'id'                     => 5,
			'status'                 => 'active',
			'booking_date'           => '2027-03-01',
			'end_date'               => '2027-03-03',
			'skipped_dates'          => '["2027-03-02"]',
			'attendance_mode'        => 'total',
			'attendance_min_percent' => 100,
		);
	}

	public function test_the_roster_lists_the_booking_and_who_was_recorded_sorted_by_name(): void {
		$roster = AudienceAttendanceRoster::for_day( $this->booking(), '2027-03-01' );

		$this->assertSame( array( 'ana', 'Bruno', 'Carla' ), array_column( $roster['rows'], 'name' ) );
		$this->assertSame( 2, $roster['present'], 'A line whose person was deleted counts for nobody.' );
		$this->assertSame( 3, $roster['total'] );
		$this->assertSame( array( '2027-03-01', '2027-03-03' ), $roster['days'] );
		$this->assertSame( 2, $roster['required'] );
	}

	public function test_a_skipped_day_does_not_count_and_the_rule_reads_the_counted_days(): void {
		$rows = array_column( AudienceAttendanceRoster::for_day( $this->booking(), '2027-03-01' )['rows'], null, 'user_id' );

		$this->assertSame( array( '2027-03-01' ), $rows[7]['days'] );
		$this->assertFalse( $rows[7]['met'], 'One of two counted days does not meet 100%.' );
		$this->assertSame( 1800000000, $rows[7]['present_at'] );
		$this->assertSame( 'qr', $rows[7]['method'] );
		$this->assertNull( $rows[8]['present_at'] );
		$this->assertSame( array(), $rows[8]['days'] );
	}

	public function test_the_csv_has_a_column_per_counted_day_and_names_no_identifier(): void {
		Functions\when( 'current_time' )->justReturn( '2027-03-01' );
		// No form applies and nobody was invited, so the post-event columns
		// stay out (#2026).
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceFormInviteReader' )
			->shouldReceive( 'for_booking' )->with( 5 )->andReturn( array() );
		$source = new AudienceAttendanceExportSource( $this->booking() );

		$this->assertSame( array( 'Name', '2027-03-01', '2027-03-03', 'Days present', 'Attendance rule met' ), $source->header() );
		$this->assertSame(
			array(
				array( 'ana', '', '', '0', 'No' ),
				array( 'Bruno', 'Present', '', '1', 'No' ),
				array( 'Carla', 'Present', '', '1', 'No' ),
			),
			iterator_to_array( $source->rows(), false )
		);
	}
}
