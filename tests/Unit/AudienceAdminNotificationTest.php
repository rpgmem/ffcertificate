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
 * Tests for the opt-in audience admin notifications (item 4).
 *
 * Exercises the private admin-notification helpers via Reflection, with
 * SchedulingMailer alias-mocked to capture the outbound admin emails.
 *
 * @covers \FreeFormCertificate\Audience\AudienceNotificationHandler
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAdminNotificationTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        class_exists( '\FreeFormCertificate\Audience\AudienceNotificationHandler' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'is_email' )->alias( fn( $e ) => (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ) );
        Functions\when( 'get_option' )->justReturn( 'admin@site.com' );
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
        $this->assertStringContainsString( 'info-box', $html );
    }

    // ==================================================================
    // maybe_notify_admin_of_booking()
    // ==================================================================

    public function test_booking_admin_notification_sends_when_enabled(): void {
        $sent = array();
        Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' )
            ->shouldReceive( 'send' )
            ->andReturnUsing(
                function ( $to, $subj, $body ) use ( &$sent ) {
                    $sent[] = compact( 'to', 'subj', 'body' );
                    return true;
                }
            );

        $schedule = (object) array(
            'notify_admin_on_booking'   => 1,
            'admin_notification_emails' => 'ops@x.com',
        );
        $this->invoke( 'maybe_notify_admin_of_booking', array( $schedule, $this->booking_data() ) );

        $this->assertCount( 1, $sent );
        $this->assertSame( 'ops@x.com', $sent[0]['to'] );
        $this->assertStringContainsString( 'Room A', $sent[0]['subj'] );
        $this->assertStringContainsString( 'Group A', $sent[0]['body'] );
    }

    public function test_booking_admin_notification_skips_when_disabled(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' )
            ->shouldNotReceive( 'send' );

        $schedule = (object) array(
            'notify_admin_on_booking'   => 0,
            'admin_notification_emails' => 'ops@x.com',
        );
        $this->invoke( 'maybe_notify_admin_of_booking', array( $schedule, $this->booking_data() ) );

        $this->assertTrue( true );
    }

    // ==================================================================
    // maybe_notify_admin_of_cancellation()
    // ==================================================================

    public function test_cancellation_admin_notification_sends_with_fallback_recipient(): void {
        $sent = array();
        Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' )
            ->shouldReceive( 'send' )
            ->andReturnUsing(
                function ( $to, $subj, $body ) use ( &$sent ) {
                    $sent[] = compact( 'to', 'subj', 'body' );
                    return true;
                }
            );

        $schedule = (object) array(
            'notify_admin_on_cancellation' => 1,
            'admin_notification_emails'    => '',
        );
        $this->invoke( 'maybe_notify_admin_of_cancellation', array( $schedule, $this->booking_data() ) );

        $this->assertCount( 1, $sent );
        $this->assertSame( 'admin@site.com', $sent[0]['to'] );
        $this->assertStringContainsString( 'Conflict', $sent[0]['body'] );
    }

    public function test_cancellation_admin_notification_skips_when_disabled(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' )
            ->shouldNotReceive( 'send' );

        $schedule = (object) array( 'notify_admin_on_cancellation' => 0 );
        $this->invoke( 'maybe_notify_admin_of_cancellation', array( $schedule, $this->booking_data() ) );

        $this->assertTrue( true );
    }
}
