<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceNotificationHandler;

/**
 * Flow tests for AudienceNotificationHandler — the orchestration entry points
 * (booking created / cancelled / overridden) and their admin-notification, ICS
 * and recipient-resolution helpers. The template-rendering / subject /
 * default-template unit tests live in the sibling AudienceNotificationHandlerTest.
 *
 * Everything below the handler runs for real — AudienceBookingReader,
 * AudienceReader, AudienceEnvironmentRepository, AudienceScheduleRepository,
 * DateFormatter, EmailTemplates, IcsGenerator, SchedulingMailer and the
 * plugin's e-mail path down to a stubbed `wp_mail()` — over a `$wpdb` double
 * that answers each read by its SQL text from the rows a test sets, and fails
 * the test on a query it does not know.
 *
 * @covers \FreeFormCertificate\Audience\AudienceNotificationHandler
 */
class AudienceNotificationHandlerFlowTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, object> Booking ID => booking row. */
	private array $bookings = array();

	/** @var array<int, object> Environment ID => environment row. */
	private array $environments = array();

	/** @var array<int, object> Schedule ID => schedule row. */
	private array $schedules = array();

	/** @var list<object> Audience rows linked to every booking. */
	private array $audiences = array();

	/** @var list<int> User IDs linked directly to every booking. */
	private array $booking_users = array();

	/** @var array<int, list<int>> Audience ID => member user IDs. */
	private array $members = array();

	/** @var list<array{0: string, 1: string, 2: string, 3: mixed, 4: mixed}> Every `wp_mail()` call. */
	private array $mailed = array();

	/** @var list<string> ICS contents read from each attachment while `wp_mail()` ran. */
	private array $attached = array();

	/** @var list<string> Every read query the double ran. */
	private array $reads = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->bookings      = array();
		$this->environments  = array();
		$this->schedules     = array();
		$this->audiences     = array();
		$this->booking_users = array();
		$this->members       = array();
		$this->mailed        = array();
		$this->attached      = array();
		$this->reads         = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		// What AudienceReader::get_all() reads to find an audience's children.
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ) => gmdate( $format, $ts ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// send_email() guards on function_exists( 'wp_tempnam' ): stub it so
		// the branch is chosen here, not by an earlier test.
		Functions\when( 'wp_tempnam' )->alias( static fn() => tempnam( sys_get_temp_dir(), 'ffc-ics-test-' ) );
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return is_string( $email ) && false !== strpos( $email, '@' ) ? $email : false;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'admin_email' === $key ) {
					return 'siteadmin@example.com';
				}
				if ( 'ffc_settings' === $key ) {
					// What DateFormatter and the e-mail kill-switch read: the
					// wall-clock values render verbatim, e-mails on, debug off.
					return array(
						'date_format' => 'Y-m-d',
						'time_format' => 'H:i:s',
					);
				}
				return $default;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers = array(), $attachments = array() ) {
				$this->mailed[] = array( $to, $subject, $body, $headers, $attachments );
				foreach ( (array) $attachments as $file ) {
					$this->attached[] = is_file( $file ) ? (string) file_get_contents( $file ) : '';
				}
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
	 * are answered by SQL text from the rows a test sets.
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
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = $q;
				if ( preg_match( '/FROM wp_ffc_audience_bookings b\s.*WHERE b\.id = (\d+)$/s', $q, $m ) ) {
					return isset( $this->bookings[ (int) $m[1] ] ) ? clone $this->bookings[ (int) $m[1] ] : null;
				}
				if ( preg_match( '/^SELECT \* FROM wp_ffc_audience_environments WHERE id = (\d+)$/', $q, $m ) ) {
					return isset( $this->environments[ (int) $m[1] ] ) ? clone $this->environments[ (int) $m[1] ] : null;
				}
				if ( preg_match( '/^SELECT \* FROM wp_ffc_audience_schedules WHERE id = (\d+)$/', $q, $m ) ) {
					return isset( $this->schedules[ (int) $m[1] ] ) ? clone $this->schedules[ (int) $m[1] ] : null;
				}
				$this->fail( 'Unexpected get_row(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = $q;
				if ( false !== strpos( $q, 'INNER JOIN wp_ffc_audience_booking_audiences ba' ) ) {
					return $this->audiences;
				}
				if ( false !== strpos( $q, 'FROM wp_ffc_audiences' ) && false !== strpos( $q, 'parent_id' ) ) {
					return array(); // No child audiences.
				}
				$this->fail( 'Unexpected get_results(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = $q;
				if ( preg_match( '/^SELECT user_id FROM wp_ffc_audience_booking_users WHERE booking_id = \d+$/', $q ) ) {
					return array_map( 'strval', $this->booking_users );
				}
				if ( preg_match( '/^SELECT DISTINCT user_id FROM wp_ffc_audience_members WHERE audience_id IN \((\d+)\)$/', $q, $m ) ) {
					return array_map( 'strval', $this->members[ (int) $m[1] ] ?? array() );
				}
				$this->fail( 'Unexpected get_col(): ' . $q );
			}
		);
		return $wpdb;
	}

	// ------------------------------------------------------------------
	// Fixtures
	// ------------------------------------------------------------------

	/** @return object A booking row. */
	private function booking( array $overrides = array() ): object {
		return (object) array_merge(
			array(
				'id'             => 1,
				'environment_id' => 7,
				'created_by'     => 2,
				'cancelled_by'   => 3,
				'booking_date'   => '2026-05-20',
				'start_time'     => '09:00:00',
				'end_time'       => '10:00:00',
				'description'    => 'Team meeting',
			),
			$overrides
		);
	}

	/** @return object A schedule row. */
	private function schedule( array $overrides = array() ): object {
		return (object) array_merge(
			array(
				'id'                           => 5,
				'name'                         => 'My Calendar',
				'notify_on_booking'            => 1,
				'notify_on_cancellation'       => 1,
				'include_ics'                  => 0,
				'email_template_booking'       => '',
				'email_template_cancellation'  => '',
				'notify_admin_on_booking'      => 0,
				'notify_admin_on_cancellation' => 0,
				'admin_notification_emails'    => '',
			),
			$overrides
		);
	}

	/** A WP_User for get_user_by(). */
	private function wp_user( int $id, string $email, string $name = 'User' ): \WP_User {
		$u               = new \WP_User( $id );
		$u->display_name = $name;
		$u->user_email   = $email;
		return $u;
	}

	/**
	 * Store booking 1 in environment 7 ("Room A") of calendar 5, linked to
	 * audience "Group A", with the given directly linked users.
	 *
	 * @param object     $schedule Schedule row to store.
	 * @param array<int> $users    User IDs linked to the booking.
	 */
	private function store( object $schedule, array $users, array $booking = array() ): void {
		$this->bookings[1]     = $this->booking( $booking );
		$this->environments[7] = (object) array(
			'id'          => 7,
			'schedule_id' => 5,
			'name'        => 'Room A',
		);
		$this->schedules[5]    = $schedule;
		$this->audiences       = array(
			(object) array(
				'id'   => 30,
				'name' => 'Group A',
			),
		);
		$this->booking_users   = $users;
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
	 * How many read queries selected FROM a table (a JOIN does not count).
	 */
	private function reads_of( string $table ): int {
		return count( array_filter( $this->reads, static fn( $q ) => 1 === preg_match( '/FROM ' . preg_quote( $table, '/' ) . '(\s|$)/', $q ) ) );
	}

	// ==================================================================
	// init()
	// ==================================================================

	public function test_init_registers_booking_hooks(): void {
		Functions\expect( 'add_action' )->once()->with(
			'ffcertificate_audience_booking_created',
			array( AudienceNotificationHandler::class, 'send_booking_created_notification' )
		);
		Functions\expect( 'add_action' )->once()->with(
			'ffcertificate_audience_booking_cancelled',
			array( AudienceNotificationHandler::class, 'send_booking_cancelled_notification' ),
			10,
			2
		);
		Functions\expect( 'add_action' )->once()->with(
			'ffcertificate_audience_booking_overridden',
			array( AudienceNotificationHandler::class, 'send_booking_overridden_notification' ),
			10,
			3
		);

		AudienceNotificationHandler::init();
	}

	// ==================================================================
	// send_booking_created_notification() — guard clauses
	// ==================================================================

	public function test_created_returns_when_booking_missing(): void {
		AudienceNotificationHandler::send_booking_created_notification( 99 );

		$this->assertCount( 1, $this->reads, 'Only the booking is read.' );
		$this->assertStringContainsString( 'WHERE b.id = 99', $this->reads[0] );
		$this->assertSame( 0, $this->reads_of( 'wp_ffc_audience_environments' ), 'No environment lookup happens.' );
		$this->assertSame( array(), $this->mailed );
	}

	public function test_created_returns_when_environment_missing(): void {
		$this->bookings[1] = $this->booking();

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$this->assertTrue( in_array( 'SELECT * FROM wp_ffc_audience_environments WHERE id = 7', $this->reads, true ) );
		$this->assertSame( 0, $this->reads_of( 'wp_ffc_audience_schedules' ), 'No schedule lookup happens.' );
		$this->assertSame( array(), $this->mailed );
	}

	public function test_created_returns_when_schedule_missing(): void {
		$this->bookings[1]     = $this->booking();
		$this->environments[7] = (object) array(
			'id'          => 7,
			'schedule_id' => 5,
			'name'        => 'Room A',
		);

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$this->assertSame( 'SELECT * FROM wp_ffc_audience_schedules WHERE id = 5', end( $this->reads ), 'The schedule lookup is the last read.' );
		$this->assertSame( array(), $this->mailed );
	}

	// ==================================================================
	// send_booking_created_notification() — happy path + user toggle
	// ==================================================================

	public function test_created_emails_each_affected_user(): void {
		// 10 is on the booking directly, 11 through its audience.
		$this->store( $this->schedule( array( 'notify_on_booking' => 1 ) ), array( 10 ) );
		$this->members[30] = array( 11 );

		Functions\when( 'get_user_by' )->alias(
			fn( $field, $id ) => (int) $id === 10 ? $this->wp_user( 10, 'a@example.com', 'Ana' )
				: ( (int) $id === 11 ? $this->wp_user( 11, 'b@example.com', 'Bruno' ) : $this->wp_user( (int) $id, 'creator@example.com', 'Creator' ) )
		);

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$recipients = $this->recipients();
		$this->assertContains( 'a@example.com', $recipients );
		$this->assertContains( 'b@example.com', $recipients );
		$this->assertCount( 2, $recipients );
	}

	public function test_created_skips_users_without_email(): void {
		$this->store( $this->schedule(), array( 10, 12 ) );

		Functions\when( 'get_user_by' )->alias(
			fn( $field, $id ) => (int) $id === 10 ? $this->wp_user( 10, 'a@example.com' )
				: ( (int) $id === 12 ? $this->wp_user( 12, '' ) /* no email → skipped */ : $this->wp_user( (int) $id, 'c@example.com' ) )
		);

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$this->assertSame( array( 'a@example.com' ), $this->recipients() );
	}

	public function test_created_stops_before_users_when_toggle_off(): void {
		$this->store( $this->schedule( array( 'notify_on_booking' => 0 ) ), array( 10 ) );

		Functions\when( 'get_user_by' )->justReturn( $this->wp_user( 2, 'creator@example.com', 'Creator' ) );

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$this->assertSame( array(), $this->mailed );
		$this->assertSame( 0, $this->reads_of( 'wp_ffc_audience_members' ), 'The affected users are never resolved.' );
	}

	public function test_created_returns_when_no_affected_users(): void {
		$this->store( $this->schedule(), array() );

		Functions\when( 'get_user_by' )->justReturn( $this->wp_user( 2, 'creator@example.com' ) );

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$this->assertSame( array(), $this->mailed );
	}

	public function test_created_attaches_ics_when_enabled(): void {
		$this->store( $this->schedule( array( 'include_ics' => 1 ) ), array( 10 ) );

		Functions\when( 'get_user_by' )->alias( fn( $field, $id ) => $this->wp_user( (int) $id, 'a@example.com' ) );

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$this->assertCount( 1, $this->mailed );
		$captured = $this->mailed[0][4];
		$this->assertNotEmpty( $captured, 'An ICS attachment path should be passed to the mailer.' );
		$this->assertCount( 1, $this->attached, 'The ICS is generated once.' );
		$this->assertStringStartsWith( 'BEGIN:VCALENDAR', $this->attached[0] );
		$this->assertStringContainsString( 'METHOD:REQUEST', $this->attached[0] );
		// Temp file cleaned up after send.
		$this->assertFileDoesNotExist( $captured[0] );
	}

	// ==================================================================
	// Admin notification (opt-in, independent of the user toggle)
	// ==================================================================

	public function test_created_admin_notification_uses_configured_recipients(): void {
		$this->store(
			$this->schedule(
				array(
					'notify_on_booking'         => 0, // user emails off …
					'notify_admin_on_booking'   => 1, // … but admin opted in
					'admin_notification_emails' => 'ops@example.com, bad-email, boss@example.com',
				)
			),
			array()
		);

		Functions\when( 'get_user_by' )->justReturn( $this->wp_user( 2, 'creator@example.com' ) );

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		// Two valid addresses kept, the invalid one dropped, no site-admin fallback.
		$this->assertSame( array( 'ops@example.com', 'boss@example.com' ), $this->recipients() );
	}

	public function test_admin_notification_falls_back_to_site_admin_when_unset(): void {
		$this->store(
			$this->schedule(
				array(
					'notify_on_booking'         => 0,
					'notify_admin_on_booking'   => 1,
					'admin_notification_emails' => '',
				)
			),
			array()
		);

		Functions\when( 'get_user_by' )->justReturn( $this->wp_user( 2, 'creator@example.com' ) );

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$this->assertSame( array( 'siteadmin@example.com' ), $this->recipients() );
	}

	// ==================================================================
	// send_booking_cancelled_notification()
	// ==================================================================

	public function test_cancelled_emails_affected_users_and_admin(): void {
		$this->store(
			$this->schedule(
				array(
					'notify_on_cancellation'       => 1,
					'notify_admin_on_cancellation' => 1,
					'admin_notification_emails'    => 'ops@example.com',
				)
			),
			array( 20 )
		);

		Functions\when( 'get_user_by' )->alias(
			fn( $field, $id ) => (int) $id === 20 ? $this->wp_user( 20, 'member@example.com' ) : $this->wp_user( (int) $id, 'canceller@example.com', 'Manager' )
		);

		AudienceNotificationHandler::send_booking_cancelled_notification( 1, 'Room double-booked' );

		$recipients = $this->recipients();
		$this->assertContains( 'ops@example.com', $recipients );   // admin
		$this->assertContains( 'member@example.com', $recipients ); // affected user
	}

	public function test_overridden_emails_the_owner_the_admin_cancellation_and_the_others_the_usual_notice(): void {
		// The owner (2) is also a participant: told once, by the new e-mail.
		$this->store(
			$this->schedule( array( 'notify_on_cancellation' => 1 ) ),
			array( 2, 20 ),
			array(
				'created_by'   => 2,
				'cancelled_by' => 3,
			)
		);
		$this->audiences = array();

		Functions\when( 'get_user_by' )->alias(
			fn( $field, $id ) => $this->wp_user( (int) $id, 'u' . (int) $id . '@example.com', 'User ' . (int) $id )
		);

		AudienceNotificationHandler::send_booking_overridden_notification( 1, 2, 'Overridden by a priority booking' );

		$this->assertSame( array( 'u2@example.com', 'u20@example.com' ), $this->recipients() );
		$this->assertStringContainsString( 'cancelled by an administrator', $this->mailed[0][1] );
		$this->assertStringContainsString( 'Overridden by a priority booking', $this->mailed[0][2] );
		$this->assertStringContainsString( 'Room A', $this->mailed[0][2] );
		$this->assertStringNotContainsString( 'cancelled by an administrator', $this->mailed[1][1], 'The other participants get the usual cancellation notice.' );
	}

	public function test_cancelled_returns_when_booking_missing(): void {
		AudienceNotificationHandler::send_booking_cancelled_notification( 1, 'x' );

		$this->assertSame( array(), $this->mailed );
	}
}
