<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAdminAttendance;
use FreeFormCertificate\Audience\AudienceAttendanceAjax;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The attendance screen's writes, its day choice, its render and the poll
 * it shares with the projected QR (#1947).
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminAttendance
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceAjax
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAdminAttendanceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const NOW = 1800000000;

	private const TODAY = '2027-03-01';

	/** @var \Mockery\MockInterface */
	private $windows;

	/** @var \Mockery\MockInterface */
	private $window_writer;

	/** @var \Mockery\MockInterface */
	private $writer;

	/** @var \Mockery\MockInterface */
	private $bookings;

	/** @var \Mockery\MockInterface */
	private $booking_writer;

	/** @var list<string> */
	private array $caps = array( 'ffc_checkin_audiences' );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\\Audience\\AudienceAttendanceWriter' => array(
					'RECORDED'      => 'recorded',
					'ALREADY'       => 'already',
					'FAILED'        => 'failed',
					'METHOD_QR'     => 'qr',
					'METHOD_MANUAL' => 'manual',
				),
			)
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_time' )->justReturn( self::TODAY );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.org/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$a ): string {
				return is_array( $a[0] ) ? $a[1] . '?' . http_build_query( $a[0] ) : $a[2] . '?' . $a[0] . '=' . $a[1];
			}
		);
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'disabled' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input type="hidden" name="_wpnonce" value="n">' );

		$caps = &$this->caps;
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )
			->andReturnUsing(
				static function ( string $cap ) use ( &$caps ): bool {
					return in_array( $cap, $caps, true );
				}
			);

		$dates = Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' );
		$dates->shouldReceive( 'format_time' )->andReturn( '09:30' );
		$dates->shouldReceive( 'format_wallclock_date' )->andReturnUsing( static fn( $d ) => 'D:' . $d );
		$dates->shouldReceive( 'format_datetime' )->andReturnUsing( static fn( int $t ) => gmdate( 'Y-m-d H:i', $t ) );

		$this->windows        = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceCheckinWindowReader' );
		$this->window_writer  = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceCheckinWindowWriter' );
		$this->writer         = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceAttendanceWriter' );
		$this->bookings       = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$this->booking_writer = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingWriter' );

		$this->bookings->shouldReceive( 'get_all_affected_users' )->andReturn( array( '7', '8' ) )->byDefault();
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		Mockery::getConfiguration()->setConstantsMap( array() );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function booking( array $override = array() ): object {
		return (object) array_merge(
			array(
				'id'               => 5,
				'status'           => 'active',
				'booking_date'     => self::TODAY,
				'end_date'         => '2027-03-03',
				'description'      => 'Workshop',
				'environment_name' => 'Room 3',
				'start_time'       => '09:00:00',
				'end_time'         => '12:00:00',
			),
			$override
		);
	}

	private function page(): AudienceAdminAttendance {
		return new AudienceAdminAttendance( 'ffc-scheduling' );
	}

	public function test_the_day_shown_is_the_one_asked_else_today_else_the_next_else_the_last(): void {
		$days = array( '2027-03-01', '2027-03-03', '2027-03-05' );

		$this->assertSame( '2027-03-05', AudienceAdminAttendance::resolve_day( $days, '2027-03-05', '2027-03-03' ) );
		$this->assertSame( '2027-03-03', AudienceAdminAttendance::resolve_day( $days, 'bogus', '2027-03-03' ) );
		$this->assertSame( '2027-03-03', AudienceAdminAttendance::resolve_day( $days, '', '2027-03-02' ) );
		$this->assertSame( '2027-03-05', AudienceAdminAttendance::resolve_day( $days, '', '2027-04-01' ) );
		$this->assertSame( '', AudienceAdminAttendance::resolve_day( array(), '', '2027-04-01' ) );
	}

	public function test_open_keeps_only_known_locations_and_closes_on_its_own(): void {
		$_POST = array(
			'rotation_seconds'   => '300',
			'geofence_locations' => array( 'loc_a', 'loc_forged' ),
		);
		Mockery::mock( 'alias:FreeFormCertificate\Security\GeofenceLocationRegistry' )
			->shouldReceive( 'get_all' )->andReturn( array( array( 'id' => 'loc_a' ) ) );
		$this->window_writer->shouldReceive( 'auto_close_at' )->andReturn( self::NOW + 9000 );
		$this->window_writer->shouldReceive( 'open' )
			->once()
			->with( 5, self::TODAY, 300, self::NOW + 9000, 3, self::NOW, array( 'loc_a' ) )
			->andReturn( 11 );

		$this->assertSame( 'opened', $this->page()->perform( 'open', $this->booking(), self::TODAY, self::NOW ) );
	}

	public function test_open_is_refused_with_its_reason_once_the_day_has_closed(): void {
		$this->window_writer->shouldReceive( 'auto_close_at' )->andReturn( self::NOW - 60 );
		$this->window_writer->shouldNotReceive( 'open' );

		$this->assertSame( 'ended', $this->page()->perform( 'open', $this->booking(), self::TODAY, self::NOW ) );
		$this->assertSame( 'warning', AudienceAdminAttendance::messages()['ended'][1] );
	}

	public function test_open_is_refused_on_another_day_and_for_a_cancelled_booking(): void {
		$this->window_writer->shouldNotReceive( 'open' );

		$this->assertSame( 'not_today', $this->page()->perform( 'open', $this->booking(), '2027-03-02', self::NOW ) );
		$this->assertSame( 'not_today', $this->page()->perform( 'open', $this->booking( array( 'status' => 'cancelled' ) ), self::TODAY, self::NOW ) );
	}

	public function test_close_closes_the_open_window_and_reports_when_none_was(): void {
		$this->windows->shouldReceive( 'open_for' )->andReturn( array( 'id' => 11 ), null );
		$this->window_writer->shouldReceive( 'close' )->once()->with( 11, 3, self::NOW )->andReturn( true );

		$this->assertSame( 'closed', $this->page()->perform( 'close', $this->booking(), self::TODAY, self::NOW ) );
		$this->assertSame( 'not_open', $this->page()->perform( 'close', $this->booking(), self::TODAY, self::NOW ) );
	}

	public function test_mark_records_by_hand_with_who_marked_it(): void {
		$_POST = array( 'mark_user' => '7' );
		$this->writer->shouldReceive( 'record' )->once()->with( 5, 7, self::TODAY, 'manual', 3, self::NOW )->andReturn( 'recorded' );

		$this->assertSame( 'marked', $this->page()->perform( 'mark', $this->booking(), self::TODAY, self::NOW ) );
	}

	public function test_mark_refuses_a_stranger_and_a_future_day(): void {
		$_POST = array( 'mark_user' => '99' );
		$this->writer->shouldNotReceive( 'record' );

		$this->assertSame( 'failed', $this->page()->perform( 'mark', $this->booking(), self::TODAY, self::NOW ) );
		$this->assertSame( 'future', $this->page()->perform( 'mark', $this->booking(), '2027-03-03', self::NOW ) );
	}

	public function test_unmark_removes_the_line(): void {
		$_POST = array( 'unmark_user' => '7' );
		$this->writer->shouldReceive( 'remove' )->once()->with( 5, 7, self::TODAY )->andReturn( true );

		$this->assertSame( 'unmarked', $this->page()->perform( 'unmark', $this->booking(), self::TODAY, self::NOW ) );
	}

	public function test_the_rule_needs_the_manage_capability_and_is_normalised(): void {
		$_POST = array(
			'attendance_mode'        => 'total',
			'attendance_min_percent' => '250',
		);
		$this->booking_writer->shouldReceive( 'set_attendance_rule' )->once()->with( 5, 'total', 100 )->andReturn( true );

		$this->assertSame( 'denied', $this->page()->perform( 'rule', $this->booking(), self::TODAY, self::NOW ) );

		$this->caps[] = 'ffc_manage_audiences';
		$this->assertSame( 'rule_saved', $this->page()->perform( 'rule', $this->booking(), self::TODAY, self::NOW ) );
	}

	public function test_the_post_event_form_needs_the_manage_capability_and_a_real_form(): void {
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				$post              = new \WP_Post();
				$post->post_type   = 'ffc_form';
				$post->post_status = 40 === (int) $id ? 'publish' : 'draft';
				return $post;
			}
		);
		$_POST = array(
			'post_event_custom'         => '1',
			'post_event_form'           => '40',
			'post_event_window_days'    => '10',
			'post_event_attendees_only' => '1',
		);
		$this->booking_writer->shouldReceive( 'set_post_event_form' )->once()->with( 5, true, 40, 10, true )->andReturn( true );

		$this->assertSame( 'denied', $this->page()->perform( 'post_event', $this->booking(), self::TODAY, self::NOW ) );

		$this->caps[] = 'ffc_manage_audiences';
		$this->assertSame( 'post_event_saved', $this->page()->perform( 'post_event', $this->booking(), self::TODAY, self::NOW ) );

		$_POST['post_event_form'] = '41';
		$this->assertSame( 'failed', $this->page()->perform( 'post_event', $this->booking(), self::TODAY, self::NOW ), 'A draft or unknown form is refused.' );
	}

	public function test_turning_the_switch_off_follows_the_calendar_again(): void {
		$this->caps[] = 'ffc_manage_audiences';
		$_POST        = array(
			'post_event_form'        => '0',
			'post_event_window_days' => '7',
		);
		$this->booking_writer->shouldReceive( 'set_post_event_form' )->once()->with( 5, false, 0, 7, false )->andReturn( true );

		$this->assertSame( 'post_event_saved', $this->page()->perform( 'post_event', $this->booking(), self::TODAY, self::NOW ) );
	}

	public function test_the_post_event_status_says_which_form_and_when(): void {
		Functions\when( 'get_post' )->alias(
			static function () {
				$post              = new \WP_Post();
				$post->post_type   = 'ffc_form';
				$post->post_status = 'publish';
				return $post;
			}
		);
		Functions\when( 'get_the_title' )->justReturn( 'Evaluation' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$applied = array(
			'form_id'        => 40,
			'window_days'    => 2,
			'attendees_only' => false,
		);
		$status  = AudienceAdminAttendance::post_event_status( $this->booking(), $applied, false );

		$this->assertSame( 'Participants are invited to "Evaluation", open from 2027-03-03 12:00 to 2027-03-05 12:00.', $status );

		$applied['form_id'] = 0;
		$this->assertSame( 'This booking invites nobody to a form.', AudienceAdminAttendance::post_event_status( $this->booking(), $applied, true ) );
		$this->assertSame( 'Its calendar names no form, so this booking invites nobody.', AudienceAdminAttendance::post_event_status( $this->booking(), $applied, false ) );
	}

	public function test_export_needs_the_export_capability_and_an_unknown_action_fails(): void {
		$this->assertSame( 'denied', $this->page()->perform( 'export', $this->booking(), self::TODAY, self::NOW ) );
		$this->assertSame( 'failed', $this->page()->perform( 'bogus', $this->booking(), self::TODAY, self::NOW ) );
	}

	public function test_every_redirect_message_exists(): void {
		foreach ( array( 'opened', 'closed', 'marked', 'unmarked', 'rule_saved', 'post_event_saved', 'invite_sent', 'invite_sent_all', 'invite_none', 'invite_not_eligible', 'invite_no_page', 'invite_failed', 'ended', 'not_today', 'not_open', 'future', 'denied', 'failed' ) as $key ) {
			$this->assertArrayHasKey( $key, AudienceAdminAttendance::messages() );
		}
	}

	/**
	 * The post-event form's eligibility and mailer, answering per person.
	 *
	 * @param array<int, string|true> $eligible User ID => true or an error code.
	 * @param array<int, string|true> $sends    User ID => true or an error code.
	 * @return \Mockery\MockInterface The mailer.
	 */
	private function invitations( array $eligible, array $sends ) {
		$form = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudiencePostEventForm' );
		$form->shouldReceive( 'participants' )->andReturn( array_keys( $eligible ) );
		$form->shouldReceive( 'eligibility' )->andReturnUsing(
			static fn( $booking, int $user ) => true === ( $eligible[ $user ] ?? 'not_participant' ) ? true : new \WP_Error( $eligible[ $user ] ?? 'not_participant', 'No.' )
		);
		$mailer = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceFormInviteMailer' );
		$mailer->shouldReceive( 'send' )->andReturnUsing(
			static fn( $booking, int $user ) => true === ( $sends[ $user ] ?? true ) ? true : new \WP_Error( $sends[ $user ], 'No.' )
		);
		return $mailer;
	}

	public function test_a_resend_goes_only_to_an_eligible_person_and_reports_why_not(): void {
		$mailer = $this->invitations(
			array(
				7 => true,
				8 => 'absent',
				9 => true,
			),
			array( 9 => 'no_page' )
		);

		$_POST = array( 'invite_user' => '7' );
		$this->assertSame( 'invite_sent', $this->page()->perform( 'invite', $this->booking(), self::TODAY, self::NOW ) );

		$_POST = array( 'invite_user' => '8' );
		$this->assertSame( 'invite_not_eligible', $this->page()->perform( 'invite', $this->booking(), self::TODAY, self::NOW ) );

		$_POST = array( 'invite_user' => '9' );
		$this->assertSame( 'invite_no_page', $this->page()->perform( 'invite', $this->booking(), self::TODAY, self::NOW ) );

		$_POST = array( 'invite_user' => '12' );
		$this->assertSame( 'invite_not_eligible', $this->page()->perform( 'invite', $this->booking(), self::TODAY, self::NOW ), 'A forged id is not on the booking.' );

		$mailer->shouldHaveReceived( 'send' )->twice();
	}

	public function test_sending_to_everyone_skips_who_answered_and_who_is_not_eligible(): void {
		$mailer = $this->invitations(
			array(
				7 => true,
				8 => 'absent',
				9 => true,
			),
			array()
		);
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceFormInviteReader' );
		$reader->shouldReceive( 'answers' )->with( 5 )->andReturn( array( 9 => 'publish' ) );

		$this->assertSame( 'invite_sent_all', $this->page()->perform( 'invite_all', $this->booking(), self::TODAY, self::NOW ) );
		$mailer->shouldHaveReceived( 'send' )->once()->with( Mockery::any(), 7, self::NOW );
	}

	public function test_sending_to_everyone_says_when_nobody_was_left(): void {
		$this->invitations( array( 8 => 'absent' ), array() );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceFormInviteReader' )
			->shouldReceive( 'answers' )->andReturn( array() );

		$this->assertSame( 'invite_none', $this->page()->perform( 'invite_all', $this->booking(), self::TODAY, self::NOW ) );
	}

	public function test_the_tracking_cells_say_what_was_sent_and_answered(): void {
		$this->assertSame( 'Not yet', AudienceAdminAttendance::invite_cell( null ) );
		$this->assertSame(
			'2027-01-15 08:00',
			AudienceAdminAttendance::invite_cell(
				array(
					'sent_at'    => self::NOW,
					'send_count' => 1,
				)
			)
		);
		$this->assertSame(
			'2027-01-15 08:00 (sent 3 times)',
			AudienceAdminAttendance::invite_cell(
				array(
					'sent_at'    => self::NOW,
					'send_count' => 3,
				)
			)
		);

		$this->assertSame( 'Not yet', AudienceAdminAttendance::answer_label( null ) );
		$this->assertSame( 'Answered', AudienceAdminAttendance::answer_label( 'pending' ) );
		$this->assertSame( 'Certificate issued', AudienceAdminAttendance::answer_label( 'publish' ) );
	}

	public function test_the_screen_lists_invitations_once_a_form_applies(): void {
		$this->roster_present_for_7();
		$_GET    = array( 'booking_id' => '5' );
		$booking = $this->booking(
			array(
				'post_event_custom'         => '1',
				'post_event_form_id'        => '40',
				'post_event_window_days'    => '7',
				'post_event_attendees_only' => '0',
			)
		);
		$this->bookings->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $booking );
		$this->windows->shouldReceive( 'open_for' )->andReturn( null );
		$this->window_writer->shouldReceive( 'auto_close_at' )->andReturn( self::NOW + 9000 );
		Mockery::mock( 'alias:FreeFormCertificate\Security\GeofenceLocationRegistry' )
			->shouldReceive( 'get_all' )->andReturn( array() );
		$ui = Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminUI' );
		$ui->shouldReceive( 'render_button' )->andReturnUsing(
			static function ( array $args ): void {
				echo '<button data-label="' . $args['label'] . '"></button>';
			}
		);
		$ui->shouldReceive( 'render_segmented' );
		$ui->shouldReceive( 'render_toggle' );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_the_title' )->justReturn( 'Evaluation' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_post' )->alias(
			static function () {
				$post              = new \WP_Post();
				$post->post_type   = 'ffc_form';
				$post->post_status = 'publish';
				return $post;
			}
		);
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceFormInviteReader' );
		$reader->shouldReceive( 'for_booking' )->andReturn(
			array(
				7 => array(
					'sent_at'    => self::NOW,
					'send_count' => 2,
				),
			)
		);
		$reader->shouldReceive( 'answers' )->andReturn( array( 7 => 'publish' ) );

		ob_start();
		$this->page()->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-attendance-invites', $html );
		$this->assertStringContainsString( 'name="ffc_attendance_action" value="invite"', $html );
		$this->assertStringContainsString( '(sent 2 times)', $html );
		$this->assertStringContainsString( 'Certificate issued', $html );
		$this->assertStringContainsString( 'Invitations go out on their own once the event ends', $html, 'Before the event ends nothing can be sent.' );
		$this->assertStringNotContainsString( 'data-label="Send to everyone who has not answered"', $html );
	}

	public function test_a_status_cell_says_when_and_how(): void {
		$this->assertStringContainsString( '—', AudienceAdminAttendance::status_html( null, null ) );
		$this->assertStringContainsString( 'by hand', AudienceAdminAttendance::status_html( self::NOW, 'manual' ) );
		$this->assertStringContainsString( '09:30', AudienceAdminAttendance::status_html( self::NOW, 'qr' ) );
	}

	/**
	 * The roster a render or a poll reads.
	 */
	private function roster_present_for_7(): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceAttendanceReader' );
		$reader->shouldReceive( 'days_by_user' )->andReturn( array( 7 => array( self::TODAY ) ) );
		$reader->shouldReceive( 'for_day' )->andReturn(
			array(
				array(
					'user_id'    => 7,
					'checked_at' => self::NOW,
					'method'     => 'qr',
				),
			)
		);
		Functions\when( 'get_users' )->justReturn(
			array(
				(object) array(
					'ID'           => 7,
					'display_name' => 'Ana',
				),
				(object) array(
					'ID'           => 8,
					'display_name' => 'Bia',
				),
			)
		);
	}

	public function test_the_poll_reports_who_is_present_and_the_qr_only_while_open(): void {
		$this->roster_present_for_7();
		$window = array(
			'id'                 => 11,
			'booking_id'         => 5,
			'window_date'        => self::TODAY,
			'rotation_seconds'   => 60,
			'opened_at'          => self::NOW - 60,
			'opened_by'          => 3,
			'closes_at'          => self::NOW + 3600,
			'closed_at'          => null,
			'closed_by'          => null,
			'geofence_locations' => array(),
		);
		$this->windows->shouldReceive( 'open_for' )->andReturn( $window, null );
		$this->windows->shouldReceive( 'is_open' )->andReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.org' . $p );

		$open   = AudienceAttendanceAjax::state( $this->booking(), self::TODAY, true, self::NOW + 15 );
		$closed = AudienceAttendanceAjax::state( $this->booking(), self::TODAY, true, self::NOW + 15 );

		$this->assertSame( 1, $open['present'] );
		$this->assertSame( 2, $open['total'] );
		$this->assertSame( 7, $open['rows'][0]['user_id'] );
		$this->assertTrue( $open['open'] );
		$this->assertStringStartsWith( '<svg', $open['qr'] );
		$this->assertSame( 45, $open['seconds_remaining'] );
		$this->assertSame( 60, $open['rotation'] );
		$this->assertFalse( $closed['open'] );
		$this->assertArrayNotHasKey( 'qr', $closed );
	}

	public function test_the_screen_draws_the_open_form_today_and_the_roster(): void {
		$this->roster_present_for_7();
		$_GET = array( 'booking_id' => '5' );
		$this->bookings->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $this->booking() );
		$this->windows->shouldReceive( 'open_for' )->andReturn( null );
		$this->window_writer->shouldReceive( 'auto_close_at' )->andReturn( self::NOW + 9000 );
		Mockery::mock( 'alias:FreeFormCertificate\Security\GeofenceLocationRegistry' )
			->shouldReceive( 'get_all' )->andReturn( array() );
		$ui = Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminUI' );
		$ui->shouldReceive( 'render_button' )->andReturnUsing(
			static function ( array $args ): void {
				echo '<button data-label="' . $args['label'] . '"></button>';
			}
		);
		$ui->shouldReceive( 'render_segmented' );
		$ui->shouldReceive( 'render_toggle' );
		Functions\when( 'get_posts' )->justReturn( array() );

		ob_start();
		$this->page()->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-page-scheduling-attendance', $html );
		$this->assertStringContainsString( 'name="ffc_attendance_action" value="post_event"', $html );
		$this->assertStringContainsString( 'Its calendar names no form, so this booking invites nobody.', $html );
		$this->assertStringContainsString( 'name="ffc_attendance_action" value="open"', $html );
		$this->assertStringContainsString( 'data-label="Open check-in"', $html );
		$this->assertStringContainsString( 'name="rotation_seconds"', $html );
		$this->assertStringNotContainsString( 'ffc-attendance-geofence', $html, 'No saved location, no geofence choice.' );
		$this->assertStringContainsString( '<tr data-user-id="7">', $html );
		$this->assertStringContainsString( 'data-label="Remove"', $html );
		$this->assertStringContainsString( 'data-label="Mark present"', $html );
		$this->assertStringContainsString( 'id="ffc-attendance-date"', $html, 'A booking over several days has a day picker.' );
		$this->assertMatchesRegularExpression( '/name="attendance_min_percent"[^>]*value="75"/', $html, 'Under "Any day" the share field still holds a value its min accepts, so the rule form can submit.' );
	}

	public function test_after_the_day_closes_the_card_says_so_and_offers_no_open(): void {
		$this->roster_present_for_7();
		$_GET = array( 'booking_id' => '5' );
		$this->bookings->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $this->booking() );
		$this->windows->shouldReceive( 'open_for' )->andReturn( null );
		$this->window_writer->shouldReceive( 'auto_close_at' )->andReturn( 1000 );
		$ui = Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminUI' );
		$ui->shouldReceive( 'render_button' )->andReturnUsing(
			static function ( array $args ): void {
				echo '<button data-label="' . $args['label'] . '"></button>';
			}
		);
		$ui->shouldReceive( 'render_segmented' );
		$ui->shouldReceive( 'render_toggle' );
		Functions\when( 'get_posts' )->justReturn( array() );

		ob_start();
		$this->page()->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Check-in closed on its own at 09:30', $html );
		$this->assertStringNotContainsString( 'value="open"', $html );
		$this->assertStringNotContainsString( 'data-label="Open check-in"', $html );
	}

	public function test_the_screen_on_another_day_offers_no_qr(): void {
		$this->roster_present_for_7();
		$_GET = array(
			'booking_id' => '5',
			'date'       => '2027-03-03',
		);
		$this->bookings->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $this->booking() );
		$this->windows->shouldReceive( 'open_for' )->andReturn( null );
		$ui = Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminUI' );
		$ui->shouldReceive( 'render_button' )->andReturnUsing(
			static function ( array $args ): void {
				echo '<button data-label="' . $args['label'] . '"></button>';
			}
		);
		$ui->shouldReceive( 'render_segmented' );
		$ui->shouldReceive( 'render_toggle' );
		Functions\when( 'get_posts' )->justReturn( array() );

		ob_start();
		$this->page()->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'value="open"', $html );
		$this->assertStringNotContainsString( 'data-label="Mark present"', $html, 'A day that has not come yet cannot be marked.' );
	}
}
