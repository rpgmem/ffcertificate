<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
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
 * Everything below the scanner runs for real — AudiencePostEventForm's
 * eligibility, AudienceBookingReader, AudienceAttendanceReader, the invite
 * reader and writer, the mailer, the page lookup in ScheduleExceptionAction,
 * EmailTemplates, DateFormatter and the plugin's e-mail path down to a
 * stubbed `wp_mail()` — over a `$wpdb` double that answers each query by its
 * SQL text from the rows a test sets, records every write, and fails the test
 * on a query it does not know.
 *
 * @covers \FreeFormCertificate\Audience\AudienceFormInviteScanner
 * @covers \FreeFormCertificate\Audience\AudienceFormInviteMailer
 * @covers \FreeFormCertificate\Audience\AudienceFormInviteReader
 * @covers \FreeFormCertificate\Audience\AudienceFormInviteWriter
 */
class AudienceFormInviteSendingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FORM = 40;

	private const PAGE = 'https://example.org/feedback/';

	/** @var int */
	private $now = 0;

	/** @var list<int> The people on every booking. */
	private array $participants = array();

	/** @var array<int, list<string>> User ID => days present. */
	private array $present = array();

	/** @var array<int, list<array<string, string>>> Booking ID => invitation rows. */
	private array $invited = array();

	/** @var list<array<string, string>> Submission rows the answers query returns. */
	private array $answers = array();

	/** @var array<int, object> Booking ID => booking row. */
	private array $bookings = array();

	/** @var list<int> Booking IDs the ended-between query returns. */
	private array $ended = array();

	/** @var list<string> What successive page lookups find: a URL, or '' for no page. */
	private array $pages = array();

	/** @var bool What `wp_mail()` answers. */
	private bool $transport = true;

	/** @var list<array{0: string, 1: string, 2: string, 3: array<int, string>, 4: array<int, string>}> Every `wp_mail()` call. */
	private array $mailed = array();

	/** @var list<int> Every user `get_userdata()` was asked for. */
	private array $looked_up = array();

	/** @var list<string> Every read query the double ran. */
	private array $reads = array();

	/** @var list<string> Every `query()` the double ran. */
	private array $writes = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->now          = ( new \DateTimeImmutable( '2027-03-01 12:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp() + 3600;
		$this->participants = array();
		$this->present      = array();
		$this->invited      = array();
		$this->answers      = array();
		$this->bookings     = array();
		$this->ended        = array();
		$this->pages        = array( self::PAGE );
		$this->transport    = true;
		$this->mailed       = array();
		$this->looked_up    = array();
		$this->reads        = array();
		$this->writes       = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ) => gmdate( $format, $ts ) );
		Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== strpos( (string) $e, '@' ) );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
		Functions\when( 'get_the_title' )->justReturn( 'Evaluation & feedback' );
		Functions\when( 'get_bloginfo' )->justReturn( 'School' );
		// What the e-mail chrome reads.
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.org' . $path );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					// What DateFormatter reads; e-mails enabled, debug off.
					return array(
						'date_format' => 'Y-m-d',
						'time_format' => 'H:i',
					);
				}
				if ( 'ffc_email_bodies' === $key ) {
					// An operator's override of the invitation, so the
					// assertions below read a template they can spell out.
					return array(
						'audience-post-event-invite' => array(
							'subject' => 'Certificate for {{event}}',
							'body'    => '<p>{{user_name}} · {{form_title}} · <a href="{{form_link}}">x</a> · {{closes_at}} · {{site_name}}</p>',
						),
					);
				}
				return $default;
			}
		);
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				if ( self::FORM !== (int) $id ) {
					return null;
				}
				$post              = new \WP_Post();
				$post->post_type   = 'ffc_form';
				$post->post_status = 'publish';
				return $post;
			}
		);
		// The page lookup: one published page embeds the form, or none does.
		Functions\when( 'get_posts' )->alias(
			function () {
				$url = count( $this->pages ) > 1 ? array_shift( $this->pages ) : ( $this->pages[0] ?? '' );
				return '' === $url ? array() : array( 11 );
			}
		);
		Functions\when( 'get_post_field' )->justReturn( '<p>[ffc_form id="40"]</p>' );
		Functions\when( 'get_permalink' )->justReturn( self::PAGE );
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				$this->looked_up[] = (int) $id;
				if ( 7 === (int) $id ) {
					return (object) array(
						'display_name' => 'Ana <Souza>',
						'user_email'   => 'ana@example.org',
					);
				}
				if ( 8 === (int) $id ) {
					return (object) array(
						'display_name' => 'No Mail',
						'user_email'   => '',
					);
				}
				return in_array( (int) $id, array( 9, 10 ), true ) ? (object) array(
					'display_name' => 'Person ' . $id,
					'user_email'   => 'p' . $id . '@example.org',
				) : false;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers = array(), $attachments = array() ) {
				$this->mailed[] = array( $to, $subject, $body, $headers, $attachments );
				return $this->transport;
			}
		);

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->wpdb_double();
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
	 * A `$wpdb` double: `prepare()` binds its arguments into the SQL, reads
	 * are answered by SQL text, `query()` writes are recorded.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function wpdb_double() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function () use ( &$flat ) {
						return (string) array_shift( $flat );
					},
					$sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = $q;
				if ( preg_match( '/^SELECT user_id FROM wp_ffc_audience_booking_users WHERE booking_id = \d+$/', $q ) ) {
					return array_map( 'strval', $this->participants );
				}
				if ( preg_match( '/^SELECT attendance_date FROM wp_ffc_audience_attendance WHERE booking_id = \d+ AND user_id = (\d+)/', $q, $m ) ) {
					return $this->present[ (int) $m[1] ] ?? array();
				}
				if ( 0 === strpos( $q, "SELECT id FROM wp_ffc_audience_bookings WHERE status = 'active'" ) ) {
					return array_map( 'strval', $this->ended );
				}
				$this->fail( 'Unexpected get_col(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = $q;
				if ( preg_match( '/^SELECT user_id, sent_at, send_count FROM wp_ffc_audience_form_invites WHERE booking_id = (\d+)$/', $q, $m ) ) {
					return $this->invited[ (int) $m[1] ] ?? array();
				}
				if ( 0 === strpos( $q, 'SELECT user_id, status FROM wp_ffc_submissions WHERE audience_booking_id = 5 ' ) ) {
					return $this->answers;
				}
				if ( false !== strpos( $q, 'INNER JOIN wp_ffc_audience_booking_audiences ba' ) ) {
					return array();
				}
				$this->fail( 'Unexpected get_results(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = $q;
				if ( preg_match( '/FROM wp_ffc_audience_bookings b\s.*WHERE b\.id = (\d+)$/s', $q, $m ) ) {
					return isset( $this->bookings[ (int) $m[1] ] ) ? clone $this->bookings[ (int) $m[1] ] : null;
				}
				$this->fail( 'Unexpected get_row(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) {
				if ( 0 === strpos( $q, 'INSERT INTO wp_ffc_audience_form_invites' ) ) {
					$this->writes[] = $q;
					return 1;
				}
				$this->fail( 'Unexpected query(): ' . $q );
			}
		);
		return $wpdb;
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
	 * The recipients `wp_mail()` was called for, in order.
	 *
	 * @return list<string>
	 */
	private function recipients(): array {
		return array_column( $this->mailed, 0 );
	}

	/**
	 * The (booking, user) pairs recorded as invited, in order.
	 *
	 * @return list<string> `booking/user`.
	 */
	private function recorded(): array {
		$out = array();
		foreach ( $this->writes as $sql ) {
			if ( preg_match( '/VALUES \((\d+), (\d+), \d+, \d+, 1\)/', $sql, $m ) ) {
				$out[] = $m[1] . '/' . $m[2];
			}
		}
		return $out;
	}

	/**
	 * How many times the booking's participant list was read.
	 *
	 * @return int
	 */
	private function participant_reads(): int {
		return count( array_filter( $this->reads, static fn( $q ) => 0 === strpos( $q, 'SELECT user_id FROM wp_ffc_audience_booking_users' ) ) );
	}

	public function test_a_booking_invites_each_eligible_participant_once(): void {
		// Only those present may answer: 8 was absent, 9 was already invited.
		$this->participants = array( 7, 8, 9, 10 );
		$this->present      = array(
			7  => array( '2027-03-01' ),
			10 => array( '2027-03-01' ),
		);
		$this->invited[5]   = array(
			array(
				'user_id'    => '9',
				'sent_at'    => '1',
				'send_count' => '1',
			),
		);

		$this->assertSame( 2, AudienceFormInviteScanner::invite_booking( $this->booking( array( 'post_event_attendees_only' => '1' ) ), $this->now, 200 ) );
		$this->assertSame( array( 'ana@example.org', 'p10@example.org' ), $this->recipients(), 'Each eligible person is mailed once; the absent and the already invited are not.' );
		$this->assertSame( array( '5/7', '5/10' ), $this->recorded() );
		$this->assertNotContains( 8, $this->looked_up );
		$this->assertNotContains( 9, $this->looked_up );
	}

	public function test_a_refusal_about_the_booking_stops_it_for_everyone(): void {
		$this->participants = array( 7, 8 );
		// A day after the seven-day window closed.
		$closed = $this->now + 8 * DAY_IN_SECONDS;

		$this->assertSame( 0, AudienceFormInviteScanner::invite_booking( $this->booking(), $closed, 200 ) );
		$this->assertSame( array(), $this->mailed );
		$this->assertSame( 2, $this->participant_reads(), 'The scan reads the list once and judges person 7 only: the refusal holds for 8 too.' );
	}

	public function test_a_form_no_page_embeds_stops_the_booking_and_an_address_problem_does_not(): void {
		// 8 has no address (skipped), then 7 finds no page (the booking stops).
		$this->participants = array( 8, 7, 9 );
		$this->pages        = array( '' );

		$this->assertSame( 0, AudienceFormInviteScanner::invite_booking( $this->booking(), $this->now, 200 ), 'Person 9 waits: the page is missing for everyone.' );
		$this->assertSame( array( 8, 7 ), $this->looked_up, 'No address skips the person; no page stops before 9.' );
		$this->assertSame( array(), $this->mailed );
	}

	public function test_the_limit_is_respected_inside_a_booking_and_across_bookings(): void {
		$this->participants = array( 7, 9, 10 );
		$this->assertSame( 2, AudienceFormInviteScanner::invite_booking( $this->booking(), $this->now, 2 ) );
		$this->assertSame( array( '5/7', '5/9' ), $this->recorded() );

		$this->writes   = array();
		$this->ended    = array( 5, 6, 404 );
		$this->bookings = array(
			5 => $this->booking(),
			6 => $this->booking( array( 'id' => 6 ) ),
		);

		$this->assertSame( 6, AudienceFormInviteScanner::scan( $this->now ), 'Two bookings of three people each, the unknown one skipped.' );
		$ended = array_values( array_filter( $this->reads, static fn( $q ) => 0 === strpos( $q, 'SELECT id FROM wp_ffc_audience_bookings' ) ) );
		$this->assertCount( 1, $ended );
		$this->assertStringContainsString( 'BETWEEN 2026-12-01 AND 2027-03-01', $ended[0] );
		$this->assertSame( array( '5/7', '5/9', '5/10', '6/7', '6/9', '6/10' ), $this->recorded() );
	}

	public function test_scheduling_is_hourly_and_idempotent(): void {
		Functions\expect( 'wp_next_scheduled' )->twice()->andReturn( false, 123 );
		Functions\expect( 'wp_schedule_event' )->once()->with( Mockery::type( 'int' ), 'hourly', AudienceFormInviteScanner::CRON_HOOK );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( AudienceFormInviteScanner::CRON_HOOK );

		AudienceFormInviteScanner::schedule();
		AudienceFormInviteScanner::schedule();
		AudienceFormInviteScanner::unschedule();
	}

	public function test_the_mail_carries_the_personal_link_and_is_recorded(): void {
		// Stamp the mail-queue source headers, so the source key is visible.
		Filters\expectApplied( 'ffcertificate_emit_source_headers' )->andReturn( true );
		$link = self::PAGE . '?ffc_invite=' . AudienceFormInvite::code( 5, 7, self::FORM );

		$this->assertTrue( AudienceFormInviteMailer::send( $this->booking(), 7, $this->now ) );

		$this->assertCount( 1, $this->mailed );
		list( $to, $subject, $body, $headers, $attachments ) = $this->mailed[0];
		$this->assertSame( 'ana@example.org', $to );
		$this->assertSame( 'Certificate for Workshop <A>', $subject );
		$inner = '<p>Ana &lt;Souza&gt; · Evaluation &amp; feedback · <a href="' . $link . '">x</a> · 2027-03-08 12:00 · School</p>';
		$this->assertStringContainsString( $inner, $body );
		$this->assertNotSame( $inner, $body, 'The body is wrapped in the e-mail chrome.' );
		$this->assertSame( array(), $attachments );
		$this->assertContains( 'X-TMQ-Source-Key: plugin:ffcertificate_audience_post_event', $headers );
		$this->assertSame( array( 'INSERT INTO wp_ffc_audience_form_invites (booking_id, user_id, form_id, sent_at, send_count) VALUES (5, 7, 40, ' . $this->now . ', 1) ON DUPLICATE KEY UPDATE form_id = VALUES(form_id), sent_at = VALUES(sent_at), send_count = send_count + 1' ), $this->writes );
		$this->assertNotFalse( AudienceFormInvite::verify( AudienceFormInvite::code( 5, 7, self::FORM ), self::FORM ) );
	}

	public function test_nothing_is_sent_or_recorded_without_an_address_a_page_or_a_transport(): void {
		$this->transport = false;

		$this->assertSame( 'no_email', AudienceFormInviteMailer::send( $this->booking(), 8, $this->now )->get_error_code() );
		$this->assertSame( 'no_email', AudienceFormInviteMailer::send( $this->booking(), 99, $this->now )->get_error_code(), 'A deleted account.' );
		$this->assertSame( 'send_failed', AudienceFormInviteMailer::send( $this->booking(), 7, $this->now )->get_error_code() );
		$this->assertSame( array(), $this->writes, 'Nothing is recorded.' );
	}

	public function test_a_form_no_page_embeds_has_no_link(): void {
		$this->pages = array( '' );

		$this->assertSame( '', AudienceFormInviteMailer::link( 5, 7, self::FORM ) );
		$this->assertSame( 'no_page', AudienceFormInviteMailer::send( $this->booking(), 7, $this->now )->get_error_code() );
		$this->assertSame( array(), $this->mailed, 'Nothing is sent.' );
		$this->assertSame( array(), $this->writes, 'Nothing is recorded.' );
	}

	public function test_a_form_no_page_embeds_is_flagged_and_an_embedded_or_absent_one_is_not(): void {
		$this->pages = array( '', self::PAGE );

		$notice = AudienceFormInviteMailer::missing_page_notice( self::FORM );
		$this->assertStringContainsString( 'notice-warning', $notice );
		$this->assertStringContainsString( '[ffc_form id=&quot;40&quot;]', $notice );
		$this->assertSame( '', AudienceFormInviteMailer::missing_page_notice( self::FORM ), 'Embedded: nothing to say.' );
		$this->assertSame( '', AudienceFormInviteMailer::missing_page_notice( 0 ), 'No form chosen: nothing to say.' );
	}

	public function test_the_records_read_back_per_person_and_a_resend_counts(): void {
		$this->invited[5] = array(
			array(
				'user_id'    => '7',
				'sent_at'    => '99',
				'send_count' => '2',
			),
		);
		$this->answers    = array(
			array(
				'user_id' => '7',
				'status'  => 'pending',
			),
			array(
				'user_id' => '7',
				'status'  => 'publish',
			),
		);

		$this->assertTrue( AudienceFormInviteWriter::record_sent( 5, 7, 40, 99 ) );
		$this->assertCount( 1, $this->writes );
		$this->assertStringContainsString( '(5, 7, 40, 99, 1)', $this->writes[0] );
		$this->assertStringContainsString( 'send_count = send_count + 1', $this->writes[0] );
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
