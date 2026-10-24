<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAttendanceWriter;
use FreeFormCertificate\Audience\AudienceCheckin;
use FreeFormCertificate\Audience\AudienceCheckinCode;
use FreeFormCertificate\Audience\AudienceCheckinReceipt;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The participant half of the check-in (#1947): scan → receipt → confirm.
 *
 * The code and the receipt run for real, so a forged or stale token is
 * refused by the same signature the projected QR carries; the stores are
 * alias mocks.
 *
 * @covers \FreeFormCertificate\Audience\AudienceCheckin
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceCheckinTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** A moment on a 60-second boundary. */
	private const T0 = 1800000000;

	private const DAY = '2027-01-15';

	private const USER = 42;

	/** @var \Mockery\MockInterface */
	private $windows;

	/** @var \Mockery\MockInterface */
	private $bookings;

	/** @var \Mockery\MockInterface */
	private $attendance;

	/** @var \Mockery\MockInterface */
	private $writer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'current_time' )->justReturn( self::DAY );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );

		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Audience\AudienceAttendanceWriter' => array(
					'RECORDED'      => 'recorded',
					'ALREADY'       => 'already',
					'FAILED'        => 'failed',
					'METHOD_QR'     => 'qr',
					'METHOD_MANUAL' => 'manual',
				),
			)
		);

		$this->windows    = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceCheckinWindowReader' );
		$this->bookings   = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$this->attendance = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceAttendanceReader' );
		$this->writer     = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceAttendanceWriter' );

		$this->windows->shouldReceive( 'is_open' )->andReturn( true )->byDefault();
		$this->bookings->shouldReceive( 'get_by_id' )->with( 123 )->andReturn( $this->booking() )->byDefault();
		$this->bookings->shouldReceive( 'get_all_affected_users' )->with( 123 )->andReturn( array( '7', (string) self::USER ) )->byDefault();
		$this->attendance->shouldReceive( 'for_day' )->with( 123, self::DAY )->andReturn( array() )->byDefault();
	}

	protected function tearDown(): void {
		Mockery::getConfiguration()->setConstantsMap( array() );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A window open today on booking 123.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 * @return array<string, mixed>
	 */
	private function window( array $override = array() ): array {
		return array_merge(
			array(
				'id'                 => 7,
				'booking_id'         => 123,
				'window_date'        => self::DAY,
				'rotation_seconds'   => 60,
				'opened_at'          => self::T0 - 600,
				'opened_by'          => 1,
				'closes_at'          => self::T0 + 3600,
				'closed_at'          => null,
				'closed_by'          => null,
				'geofence_locations' => array(),
			),
			$override
		);
	}

	/**
	 * An active one-day booking on DAY.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 */
	private function booking( array $override = array() ): object {
		return (object) array_merge(
			array(
				'id'           => 123,
				'status'       => 'active',
				'booking_date' => self::DAY,
				'end_date'     => null,
				'description'  => 'Workshop',
			),
			$override
		);
	}

	private function window_is_open( array $window ): void {
		$this->windows->shouldReceive( 'open_for' )->with( 123, self::DAY, Mockery::any() )->andReturn( $window );
	}

	private function receipt( int $window_id = 7 ): string {
		return AudienceCheckinReceipt::issue( 123, self::DAY, $window_id, self::T0 );
	}

	public function test_a_live_code_is_exchanged_for_a_receipt_of_its_window(): void {
		$window = $this->window();
		$this->window_is_open( $window );

		$receipt = AudienceCheckin::scan( AudienceCheckinCode::for_window( $window, self::T0 ), self::T0 + 10 );

		$this->assertIsString( $receipt );
		$this->assertSame(
			array(
				'booking_id' => 123,
				'date'       => self::DAY,
				'window_id'  => 7,
			),
			array_intersect_key( (array) AudienceCheckinReceipt::verify( $receipt, self::T0 + 10 ), array_flip( array( 'booking_id', 'date', 'window_id' ) ) )
		);
	}

	public function test_a_tampered_code_is_refused(): void {
		$window = $this->window();
		$this->window_is_open( $window );
		$code = AudienceCheckinCode::for_window( $window, self::T0 );

		$result = AudienceCheckin::scan( substr( $code, 0, -1 ) . ( 'A' === substr( $code, -1 ) ? 'B' : 'A' ), self::T0 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( AudienceCheckin::ERROR_INVALID_CODE, $result->get_error_code() );
	}

	public function test_a_code_two_rotations_old_is_refused(): void {
		$window = $this->window();
		$this->window_is_open( $window );

		$result = AudienceCheckin::scan( AudienceCheckinCode::for_window( $window, self::T0 ), self::T0 + 120 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( AudienceCheckin::ERROR_INVALID_CODE, $result->get_error_code() );
	}

	public function test_a_code_is_refused_when_no_window_is_open(): void {
		$code = AudienceCheckinCode::for_window( $this->window(), self::T0 );
		$this->windows->shouldReceive( 'open_for' )->andReturn( null );

		$result = AudienceCheckin::scan( $code, self::T0 );

		$this->assertSame( AudienceCheckin::ERROR_INVALID_CODE, $result->get_error_code() );
	}

	public function test_a_code_from_a_window_since_reopened_is_refused(): void {
		$code = AudienceCheckinCode::for_window( $this->window(), self::T0 );
		$this->window_is_open( $this->window( array( 'id' => 8 ) ) );

		$result = AudienceCheckin::scan( $code, self::T0 );

		$this->assertSame( AudienceCheckin::ERROR_INVALID_CODE, $result->get_error_code() );
	}

	public function test_garbage_is_refused_before_any_lookup(): void {
		$this->windows->shouldNotReceive( 'open_for' );

		$this->assertSame( AudienceCheckin::ERROR_INVALID_CODE, AudienceCheckin::scan( 'not-a-code', self::T0 )->get_error_code() );
	}

	public function test_an_expired_receipt_is_refused(): void {
		$this->windows->shouldNotReceive( 'open_for' );

		$result = AudienceCheckin::context( $this->receipt(), self::USER, self::T0 + 3600 );

		$this->assertSame( AudienceCheckin::ERROR_EXPIRED_RECEIPT, $result->get_error_code() );
	}

	public function test_a_receipt_of_a_window_closed_or_reopened_since_is_refused(): void {
		$this->window_is_open( $this->window( array( 'id' => 8 ) ) );

		$result = AudienceCheckin::context( $this->receipt( 7 ), self::USER, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_CLOSED, $result->get_error_code() );
	}

	public function test_a_cancelled_booking_takes_no_check_in(): void {
		$this->window_is_open( $this->window() );
		$this->bookings->shouldReceive( 'get_by_id' )->with( 123 )->andReturn( $this->booking( array( 'status' => 'cancelled' ) ) );

		$result = AudienceCheckin::context( $this->receipt(), self::USER, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_UNAVAILABLE, $result->get_error_code() );
	}

	public function test_a_skipped_day_of_a_range_takes_no_check_in(): void {
		$this->window_is_open( $this->window() );
		$this->bookings->shouldReceive( 'get_by_id' )->with( 123 )->andReturn(
			$this->booking(
				array(
					'booking_date'  => '2027-01-14',
					'end_date'      => '2027-01-16',
					'skipped_dates' => '["' . self::DAY . '"]',
				)
			)
		);

		$result = AudienceCheckin::context( $this->receipt(), self::USER, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_UNAVAILABLE, $result->get_error_code() );
	}

	public function test_someone_not_on_the_booking_is_refused(): void {
		$this->window_is_open( $this->window() );
		$this->writer->shouldNotReceive( 'record' );

		$result = AudienceCheckin::confirm( $this->receipt(), 99, null, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_NOT_ON_BOOKING, $result->get_error_code() );
	}

	public function test_a_participant_is_recorded_by_qr(): void {
		$this->window_is_open( $this->window() );
		$this->writer->shouldReceive( 'record' )
			->once()
			->with( 123, self::USER, self::DAY, AudienceAttendanceWriter::METHOD_QR, null, self::T0 + 30 )
			->andReturn( AudienceAttendanceWriter::RECORDED );

		$result = AudienceCheckin::confirm( $this->receipt(), self::USER, null, self::T0 + 30 );

		$this->assertSame( AudienceAttendanceWriter::RECORDED, $result['status'] );
		$this->assertSame( self::T0 + 30, $result['checked_at'] );
	}

	public function test_a_second_check_in_the_same_day_reports_the_first_and_writes_nothing(): void {
		$this->window_is_open( $this->window() );
		$this->attendance->shouldReceive( 'for_day' )->with( 123, self::DAY )->andReturn(
			array(
				array(
					'user_id'    => self::USER,
					'checked_at' => self::T0 - 300,
				),
			)
		);
		$this->writer->shouldNotReceive( 'record' );

		$result = AudienceCheckin::confirm( $this->receipt(), self::USER, null, self::T0 + 30 );

		$this->assertSame( AudienceAttendanceWriter::ALREADY, $result['status'] );
		$this->assertSame( self::T0 - 300, $result['checked_at'] );
	}

	public function test_a_failed_write_is_an_error_not_a_success(): void {
		$this->window_is_open( $this->window() );
		$this->writer->shouldReceive( 'record' )->andReturn( AudienceAttendanceWriter::FAILED );

		$result = AudienceCheckin::confirm( $this->receipt(), self::USER, null, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_FAILED, $result->get_error_code() );
	}

	public function test_a_geofenced_window_needs_a_position(): void {
		$this->window_is_open( $this->window( array( 'geofence_locations' => array( 'loc_a' ) ) ) );
		$registry = Mockery::mock( 'alias:FreeFormCertificate\Security\GeofenceLocationRegistry' );
		$registry->shouldReceive( 'get_by_ids' )->andReturn(
			array(
				array(
					'lat'    => -23.5,
					'lng'    => -46.6,
					'radius' => 200,
				),
			)
		);
		$this->writer->shouldNotReceive( 'record' );

		$result = AudienceCheckin::confirm( $this->receipt(), self::USER, null, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_NEEDS_LOCATION, $result->get_error_code() );
	}

	public function test_a_position_outside_the_areas_is_refused_and_inside_is_recorded(): void {
		$registry = Mockery::mock( 'alias:FreeFormCertificate\Security\GeofenceLocationRegistry' );
		$registry->shouldReceive( 'get_by_ids' )->with( array( 'loc_a' ) )->andReturn(
			array(
				array(
					'lat'    => -23.5,
					'lng'    => -46.6,
					'radius' => 200,
				),
			)
		);
		$geo = Mockery::mock( 'alias:FreeFormCertificate\Integrations\IpGeolocation' );
		$geo->shouldReceive( 'is_within_areas' )
			->andReturnUsing( static fn( array $at ) => $at['latitude'] === -23.5 );

		$outside = AudienceCheckin::check_position( array( 'loc_a' ), array( 'lat' => 10.0, 'lng' => 10.0, 'accuracy' => 5.0 ) );
		$inside  = AudienceCheckin::check_position( array( 'loc_a' ), array( 'lat' => -23.5, 'lng' => -46.6, 'accuracy' => 5.0 ) );

		$this->assertSame( AudienceCheckin::ERROR_OUTSIDE_AREA, $outside->get_error_code() );
		$this->assertTrue( $inside );
	}

	public function test_a_geofence_whose_locations_were_all_deleted_cannot_be_met(): void {
		$registry = Mockery::mock( 'alias:FreeFormCertificate\Security\GeofenceLocationRegistry' );
		$registry->shouldReceive( 'get_by_ids' )->andReturn( array() );

		$result = AudienceCheckin::check_position( array( 'gone' ), array( 'lat' => 1.0, 'lng' => 1.0, 'accuracy' => 5.0 ) );

		$this->assertSame( AudienceCheckin::ERROR_OUTSIDE_AREA, $result->get_error_code() );
	}

	public function test_a_reading_less_precise_than_the_smallest_area_is_refused(): void {
		$registry = Mockery::mock( 'alias:FreeFormCertificate\Security\GeofenceLocationRegistry' );
		$registry->shouldReceive( 'get_by_ids' )->andReturn(
			array(
				array(
					'lat'    => -23.5,
					'lng'    => -46.6,
					'radius' => 200,
				),
			)
		);
		$geo = Mockery::mock( 'alias:FreeFormCertificate\Integrations\IpGeolocation' );
		$geo->shouldReceive( 'is_within_areas' )->andReturn( true );

		$imprecise = AudienceCheckin::check_position( array( 'loc_a' ), array( 'lat' => -23.5, 'lng' => -46.6, 'accuracy' => 201.0 ) );
		$exact     = AudienceCheckin::check_position( array( 'loc_a' ), array( 'lat' => -23.5, 'lng' => -46.6, 'accuracy' => 200.0 ) );

		$this->assertSame( AudienceCheckin::ERROR_INACCURATE, $imprecise->get_error_code() );
		$this->assertStringContainsString( 'not precise enough', $imprecise->get_error_message() );
		$this->assertTrue( $exact );
	}

	public function test_no_geofence_needs_no_position(): void {
		$this->assertTrue( AudienceCheckin::check_position( array(), null ) );
	}

	public function test_a_posted_position_is_read_only_within_range(): void {
		$this->assertSame( array( 'lat' => -23.5, 'lng' => -46.6, 'accuracy' => 12.0 ), AudienceCheckin::position( '-23.5', '-46.6', '12' ) );
		$this->assertNull( AudienceCheckin::position( '', '', '' ) );
		$this->assertNull( AudienceCheckin::position( 'abc', '1', '1' ) );
		$this->assertNull( AudienceCheckin::position( '91', '0', '1' ) );
		$this->assertNull( AudienceCheckin::position( '0', '-181', '1' ) );
		// The margin of error is part of the position (#2040).
		$this->assertNull( AudienceCheckin::position( '-23.5', '-46.6', null ) );
		$this->assertNull( AudienceCheckin::position( '-23.5', '-46.6', '-1' ) );
	}

	public function test_every_error_has_its_own_message(): void {
		$codes = array(
			AudienceCheckin::ERROR_INVALID_CODE,
			AudienceCheckin::ERROR_EXPIRED_RECEIPT,
			AudienceCheckin::ERROR_CLOSED,
			AudienceCheckin::ERROR_UNAVAILABLE,
			AudienceCheckin::ERROR_NOT_ON_BOOKING,
			AudienceCheckin::ERROR_NEEDS_LOCATION,
			AudienceCheckin::ERROR_OUTSIDE_AREA,
			AudienceCheckin::ERROR_FAILED,
		);

		$messages = array_map( array( AudienceCheckin::class, 'message' ), $codes );

		$this->assertCount( count( $codes ), array_unique( $messages ) );
	}
}
