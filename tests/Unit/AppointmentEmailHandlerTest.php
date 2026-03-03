<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentEmailHandler;

/**
 * Tests for AppointmentEmailHandler: email-disabled short-circuits,
 * invalid-email guard, booking confirmation, admin notification,
 * approval, cancellation, reminder, and private helper methods.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentEmailHandler
 */
class AppointmentEmailHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private AppointmentEmailHandler $handler;

    /** @var bool Track whether wp_mail was called */
    private bool $mail_sent = false;

    /** @var array<string, mixed> Last arguments passed to wp_mail */
    private array $last_mail = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Translation stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();

        // WordPress stubs
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'ffc_settings' ) {
                return array(); // emails not disabled by default
            }
            if ( $key === 'date_format' ) {
                return 'Y-m-d';
            }
            if ( $key === 'ffc_dashboard_page_id' ) {
                return 0;
            }
            return $default;
        } );
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'date_i18n' )->alias( function ( $format, $timestamp = false ) {
            return gmdate( $format, $timestamp ?: time() );
        } );
        Functions\when( 'is_email' )->alias( function ( $email ) {
            return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
        } );
        Functions\when( 'home_url' )->alias( function ( $path = '' ) {
            return 'https://example.com' . $path;
        } );
        Functions\when( 'get_permalink' )->justReturn( 'https://example.com/dashboard' );
        Functions\when( 'add_query_arg' )->alias( function () {
            $args = func_get_args();
            // add_query_arg( array, url ) or add_query_arg( key, value, url )
            if ( is_array( $args[0] ) ) {
                $url = $args[1] ?? '';
                $params = $args[0];
            } else {
                $url = $args[2] ?? '';
                $params = array( $args[0] => $args[1] );
            }
            $sep = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
            return $url . $sep . http_build_query( $params );
        } );
        Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
            return 'https://example.com/wp-admin/' . $path;
        } );

        // wp_mail stub — capture calls
        $this->mail_sent = false;
        $this->last_mail = array();
        $sent = &$this->mail_sent;
        $last = &$this->last_mail;
        Functions\when( 'wp_mail' )->alias( function ( $to, $subject, $body, $headers = array(), $attachments = array() ) use ( &$sent, &$last ) {
            $sent = true;
            $last = compact( 'to', 'subject', 'body', 'headers', 'attachments' );
            return true;
        } );

        // wp_kses_post stub
        Functions\when( 'wp_kses_post' )->returnArg();

        $this->handler = new AppointmentEmailHandler();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper factories
    // ------------------------------------------------------------------

    private function makeAppointment( array $overrides = [] ): array {
        return array_merge( array(
            'id'                  => 1,
            'calendar_id'         => 1,
            'name'                => 'John Doe',
            'email'               => 'john@example.com',
            'appointment_date'    => '2026-03-15',
            'start_time'          => '09:00',
            'status'              => 'confirmed',
            'user_notes'          => '',
            'confirmation_token'  => 'tok123',
            'cancellation_reason' => '',
        ), $overrides );
    }

    private function makeCalendar( array $overrides = [] ): array {
        return array_merge( array(
            'id'                => 1,
            'title'             => 'Test Calendar',
            'requires_approval' => 0,
            'allow_cancellation' => 1,
            'email_config'      => '{"admin_email":"admin@example.com"}',
        ), $overrides );
    }

    // ==================================================================
    // send_booking_confirmation()
    // ==================================================================

    public function test_booking_confirmation_sends_email(): void {
        $this->handler->send_booking_confirmation(
            $this->makeAppointment(),
            $this->makeCalendar()
        );

        $this->assertTrue( $this->mail_sent );
        $this->assertSame( 'john@example.com', $this->last_mail['to'] );
        $this->assertStringContainsString( 'Test Calendar', $this->last_mail['subject'] );
    }

    public function test_booking_confirmation_skipped_when_emails_disabled(): void {
        // Override get_option to disable emails
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'ffc_settings' ) {
                return array( 'disable_all_emails' => true );
            }
            return $default;
        } );

        $this->handler->send_booking_confirmation(
            $this->makeAppointment(),
            $this->makeCalendar()
        );

        $this->assertFalse( $this->mail_sent );
    }

    public function test_booking_confirmation_skipped_for_empty_email(): void {
        $this->handler->send_booking_confirmation(
            $this->makeAppointment( array( 'email' => '' ) ),
            $this->makeCalendar()
        );

        $this->assertFalse( $this->mail_sent );
    }

    public function test_booking_confirmation_skipped_for_invalid_email(): void {
        $this->handler->send_booking_confirmation(
            $this->makeAppointment( array( 'email' => 'not-an-email' ) ),
            $this->makeCalendar()
        );

        $this->assertFalse( $this->mail_sent );
    }

    public function test_booking_confirmation_includes_pending_message_when_requires_approval(): void {
        $this->handler->send_booking_confirmation(
            $this->makeAppointment( array( 'status' => 'pending' ) ),
            $this->makeCalendar( array( 'requires_approval' => 1 ) )
        );

        $this->assertTrue( $this->mail_sent );
        $this->assertStringContainsString( 'pending approval', $this->last_mail['body'] );
    }

    public function test_booking_confirmation_includes_user_notes_when_provided(): void {
        $this->handler->send_booking_confirmation(
            $this->makeAppointment( array( 'user_notes' => 'Please call ahead' ) ),
            $this->makeCalendar()
        );

        $this->assertTrue( $this->mail_sent );
        $this->assertStringContainsString( 'Please call ahead', $this->last_mail['body'] );
    }

    // ==================================================================
    // send_admin_notification()
    // ==================================================================

    public function test_admin_notification_sends_to_configured_email(): void {
        $this->handler->send_admin_notification(
            $this->makeAppointment(),
            $this->makeCalendar()
        );

        $this->assertTrue( $this->mail_sent );
        $this->assertSame( 'admin@example.com', $this->last_mail['to'] );
    }

    public function test_admin_notification_skipped_when_emails_disabled(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'ffc_settings' ) {
                return array( 'disable_all_emails' => true );
            }
            return $default;
        } );

        $this->handler->send_admin_notification(
            $this->makeAppointment(),
            $this->makeCalendar()
        );

        $this->assertFalse( $this->mail_sent );
    }

    // ==================================================================
    // send_cancellation_notification()
    // ==================================================================

    public function test_cancellation_notification_sends_email(): void {
        $this->handler->send_cancellation_notification(
            $this->makeAppointment( array( 'status' => 'cancelled' ) ),
            $this->makeCalendar()
        );

        $this->assertTrue( $this->mail_sent );
        $this->assertStringContainsString( 'Cancelled', $this->last_mail['subject'] );
    }

    public function test_cancellation_notification_includes_reason(): void {
        $this->handler->send_cancellation_notification(
            $this->makeAppointment( array(
                'status'              => 'cancelled',
                'cancellation_reason' => 'Scheduling conflict',
            ) ),
            $this->makeCalendar()
        );

        $this->assertTrue( $this->mail_sent );
        $this->assertStringContainsString( 'Scheduling conflict', $this->last_mail['body'] );
    }

    // ==================================================================
    // send_approval_notification()
    // ==================================================================

    public function test_approval_notification_sends_email(): void {
        $this->handler->send_approval_notification(
            $this->makeAppointment( array( 'status' => 'confirmed' ) ),
            $this->makeCalendar()
        );

        $this->assertTrue( $this->mail_sent );
        $this->assertStringContainsString( 'Approved', $this->last_mail['subject'] );
    }

    // ==================================================================
    // send_reminder()
    // ==================================================================

    public function test_reminder_sends_email(): void {
        $this->handler->send_reminder(
            $this->makeAppointment(),
            $this->makeCalendar()
        );

        $this->assertTrue( $this->mail_sent );
        $this->assertStringContainsString( 'Reminder', $this->last_mail['subject'] );
    }

    public function test_reminder_includes_cancel_link_when_allowed(): void {
        $this->handler->send_reminder(
            $this->makeAppointment(),
            $this->makeCalendar( array( 'allow_cancellation' => 1 ) )
        );

        $this->assertTrue( $this->mail_sent );
        $this->assertStringContainsString( 'Cancel Appointment', $this->last_mail['body'] );
    }

    public function test_reminder_excludes_cancel_link_when_not_allowed(): void {
        $this->handler->send_reminder(
            $this->makeAppointment(),
            $this->makeCalendar( array( 'allow_cancellation' => 0 ) )
        );

        $this->assertTrue( $this->mail_sent );
        $this->assertStringNotContainsString( 'Cancel Appointment', $this->last_mail['body'] );
    }
}
