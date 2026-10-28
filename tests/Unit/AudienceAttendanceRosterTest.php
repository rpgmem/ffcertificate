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
 * The attendance, booking and invitation readers are the real classes; their
 * tables answer through the `$wpdb` double below, which dispatches on the
 * table each statement binds and on the booking (and day) it asks for, and
 * fails on anything else, so a read for another booking cannot pass unseen.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceRoster
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceExportSource
 */
class AudienceAttendanceRosterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	/** @var list<int> Booking IDs the invitation table was asked about. */
	private array $invite_reads = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
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

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->bound = is_array( $args[0] ?? null ) ? $args[0] : $args;
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn( $sql ) => $this->col( (string) $sql ) );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn( $sql ) => $this->results( (string) $sql ) );

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
	 * AudienceBookingReader::get_booking_users(): users 7 and 8 are on booking 5.
	 *
	 * @param string $sql Prepared statement.
	 * @return list<string>
	 */
	private function col( string $sql ): array {
		if ( str_contains( $sql, 'SELECT user_id FROM %i WHERE booking_id = %d' )
			&& array( 'wp_ffc_audience_booking_users', 5 ) === $this->bound ) {
			return array( '7', '8' );
		}

		throw new \RuntimeException( 'Unexpected get_col: ' . $sql . ' ' . json_encode( $this->bound ) );
	}

	/**
	 * The attendance lines, the booking's audiences and its invitations.
	 *
	 * @param string $sql Prepared statement.
	 * @return list<array<string, mixed>|object>
	 */
	private function results( string $sql ): array {
		$table = $this->bound[0] ?? null;

		// AudienceAttendanceReader::days_by_user(): 9 left the booking after
		// being recorded; 7 has a line on a day the booking later skipped.
		if ( str_starts_with( $sql, 'SELECT user_id, attendance_date FROM' )
			&& array( 'wp_ffc_audience_attendance', 5 ) === $this->bound ) {
			return array(
				array(
					'user_id'         => '7',
					'attendance_date' => '2027-03-01',
				),
				array(
					'user_id'         => '9',
					'attendance_date' => '2027-03-01',
				),
				array(
					'user_id'         => '7',
					'attendance_date' => '2027-03-02',
				),
			);
		}

		// AudienceAttendanceReader::for_day(): the third line's person was deleted.
		if ( str_starts_with( $sql, 'SELECT id, booking_id, user_id, attendance_date, checked_at' )
			&& array( 'wp_ffc_audience_attendance', 5, '2027-03-01' ) === $this->bound ) {
			return array(
				$this->line( 1, '7', '1800000000', 'qr' ),
				$this->line( 2, '9', '1800000100', 'manual' ),
				$this->line( 3, null, '1800000200', 'qr' ),
			);
		}

		// AudienceBookingReader::get_booking_audiences(): no audience on the booking.
		if ( str_starts_with( $sql, 'SELECT a.* FROM %i a' )
			&& 'wp_ffc_audiences' === $table && 5 === ( $this->bound[2] ?? null ) ) {
			return array();
		}

		// AudienceFormInviteReader::for_booking(): nobody was invited.
		if ( str_starts_with( $sql, 'SELECT user_id, sent_at, send_count FROM' )
			&& 'wp_ffc_audience_form_invites' === $table ) {
			$this->invite_reads[] = (int) ( $this->bound[1] ?? 0 );
			return array();
		}

		throw new \RuntimeException( 'Unexpected get_results: ' . $sql . ' ' . json_encode( $this->bound ) );
	}

	/**
	 * One raw attendance row, as the table returns it.
	 *
	 * @param int         $id         Line ID.
	 * @param string|null $user_id    Person, null once deleted.
	 * @param string      $checked_at Unix time.
	 * @param string      $method     How it was recorded.
	 * @return array<string, mixed>
	 */
	private function line( int $id, ?string $user_id, string $checked_at, string $method ): array {
		return array(
			'id'              => (string) $id,
			'booking_id'      => '5',
			'user_id'         => $user_id,
			'attendance_date' => '2027-03-01',
			'checked_at'      => $checked_at,
			'method'          => $method,
			'marked_by'       => null,
		);
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
		$this->assertNotSame( array(), $this->invite_reads, 'The invitations of the booking were read.' );
		$this->assertSame( array( 5 ), array_values( array_unique( $this->invite_reads ) ), 'Only booking 5 was asked about.' );
	}
}
