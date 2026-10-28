<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudiencePostEventForm;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Which form a booking leads to, when it is open and who may fill it in (#1948).
 *
 * The calendar repository and the booking and attendance readers are the
 * real classes; their tables answer through the `$wpdb` double below, which
 * dispatches on the table each statement binds and on the ids it asks for,
 * and fails on anything else.
 *
 * @covers \FreeFormCertificate\Audience\AudiencePostEventForm
 */
class AudiencePostEventFormTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FORM = 40;

	/** @var array<int, list<string>> Days each person was recorded present at booking 5. */
	private array $present = array();

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				$post              = new \WP_Post();
				$post->ID          = (int) $id;
				$post->post_type   = 'ffc_form';
				$post->post_status = self::FORM === (int) $id ? 'publish' : 'draft';
				return $post;
			}
		);

		$this->present = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->bound = is_array( $args[0] ?? null ) ? $args[0] : $args;
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn( $sql ) => $this->row( (string) $sql ) );
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
	 * AudienceScheduleRepository::get_by_id(): calendar 2 names the form.
	 *
	 * @param string $sql Prepared statement.
	 * @return object
	 */
	private function row( string $sql ): object {
		if ( str_starts_with( $sql, 'SELECT * FROM %i WHERE id = %d' )
			&& array( 'wp_ffc_audience_schedules', 2 ) === $this->bound ) {
			return (object) array(
				'id'                        => '2',
				'post_event_form_id'        => (string) self::FORM,
				'post_event_window_days'    => '3',
				'post_event_attendees_only' => '0',
			);
		}

		throw new \RuntimeException( 'Unexpected get_row: ' . $sql . ' ' . json_encode( $this->bound ) );
	}

	/**
	 * The booking's users and the days a person was recorded present.
	 *
	 * @param string $sql Prepared statement.
	 * @return list<string>
	 */
	private function col( string $sql ): array {
		// AudienceBookingReader::get_booking_users(): 7 is listed twice, and
		// still counts once.
		if ( str_starts_with( $sql, 'SELECT user_id FROM %i WHERE booking_id = %d' )
			&& array( 'wp_ffc_audience_booking_users', 5 ) === $this->bound ) {
			return array( '7', '8', '7' );
		}

		// AudienceAttendanceReader::days_present().
		if ( str_starts_with( $sql, 'SELECT attendance_date FROM %i WHERE booking_id = %d AND user_id = %d' )
			&& 'wp_ffc_audience_attendance' === ( $this->bound[0] ?? null ) && 5 === ( $this->bound[1] ?? null ) ) {
			return $this->present[ (int) $this->bound[2] ] ?? array();
		}

		throw new \RuntimeException( 'Unexpected get_col: ' . $sql . ' ' . json_encode( $this->bound ) );
	}

	/**
	 * AudienceBookingReader::get_booking_audiences(): no audience on booking 5.
	 *
	 * @param string $sql Prepared statement.
	 * @return array<int, object>
	 */
	private function results( string $sql ): array {
		if ( str_starts_with( $sql, 'SELECT a.* FROM %i a' )
			&& array( 'wp_ffc_audiences', 'wp_ffc_audience_booking_audiences', 5 ) === $this->bound ) {
			return array();
		}

		throw new \RuntimeException( 'Unexpected get_results: ' . $sql . ' ' . json_encode( $this->bound ) );
	}

	private function booking( array $override = array() ): object {
		return (object) array_merge(
			array(
				'id'           => 5,
				'schedule_id'  => '2',
				'status'       => 'active',
				'booking_date' => '2027-03-01',
				'end_date'     => '2027-03-02',
				'start_time'   => '09:00:00',
				'end_time'     => '12:00:00',
			),
			$override
		);
	}

	/**
	 * 12:00 on 2027-03-02 in São Paulo (UTC-3).
	 */
	private function event_end(): int {
		return ( new \DateTimeImmutable( '2027-03-02 12:00', new \DateTimeZone( 'America/Sao_Paulo' ) ) )->getTimestamp();
	}

	public function test_a_booking_follows_its_calendar_until_its_own_settings_are_on(): void {
		$this->assertSame(
			array(
				'form_id'        => self::FORM,
				'window_days'    => 3,
				'attendees_only' => false,
			),
			AudiencePostEventForm::settings( $this->booking() )
		);

		$own = $this->booking(
			array(
				'post_event_custom'         => '1',
				'post_event_form_id'        => '0',
				'post_event_window_days'    => '500',
				'post_event_attendees_only' => '1',
			)
		);
		$this->assertSame(
			array(
				'form_id'        => 0,
				'window_days'    => AudiencePostEventForm::MAX_WINDOW_DAYS,
				'attendees_only' => true,
			),
			AudiencePostEventForm::settings( $own ),
			'Its own settings apply, None included, and the window is clamped.'
		);
	}

	public function test_a_calendar_without_a_saved_window_uses_the_default(): void {
		$this->assertSame( AudiencePostEventForm::DEFAULT_WINDOW_DAYS, AudiencePostEventForm::of_schedule( (object) array() )['window_days'] );
		$this->assertSame( 0, AudiencePostEventForm::of_schedule( null )['form_id'] );
		$this->assertSame( 1, AudiencePostEventForm::normalize_window( 0 ) );
	}

	public function test_only_a_published_form_counts(): void {
		$this->assertTrue( AudiencePostEventForm::is_form( self::FORM ) );
		$this->assertFalse( AudiencePostEventForm::is_form( 41 ), 'A draft form invites nobody.' );
		$this->assertFalse( AudiencePostEventForm::is_form( 0 ) );
	}

	public function test_the_window_opens_when_the_last_day_ends_in_the_site_timezone(): void {
		$this->assertSame( $this->event_end(), AudiencePostEventForm::opens_at( $this->booking() ) );
		$this->assertSame( $this->event_end() + 3 * DAY_IN_SECONDS, AudiencePostEventForm::closes_at( $this->booking(), 3 ) );

		$all_day = AudiencePostEventForm::opens_at( $this->booking( array( 'is_all_day' => '1' ) ) );
		$this->assertSame( $this->event_end() + 11 * HOUR_IN_SECONDS + 59 * MINUTE_IN_SECONDS, $all_day, 'An all-day booking ends at 23:59.' );

		$skipped = $this->booking( array( 'skipped_dates' => '["2027-03-02"]' ) );
		$this->assertSame( $this->event_end() - DAY_IN_SECONDS, AudiencePostEventForm::opens_at( $skipped ), 'A skipped last day is not the end of the event.' );
	}

	public function test_a_participant_may_answer_inside_the_window(): void {
		$this->assertTrue( AudiencePostEventForm::eligibility( $this->booking(), 7, $this->event_end() + 60 ) );
		$this->assertSame( array( 7, 8 ), AudiencePostEventForm::participants( 5 ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: int, 2: int, 3: string}>
	 */
	public function refusals(): array {
		return array(
			'someone outside the booking' => array( array(), 99, 60, 'not_participant' ),
			'before the event ends'       => array( array(), 7, -60, 'not_open' ),
			'after the window closes'     => array( array(), 7, 3 * 86400, 'closed' ),
			'a cancelled booking'         => array( array( 'status' => 'cancelled' ), 7, 60, 'cancelled' ),
			'a booking set to no form'    => array(
				array(
					'post_event_custom'  => '1',
					'post_event_form_id' => '0',
				),
				7,
				60,
				'no_form',
			),
		);
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param array<string, mixed> $override Booking columns.
	 * @param int                  $user_id  Person.
	 * @param int                  $offset   Seconds after the event ends.
	 * @param string               $code     Expected refusal.
	 */
	public function test_each_refusal_names_its_reason( array $override, int $user_id, int $offset, string $code ): void {
		$result = AudiencePostEventForm::eligibility( $this->booking( $override ), $user_id, $this->event_end() + $offset );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertNotSame( '', AudiencePostEventForm::message( $code ) );
	}

	public function test_attendees_only_refuses_someone_whose_attendance_misses_the_rule(): void {
		$booking = $this->booking(
			array(
				'post_event_custom'         => '1',
				'post_event_form_id'        => (string) self::FORM,
				'post_event_window_days'    => '7',
				'post_event_attendees_only' => '1',
				'attendance_mode'           => 'total',
				'attendance_min_percent'    => '100',
			)
		);
		$this->present = array(
			7 => array( '2027-03-01' ),
			8 => array( '2027-03-01', '2027-03-02' ),
		);

		$absent = AudiencePostEventForm::eligibility( $booking, 7, $this->event_end() + 60 );
		$this->assertInstanceOf( \WP_Error::class, $absent );
		$this->assertSame( 'absent', $absent->get_error_code() );
		$this->assertTrue( AudiencePostEventForm::eligibility( $booking, 8, $this->event_end() + 60 ) );
	}
}
