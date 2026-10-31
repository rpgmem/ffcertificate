<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\SelfScheduling\CalendarBlockedDates;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The per-calendar Blocked dates tab's two AJAX actions (#2244).
 *
 * `wp_send_json_*()` throw, which is how a test reads what the request
 * answered and how a refusal stops before the code that follows it.
 *
 * @covers \FreeFormCertificate\SelfScheduling\CalendarBlockedDates
 */
class CalendarBlockedDatesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $blocks;

	/** @var Mockery\MockInterface */
	private $appointments;

	/** @var Mockery\MockInterface */
	private $calendars;

	/** @var Mockery\MockInterface */
	private $actions;

	private CalendarBlockedDates $handler;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 4 );
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) {
				throw new \RuntimeException( 'success:' . json_encode( $data ) );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ) {
				throw new \RuntimeException( 'error:' . $status . ':' . json_encode( $data ) );
			}
		);

		$this->blocks       = Mockery::mock( 'FreeFormCertificate\Repositories\BlockedDateRepository' );
		$this->appointments = Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' );
		$this->calendars    = Mockery::mock( 'FreeFormCertificate\Repositories\CalendarRepository' );
		$this->actions      = Mockery::mock( 'FreeFormCertificate\SelfScheduling\AppointmentAdminActions' );
		$this->calendars->shouldReceive( 'findByPostId' )->with( 20 )->andReturn(
			array(
				'id'           => 5,
				'post_id'      => 20,
				'title'        => 'Clinic',
				'email_config' => (string) json_encode( array( 'send_cancellation_notification' => 1 ) ),
			)
		);

		$this->handler = new CalendarBlockedDates( $this->blocks, $this->appointments, $this->calendars, $this->actions );
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Run a handler with the given POST and return what it answered.
	 *
	 * @param string               $method handle_add | handle_delete.
	 * @param array<string, mixed> $post   Request body.
	 * @return string `success:{json}` or `error:{status}:{json}`.
	 */
	private function answer( string $method, array $post ): string {
		$_POST = array_merge( array( 'post_id' => '20' ), $post );
		try {
			$this->handler->$method();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'The handler did not answer.' );
	}

	public function test_a_bad_nonce_or_missing_cap_is_refused_before_anything_is_read(): void {
		$this->blocks->shouldReceive( 'isCovered' )->never();
		Functions\when( 'check_ajax_referer' )->justReturn( false );
		$this->assertStringStartsWith( 'error:403:', $this->answer( 'handle_add', array( 'start_date' => '2032-05-20' ) ) );

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertStringStartsWith( 'error:403:', $this->answer( 'handle_delete', array( 'block_id' => '9' ) ) );
	}

	/** @return array<string, array{array<string, string>, string}> */
	public function bad_requests(): array {
		return array(
			'no date'          => array( array( 'start_date' => '' ), 'Choose a valid date.' ),
			'not a date'       => array( array( 'start_date' => '2032-02-30' ), 'Choose a valid date.' ),
			'end before start' => array( array( 'start_date' => '2032-05-20', 'end_date' => '2032-05-19' ), 'The last day must be a valid date on or after the first.' ),
			'too long'         => array( array( 'start_date' => '2032-01-01', 'end_date' => '2033-02-01' ), 'One block covers at most 366 days.' ),
			'times reversed'   => array( array( 'block_type' => 'time_range', 'start_date' => '2032-05-20', 'start_time' => '12:00', 'end_time' => '09:00' ), 'Choose a start time before the end time.' ),
			'time garbage'     => array( array( 'block_type' => 'time_range', 'start_date' => '2032-05-20', 'start_time' => 'nine', 'end_time' => '10:00' ), 'Choose a start time before the end time.' ),
		);
	}

	/**
	 * @dataProvider bad_requests
	 *
	 * @param array<string, string> $post    Request body.
	 * @param string                $message Expected refusal.
	 */
	public function test_an_invalid_block_is_refused( array $post, string $message ): void {
		$this->blocks->shouldReceive( 'isCovered' )->never();

		$answer = $this->answer( 'handle_add', $post );

		$this->assertStringStartsWith( 'error:400:', $answer );
		$this->assertStringContainsString( json_encode( $message ), $answer );
	}

	public function test_a_duplicate_is_refused(): void {
		$this->blocks->shouldReceive( 'isCovered' )->once()->with( 5, 'full_day', '2032-05-20', '2032-05-20', null, null )->andReturn( true );
		$this->blocks->shouldReceive( 'createFullDayBlock' )->never();

		$this->assertStringStartsWith( 'error:409:', $this->answer( 'handle_add', array( 'start_date' => '2032-05-20' ) ) );
	}

	public function test_a_block_reaching_appointments_asks_first_and_creates_nothing(): void {
		$this->blocks->shouldReceive( 'isCovered' )->andReturn( false );
		$this->appointments->shouldReceive( 'getAppointmentsByDateRange' )
			->with( 5, '2032-05-20', '2032-05-21', array( 'pending', 'confirmed', 'waitlist' ) )
			->andReturn( array( array( 'id' => 1 ), array( 'id' => 2 ) ) );
		$this->blocks->shouldReceive( 'createFullDayBlock' )->never();
		$this->actions->shouldReceive( 'cancel' )->never();

		$answer = $this->answer( 'handle_add', array( 'start_date' => '2032-05-20', 'end_date' => '2032-05-21' ) );

		$this->assertSame( 'success:' . json_encode( array( 'needs_confirm' => true, 'count' => 2, 'notify' => true, 'title' => 'Clinic' ) ), $answer );
	}

	public function test_a_count_that_moved_since_the_confirmation_is_refused(): void {
		$this->blocks->shouldReceive( 'isCovered' )->andReturn( false );
		$this->appointments->shouldReceive( 'getAppointmentsByDateRange' )->andReturn( array( array( 'id' => 1 ), array( 'id' => 2 ), array( 'id' => 3 ) ) );
		$this->blocks->shouldReceive( 'createFullDayBlock' )->never();

		$answer = $this->answer( 'handle_add', array( 'start_date' => '2032-05-20', 'confirm' => '1', 'expected' => '2' ) );

		$this->assertStringStartsWith( 'error:409:', $answer );
	}

	public function test_a_confirmed_block_is_created_first_then_each_appointment_cancelled_with_the_reason(): void {
		$order = array();
		$this->blocks->shouldReceive( 'isCovered' )->andReturn( false );
		$this->appointments->shouldReceive( 'getAppointmentsByDateRange' )->andReturn( array( array( 'id' => 1 ), array( 'id' => 2 ) ) );
		$this->blocks->shouldReceive( 'createFullDayBlock' )->once()->with( 5, '2032-05-20', null, 'Flooding' )
			->andReturnUsing(
				static function () use ( &$order ) {
					$order[] = 'block';
					return 77;
				}
			);
		$this->actions->shouldReceive( 'cancel' )->twice()->with( Mockery::anyOf( 1, 2 ), 4, 'The calendar was closed: Flooding' )
			->andReturnUsing(
				static function ( $id ) use ( &$order ) {
					$order[] = 'cancel ' . $id;
					return 1 === $id ? true : new \WP_Error( 'status_changed', 'moved' );
				}
			);

		$answer = $this->answer( 'handle_add', array( 'start_date' => '2032-05-20', 'reason' => ' Flooding ', 'confirm' => '1', 'expected' => '2' ) );

		$this->assertSame( array( 'block', 'cancel 1', 'cancel 2' ), $order );
		$this->assertSame( 'success:' . json_encode( array( 'message' => 'Blocked. 1 appointment was cancelled.' ) ), $answer, 'Only the cancel that went through is counted.' );
	}

	public function test_a_time_range_cancels_only_the_appointments_it_overlaps(): void {
		$this->blocks->shouldReceive( 'isCovered' )->with( 5, 'time_range', '2032-05-20', '2032-05-20', '10:00:00', '12:00:00' )->andReturn( false );
		$this->appointments->shouldReceive( 'getAppointmentsByDateRange' )->andReturn(
			array(
				array( 'id' => 1, 'appointment_date' => '2032-05-20', 'start_time' => '09:30:00', 'end_time' => '10:30:00' ),
				array( 'id' => 2, 'appointment_date' => '2032-05-20', 'start_time' => '12:00:00', 'end_time' => '12:30:00' ),
			)
		);
		$this->blocks->shouldReceive( 'createTimeRangeBlock' )->once()->with( 5, '2032-05-20', '10:00:00', '12:00:00', '' )->andReturn( 8 );
		$this->actions->shouldReceive( 'cancel' )->once()->with( 1, 4, Mockery::type( 'string' ) )->andReturn( true );

		$answer = $this->answer( 'handle_add', array( 'block_type' => 'time_range', 'start_date' => '2032-05-20', 'start_time' => '10:00', 'end_time' => '12:00', 'confirm' => '1', 'expected' => '1' ) );

		$this->assertStringStartsWith( 'success:', $answer );
	}

	public function test_a_block_reaching_nothing_is_created_without_asking(): void {
		$this->blocks->shouldReceive( 'isCovered' )->andReturn( false );
		$this->appointments->shouldReceive( 'getAppointmentsByDateRange' )->andReturn( array() );
		$this->blocks->shouldReceive( 'createFullDayBlock' )->once()->andReturn( 3 );
		$this->actions->shouldReceive( 'cancel' )->never();

		$this->assertSame( 'success:' . json_encode( array( 'message' => 'Blocked.' ) ), $this->answer( 'handle_add', array( 'start_date' => '2032-05-20' ) ) );
	}

	public function test_a_failed_insert_is_reported(): void {
		$this->blocks->shouldReceive( 'isCovered' )->andReturn( false );
		$this->appointments->shouldReceive( 'getAppointmentsByDateRange' )->andReturn( array() );
		$this->blocks->shouldReceive( 'createFullDayBlock' )->once()->andReturn( false );

		$this->assertStringStartsWith( 'error:500:', $this->answer( 'handle_add', array( 'start_date' => '2032-05-20' ) ) );
	}

	public function test_delete_removes_only_the_calendars_block(): void {
		$this->blocks->shouldReceive( 'deleteForCalendar' )->once()->with( 5, 9 )->andReturn( true, false );

		$this->assertStringStartsWith( 'success:', $this->answer( 'handle_delete', array( 'block_id' => '9' ) ) );
	}

	public function test_delete_of_a_block_it_does_not_find_is_reported(): void {
		$this->blocks->shouldReceive( 'deleteForCalendar' )->once()->andReturn( false );

		$this->assertStringStartsWith( 'error:404:', $this->answer( 'handle_delete', array( 'block_id' => '9' ) ) );
	}

	public function test_a_calendar_it_cannot_find_is_reported(): void {
		$this->calendars->shouldReceive( 'findByPostId' )->with( 21 )->andReturn( null );
		$this->assertStringStartsWith( 'error:404:', $this->answer( 'handle_delete', array( 'post_id' => '21', 'block_id' => '9' ) ) );
	}
}
