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
 * Every test runs in the shared process: `has_attended()` runs the real
 * AudienceBookingReader and AudienceAttendanceReader over a `$wpdb` double.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceRule
 */
class AudienceAttendanceRuleTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Every prepared statement, in order: [ sql, args ].
	 *
	 * @var list<array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = array();

	/**
	 * Each attendance read: [ booking id, user id ].
	 *
	 * @var list<array{0: int, 1: int}>
	 */
	private array $attendance_reads = array();

	/** @var mixed The `$wpdb` global before the test. */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->prepared         = array();
		$this->attendance_reads = array();
		$this->had_wpdb         = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb    = $GLOBALS['wpdb'] ?? null;
		// The booking reader goes through the object cache; a miss reaches the double.
		\Brain\Monkey\Functions\when( 'wp_cache_get' )->justReturn( false );
		\Brain\Monkey\Functions\when( 'wp_cache_set' )->justReturn( true );
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
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

	/**
	 * Install a `$wpdb` double for the real AudienceBookingReader and
	 * AudienceAttendanceReader: `get_row()` answers the booking by id,
	 * `get_col()` answers the attendance days by booking and person, and
	 * every attendance read is recorded. Any other statement fails.
	 *
	 * @param array<int, \stdClass>       $bookings Stored bookings by id.
	 * @param array<string, list<string>> $days     Days present by "booking:user".
	 */
	private function install_wpdb( array $bookings, array $days ): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->prepared[] = array( (string) preg_replace( '/\s+/', ' ', (string) $sql ), $args );
				return (string) ( count( $this->prepared ) - 1 );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) use ( $bookings ) {
				[ $sql, $args ] = $this->prepared[ (int) $query ];
				$this->assertStringStartsWith( 'SELECT b.*, e.name as environment_name', $sql, 'Unexpected get_row() query.' );
				$this->assertSame( array( 'wp_ffc_audience_bookings', 'wp_ffc_audience_environments' ), array_slice( $args, 0, 2 ) );
				return isset( $bookings[ $args[2] ] ) ? clone $bookings[ $args[2] ] : null;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) {
				[ $sql ] = $this->prepared[ (int) $query ];
				$this->assertStringStartsWith( 'SELECT a.* FROM %i a INNER JOIN %i ba', $sql, 'Unexpected get_results() query.' );
				return array();
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $query ) use ( $days ) {
				[ $sql, $args ] = $this->prepared[ (int) $query ];
				if ( 'SELECT user_id FROM %i WHERE booking_id = %d' === $sql ) {
					return array();
				}
				$this->assertSame( 'SELECT attendance_date FROM %i WHERE booking_id = %d AND user_id = %d ORDER BY attendance_date ASC', $sql, 'Unexpected get_col() query.' );
				$this->assertSame( 'wp_ffc_audience_attendance', $args[0] );
				$this->attendance_reads[] = array( $args[1], $args[2] );
				return $days[ $args[1] . ':' . $args[2] ] ?? array();
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_has_attended_reads_the_booking_and_the_days_present(): void {
		$this->install_wpdb(
			array(
				5 => $this->booking(
					array(
						'end_date'               => '2027-03-02',
						'attendance_mode'        => 'total',
						'attendance_min_percent' => '100',
					)
				),
			),
			array(
				'5:42' => array( '2027-03-01', '2027-03-02' ),
				'5:43' => array( '2027-03-01' ),
			)
		);

		$this->assertTrue( AudienceAttendanceRule::has_attended( 5, 42 ) );
		$this->assertFalse( AudienceAttendanceRule::has_attended( 5, 43 ) );
		$this->assertSame( array( array( 5, 42 ), array( 5, 43 ) ), $this->attendance_reads, 'The days are read for that booking and that person.' );
	}

	public function test_nobody_attended_a_cancelled_or_unknown_booking(): void {
		$this->install_wpdb(
			array( 5 => $this->booking( array( 'status' => 'cancelled' ) ) ),
			array( '5:42' => array( '2027-03-01' ) )
		);

		$this->assertFalse( AudienceAttendanceRule::has_attended( 5, 42 ) );
		$this->assertFalse( AudienceAttendanceRule::has_attended( 6, 42 ) );
		$this->assertSame( array(), $this->attendance_reads, 'No attendance is read for a cancelled or unknown booking.' );
	}
}
