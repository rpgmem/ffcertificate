<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAttendanceStatus;
use FreeFormCertificate\Audience\AudienceFormInvite;
use FreeFormCertificate\Audience\AudiencePresenceEvents;
use FreeFormCertificate\Tests\Support\FakeCredentials;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Whether a participant was recorded present, and what waits for them in
 * the app (#2749).
 *
 * Runs the real AudienceAttendanceReader, AudienceAttendanceRule,
 * AudienceQueryService, AudiencePostEventPending and DateFormatter over a
 * `$wpdb` double that answers each query by its SQL text and records the
 * attendance queries it ran.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceStatus
 * @covers \FreeFormCertificate\Audience\AudiencePresenceEvents::pending
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceReader::for_user
 */
class AudienceAttendanceStatusTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** 2027-01-15 08:00:00 UTC. */
	private const NOW = 1800000000;

	private const USER = 9;

	private const FORM = 40;

	private const PAGE = 77;

	private const PAGE_URL = 'https://example.org/form/';

	/** @var list<array<string, string>> Attendance rows the double returns. */
	private array $attendance = array();

	/** @var list<array<string, mixed>> Booking rows the user-bookings query returns. */
	private array $bookings = array();

	/** @var list<array{sql: string, args: array<int, mixed>}> Attendance queries the double ran. */
	private array $attendance_queries = array();

	/** @var mixed The `$wpdb` global an earlier test left. */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->attendance         = array();
		$this->bookings           = array();
		$this->attendance_queries = array();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_the_title' )->justReturn( 'Evaluation' );
		Functions\when( 'wp_salt' )->justReturn( FakeCredentials::WP_SALT );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
		Functions\when( 'get_post_field' )->alias(
			static fn( $field, $id ) => self::PAGE === (int) $id ? '[ffc_form id="' . self::FORM . '"]' : ''
		);
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => self::PAGE === (int) $id ? self::PAGE_URL : false );
		Functions\when( 'get_posts' )->justReturn( array( self::PAGE ) );
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				if ( self::FORM !== (int) $id ) {
					return null;
				}
				$post              = new \WP_Post();
				$post->ID          = self::FORM;
				$post->post_type   = 'ffc_form';
				$post->post_status = 'publish';
				return $post;
			}
		);
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					return array(
						'date_format' => 'Y-m-d',
						'time_format' => 'H:i',
					);
				}
				return $default;
			}
		);

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
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				if ( false !== strpos( $q['sql'], 'WHERE user_id = %d AND booking_id IN' ) ) {
					$this->attendance_queries[] = $q;
					return $this->attendance;
				}
				if ( false !== strpos( $q['sql'], 'bu.user_id = %d OR am.user_id = %d' ) ) {
					return $this->bookings;
				}
				// Audiences on the bookings, the answers to a form: none.
				return array();
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			static function ( $q ) {
				if ( false !== strpos( $q['sql'], 'SELECT user_id FROM %i WHERE booking_id = %d' ) ) {
					return array( (string) self::USER );
				}
				return array();
			}
		);

		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A booking row.
	 *
	 * @param int                  $id    Booking ID.
	 * @param array<string, mixed> $extra Columns to change.
	 * @return array<string, mixed>
	 */
	private function booking( int $id, array $extra = array() ): array {
		return array_merge(
			array(
				'id'           => (string) $id,
				'status'       => 'active',
				'booking_date' => '2027-01-15',
				'end_date'     => null,
				'start_time'   => '07:00:00',
				'end_time'     => '08:00:00',
				'description'  => 'Library',
			),
			$extra
		);
	}

	/**
	 * An attendance row.
	 *
	 * @param int    $booking_id Booking.
	 * @param string $date       Day.
	 * @param int    $at         Unix time.
	 * @param string $method     Method.
	 * @return array<string, string>
	 */
	private function line( int $booking_id, string $date, int $at, string $method = 'terminal' ): array {
		return array(
			'booking_id'      => (string) $booking_id,
			'attendance_date' => $date,
			'checked_at'      => (string) $at,
			'method'          => $method,
		);
	}

	public function test_a_single_day_event_says_present_with_how_and_when(): void {
		$this->attendance = array( $this->line( 5, '2027-01-15', self::NOW - 600 ) );

		$status = AudienceAttendanceStatus::for_bookings( self::USER, array( $this->booking( 5 ), $this->booking( 6 ) ) );

		$this->assertSame( array( 5 ), array_keys( $status ), 'A booking with no attendance is absent.' );
		$this->assertSame( 'Present', $status[5]['label'] );
		$this->assertTrue( $status[5]['met'] );
		$this->assertSame( 'terminal', $status[5]['method'] );
		$this->assertSame( 'Self-service terminal · ' . gmdate( 'Y-m-d H:i', self::NOW - 600 ), $status[5]['detail'] );
		$this->assertCount( 1, $this->attendance_queries, 'One query for every booking.' );
		$this->assertSame( array( 'wp_ffc_audience_attendance', self::USER, 5, 6 ), $this->attendance_queries[0]['args'] );
	}

	public function test_a_multi_day_event_counts_days_and_follows_its_rule(): void {
		$booking = (object) $this->booking(
			5,
			array(
				'end_date'               => '2027-01-17',
				'attendance_mode'        => 'total',
				'attendance_min_percent' => '100',
			)
		);
		$rows    = array(
			array(
				'date'       => '2027-01-15',
				'checked_at' => self::NOW,
				'method'     => 'qr',
			),
			array(
				'date'       => '2027-01-16',
				'checked_at' => self::NOW + 86400,
				'method'     => 'manual',
			),
		);

		$status = AudienceAttendanceStatus::of( $booking, $rows );

		$this->assertNotNull( $status );
		$this->assertSame( 'Present: 2 of 3 days', $status['label'] );
		$this->assertSame( 2, $status['days'] );
		$this->assertSame( 3, $status['total'] );
		$this->assertSame( 3, $status['required'] );
		$this->assertFalse( $status['met'], 'Two of three days do not meet a 100% rule.' );
		$this->assertSame( 'manual', $status['method'], 'The latest reading names the method.' );
		$this->assertNull( AudienceAttendanceStatus::of( $booking, array() ) );
	}

	public function test_without_a_user_or_a_booking_nothing_is_read(): void {
		$this->assertSame( array(), AudienceAttendanceStatus::for_bookings( 0, array( $this->booking( 5 ) ) ) );
		$this->assertSame( array(), AudienceAttendanceStatus::for_bookings( self::USER, array() ) );
		$this->assertSame( array(), $this->attendance_queries );
	}

	public function test_confirmations_cover_the_recent_days_newest_first(): void {
		$this->attendance = array(
			$this->line( 5, '2027-01-07', self::NOW - 8 * 86400 ),
			$this->line( 5, '2027-01-14', self::NOW - 86400, 'qr' ),
			$this->line( 6, '2027-01-15', self::NOW - 60 ),
		);
		$bookings         = array( $this->booking( 5 ), $this->booking( 6, array( 'description' => '', 'schedule_name' => 'Lab' ) ), $this->booking( 7, array( 'status' => 'cancelled' ) ) );

		$items = AudienceAttendanceStatus::confirmations( self::USER, $bookings, self::NOW );

		$this->assertSame( array( 'confirmed:6:2027-01-15', 'confirmed:5:2027-01-14' ), array_column( $items, 'key' ), 'A reading older than a week is left out.' );
		$this->assertSame( 'Attendance confirmed at Lab', $items[0]['text'], 'A booking without a description is named by its calendar.' );
		$this->assertSame( 'QR check-in · ' . gmdate( 'Y-m-d H:i', self::NOW - 86400 ), $items[1]['detail'] );
		$this->assertArrayNotHasKey( 'checked_at', $items[0] );
		$this->assertSame( array( 'wp_ffc_audience_attendance', self::USER, 5, 6 ), $this->attendance_queries[0]['args'], 'A cancelled booking is not read.' );
	}

	public function test_the_app_lists_confirmations_and_the_open_post_event_form(): void {
		$this->bookings   = array(
			$this->booking(
				5,
				array(
					'booking_date'              => '2027-01-14',
					'post_event_custom'         => 1,
					'post_event_form_id'        => self::FORM,
					'post_event_window_days'    => 7,
					'post_event_attendees_only' => 0,
				)
			),
		);
		$this->attendance = array( $this->line( 5, '2027-01-14', self::NOW - 86400 ) );

		$items = AudiencePresenceEvents::pending( self::USER, self::NOW );

		$this->assertSame( array( 'confirmed', 'form' ), array_column( $items, 'type' ) );
		$this->assertSame(
			array(
				'type'   => 'form',
				'key'    => 'form:5',
				'title'  => 'Library',
				'text'   => 'Post-event form · until ' . gmdate( 'Y-m-d H:i', strtotime( '2027-01-14 08:00:00 UTC' ) + 7 * 86400 ),
				'detail' => 'Evaluation',
				'url'    => self::PAGE_URL . '?ffc_invite=' . AudienceFormInvite::code( 5, self::USER, self::FORM ),
			),
			$items[1]
		);
	}

	public function test_the_app_lists_nothing_when_no_booking_is_recent(): void {
		$this->assertSame( array(), AudiencePresenceEvents::pending( self::USER, self::NOW ) );
	}
}
