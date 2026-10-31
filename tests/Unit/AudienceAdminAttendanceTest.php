<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAdminAttendance;
use FreeFormCertificate\Audience\AudienceAttendanceAjax;
use FreeFormCertificate\Audience\AudienceAttendanceExportSource;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The attendance screen's writes, its day choice, its render and the poll
 * it shares with the projected QR (#1947).
 *
 * Everything below the screen runs for real — the booking, check-in window,
 * attendance and invitation readers and writers, the attendance rule and
 * roster, the post-event form's eligibility, the invitation mailer down to a
 * stubbed `wp_mail()`, Capabilities over a stubbed `current_user_can()`,
 * DateFormatter, AdminUI and the geofence registry over `get_option()` —
 * over a `$wpdb` double that answers each read by its SQL text from the rows
 * a test sets, records every write, and fails the test on a query it does
 * not know.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminAttendance
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceAjax
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceExportSource
 */
class AudienceAdminAttendanceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** 2027-01-15 08:00 UTC. */
	private const NOW = 1800000000;

	private const TODAY = '2027-03-01';

	private const PAGE = 'https://example.org/feedback/';

	/** @var list<string> */
	private array $caps = array( 'ffc_checkin_audiences' );

	/** @var string What `current_time( 'Y-m-d' )` answers. */
	private string $today = self::TODAY;

	/** @var object|null The stored booking 5. */
	private ?object $booking_row = null;

	/** @var list<int> The people on the booking. */
	private array $participants = array( 7, 8 );

	/** @var list<array<string, mixed>> Stored attendance lines. */
	private array $attendance = array();

	/** @var list<array<string, mixed>> Stored check-in windows, oldest first. */
	private array $windows = array();

	/** @var list<array<string, string>> Stored invitation rows. */
	private array $invited = array();

	/** @var list<array<string, string>> Stored submissions answering the form. */
	private array $answers = array();

	/** @var list<array<string, mixed>> Saved geofence locations. */
	private array $locations = array();

	/** @var list<string> What successive page lookups find: a URL, or '' for no page. */
	private array $pages = array( '' );

	/** @var list<array<int, mixed>> Every write the double received: [op, table, data, where]. */
	private array $writes = array();

	/** @var list<array{0: string, 1: string, 2: string}> Every `wp_mail()` call. */
	private array $mailed = array();

	/** @var list<int> Every user `get_userdata()` was asked for. */
	private array $looked_up = array();

	/** @var array<string, mixed> The `$_POST` an earlier test left. */
	private array $post_backup = array();

	/** @var array<string, mixed> The `$_GET` an earlier test left. */
	private array $get_backup = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->post_backup  = $_POST ?? array();
		$this->get_backup   = $_GET ?? array();
		$_POST              = array();
		$_GET               = array();
		$this->caps         = array( 'ffc_checkin_audiences' );
		$this->today        = self::TODAY;
		$this->booking_row  = $this->booking();
		$this->participants = array( 7, 8 );
		$this->attendance   = array();
		$this->windows      = array();
		$this->invited      = array();
		$this->answers      = array();
		$this->locations    = array();
		$this->pages        = array( '' );
		$this->writes       = array();
		$this->mailed       = array();
		$this->looked_up    = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_time' )->alias( fn() => $this->today );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.org/wp-admin/' . $path );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.org' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$a ): string {
				return is_array( $a[0] ) ? $a[1] . '?' . http_build_query( $a[0] ) : $a[2] . '?' . $a[0] . '=' . $a[1];
			}
		);
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'disabled' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input type="hidden" name="_wpnonce" value="n">' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ) => gmdate( $format, $ts ) );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( 'School' );
		Functions\when( 'get_the_title' )->justReturn( 'Evaluation' );
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== strpos( (string) $e, '@' ) );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					// What DateFormatter reads; e-mails enabled, debug off.
					return array(
						'date_format' => 'Y-m-d',
						'time_format' => 'H:i',
					);
				}
				if ( 'ffc_geofence_locations' === $key ) {
					return $this->locations;
				}
				return $default;
			}
		);
		// Form 40 is published; every other id is a draft form.
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				$post              = new \WP_Post();
				$post->post_type   = 'ffc_form';
				$post->post_status = 40 === (int) $id ? 'publish' : 'draft';
				return $post;
			}
		);
		// The form select lists no form; the page lookup finds a page or none.
		Functions\when( 'get_posts' )->alias(
			function ( $args ) {
				if ( 'ffc_form' === ( $args['post_type'] ?? '' ) ) {
					return array();
				}
				$url = count( $this->pages ) > 1 ? array_shift( $this->pages ) : ( $this->pages[0] ?? '' );
				return '' === $url ? array() : array( 11 );
			}
		);
		Functions\when( 'get_post_field' )->justReturn( '<p>[ffc_form id="40"]</p>' );
		Functions\when( 'get_permalink' )->justReturn( self::PAGE );
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
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				$this->looked_up[] = (int) $id;
				return (object) array(
					'display_name' => 'Person ' . $id,
					'user_email'   => 'p' . $id . '@example.org',
				);
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				$this->mailed[] = array( $to, $subject, $body );
				return true;
			}
		);

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->wpdb_double();
	}

	protected function tearDown(): void {
		$_POST = $this->post_backup;
		$_GET  = $this->get_backup;
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
	 * are answered by SQL text, writes are recorded and applied.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function wpdb_double() {
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;
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
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				if ( preg_match( '/FROM wp_ffc_audience_bookings b\s.*WHERE b\.id = (\d+)$/s', $q, $m ) ) {
					return 5 === (int) $m[1] && null !== $this->booking_row ? clone $this->booking_row : null;
				}
				if ( preg_match( '/^SELECT \* FROM wp_ffc_audience_checkin_windows WHERE booking_id = (\d+) AND window_date = (\S+) ORDER BY id DESC LIMIT 1$/', $q, $m ) ) {
					$found = null;
					foreach ( $this->windows as $row ) {
						if ( (int) $row['booking_id'] === (int) $m[1] && $row['window_date'] === $m[2] ) {
							$found = $row;
						}
					}
					return $found;
				}
				$this->fail( 'Unexpected get_row(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				if ( false !== strpos( $q, 'INNER JOIN wp_ffc_audience_booking_audiences ba' ) ) {
					return array();
				}
				if ( 0 === strpos( $q, 'SELECT user_id, attendance_date FROM wp_ffc_audience_attendance WHERE booking_id = 5 ' ) ) {
					return array_map(
						static fn( $l ) => array(
							'user_id'         => (string) $l['user_id'],
							'attendance_date' => $l['attendance_date'],
						),
						$this->attendance
					);
				}
				if ( preg_match( '/^SELECT id, booking_id, user_id, attendance_date, checked_at, method, marked_by FROM wp_ffc_audience_attendance WHERE booking_id = 5 AND attendance_date = (\S+) ORDER BY/', $q, $m ) ) {
					return array_values( array_filter( $this->attendance, static fn( $l ) => $l['attendance_date'] === $m[1] ) );
				}
				if ( 'SELECT user_id, sent_at, send_count FROM wp_ffc_audience_form_invites WHERE booking_id = 5' === $q ) {
					return $this->invited;
				}
				if ( 0 === strpos( $q, 'SELECT user_id, status FROM wp_ffc_submissions WHERE audience_booking_id = 5 ' ) ) {
					return $this->answers;
				}
				$this->fail( 'Unexpected get_results(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $q ) {
				if ( 'SELECT user_id FROM wp_ffc_audience_booking_users WHERE booking_id = 5' === $q ) {
					return array_map( 'strval', $this->participants );
				}
				if ( preg_match( '/^SELECT attendance_date FROM wp_ffc_audience_attendance WHERE booking_id = 5 AND user_id = (\d+) ORDER BY/', $q, $m ) ) {
					$days = array();
					foreach ( $this->attendance as $line ) {
						if ( (int) $line['user_id'] === (int) $m[1] ) {
							$days[] = $line['attendance_date'];
						}
					}
					return $days;
				}
				$this->fail( 'Unexpected get_col(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) {
				if ( preg_match( '/^UPDATE wp_ffc_audience_checkin_windows SET closed_at = (\d+), closed_by = (\d+) WHERE id = (\d+) AND closed_at IS NULL$/', $q, $m ) ) {
					$this->writes[] = array( 'query', 'wp_ffc_audience_checkin_windows', $q );
					foreach ( $this->windows as $i => $row ) {
						if ( (int) $row['id'] === (int) $m[3] && null === $row['closed_at'] ) {
							$this->windows[ $i ]['closed_at'] = $m[1];
							$this->windows[ $i ]['closed_by'] = $m[2];
							return 1;
						}
					}
					return 0;
				}
				if ( 0 === strpos( $q, 'INSERT IGNORE INTO wp_ffc_audience_attendance ' ) || 0 === strpos( $q, 'INSERT INTO wp_ffc_audience_form_invites ' ) ) {
					$this->writes[] = array( 'query', strtok( substr( $q, strpos( $q, 'INTO ' ) + 5 ), ' ' ), $q );
					return 1;
				}
				$this->fail( 'Unexpected query(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->writes[]  = array( 'insert', $table, $data );
				$wpdb->insert_id = 11;
				return 1;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->writes[] = array( 'update', $table, $data, $where );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->writes[] = array( 'delete', $table, $where );
				return 1;
			}
		);
		return $wpdb;
	}

	/** A UTC timestamp. */
	private static function utc( string $moment ): int {
		return ( new \DateTimeImmutable( $moment, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
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

	/**
	 * The labels of the family buttons a render printed.
	 *
	 * @return list<string>
	 */
	private static function button_labels( string $html ): array {
		preg_match_all( '/<(button|a)\b[^>]*\bclass="ffc-btn [^"]*"[^>]*>([^<]*)<\/\1>/', $html, $m );
		return $m[2];
	}

	/**
	 * The writes the double received to one table.
	 *
	 * @return list<array<int, mixed>>
	 */
	private function writes_to( string $table ): array {
		return array_values( array_filter( $this->writes, static fn( $w ) => $table === $w[1] ) );
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
		$_POST           = array(
			'rotation_seconds'   => '300',
			'geofence_locations' => array( 'loc_a', 'loc_forged' ),
		);
		$this->locations = array( array( 'id' => 'loc_a' ) );

		$this->assertSame( 'opened', $this->page()->perform( 'open', $this->booking(), self::TODAY, self::NOW ) );

		$this->assertSame(
			array(
				array(
					'insert',
					'wp_ffc_audience_checkin_windows',
					array(
						'booking_id'         => 5,
						'window_date'        => self::TODAY,
						'rotation_seconds'   => 300,
						'opened_at'          => self::NOW,
						// 30 minutes after the booking's 12:00 end, today.
						'closes_at'          => self::utc( '2027-03-01 12:30' ),
						'opened_by'          => 3,
						'geofence_locations' => 'loc_a',
					),
				),
			),
			$this->writes
		);
	}

	public function test_open_is_refused_with_its_reason_once_the_day_has_closed(): void {
		$after_close = self::utc( '2027-03-01 12:30' ) + 60;

		$this->assertSame( 'ended', $this->page()->perform( 'open', $this->booking(), self::TODAY, $after_close ) );
		$this->assertSame( array(), $this->writes, 'No window is opened.' );
		$this->assertSame( 'warning', AudienceAdminAttendance::messages()['ended'][1] );
	}

	public function test_open_is_refused_on_another_day_and_for_a_cancelled_booking(): void {
		$this->assertSame( 'not_today', $this->page()->perform( 'open', $this->booking(), '2027-03-02', self::NOW ) );
		$this->assertSame( 'not_today', $this->page()->perform( 'open', $this->booking( array( 'status' => 'cancelled' ) ), self::TODAY, self::NOW ) );
		$this->assertSame( array(), $this->writes, 'No window is opened.' );
	}

	public function test_close_closes_the_open_window_and_reports_when_none_was(): void {
		$this->windows = array(
			array(
				'id'               => '11',
				'booking_id'       => '5',
				'window_date'      => self::TODAY,
				'rotation_seconds' => '60',
				'opened_at'        => (string) ( self::NOW - 60 ),
				'opened_by'        => '3',
				'closes_at'        => (string) ( self::NOW + 3600 ),
				'closed_at'        => null,
				'closed_by'        => null,
			),
		);

		$this->assertSame( 'closed', $this->page()->perform( 'close', $this->booking(), self::TODAY, self::NOW ) );
		$this->assertSame( 'not_open', $this->page()->perform( 'close', $this->booking(), self::TODAY, self::NOW ) );

		$this->assertCount( 1, $this->writes, 'The window is closed once.' );
		$this->assertSame( 'UPDATE wp_ffc_audience_checkin_windows SET closed_at = ' . self::NOW . ', closed_by = 3 WHERE id = 11 AND closed_at IS NULL', $this->writes[0][2] );
	}

	public function test_mark_records_by_hand_with_who_marked_it(): void {
		$_POST = array( 'mark_user' => '7' );

		$this->assertSame( 'marked', $this->page()->perform( 'mark', $this->booking(), self::TODAY, self::NOW ) );

		$this->assertCount( 1, $this->writes );
		$this->assertStringEndsWith( 'VALUES (5, 7, ' . self::TODAY . ', ' . self::NOW . ', manual, 3)', $this->writes[0][2] );
	}

	public function test_mark_refuses_a_stranger_and_a_future_day(): void {
		$_POST = array( 'mark_user' => '99' );

		$this->assertSame( 'failed', $this->page()->perform( 'mark', $this->booking(), self::TODAY, self::NOW ) );
		$this->assertSame( 'future', $this->page()->perform( 'mark', $this->booking(), '2027-03-03', self::NOW ) );
		$this->assertSame( array(), $this->writes, 'No line is recorded.' );
	}

	public function test_unmark_removes_the_line(): void {
		$_POST = array( 'unmark_user' => '7' );

		$this->assertSame( 'unmarked', $this->page()->perform( 'unmark', $this->booking(), self::TODAY, self::NOW ) );

		$this->assertSame(
			array(
				array(
					'delete',
					'wp_ffc_audience_attendance',
					array(
						'booking_id'      => 5,
						'user_id'         => 7,
						'attendance_date' => self::TODAY,
					),
				),
			),
			$this->writes
		);
	}

	public function test_the_rule_needs_the_manage_capability_and_is_normalised(): void {
		$_POST = array(
			'attendance_mode'        => 'total',
			'attendance_min_percent' => '250',
		);

		$this->assertSame( 'denied', $this->page()->perform( 'rule', $this->booking(), self::TODAY, self::NOW ) );
		$this->assertSame( array(), $this->writes );

		$this->caps[] = 'ffc_manage_audiences';
		$this->assertSame( 'rule_saved', $this->page()->perform( 'rule', $this->booking(), self::TODAY, self::NOW ) );

		$this->assertSame(
			array(
				array(
					'update',
					'wp_ffc_audience_bookings',
					array(
						'attendance_mode'        => 'total',
						'attendance_min_percent' => 100,
					),
					array( 'id' => 5 ),
				),
			),
			$this->writes
		);
	}

	public function test_the_post_event_form_needs_the_manage_capability_and_a_real_form(): void {
		$_POST = array(
			'post_event_custom'         => '1',
			'post_event_form'           => '40',
			'post_event_window_days'    => '10',
			'post_event_attendees_only' => '1',
		);

		$this->assertSame( 'denied', $this->page()->perform( 'post_event', $this->booking(), self::TODAY, self::NOW ) );

		$this->caps[] = 'ffc_manage_audiences';
		$this->assertSame( 'post_event_saved', $this->page()->perform( 'post_event', $this->booking(), self::TODAY, self::NOW ) );

		$_POST['post_event_form'] = '41';
		$this->assertSame( 'failed', $this->page()->perform( 'post_event', $this->booking(), self::TODAY, self::NOW ), 'A draft or unknown form is refused.' );

		$this->assertSame(
			array(
				array(
					'update',
					'wp_ffc_audience_bookings',
					array(
						'post_event_custom'         => 1,
						'post_event_form_id'        => 40,
						'post_event_window_days'    => 10,
						'post_event_attendees_only' => 1,
					),
					array( 'id' => 5 ),
				),
			),
			$this->writes,
			'Only the allowed save with a published form is written.'
		);
	}

	public function test_turning_the_switch_off_follows_the_calendar_again(): void {
		$this->caps[] = 'ffc_manage_audiences';
		$_POST        = array(
			'post_event_form'        => '0',
			'post_event_window_days' => '7',
		);

		$this->assertSame( 'post_event_saved', $this->page()->perform( 'post_event', $this->booking(), self::TODAY, self::NOW ) );

		$this->assertSame(
			array(
				array(
					'update',
					'wp_ffc_audience_bookings',
					array(
						'post_event_custom'         => 0,
						'post_event_form_id'        => null,
						'post_event_window_days'    => null,
						'post_event_attendees_only' => null,
					),
					array( 'id' => 5 ),
				),
			),
			$this->writes
		);
	}

	public function test_the_post_event_status_says_which_form_and_when(): void {
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

	/**
	 * A booking that invites to form 40, which no page embeds by default.
	 */
	private function booking_with_form( array $override = array() ): object {
		return $this->booking(
			array_merge(
				array(
					'post_event_custom'         => '1',
					'post_event_form_id'        => '40',
					'post_event_window_days'    => '2',
					'post_event_attendees_only' => '0',
				),
				$override
			)
		);
	}

	public function test_the_fragments_describe_the_booking_as_saved(): void {
		$fragments = AudienceAdminAttendance::fragments( $this->booking_with_form() );

		$this->assertSame(
			array(
				'ffc-attendance-rule-summary'      => 'As saved, 1 of the 3 booked day(s) are required.',
				'ffc-attendance-post-event-status' => 'Participants are invited to "Evaluation", open from 2027-03-03 12:00 to 2027-03-05 12:00.',
				'ffc-post-event-no-page'           => 'No published page embeds this form, so no invitation can be sent. Publish a page holding [ffc_form id="40"].',
			),
			$fragments
		);

		$none = AudienceAdminAttendance::fragments( $this->booking() );
		$this->assertSame( '', $none['ffc-post-event-no-page'], 'No form, nothing to warn about: the warning hides.' );
	}

	public function test_the_csv_adds_the_post_event_columns_when_a_form_applies(): void {
		$this->roster_present_for_7();
		$this->invited = array(
			array(
				'user_id'    => '7',
				'sent_at'    => (string) self::NOW,
				'send_count' => '1',
			),
		);
		$this->answers = array(
			array(
				'user_id' => '7',
				'status'  => 'publish',
			),
		);

		$source = new AudienceAttendanceExportSource( $this->booking_with_form() );
		$rows   = iterator_to_array( $source->rows(), false );

		$this->assertSame( array( 'Invited', 'Post-event form' ), array_slice( $source->header(), -2 ) );
		$this->assertSame( array( gmdate( 'Y-m-d H:i', self::NOW ), 'Certificate issued' ), array_slice( $rows[0], -2 ), 'Ana was invited and her certificate was issued.' );
		$this->assertSame( array( '', '' ), array_slice( $rows[1], -2 ), 'Bia was neither invited nor answered.' );
		$this->assertCount( count( $source->header() ), $rows[1] );
	}

	public function test_the_csv_leaves_the_post_event_columns_out_without_a_form_or_an_invitation(): void {
		$this->roster_present_for_7();

		$source = new AudienceAttendanceExportSource( $this->booking() );

		$this->assertSame( 'Attendance rule met', array_slice( $source->header(), -1 )[0] );
		$this->assertCount( count( $source->header() ), iterator_to_array( $source->rows(), false )[0] );
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
	 * A booking whose form opened when it ended (2027-03-03 12:00) and, for
	 * the attendees only, stays open two days; 7 and 9 attended, 8 did not.
	 *
	 * @return array{0: object, 1: int} The booking and a moment the form is open.
	 */
	private function invitations(): array {
		$this->participants = array( 7, 8, 9 );
		$this->attendance   = array(
			array(
				'user_id'         => 7,
				'attendance_date' => self::TODAY,
				'checked_at'      => self::NOW,
				'method'          => 'qr',
			),
			array(
				'user_id'         => 9,
				'attendance_date' => self::TODAY,
				'checked_at'      => self::NOW,
				'method'          => 'qr',
			),
		);

		return array( $this->booking_with_form( array( 'post_event_attendees_only' => '1' ) ), self::utc( '2027-03-04 10:00' ) );
	}

	public function test_a_resend_goes_only_to_an_eligible_person_and_reports_why_not(): void {
		list( $booking, $open ) = $this->invitations();
		// The page lookup finds the page for 7, and none any more for 9.
		$this->pages = array( self::PAGE, '' );

		$_POST = array( 'invite_user' => '7' );
		$this->assertSame( 'invite_sent', $this->page()->perform( 'invite', $booking, self::TODAY, $open ) );

		$_POST = array( 'invite_user' => '8' );
		$this->assertSame( 'invite_not_eligible', $this->page()->perform( 'invite', $booking, self::TODAY, $open ) );

		$_POST = array( 'invite_user' => '9' );
		$this->assertSame( 'invite_no_page', $this->page()->perform( 'invite', $booking, self::TODAY, $open ) );

		$_POST = array( 'invite_user' => '12' );
		$this->assertSame( 'invite_not_eligible', $this->page()->perform( 'invite', $booking, self::TODAY, $open ), 'A forged id is not on the booking.' );

		$this->assertSame( array( 7, 9 ), $this->looked_up, 'The mailer is reached for the two eligible people only.' );
		$this->assertSame( array( 'p7@example.org' ), array_column( $this->mailed, 0 ) );
		$this->assertCount( 1, $this->writes_to( 'wp_ffc_audience_form_invites' ), 'Only the sent invitation is recorded.' );
	}

	public function test_sending_to_everyone_skips_who_answered_and_who_is_not_eligible(): void {
		list( $booking, $open ) = $this->invitations();
		$this->pages            = array( self::PAGE );
		$this->answers          = array(
			array(
				'user_id' => '9',
				'status'  => 'publish',
			),
		);

		$this->assertSame( 'invite_sent_all', $this->page()->perform( 'invite_all', $booking, self::TODAY, $open ) );

		$this->assertSame( array( 7 ), $this->looked_up, 'Only 7 is sent to.' );
		$this->assertSame( array( 'p7@example.org' ), array_column( $this->mailed, 0 ) );
		$recorded = $this->writes_to( 'wp_ffc_audience_form_invites' );
		$this->assertCount( 1, $recorded );
		$this->assertStringContainsString( 'VALUES (5, 7, 40, ' . $open . ', 1)', $recorded[0][2], 'Sent to 7 at the moment given.' );
	}

	public function test_sending_to_everyone_says_when_nobody_was_left(): void {
		list( $booking, $open ) = $this->invitations();
		$this->participants     = array( 8 );

		$this->assertSame( 'invite_none', $this->page()->perform( 'invite_all', $booking, self::TODAY, $open ) );
		$this->assertSame( array(), $this->mailed );
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
		$_GET              = array( 'booking_id' => '5' );
		$this->booking_row = $this->booking_with_form( array( 'post_event_window_days' => '7' ) );
		$this->invited     = array(
			array(
				'user_id'    => '7',
				'sent_at'    => (string) self::NOW,
				'send_count' => '2',
			),
		);
		$this->answers     = array(
			array(
				'user_id' => '7',
				'status'  => 'publish',
			),
		);

		ob_start();
		$this->page()->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-attendance-invites', $html );
		$this->assertStringContainsString( 'id="ffc-attendance-rule-summary"', $html );
		$this->assertStringContainsString( 'id="ffc-attendance-post-event-status"', $html );
		$this->assertStringContainsString( '<div id="ffc-post-event-no-page" class="notice notice-warning inline ffc-post-event-no-page" data-ffc-fragment-hide-empty>', $html, 'No page embeds the form, so the warning shows.' );
		$this->assertStringContainsString( 'name="ffc_attendance_action" value="invite"', $html );
		$this->assertStringContainsString( '(sent 2 times)', $html );
		$this->assertStringContainsString( 'Certificate issued', $html );
		$this->assertStringContainsString( 'Invitations go out on their own once the event ends', $html, 'Before the event ends nothing can be sent.' );
		$this->assertNotContains( 'Send to everyone who has not answered', self::button_labels( $html ) );
	}

	public function test_a_status_cell_says_when_and_how(): void {
		$this->assertStringContainsString( '—', AudienceAdminAttendance::status_html( null, null ) );
		$this->assertStringContainsString( 'by hand', AudienceAdminAttendance::status_html( self::NOW, 'manual' ) );
		$this->assertStringContainsString( '08:00', AudienceAdminAttendance::status_html( self::NOW, 'qr' ) );
	}

	/**
	 * The roster a render or a poll reads: Ana (7) checked in today by QR,
	 * Bia (8) not.
	 */
	private function roster_present_for_7(): void {
		$this->attendance = array(
			array(
				'user_id'         => 7,
				'attendance_date' => self::TODAY,
				'checked_at'      => self::NOW,
				'method'          => 'qr',
			),
		);
	}

	public function test_the_poll_reports_who_is_present_and_the_qr_only_while_open(): void {
		$this->roster_present_for_7();
		$this->windows = array(
			array(
				'id'                 => '11',
				'booking_id'         => '5',
				'window_date'        => self::TODAY,
				'rotation_seconds'   => '60',
				'opened_at'          => (string) ( self::NOW - 60 ),
				'opened_by'          => '3',
				'closes_at'          => (string) ( self::NOW + 3600 ),
				'closed_at'          => null,
				'closed_by'          => null,
				'geofence_locations' => '',
			),
		);

		$open = AudienceAttendanceAjax::state( $this->booking(), self::TODAY, true, self::NOW + 15 );
		// The organiser closes check-in before the next poll.
		$this->windows[0]['closed_at'] = (string) ( self::NOW + 10 );
		$closed                        = AudienceAttendanceAjax::state( $this->booking(), self::TODAY, true, self::NOW + 15 );

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

		ob_start();
		$this->page()->render_page();
		$html    = (string) ob_get_clean();
		$buttons = self::button_labels( $html );

		$this->assertStringContainsString( 'ffc-page-scheduling-attendance', $html );
		$this->assertStringContainsString( 'name="ffc_attendance_action" value="post_event"', $html );
		$this->assertStringContainsString( 'Its calendar names no form, so this booking invites nobody.', $html );
		$this->assertStringContainsString( 'name="ffc_attendance_action" value="open"', $html );
		$this->assertContains( 'Open check-in', $buttons );
		$this->assertStringContainsString( 'name="rotation_seconds"', $html );
		$this->assertStringNotContainsString( 'ffc-attendance-geofence', $html, 'No saved location, no geofence choice.' );
		$this->assertStringContainsString( '<tr data-user-id="7">', $html );
		$this->assertContains( 'Remove', $buttons );
		$this->assertContains( 'Mark present', $buttons );
		$this->assertStringContainsString( 'id="ffc-attendance-date"', $html, 'A booking over several days has a day picker.' );
		$this->assertMatchesRegularExpression( '/name="attendance_min_percent"[^>]*value="75"/', $html, 'Under "Any day" the share field still holds a value its min accepts, so the rule form can submit.' );
	}

	public function test_a_manager_s_cards_save_as_they_change_and_a_checker_s_do_not(): void {
		$this->roster_present_for_7();
		$_GET = array( 'booking_id' => '5' );

		$this->caps = array( 'ffc_checkin_audiences', 'ffc_manage_audiences' );
		ob_start();
		$this->page()->render_page();
		$html = (string) ob_get_clean();

		foreach ( array( 'attendance_min_percent', 'post_event_form_id', 'post_event_window_days' ) as $key ) {
			$this->assertStringContainsString( 'data-ffc-autosave-record-key="' . $key . '" data-ffc-autosave-record="audience_booking:5"', $html, $key );
		}
		foreach ( array( 'attendance_mode', 'post_event_custom', 'post_event_attendees_only' ) as $name ) {
			$this->assertMatchesRegularExpression( '/<input\b[^>]*name="' . $name . '"[^>]*data-ffc-autosave-record="audience_booking:5"/', $html, $name );
		}
		$this->assertNotContains( 'Save rule', self::button_labels( $html ), 'Nothing is left to save by hand.' );
		$this->assertStringContainsString( 'data-ffc-master="ffc-attendance-mode" data-ffc-master-value="total"', $html, 'The share shows under Share of days only.' );
		$this->assertStringContainsString( 'data-ffc-master="ffc-post-event-custom"', $html, 'The booking\'s own settings show only when it has them.' );

		$this->caps = array( 'ffc_checkin_audiences' );
		ob_start();
		$this->page()->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'data-ffc-autosave-record', $html, 'Without the manage capability the cards are read-only.' );
		$this->assertMatchesRegularExpression( '/<input type="radio"[^>]*name="attendance_mode"/', $html, 'The rule control is still drawn.' );
	}

	public function test_after_the_day_closes_the_card_says_so_and_offers_no_open(): void {
		$this->roster_present_for_7();
		$_GET = array( 'booking_id' => '5' );
		// A one-day booking whose day has passed: check-in closed at 12:30.
		$this->today       = '2020-03-02';
		$this->booking_row = $this->booking(
			array(
				'booking_date' => '2020-03-02',
				'end_date'     => null,
			)
		);

		ob_start();
		$this->page()->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Check-in closed on its own at 12:30', $html );
		$this->assertStringNotContainsString( 'value="open"', $html );
		$this->assertNotContains( 'Open check-in', self::button_labels( $html ) );
	}

	public function test_the_screen_on_another_day_offers_no_qr(): void {
		$this->roster_present_for_7();
		$_GET = array(
			'booking_id' => '5',
			'date'       => '2027-03-03',
		);

		ob_start();
		$this->page()->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<tr data-user-id="8">', $html, 'The roster is drawn.' );
		$this->assertStringNotContainsString( 'value="open"', $html );
		$this->assertNotContains( 'Mark present', self::button_labels( $html ), 'A day that has not come yet cannot be marked.' );
	}
}
