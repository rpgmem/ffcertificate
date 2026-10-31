<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceNotificationHandler;

/**
 * Tests for the opt-in audience admin notifications (item 4).
 *
 * Exercises the private admin-notification helpers via Reflection. Every
 * test runs in the shared process: the real SchedulingMailer, the e-mail
 * chrome and EmailService run down to a stubbed `wp_mail()`, which records
 * what was sent.
 *
 * @covers \FreeFormCertificate\Audience\AudienceNotificationHandler
 */
class AudienceAdminNotificationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Every `wp_mail()` call: to, subject, body.
	 *
	 * @var list<array{to: string, subj: string, body: string}>
	 */
	private array $sent = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Audience\AudienceNotificationHandler' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'is_email' )->alias( fn( $e ) => (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		// The site admin is the fallback recipient; every other option reads
		// its default, so e-mails are enabled and debug logging is off.
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'admin_email' === $key ? 'admin@site.com' : $default
		);

		// What the e-mail chrome reaches on its way to wp_mail().
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( (string) $format, (int) $ts ) );

		$this->sent = array();
		$sent       = &$this->sent;
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subj, $body ) use ( &$sent ) {
				$sent[] = array(
					'to'   => (string) $to,
					'subj' => (string) $subj,
					'body' => (string) $body,
				);
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function invoke( string $method, array $args ) {
		$ref = new \ReflectionMethod( AudienceNotificationHandler::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	private function booking_data(): array {
		return array(
			'environment_name'    => 'Room A',
			'booking_date'        => '2026-03-01',
			'schedule_name'       => 'Cal',
			'start_time'          => '09:00',
			'end_time'            => '10:00',
			'audiences'           => 'Group A',
			'creator_name'        => 'Booker',
			'description'         => 'Desc',
			'cancelled_by_name'   => 'Manager',
			'cancellation_reason' => 'Conflict',
		);
	}

	// ==================================================================
	// admin_recipients()
	// ==================================================================

	public function test_admin_recipients_parses_and_filters_list(): void {
		$schedule = (object) array( 'admin_notification_emails' => 'a@x.com, not-an-email , b@y.com' );
		$result   = $this->invoke( 'admin_recipients', array( $schedule ) );
		$this->assertSame( array( 'a@x.com', 'b@y.com' ), $result );
	}

	public function test_admin_recipients_falls_back_to_site_admin(): void {
		$schedule = (object) array( 'admin_notification_emails' => '' );
		$result   = $this->invoke( 'admin_recipients', array( $schedule ) );
		$this->assertSame( array( 'admin@site.com' ), $result );
	}

	// ==================================================================
	// admin_details_html()
	// ==================================================================

	public function test_admin_details_html_contains_labels_and_values(): void {
		$html = $this->invoke(
			'admin_details_html',
			array( 'Heading', array( 'Calendar' => 'Cal A', 'Date' => '2026-03-01' ) )
		);
		$this->assertStringContainsString( 'Heading', $html );
		$this->assertStringContainsString( 'Calendar', $html );
		$this->assertStringContainsString( 'Cal A', $html );
		// The info-box is now inline-styled (Gmail-safe) rather than class-based.
		$this->assertStringContainsString( 'background:#f0f6fc', $html );
	}

	// ==================================================================
	// maybe_notify_admin_of_booking()
	// ==================================================================

	public function test_booking_admin_notification_sends_when_enabled(): void {
		$schedule = (object) array(
			'notify_admin_on_booking'   => 1,
			'admin_notification_emails' => 'ops@x.com',
		);
		$this->invoke( 'maybe_notify_admin_of_booking', array( $schedule, $this->booking_data() ) );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'ops@x.com', $this->sent[0]['to'] );
		$this->assertStringContainsString( 'Room A', $this->sent[0]['subj'] );
		$this->assertStringContainsString( 'Group A', $this->sent[0]['body'] );
	}

	public function test_booking_admin_notification_skips_when_disabled(): void {
		$schedule = (object) array(
			'notify_admin_on_booking'   => 0,
			'admin_notification_emails' => 'ops@x.com',
		);
		$this->invoke( 'maybe_notify_admin_of_booking', array( $schedule, $this->booking_data() ) );

		$this->assertSame( array(), $this->sent, 'Nothing is sent while the schedule has not opted in.' );
	}

	// ==================================================================
	// maybe_notify_admin_of_cancellation()
	// ==================================================================

	public function test_cancellation_admin_notification_sends_with_fallback_recipient(): void {
		$schedule = (object) array(
			'notify_admin_on_cancellation' => 1,
			'admin_notification_emails'    => '',
		);
		$this->invoke( 'maybe_notify_admin_of_cancellation', array( $schedule, $this->booking_data() ) );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'admin@site.com', $this->sent[0]['to'] );
		$this->assertStringContainsString( 'Conflict', $this->sent[0]['body'] );
	}

	public function test_cancellation_admin_notification_skips_when_disabled(): void {
		$schedule = (object) array( 'notify_admin_on_cancellation' => 0 );
		$this->invoke( 'maybe_notify_admin_of_cancellation', array( $schedule, $this->booking_data() ) );

		$this->assertSame( array(), $this->sent, 'Nothing is sent while the schedule has not opted in.' );
	}
}
