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
 * @covers \FreeFormCertificate\Audience\AudiencePostEventForm
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudiencePostEventFormTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FORM = 40;

	/** @var \Mockery\MockInterface */
	private $schedules;

	/** @var \Mockery\MockInterface */
	private $attendance;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
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

		$this->schedules = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$this->schedules->shouldReceive( 'get_by_id' )->with( 2 )->andReturn(
			(object) array(
				'id'                        => 2,
				'post_event_form_id'        => (string) self::FORM,
				'post_event_window_days'    => '3',
				'post_event_attendees_only' => '0',
			)
		)->byDefault();

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' )
			->shouldReceive( 'get_all_affected_users' )->andReturn( array( '7', '8', '7' ) );

		$this->attendance = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceAttendanceReader' );
		$this->attendance->shouldReceive( 'days_present' )->andReturn( array() )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
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
		$this->attendance->shouldReceive( 'days_present' )->with( 5, 7 )->andReturn( array( '2027-03-01' ) );
		$this->attendance->shouldReceive( 'days_present' )->with( 5, 8 )->andReturn( array( '2027-03-01', '2027-03-02' ) );

		$absent = AudiencePostEventForm::eligibility( $booking, 7, $this->event_end() + 60 );
		$this->assertInstanceOf( \WP_Error::class, $absent );
		$this->assertSame( 'absent', $absent->get_error_code() );
		$this->assertTrue( AudiencePostEventForm::eligibility( $booking, 8, $this->event_end() + 60 ) );
	}
}
