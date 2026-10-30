<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAttendanceReader;
use FreeFormCertificate\Audience\AudienceAttendanceWriter;
use FreeFormCertificate\Audience\AudienceBookingWriter;
use FreeFormCertificate\Audience\AudienceCheckinWindowReader;
use FreeFormCertificate\Audience\AudienceCheckinWindowWriter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Attendance rows and check-in windows (#1947).
 *
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceReader
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceWriter
 * @covers \FreeFormCertificate\Audience\AudienceCheckinWindowReader
 * @covers \FreeFormCertificate\Audience\AudienceCheckinWindowWriter
 * @covers \FreeFormCertificate\Audience\AudienceBookingWriter::set_attendance_rule
 */
class AudienceAttendanceRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const NOW = 1800000000;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A raw window row as the database returns it.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 * @return array<string, string|null>
	 */
	private function window_row( array $override = array() ): array {
		return array_merge(
			array(
				'id'               => '7',
				'booking_id'       => '123',
				'window_date'      => '2027-01-15',
				'rotation_seconds' => '120',
				'opened_at'        => (string) ( self::NOW - 60 ),
				'opened_by'        => '1',
				'closes_at'        => (string) ( self::NOW + 3600 ),
				'closed_at'          => null,
				'closed_by'          => null,
				'geofence_locations' => 'loc_a, loc_b,,loc_a',
			),
			$override
		);
	}

	/**
	 * @dataProvider insert_outcomes
	 *
	 * @param int|false $affected What the INSERT IGNORE reported.
	 * @param string    $expected Result.
	 */
	public function test_record_reads_the_unique_key_as_once_per_day( $affected, string $expected ): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
			static function ( $sql, ...$args ) {
				return str_starts_with( $sql, 'INSERT IGNORE INTO %i' ) && 'wp_ffc_audience_attendance' === $args[0] && 'qr' === $args[5] ? 'SQL' : 'WRONG';
			}
		);
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' )->andReturn( $affected );

		$this->assertSame( $expected, AudienceAttendanceWriter::record( 123, 42, '2027-01-15', 'qr', null, self::NOW ) );
	}

	/**
	 * @return array<string, array{0: int|false, 1: string}>
	 */
	public function insert_outcomes(): array {
		return array(
			'first scan of the day'  => array( 1, AudienceAttendanceWriter::RECORDED ),
			'second scan of the day' => array( 0, AudienceAttendanceWriter::ALREADY ),
			'write failed'           => array( false, AudienceAttendanceWriter::FAILED ),
		);
	}

	public function test_a_manual_mark_records_the_operator_and_an_unknown_method_reads_as_qr(): void {
		$seen = array();
		$this->wpdb->shouldReceive( 'prepare' )->twice()->andReturnUsing(
			static function ( $sql, ...$args ) use ( &$seen ) {
				$seen[] = array( $sql, $args );
				return 'SQL';
			}
		);
		$this->wpdb->shouldReceive( 'query' )->twice()->andReturn( 1 );

		AudienceAttendanceWriter::record( 123, 42, '2027-01-15', 'manual', 9, self::NOW );
		AudienceAttendanceWriter::record( 123, 42, '2027-01-16', 'whatever', null, self::NOW );

		$this->assertStringContainsString( 'marked_by', $seen[0][0] );
		$this->assertSame( array( 'wp_ffc_audience_attendance', 123, 42, '2027-01-15', self::NOW, 'manual', 9 ), $seen[0][1] );
		$this->assertSame( 'qr', $seen[1][1][5] );
	}

	public function test_record_refuses_bad_input_without_touching_the_database(): void {
		$this->wpdb->shouldReceive( 'prepare' )->never();
		$this->wpdb->shouldReceive( 'query' )->never();

		$this->assertSame( AudienceAttendanceWriter::FAILED, AudienceAttendanceWriter::record( 0, 42, '2027-01-15', 'qr', null, self::NOW ) );
		$this->assertSame( AudienceAttendanceWriter::FAILED, AudienceAttendanceWriter::record( 123, 0, '2027-01-15', 'qr', null, self::NOW ) );
		$this->assertSame( AudienceAttendanceWriter::FAILED, AudienceAttendanceWriter::record( 123, 42, '15/01/2027', 'qr', null, self::NOW ) );
	}

	public function test_remove_deletes_one_day_of_one_person(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->with(
			'wp_ffc_audience_attendance',
			array(
				'booking_id'      => 123,
				'user_id'         => 42,
				'attendance_date' => '2027-01-15',
			),
			array( '%d', '%d', '%s' )
		)->andReturn( 1 );

		$this->assertTrue( AudienceAttendanceWriter::remove( 123, 42, '2027-01-15' ) );
	}

	public function test_present_counts_reads_every_booking_in_one_query(): void {
		// The bookings list shows who checked in beside who it reaches (#2243).
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
			function ( string $sql, array $args ): string {
				$this->assertStringContainsString( 'COUNT(DISTINCT user_id)', $sql );
				$this->assertStringContainsString( 'booking_id IN (%d, %d)', $sql );
				$this->assertSame( array( 'wp_ffc_audience_attendance', 5, 6 ), $args );
				return 'SQL';
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array( array( 'booking_id' => '5', 'present' => '3' ) )
		);

		$this->assertSame( array( 5 => 3 ), AudienceAttendanceReader::present_counts( array( 5, '6', 5, 0 ) ) );
		$this->assertSame( array(), AudienceAttendanceReader::present_counts( array() ), 'No query without a booking.' );
	}

	public function test_days_present_and_one_day_list(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '2027-01-15', '2027-01-16' ) );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'id'              => '3',
					'booking_id'      => '123',
					'user_id'         => null,
					'attendance_date' => '2027-01-15',
					'checked_at'      => (string) self::NOW,
					'method'          => 'manual',
					'marked_by'       => '9',
				),
			)
		);

		$this->assertSame( array( '2027-01-15', '2027-01-16' ), AudienceAttendanceReader::days_present( 123, 42 ) );
		$this->assertFalse( AudienceAttendanceReader::is_present( 123, 42, '2027-01-17' ) );
		$this->assertSame(
			array(
				array(
					'id'              => 3,
					'booking_id'      => 123,
					'user_id'         => null,
					'attendance_date' => '2027-01-15',
					'checked_at'      => self::NOW,
					'method'          => 'manual',
					'marked_by'       => 9,
				),
			),
			AudienceAttendanceReader::for_day( 123, '2027-01-15' ),
			'A row whose account was deleted keeps a null user.'
		);
	}

	public function test_a_window_is_open_until_closed_or_past_its_close(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			$this->window_row(),
			$this->window_row( array( 'closed_at' => (string) ( self::NOW - 5 ) ) ),
			null
		);

		$open = AudienceCheckinWindowReader::open_for( 123, '2027-01-15', self::NOW );
		$this->assertNotNull( $open );
		$this->assertSame( 120, $open['rotation_seconds'] );
		$this->assertSame( array( 'loc_a', 'loc_b' ), $open['geofence_locations'], 'Trimmed, without empty or repeated ids.' );
		$this->assertNull( $open['closed_at'] );
		$this->assertFalse( AudienceCheckinWindowReader::is_open( $open, self::NOW + 3600 ), 'Closed by itself at closes_at.' );

		$this->assertNull( AudienceCheckinWindowReader::open_for( 123, '2027-01-15', self::NOW ), 'Closed by hand.' );
		$this->assertNull( AudienceCheckinWindowReader::open_for( 123, '2027-01-15', self::NOW ), 'Never opened.' );
	}

	public function test_opening_closes_the_window_still_open_and_normalises_the_rotation(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->window_row() );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'insert' )->once()->with(
			'wp_ffc_audience_checkin_windows',
			array(
				'booking_id'       => 123,
				'window_date'      => '2027-01-15',
				'rotation_seconds' => 60,
				'opened_at'        => self::NOW,
				'closes_at'        => self::NOW + 1800,
				'opened_by'        => 1,
			),
			array( '%d', '%s', '%d', '%d', '%d', '%d' )
		)->andReturn( 1 );
		$this->wpdb->insert_id = 8;

		$this->assertSame( 8, AudienceCheckinWindowWriter::open( 123, '2027-01-15', 45, self::NOW + 1800, 1, self::NOW ) );
	}

	public function test_a_window_can_require_saved_geofence_locations(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->wpdb->shouldReceive( 'insert' )->once()->with(
			'wp_ffc_audience_checkin_windows',
			Mockery::on( static fn( $data ) => 'loc_1,loc_2' === ( $data['geofence_locations'] ?? null ) ),
			array( '%d', '%s', '%d', '%d', '%d', '%s' )
		)->andReturn( 1 );
		$this->wpdb->insert_id = 9;

		$this->assertSame( 9, AudienceCheckinWindowWriter::open( 123, '2027-01-15', 60, self::NOW + 60, null, self::NOW, array( 'loc_1', ' loc_2 ', 'bad id!', 'loc_1' ) ) );
	}

	public function test_a_window_that_would_close_before_it_opens_is_refused(): void {
		$this->wpdb->shouldReceive( 'insert' )->never();

		$this->assertSame( 0, AudienceCheckinWindowWriter::open( 123, '2027-01-15', 60, self::NOW, 1, self::NOW ) );
		$this->assertSame( 0, AudienceCheckinWindowWriter::open( 123, 'tomorrow', 60, self::NOW + 60, 1, self::NOW ) );
	}

	public function test_closing_twice_closes_once(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $sql ) => str_contains( $sql, 'closed_at IS NULL' ) ? 'SQL' : 'WRONG'
		);
		$this->wpdb->shouldReceive( 'query' )->with( 'SQL' )->andReturn( 1, 0 );

		$this->assertTrue( AudienceCheckinWindowWriter::close( 7, 1, self::NOW ) );
		$this->assertFalse( AudienceCheckinWindowWriter::close( 7, 1, self::NOW ) );
	}

	public function test_the_automatic_close_is_thirty_minutes_after_that_days_end_in_the_site_timezone(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Sao_Paulo' ) );

		$day = (object) array(
			'booking_date' => '2027-01-15',
			'start_time'   => '08:00:00',
			'end_time'     => '12:00:00',
			'is_all_day'   => '0',
		);
		$expected = ( new \DateTimeImmutable( '2027-01-15 12:30', new \DateTimeZone( 'America/Sao_Paulo' ) ) )->getTimestamp();
		$this->assertSame( $expected, AudienceCheckinWindowWriter::auto_close_at( $day, '2027-01-15' ) );

		$all_day  = (object) array(
			'booking_date' => '2027-01-15',
			'start_time'   => '00:00:00',
			'end_time'     => '23:59:00',
			'is_all_day'   => '1',
		);
		$expected = ( new \DateTimeImmutable( '2027-01-16 00:29', new \DateTimeZone( 'America/Sao_Paulo' ) ) )->getTimestamp();
		$this->assertSame( $expected, AudienceCheckinWindowWriter::auto_close_at( $all_day, '2027-01-15' ) );
	}

	public function test_the_rule_is_stored_normalised_and_single_stores_nothing(): void {
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		$written = array();
		$this->wpdb->shouldReceive( 'update' )->twice()->andReturnUsing(
			static function ( $table, $data ) use ( &$written ) {
				$written[] = $data;
				return 1;
			}
		);

		$this->assertTrue( AudienceBookingWriter::set_attendance_rule( 5, 'total', 140 ) );
		$this->assertTrue( AudienceBookingWriter::set_attendance_rule( 5, 'single', 80 ) );

		$this->assertSame(
			array(
				array(
					'attendance_mode'        => 'total',
					'attendance_min_percent' => 100,
				),
				array(
					'attendance_mode'        => null,
					'attendance_min_percent' => null,
				),
			),
			$written
		);
	}
}
