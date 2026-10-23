<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceFormInvite;
use FreeFormCertificate\Audience\AudienceFormInviteMailer;
use FreeFormCertificate\Audience\AudienceFormInviteReader;
use FreeFormCertificate\Audience\AudienceFormInviteScanner;
use FreeFormCertificate\Audience\AudienceFormInviteWriter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Sending post-event invitations (#1948): the hourly scan, the e-mail, and
 * the records that stop a second send.
 *
 * @covers \FreeFormCertificate\Audience\AudienceFormInviteScanner
 * @covers \FreeFormCertificate\Audience\AudienceFormInviteMailer
 * @covers \FreeFormCertificate\Audience\AudienceFormInviteReader
 * @covers \FreeFormCertificate\Audience\AudienceFormInviteWriter
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceFormInviteSendingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FORM = 40;

	/** @var int */
	private $now = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ) => gmdate( $format, $ts ) );
		Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== strpos( (string) $e, '@' ) );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
		Functions\when( 'get_the_title' )->justReturn( 'Evaluation & feedback' );
		Functions\when( 'get_bloginfo' )->justReturn( 'School' );

		$this->now = ( new \DateTimeImmutable( '2027-03-01 12:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp() + 3600;
	}

	protected function tearDown(): void {
		Mockery::getConfiguration()->setConstantsMap( array() );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function booking( array $override = array() ): object {
		return (object) array_merge(
			array(
				'id'                        => 5,
				'schedule_id'               => 0,
				'status'                    => 'active',
				'description'               => 'Workshop <A>',
				'booking_date'              => '2027-03-01',
				'start_time'                => '09:00:00',
				'end_time'                  => '12:00:00',
				'post_event_custom'         => '1',
				'post_event_form_id'        => (string) self::FORM,
				'post_event_window_days'    => '7',
				'post_event_attendees_only' => '0',
			),
			$override
		);
	}

	/**
	 * The scan's collaborators: who is on the booking, who may be invited,
	 * who already was, and a mailer that answers per person.
	 *
	 * @param array<int, string|true> $eligible User ID => true or an error code.
	 * @param array<int, mixed>       $invited  Already invited.
	 * @param array<int, string>      $refusals User ID => mailer error code.
	 * @return \Mockery\MockInterface The mailer.
	 */
	private function scan_world( array $eligible, array $invited = array(), array $refusals = array() ) {
		Mockery::getConfiguration()->setConstantsMap(
			array( 'FreeFormCertificate\Audience\AudiencePostEventForm' => array( 'MAX_WINDOW_DAYS' => 90 ) )
		);
		$form = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudiencePostEventForm' );
		$form->shouldReceive( 'participants' )->andReturn( array_keys( $eligible ) );
		$form->shouldReceive( 'eligibility' )->andReturnUsing(
			static fn( $booking, int $user ) => true === $eligible[ $user ] ? true : new \WP_Error( $eligible[ $user ], 'No.' )
		);
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceFormInviteReader' )
			->shouldReceive( 'for_booking' )->andReturn( $invited );
		$mailer = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceFormInviteMailer' );
		$mailer->shouldReceive( 'send' )->andReturnUsing(
			static fn( $booking, int $user ) => isset( $refusals[ $user ] ) ? new \WP_Error( $refusals[ $user ], 'No.' ) : true
		);
		return $mailer;
	}

	public function test_a_booking_invites_each_eligible_participant_once(): void {
		$mailer = $this->scan_world(
			array(
				7  => true,
				8  => 'absent',
				9  => true,
				10 => true,
			),
			array(
				9 => array(
					'sent_at'    => 1,
					'send_count' => 1,
				),
			)
		);

		$this->assertSame( 2, AudienceFormInviteScanner::invite_booking( $this->booking(), $this->now, 200 ) );
		$mailer->shouldHaveReceived( 'send' )->with( Mockery::any(), 7, $this->now )->once();
		$mailer->shouldHaveReceived( 'send' )->with( Mockery::any(), 10, $this->now )->once();
		$mailer->shouldNotHaveReceived( 'send', array( Mockery::any(), 8, Mockery::any() ) );
		$mailer->shouldNotHaveReceived( 'send', array( Mockery::any(), 9, Mockery::any() ) );
	}

	public function test_a_refusal_about_the_booking_stops_it_for_everyone(): void {
		$mailer = $this->scan_world(
			array(
				7 => 'closed',
				8 => true,
			)
		);

		$this->assertSame( 0, AudienceFormInviteScanner::invite_booking( $this->booking(), $this->now, 200 ) );
		$mailer->shouldNotHaveReceived( 'send' );
	}

	public function test_a_form_no_page_embeds_stops_the_booking_and_an_address_problem_does_not(): void {
		$this->scan_world(
			array(
				7 => true,
				8 => true,
				9 => true,
			),
			array(),
			array(
				7 => 'no_email',
				8 => 'no_page',
			)
		);

		$this->assertSame( 0, AudienceFormInviteScanner::invite_booking( $this->booking(), $this->now, 200 ), 'Person 9 waits: the page is missing for everyone.' );
	}

	public function test_the_limit_is_respected_inside_a_booking_and_across_bookings(): void {
		$this->scan_world(
			array(
				7 => true,
				8 => true,
				9 => true,
			)
		);
		$this->assertSame( 2, AudienceFormInviteScanner::invite_booking( $this->booking(), $this->now, 2 ) );

		$bookings = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$bookings->shouldReceive( 'ended_between' )->once()->with( '2026-12-01', '2027-03-01' )->andReturn( array( 5, 6, 404 ) );
		$bookings->shouldReceive( 'get_by_id' )->andReturnUsing( fn( int $id ) => 404 === $id ? null : $this->booking( array( 'id' => $id ) ) );

		$this->assertSame( 6, AudienceFormInviteScanner::scan( $this->now ), 'Two bookings of three people each, the unknown one skipped.' );
	}

	public function test_scheduling_is_hourly_and_idempotent(): void {
		Functions\expect( 'wp_next_scheduled' )->twice()->andReturn( false, 123 );
		Functions\expect( 'wp_schedule_event' )->once()->with( Mockery::type( 'int' ), 'hourly', AudienceFormInviteScanner::CRON_HOOK );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( AudienceFormInviteScanner::CRON_HOOK );

		AudienceFormInviteScanner::schedule();
		AudienceFormInviteScanner::schedule();
		AudienceFormInviteScanner::unschedule();
	}

	/**
	 * The mailer's collaborators.
	 *
	 * @param string $page   URL of the page embedding the form.
	 * @param bool   $sends  What the transport answers.
	 * @return array{0: \Mockery\MockInterface, 1: \Mockery\MockInterface} Transport and writer.
	 */
	private function mail_world( string $page, bool $sends = true ): array {
		Mockery::mock( 'alias:FreeFormCertificate\Frontend\ScheduleExceptionAction' )
			->shouldReceive( 'find_form_page_url' )->with( self::FORM )->andReturn( $page );
		$templates = Mockery::mock( 'alias:FreeFormCertificate\Core\EmailTemplates' );
		$templates->shouldReceive( 'effective_body' )->with( 'audience-post-event-invite', 'subject' )->andReturn( 'Certificate for {{event}}' );
		$templates->shouldReceive( 'effective_body' )->with( 'audience-post-event-invite' )->andReturn( '<p>{{user_name}} · {{form_title}} · <a href="{{form_link}}">x</a> · {{closes_at}} · {{site_name}}</p>' );
		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
			->shouldReceive( 'format_datetime' )->andReturnUsing( static fn( int $t ) => gmdate( 'Y-m-d H:i', $t ) );
		$transport = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		$transport->shouldReceive( 'send' )->andReturn( $sends )->byDefault();
		$writer = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceFormInviteWriter' );

		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				if ( 7 === (int) $id ) {
					return (object) array(
						'display_name' => 'Ana <Souza>',
						'user_email'   => 'ana@example.org',
					);
				}
				return 8 === (int) $id ? (object) array(
					'display_name' => 'No Mail',
					'user_email'   => '',
				) : false;
			}
		);

		return array( $transport, $writer );
	}

	public function test_the_mail_carries_the_personal_link_and_is_recorded(): void {
		list( $transport, $writer ) = $this->mail_world( 'https://example.org/feedback/' );
		$link                         = 'https://example.org/feedback/?ffc_invite=' . AudienceFormInvite::code( 5, 7, self::FORM );
		$transport->shouldReceive( 'send' )->once()->with(
			'ana@example.org',
			'Certificate for Workshop <A>',
			'<p>Ana &lt;Souza&gt; · Evaluation &amp; feedback · <a href="' . $link . '">x</a> · 2027-03-08 12:00 · School</p>',
			array(),
			true,
			'plugin:ffcertificate_audience_post_event'
		)->andReturn( true );
		$writer->shouldReceive( 'record_sent' )->once()->with( 5, 7, self::FORM, $this->now )->andReturn( true );

		$this->assertTrue( AudienceFormInviteMailer::send( $this->booking(), 7, $this->now ) );
		$this->assertNotFalse( AudienceFormInvite::verify( AudienceFormInvite::code( 5, 7, self::FORM ), self::FORM ) );
	}

	public function test_nothing_is_sent_or_recorded_without_an_address_a_page_or_a_transport(): void {
		list( $transport, $writer ) = $this->mail_world( 'https://example.org/feedback/', false );
		$writer->shouldNotReceive( 'record_sent' );

		$this->assertSame( 'no_email', AudienceFormInviteMailer::send( $this->booking(), 8, $this->now )->get_error_code() );
		$this->assertSame( 'no_email', AudienceFormInviteMailer::send( $this->booking(), 99, $this->now )->get_error_code(), 'A deleted account.' );
		$this->assertSame( 'send_failed', AudienceFormInviteMailer::send( $this->booking(), 7, $this->now )->get_error_code() );
	}

	public function test_a_form_no_page_embeds_has_no_link(): void {
		list( $transport, $writer ) = $this->mail_world( '' );
		$transport->shouldNotReceive( 'send' );
		$writer->shouldNotReceive( 'record_sent' );

		$this->assertSame( '', AudienceFormInviteMailer::link( 5, 7, self::FORM ) );
		$this->assertSame( 'no_page', AudienceFormInviteMailer::send( $this->booking(), 7, $this->now )->get_error_code() );
	}

	public function test_the_records_read_back_per_person_and_a_resend_counts(): void {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql, ...$args ): string {
				return vsprintf( str_replace( array( '%i', '%d' ), array( '%s', '%s' ), $sql ), $args );
			}
		);
		$wpdb->shouldReceive( 'query' )->once()->with( Mockery::on( static fn( $q ) => false !== strpos( $q, 'INSERT INTO wp_ffc_audience_form_invites' ) && false !== strpos( $q, 'send_count = send_count + 1' ) && false !== strpos( $q, '(5, 7, 40, 99, 1)' ) ) )->andReturn( 1 );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( string $q ): array {
				if ( false !== strpos( $q, 'wp_ffc_submissions' ) ) {
					return array(
						array(
							'user_id' => '7',
							'status'  => 'pending',
						),
						array(
							'user_id' => '7',
							'status'  => 'publish',
						),
					);
				}
				return array(
					array(
						'user_id'    => '7',
						'sent_at'    => '99',
						'send_count' => '2',
					),
				);
			}
		);

		$this->assertTrue( AudienceFormInviteWriter::record_sent( 5, 7, 40, 99 ) );
		$this->assertSame(
			array(
				7 => array(
					'sent_at'    => 99,
					'send_count' => 2,
				),
			),
			AudienceFormInviteReader::for_booking( 5 )
		);
		$this->assertSame( array( 7 => 'publish' ), AudienceFormInviteReader::answers( 5 ), 'The newest submission wins.' );
	}
}
