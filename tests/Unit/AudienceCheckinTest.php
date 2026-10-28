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
 * Everything runs for real — the code and the receipt, so a forged or stale
 * token is refused by the same signature the projected QR carries; the
 * window, booking and attendance stores; the attendance writer; and the
 * geofence registry and GPS verdict. Storage is a `$wpdb` double that answers
 * each query by its SQL text from the rows a test sets and records every
 * query it ran, plus the geofence locations option.
 *
 * @covers \FreeFormCertificate\Audience\AudienceCheckin
 */
class AudienceCheckinTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** A moment on a 60-second boundary. */
	private const T0 = 1800000000;

	private const DAY = '2027-01-15';

	private const USER = 42;

	private const BOOKING = 123;

	/** @var array<string, mixed>|null The window row the window store answers; null for none. */
	private ?array $window_row = null;

	/** @var object|null The booking row the booking store answers; null for none. */
	private ?object $booking_row = null;

	/** @var list<array<string, mixed>> Attendance rows the day's query answers. */
	private array $attendance = array();

	/** @var int|false What the attendance INSERT reports: rows inserted, or false on a database error. */
	private $insert_result = 1;

	/** @var list<string> Every SQL string the double ran. */
	private array $ran = array();

	/** @var list<array<int, mixed>> The bound arguments of every INSERT the double ran. */
	private array $inserts = array();

	/** @var array<int, array<string, mixed>> Geofence locations stored in the option. */
	private array $locations = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'current_time' )->justReturn( self::DAY );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( 'ffc_geofence_locations' === $key ) {
					return $this->locations;
				}
				if ( 'ffc_geolocation_settings' === $key ) {
					// The GPS tolerance setting: nothing stored, so the default (#2070).
					return array();
				}
				return $default;
			}
		);

		$this->window_row    = $this->row( $this->window() );
		$this->booking_row   = $this->booking();
		$this->attendance    = array();
		$this->insert_result = 1;
		$this->ran           = array();
		$this->inserts       = array();
		$this->locations     = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return array(
					'sql'  => $sql,
					'args' => $flat,
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( false !== strpos( $q['sql'], 'window_date = %s ORDER BY id DESC' ) ) {
					return self::BOOKING === $q['args'][1] && self::DAY === $q['args'][2] ? $this->window_row : null;
				}
				if ( false !== strpos( $q['sql'], 'INNER JOIN %i e ON b.environment_id' ) ) {
					return self::BOOKING === $q['args'][2] && null !== $this->booking_row ? clone $this->booking_row : null;
				}
				$this->fail( 'Unexpected get_row(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( false !== strpos( $q['sql'], 'WHERE ba.booking_id = %d' ) ) {
					// No audience on the booking: its participants are added directly.
					return array();
				}
				if ( false !== strpos( $q['sql'], 'attendance_date = %s ORDER BY checked_at' ) ) {
					return self::BOOKING === $q['args'][1] && self::DAY === $q['args'][2] ? $this->attendance : array();
				}
				$this->fail( 'Unexpected get_results(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( false !== strpos( $q['sql'], 'SELECT user_id FROM %i WHERE booking_id = %d' ) ) {
					return self::BOOKING === $q['args'][1] ? array( '7', (string) self::USER ) : array();
				}
				$this->fail( 'Unexpected get_col(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( 0 === strpos( $q['sql'], 'INSERT IGNORE INTO %i (booking_id, user_id, attendance_date, checked_at, method)' ) ) {
					$this->inserts[] = $q['args'];
					return $this->insert_result;
				}
				$this->fail( 'Unexpected query(): ' . $q['sql'] );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A window open today on booking 123, in the shape the window reader returns.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 * @return array<string, mixed>
	 */
	private function window( array $override = array() ): array {
		return array_merge(
			array(
				'id'                 => 7,
				'booking_id'         => self::BOOKING,
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
	 * A window as the table stores it: location ids comma-separated.
	 *
	 * @param array<string, mixed> $window Window in the reader's shape.
	 * @return array<string, mixed>
	 */
	private function row( array $window ): array {
		return array_merge( $window, array( 'geofence_locations' => implode( ',', $window['geofence_locations'] ) ) );
	}

	/**
	 * An active one-day booking on DAY.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 */
	private function booking( array $override = array() ): object {
		return (object) array_merge(
			array(
				'id'           => self::BOOKING,
				'status'       => 'active',
				'booking_date' => self::DAY,
				'end_date'     => null,
				'description'  => 'Workshop',
			),
			$override
		);
	}

	/**
	 * Store the window the window reader answers for booking 123 today.
	 *
	 * @param array<string, mixed> $window Window in the reader's shape.
	 */
	private function window_is_open( array $window ): void {
		$this->window_row = $this->row( $window );
	}

	/**
	 * Store geofence locations: `loc_a`, 200 m around a point in São Paulo,
	 * and `loc_z`, a wide area elsewhere that no window here names.
	 */
	private function store_locations(): void {
		$this->locations = array(
			array(
				'id'     => 'loc_a',
				'lat'    => -23.5,
				'lng'    => -46.6,
				'radius' => 200,
			),
			array(
				'id'     => 'loc_z',
				'lat'    => 10.0,
				'lng'    => 10.0,
				'radius' => 5000,
			),
		);
	}

	private function receipt( int $window_id = 7 ): string {
		return AudienceCheckinReceipt::issue( self::BOOKING, self::DAY, $window_id, self::T0 );
	}

	public function test_a_live_code_is_exchanged_for_a_receipt_of_its_window(): void {
		$window = $this->window();
		$this->window_is_open( $window );

		$receipt = AudienceCheckin::scan( AudienceCheckinCode::for_window( $window, self::T0 ), self::T0 + 10 );

		$this->assertIsString( $receipt );
		$this->assertSame(
			array(
				'booking_id' => self::BOOKING,
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
		$code             = AudienceCheckinCode::for_window( $this->window(), self::T0 );
		$this->window_row = null;

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
		$this->assertSame( AudienceCheckin::ERROR_INVALID_CODE, AudienceCheckin::scan( 'not-a-code', self::T0 )->get_error_code() );
		$this->assertSame( array(), $this->ran, 'Garbage reaches no store, the window lookup included.' );
	}

	public function test_an_expired_receipt_is_refused(): void {
		$result = AudienceCheckin::context( $this->receipt(), self::USER, self::T0 + 3600 );

		$this->assertSame( AudienceCheckin::ERROR_EXPIRED_RECEIPT, $result->get_error_code() );
		$this->assertSame( array(), $this->ran, 'An expired receipt reaches no store, the window lookup included.' );
	}

	public function test_a_receipt_of_a_window_closed_or_reopened_since_is_refused(): void {
		$this->window_is_open( $this->window( array( 'id' => 8 ) ) );

		$result = AudienceCheckin::context( $this->receipt( 7 ), self::USER, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_CLOSED, $result->get_error_code() );
	}

	public function test_a_cancelled_booking_takes_no_check_in(): void {
		$this->window_is_open( $this->window() );
		$this->booking_row = $this->booking( array( 'status' => 'cancelled' ) );

		$result = AudienceCheckin::context( $this->receipt(), self::USER, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_UNAVAILABLE, $result->get_error_code() );
	}

	public function test_a_skipped_day_of_a_range_takes_no_check_in(): void {
		$this->window_is_open( $this->window() );
		$this->booking_row = $this->booking(
			array(
				'booking_date'  => '2027-01-14',
				'end_date'      => '2027-01-16',
				'skipped_dates' => '["' . self::DAY . '"]',
			)
		);

		$result = AudienceCheckin::context( $this->receipt(), self::USER, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_UNAVAILABLE, $result->get_error_code() );
	}

	public function test_someone_not_on_the_booking_is_refused(): void {
		$this->window_is_open( $this->window() );

		$result = AudienceCheckin::confirm( $this->receipt(), 99, null, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_NOT_ON_BOOKING, $result->get_error_code() );
		$this->assertSame( array(), $this->inserts, 'Someone not on the booking gets no attendance row.' );
	}

	public function test_a_participant_is_recorded_by_qr(): void {
		$this->window_is_open( $this->window() );

		$result = AudienceCheckin::confirm( $this->receipt(), self::USER, null, self::T0 + 30 );

		$this->assertSame( AudienceAttendanceWriter::RECORDED, $result['status'] );
		$this->assertSame( self::T0 + 30, $result['checked_at'] );
		$this->assertSame(
			array( array( 'wp_ffc_audience_attendance', self::BOOKING, self::USER, self::DAY, self::T0 + 30, AudienceAttendanceWriter::METHOD_QR ) ),
			$this->inserts,
			'Exactly one attendance row is written, for this user, today, by QR, at the confirmation.'
		);
	}

	public function test_a_second_check_in_the_same_day_reports_the_first_and_writes_nothing(): void {
		$this->window_is_open( $this->window() );
		$this->attendance = array(
			array(
				'id'              => 1,
				'booking_id'      => self::BOOKING,
				'user_id'         => self::USER,
				'attendance_date' => self::DAY,
				'checked_at'      => self::T0 - 300,
				'method'          => 'qr',
				'marked_by'       => null,
			),
		);

		$result = AudienceCheckin::confirm( $this->receipt(), self::USER, null, self::T0 + 30 );

		$this->assertSame( AudienceAttendanceWriter::ALREADY, $result['status'] );
		$this->assertSame( self::T0 - 300, $result['checked_at'] );
		$this->assertSame( array(), $this->inserts, 'A second check-in the same day writes nothing.' );
	}

	public function test_a_failed_write_is_an_error_not_a_success(): void {
		$this->window_is_open( $this->window() );
		$this->insert_result = false;

		$result = AudienceCheckin::confirm( $this->receipt(), self::USER, null, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_FAILED, $result->get_error_code() );
		$this->assertCount( 1, $this->inserts, 'The write was attempted and the database refused it.' );
	}

	public function test_a_geofenced_window_needs_a_position(): void {
		$this->window_is_open( $this->window( array( 'geofence_locations' => array( 'loc_a' ) ) ) );
		$this->store_locations();

		$result = AudienceCheckin::confirm( $this->receipt(), self::USER, null, self::T0 + 30 );

		$this->assertSame( AudienceCheckin::ERROR_NEEDS_LOCATION, $result->get_error_code() );
		$this->assertSame( array(), $this->inserts, 'Without a position a geofenced window writes nothing.' );
	}

	public function test_a_position_outside_the_areas_is_refused_and_inside_is_recorded(): void {
		// `loc_z` covers the outside point, so a registry that did not filter
		// by the window's ids would let it through.
		$this->store_locations();

		$outside = AudienceCheckin::check_position( array( 'loc_a' ), array( 'lat' => 10.0, 'lng' => 10.0, 'accuracy' => 5.0 ) );
		$inside  = AudienceCheckin::check_position( array( 'loc_a' ), array( 'lat' => -23.5, 'lng' => -46.6, 'accuracy' => 5.0 ) );

		$this->assertSame( AudienceCheckin::ERROR_OUTSIDE_AREA, $outside->get_error_code() );
		$this->assertTrue( $inside );
	}

	public function test_a_geofence_whose_locations_were_all_deleted_cannot_be_met(): void {
		// Other locations remain stored; none is the one the window names.
		$this->store_locations();

		$result = AudienceCheckin::check_position( array( 'gone' ), array( 'lat' => 10.0, 'lng' => 10.0, 'accuracy' => 5.0 ) );

		$this->assertSame( AudienceCheckin::ERROR_OUTSIDE_AREA, $result->get_error_code() );
	}

	public function test_a_reading_less_precise_than_the_tolerance_allows_is_refused(): void {
		$this->store_locations();

		// 1.5 × the 200 m radius is the default tolerance (#2070).
		$imprecise = AudienceCheckin::check_position( array( 'loc_a' ), array( 'lat' => -23.5, 'lng' => -46.6, 'accuracy' => 301.0 ) );
		$exact     = AudienceCheckin::check_position( array( 'loc_a' ), array( 'lat' => -23.5, 'lng' => -46.6, 'accuracy' => 300.0 ) );

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
