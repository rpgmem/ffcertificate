<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceFormInvite;
use FreeFormCertificate\Audience\AudiencePostEventPending;
use FreeFormCertificate\Tests\Support\CallLog;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The post-event form a participant can still fill in, as the dashboard
 * lists it (#2028).
 *
 * Runs the real AudiencePostEventForm (settings, window, eligibility),
 * AudienceBookingReader, AudienceAttendanceReader, AudienceFormInviteReader,
 * AudienceFormInviteMailer::link() with the real signed code and the real
 * page lookup, and DateFormatter, over a `$wpdb` double that answers each
 * query by its SQL text and records every query it ran. The booking carries
 * its own post-event settings, so no calendar row is read.
 *
 * @covers \FreeFormCertificate\Audience\AudiencePostEventPending
 */
class AudiencePostEventPendingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** 2027-01-15 08:00:00 UTC: the booking's last day ends here. */
	private const OPENS = 1800000000;

	private const CLOSES = 1800000000 + 7 * 86400;

	private const FORM = 40;

	private const PAGE = 77;

	private const PAGE_URL = 'https://example.org/form/';

	/** @var bool Whether post 40 is a published certificate form. */
	private bool $form_published = true;

	/** @var list<int> Pages get_posts() answers as holding a form shortcode. */
	private array $pages = array();

	/** @var list<string> User IDs on the booking, as the booking-users query answers. */
	private array $participants = array();

	/** @var list<array<string, string>> Submission rows the answers query returns. */
	private array $answers = array();

	/** @var list<string> Every SQL string the double ran. */
	private array $ran = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->form_published = true;
		$this->pages          = array( self::PAGE );
		$this->participants   = array( '9' );
		$this->answers        = array();
		$this->ran            = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_the_title' )->justReturn( 'Evaluation' );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
		Functions\when( 'get_post_field' )->alias(
			static fn( $field, $id ) => self::PAGE === (int) $id ? 'Intro [ffc_form id="' . self::FORM . '"]' : ''
		);
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => self::PAGE === (int) $id ? self::PAGE_URL : false );
		Functions\when( 'get_posts' )->alias( fn() => $this->pages );
		Functions\when( 'get_post' )->alias( fn( $id ) => $this->post( (int) $id ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					// What DateFormatter reads.
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
				$this->ran[] = $q['sql'];
				if ( false !== strpos( $q['sql'], 'WHERE ba.booking_id = %d' ) ) {
					// No audience on the booking: its participants are added directly.
					return array();
				}
				if ( false !== strpos( $q['sql'], 'WHERE audience_booking_id = %d' ) ) {
					$this->assertSame( 'wp_ffc_submissions', $q['args'][0] );
					$this->assertSame( 5, $q['args'][1] );
					return $this->answers;
				}
				$this->fail( 'Unexpected get_results(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( false !== strpos( $q['sql'], 'SELECT user_id FROM %i WHERE booking_id = %d' ) ) {
					return $this->participants;
				}
				if ( false !== strpos( $q['sql'], 'SELECT attendance_date FROM %i WHERE booking_id = %d AND user_id = %d' ) ) {
					// Nobody was recorded present.
					return array();
				}
				$this->fail( 'Unexpected get_col(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				$this->fail( 'Unexpected get_row(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $q ) {
				$this->fail( 'Unexpected get_var(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) {
				$this->fail( 'Unexpected query(): ' . ( is_array( $q ) ? $q['sql'] : $q ) );
			}
		);

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
	 * The post get_post() answers: form 40, published unless a test says not.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post|null
	 */
	private function post( int $id ): ?\WP_Post {
		if ( self::FORM !== $id || ! $this->form_published ) {
			return null;
		}
		$post              = new \WP_Post();
		$post->ID          = $id;
		$post->post_type   = 'ffc_form';
		$post->post_status = 'publish';
		return $post;
	}

	/**
	 * A one-day booking ending at OPENS, carrying its own post-event settings:
	 * form 40, a 7-day window.
	 *
	 * @param string $status         Booking status.
	 * @param bool   $attendees_only Whether only those present may answer.
	 * @return object
	 */
	private function booking( string $status = 'active', bool $attendees_only = false ): object {
		return (object) array(
			'id'                        => 5,
			'status'                    => $status,
			'booking_date'              => '2027-01-15',
			'start_time'                => '07:00:00',
			'end_time'                  => '08:00:00',
			'post_event_custom'         => 1,
			'post_event_form_id'        => self::FORM,
			'post_event_window_days'    => 7,
			'post_event_attendees_only' => $attendees_only ? 1 : 0,
		);
	}

	/**
	 * Whether the double ran a query containing a fragment.
	 *
	 * @param string $fragment SQL fragment.
	 * @return bool
	 */
	private function ran( string $fragment ): bool {
		foreach ( $this->ran as $sql ) {
			if ( false !== strpos( $sql, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_an_open_form_the_person_may_fill_in_is_waiting_with_their_link(): void {
		$this->assertSame(
			array(
				'form_title'   => 'Evaluation',
				'url'          => self::PAGE_URL . '?ffc_invite=' . AudienceFormInvite::code( 5, 9, self::FORM ),
				'closes_at'    => self::CLOSES,
				'closes_label' => gmdate( 'Y-m-d H:i', self::CLOSES ),
			),
			AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS + 60 )
		);
	}

	public function test_someone_viewing_as_the_person_gets_no_link(): void {
		$waiting = AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS + 60, false );

		$this->assertNotNull( $waiting );
		$this->assertSame( '', $waiting['url'] );
		$this->assertSame( 'Evaluation', $waiting['form_title'] );
	}

	public function test_a_cancelled_booking_has_nothing_waiting_and_reads_nothing(): void {
		$get_post = CallLog::record( 'get_post', $this->post( self::FORM ) );

		$this->assertNull( AudiencePostEventPending::for_user( $this->booking( 'cancelled' ), 9, self::OPENS + 60 ) );
		$this->assertSame( 0, $get_post->count(), 'The form is not looked up.' );
		$this->assertSame( array(), $this->ran, 'No query runs.' );
	}

	public function test_a_booking_without_a_form_has_nothing_waiting(): void {
		$this->form_published = false;

		$this->assertNull( AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS + 60 ) );
		$this->assertSame( array(), $this->ran, 'Eligibility is not asked: no participant is read.' );
	}

	public function test_outside_the_window_nothing_waits_and_no_participant_is_read(): void {
		$this->assertNull( AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS - 1 ), 'Before the event ends.' );
		$this->assertNull( AudiencePostEventPending::for_user( $this->booking(), 9, self::CLOSES ), 'Once the window closed.' );
		$this->assertSame( array(), $this->ran, 'Neither the participants nor the answers are read.' );
	}

	public function test_a_person_the_form_would_refuse_has_nothing_waiting(): void {
		// Attendees only, and nobody was recorded present: eligibility says `absent`.
		$this->assertNull( AudiencePostEventPending::for_user( $this->booking( 'active', true ), 9, self::OPENS + 60 ) );
		$this->assertTrue( $this->ran( 'SELECT attendance_date FROM %i' ), 'Eligibility reached the attendance check.' );
		$this->assertFalse( $this->ran( 'WHERE audience_booking_id = %d' ), 'The answers are not read.' );
	}

	public function test_a_person_who_answered_has_nothing_waiting(): void {
		$this->answers = array(
			array(
				'user_id' => '9',
				'status'  => 'publish',
			),
		);
		$get_posts     = CallLog::record( 'get_posts', $this->pages );

		$this->assertNull( AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS + 60 ) );
		$this->assertTrue( $this->ran( 'WHERE audience_booking_id = %d' ) );
		$this->assertSame( 0, $get_posts->count(), 'No link is built: the page lookup never runs.' );
	}

	public function test_a_form_no_page_embeds_has_nowhere_to_send_the_person(): void {
		$this->pages = array();

		$this->assertNull( AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS + 60 ) );
	}
}
