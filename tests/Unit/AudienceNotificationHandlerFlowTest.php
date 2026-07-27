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
 * Flow tests for AudienceNotificationHandler — the two orchestration entry
 * points (booking created / cancelled) and their admin-notification, ICS and
 * recipient-resolution helpers. These need alias mocks of the reader /
 * repository / mailer collaborators, which pollute the global class table for
 * the whole PHP process, so this class runs each test in isolation. The
 * template-rendering / subject / default-template unit tests live in the
 * sibling AudienceNotificationHandlerTest (real collaborators, no isolation).
 *
 * @covers \FreeFormCertificate\Audience\AudienceNotificationHandler
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceNotificationHandlerFlowTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// pcov attribution: preload the class under test so coverage is
		// credited even though it autoloads mid-test (repo convention).
		class_exists( '\FreeFormCertificate\Audience\AudienceNotificationHandler' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return is_string( $email ) && false !== strpos( $email, '@' ) ? $email : false;
			}
		);
		Functions\when( 'get_option' )->justReturn( 'siteadmin@example.com' );

		// DateFormatter: render wall-clock values verbatim so assertions are
		// deterministic and independent of the site locale/format catalog.
		$df = Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' );
		$df->shouldReceive( 'format_wallclock_date' )->andReturnUsing( static fn( $v ) => (string) $v )->byDefault();
		$df->shouldReceive( 'format_wallclock_time' )->andReturnUsing( static fn( $v ) => (string) $v )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Fixtures
	// ------------------------------------------------------------------

	/** @return object A booking row stub. */
	private function booking( array $overrides = array() ): object {
		return (object) array_merge(
			array(
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

	/** @return object A schedule row stub. */
	private function schedule( array $overrides = array() ): object {
		return (object) array_merge(
			array(
				'name'                          => 'My Calendar',
				'notify_on_booking'             => 1,
				'notify_on_cancellation'        => 1,
				'include_ics'                   => 0,
				'email_template_booking'        => '',
				'email_template_cancellation'   => '',
				'notify_admin_on_booking'       => 0,
				'notify_admin_on_cancellation'  => 0,
				'admin_notification_emails'     => '',
			),
			$overrides
		);
	}

	/** Stub a WP_User for get_user_by(). */
	private function wp_user( int $id, string $email, string $name = 'User' ): \WP_User {
		$u               = new \WP_User( $id );
		$u->display_name = $name;
		$u->user_email   = $email;
		return $u;
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

		AudienceNotificationHandler::init();
	}

	// ==================================================================
	// send_booking_created_notification() — guard clauses
	// ==================================================================

	public function test_created_returns_when_booking_missing(): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$reader->shouldReceive( 'get_by_id' )->with( 99 )->andReturn( null );
		// No environment lookup should happen.
		$env = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$env->shouldNotReceive( 'get_by_id' );

		AudienceNotificationHandler::send_booking_created_notification( 99 );
		$this->assertTrue( true ); // reached without error / no mailer call
	}

	public function test_created_returns_when_environment_missing(): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$reader->shouldReceive( 'get_by_id' )->andReturn( $this->booking() );
		$env = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$env->shouldReceive( 'get_by_id' )->with( 7 )->andReturn( null );
		$sched = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$sched->shouldNotReceive( 'get_by_id' );

		AudienceNotificationHandler::send_booking_created_notification( 1 );
		$this->assertTrue( true );
	}

	public function test_created_returns_when_schedule_missing(): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$reader->shouldReceive( 'get_by_id' )->andReturn( $this->booking() );
		$env = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$env->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'schedule_id' => 5, 'name' => 'Room A' ) );
		$sched = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$sched->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( null );

		AudienceNotificationHandler::send_booking_created_notification( 1 );
		$this->assertTrue( true );
	}

	// ==================================================================
	// send_booking_created_notification() — happy path + user toggle
	// ==================================================================

	public function test_created_emails_each_affected_user(): void {
		$this->wire_created(
			$this->schedule( array( 'notify_on_booking' => 1 ) ),
			array( 10, 11 )
		);

		$recipients = array();
		$mailer     = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		$mailer->shouldReceive( 'send' )->andReturnUsing(
			static function ( $to ) use ( &$recipients ) {
				$recipients[] = $to;
				return true;
			}
		);

		Functions\when( 'get_user_by' )->alias(
			fn( $field, $id ) => (int) $id === 10 ? $this->wp_user( 10, 'a@example.com', 'Ana' )
				: ( (int) $id === 11 ? $this->wp_user( 11, 'b@example.com', 'Bruno' ) : $this->wp_user( (int) $id, 'creator@example.com', 'Creator' ) )
		);

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$this->assertContains( 'a@example.com', $recipients );
		$this->assertContains( 'b@example.com', $recipients );
		$this->assertCount( 2, $recipients );
	}

	public function test_created_skips_users_without_email(): void {
		$this->wire_created( $this->schedule(), array( 10, 12 ) );

		$recipients = array();
		$mailer     = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		$mailer->shouldReceive( 'send' )->andReturnUsing(
			static function ( $to ) use ( &$recipients ) {
				$recipients[] = $to;
				return true;
			}
		);

		Functions\when( 'get_user_by' )->alias(
			fn( $field, $id ) => (int) $id === 10 ? $this->wp_user( 10, 'a@example.com' )
				: ( (int) $id === 12 ? $this->wp_user( 12, '' ) /* no email → skipped */ : $this->wp_user( (int) $id, 'c@example.com' ) )
		);

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$this->assertSame( array( 'a@example.com' ), $recipients );
	}

	public function test_created_stops_before_users_when_toggle_off(): void {
		$this->wire_created( $this->schedule( array( 'notify_on_booking' => 0 ) ), array( 10 ) );

		$mailer = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		$mailer->shouldNotReceive( 'send' );

		Functions\when( 'get_user_by' )->justReturn( $this->wp_user( 2, 'creator@example.com', 'Creator' ) );

		AudienceNotificationHandler::send_booking_created_notification( 1 );
		$this->assertTrue( true );
	}

	public function test_created_returns_when_no_affected_users(): void {
		$this->wire_created( $this->schedule(), array() );

		$mailer = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		$mailer->shouldNotReceive( 'send' );

		Functions\when( 'get_user_by' )->justReturn( $this->wp_user( 2, 'creator@example.com' ) );

		AudienceNotificationHandler::send_booking_created_notification( 1 );
		$this->assertTrue( true );
	}

	public function test_created_attaches_ics_when_enabled(): void {
		$this->wire_created( $this->schedule( array( 'include_ics' => 1 ) ), array( 10 ) );

		$ics = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\IcsGenerator' );
		$ics->shouldReceive( 'generate' )->once()->andReturn( "BEGIN:VCALENDAR\nEND:VCALENDAR" );

		$captured   = array();
		$mailer     = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		$mailer->shouldReceive( 'send' )->andReturnUsing(
			static function ( $to, $subject, $body, $attachments ) use ( &$captured ) {
				$captured = $attachments;
				return true;
			}
		);

		Functions\when( 'get_user_by' )->alias( fn( $field, $id ) => $this->wp_user( (int) $id, 'a@example.com' ) );
		Functions\when( 'wp_tempnam' )->alias( fn() => tempnam( sys_get_temp_dir(), 'ffc-ics-test-' ) );

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$this->assertNotEmpty( $captured, 'An ICS attachment path should be passed to the mailer.' );
		// Temp file cleaned up after send.
		$this->assertFileDoesNotExist( $captured[0] );
	}

	// ==================================================================
	// Admin notification (opt-in, independent of the user toggle)
	// ==================================================================

	public function test_created_admin_notification_uses_configured_recipients(): void {
		$this->wire_created(
			$this->schedule(
				array(
					'notify_on_booking'         => 0, // user emails off …
					'notify_admin_on_booking'   => 1, // … but admin opted in
					'admin_notification_emails' => 'ops@example.com, bad-email, boss@example.com',
				)
			),
			array()
		);

		$recipients = array();
		$mailer     = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		$mailer->shouldReceive( 'send' )->andReturnUsing(
			static function ( $to ) use ( &$recipients ) {
				$recipients[] = $to;
				return true;
			}
		);

		Functions\when( 'get_user_by' )->justReturn( $this->wp_user( 2, 'creator@example.com' ) );

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		// Two valid addresses kept, the invalid one dropped, no site-admin fallback.
		$this->assertSame( array( 'ops@example.com', 'boss@example.com' ), $recipients );
	}

	public function test_admin_notification_falls_back_to_site_admin_when_unset(): void {
		$this->wire_created(
			$this->schedule(
				array(
					'notify_on_booking'         => 0,
					'notify_admin_on_booking'   => 1,
					'admin_notification_emails' => '',
				)
			),
			array()
		);

		$recipients = array();
		$mailer     = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		$mailer->shouldReceive( 'send' )->andReturnUsing(
			static function ( $to ) use ( &$recipients ) {
				$recipients[] = $to;
				return true;
			}
		);

		Functions\when( 'get_user_by' )->justReturn( $this->wp_user( 2, 'creator@example.com' ) );

		AudienceNotificationHandler::send_booking_created_notification( 1 );

		$this->assertSame( array( 'siteadmin@example.com' ), $recipients );
	}

	// ==================================================================
	// send_booking_cancelled_notification()
	// ==================================================================

	public function test_cancelled_emails_affected_users_and_admin(): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$reader->shouldReceive( 'get_by_id' )->andReturn( $this->booking() );
		$reader->shouldReceive( 'get_booking_audiences' )->andReturn(
			array( (object) array( 'name' => 'Group A' ) )
		);
		$reader->shouldReceive( 'get_booking_users' )->andReturn( array( 20 ) );

		$env = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$env->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'schedule_id' => 5, 'name' => 'Room A' ) );

		$sched = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$sched->shouldReceive( 'get_by_id' )->andReturn(
			$this->schedule(
				array(
					'notify_on_cancellation'        => 1,
					'notify_admin_on_cancellation'  => 1,
					'admin_notification_emails'     => 'ops@example.com',
				)
			)
		);
		$sched->shouldReceive( 'get_environment_label' )->andReturn( 'Room A' );

		$recipients = array();
		$mailer     = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		$mailer->shouldReceive( 'send' )->andReturnUsing(
			static function ( $to ) use ( &$recipients ) {
				$recipients[] = $to;
				return true;
			}
		);

		Functions\when( 'get_user_by' )->alias(
			fn( $field, $id ) => (int) $id === 20 ? $this->wp_user( 20, 'member@example.com' ) : $this->wp_user( (int) $id, 'canceller@example.com', 'Manager' )
		);

		AudienceNotificationHandler::send_booking_cancelled_notification( 1, 'Room double-booked' );

		$this->assertContains( 'ops@example.com', $recipients );   // admin
		$this->assertContains( 'member@example.com', $recipients ); // affected user
	}

	public function test_cancelled_returns_when_booking_missing(): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$reader->shouldReceive( 'get_by_id' )->andReturn( null );
		$mailer = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		$mailer->shouldNotReceive( 'send' );

		AudienceNotificationHandler::send_booking_cancelled_notification( 1, 'x' );
		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// Shared wiring for the created-notification happy paths.
	// ------------------------------------------------------------------

	/**
	 * @param object    $schedule       Schedule row to return.
	 * @param array<int> $affected_users Affected user IDs.
	 */
	private function wire_created( object $schedule, array $affected_users ): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$reader->shouldReceive( 'get_by_id' )->andReturn( $this->booking() );
		$reader->shouldReceive( 'get_booking_audiences' )->andReturn(
			array( (object) array( 'name' => 'Group A' ) )
		);
		$reader->shouldReceive( 'get_all_affected_users' )->andReturn( $affected_users );

		$env = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$env->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'schedule_id' => 5, 'name' => 'Room A' ) );

		$sched = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$sched->shouldReceive( 'get_by_id' )->andReturn( $schedule );
		$sched->shouldReceive( 'get_environment_label' )->andReturn( 'Room A' );
	}
}
