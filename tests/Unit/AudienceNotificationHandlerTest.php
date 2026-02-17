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
 * Tests for AudienceNotificationHandler: template rendering, subject generation.
 *
 * Private methods are accessed via Reflection to test pure logic in isolation.
 */
class AudienceNotificationHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper: invoke private static method via Reflection
    // ------------------------------------------------------------------

    private function invoke_private( string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( AudienceNotificationHandler::class, $method );
        $ref->setAccessible( true );
        return $ref->invoke( null, ...$args );
    }

    // ==================================================================
    // render_template()
    // ==================================================================

    public function test_render_template_replaces_user_variables(): void {
        $template = 'Hello {user_name}, your email is {user_email}.';
        $booking_data = array(
            'environment_name' => 'Room A',
            'schedule_name'    => 'Calendar',
            'booking_date'     => '2026-03-01',
            'start_time'       => '09:00',
            'end_time'         => '10:00',
            'description'      => 'Test Event',
        );

        $user = new \WP_User( 1 );
        $user->display_name = 'João Silva';
        $user->user_email   = 'joao@example.com';

        $result = $this->invoke_private( 'render_template', [ $template, $booking_data, $user ] );

        $this->assertStringContainsString( 'João Silva', $result );
        $this->assertStringContainsString( 'joao@example.com', $result );
        $this->assertStringNotContainsString( '{user_name}', $result );
        $this->assertStringNotContainsString( '{user_email}', $result );
    }

    public function test_render_template_replaces_booking_variables(): void {
        $template = '{schedule_name} at {environment_name} on {booking_date} from {start_time} to {end_time}. {description}';
        $booking_data = array(
            'environment_name' => 'Lab 3',
            'schedule_name'    => 'Science Schedule',
            'booking_date'     => '15/03/2026',
            'start_time'       => '14:00',
            'end_time'         => '16:00',
            'description'      => 'Chemistry class',
        );

        $user = new \WP_User( 1 );
        $user->display_name = 'Test';
        $user->user_email   = 'test@example.com';

        $result = $this->invoke_private( 'render_template', [ $template, $booking_data, $user ] );

        $this->assertSame( 'Science Schedule at Lab 3 on 15/03/2026 from 14:00 to 16:00. Chemistry class', $result );
    }

    public function test_render_template_replaces_audience_and_creator(): void {
        $template = 'Audiences: {audiences}. Created by: {creator_name}';
        $booking_data = array(
            'environment_name' => 'Room A',
            'schedule_name'    => 'Cal',
            'booking_date'     => '2026-01-01',
            'start_time'       => '08:00',
            'end_time'         => '09:00',
            'description'      => 'Test',
            'audiences'        => 'Group A, Group B',
            'creator_name'     => 'Admin User',
        );

        $user = new \WP_User( 1 );
        $user->display_name = 'Recipient';
        $user->user_email   = 'r@example.com';

        $result = $this->invoke_private( 'render_template', [ $template, $booking_data, $user ] );

        $this->assertStringContainsString( 'Group A, Group B', $result );
        $this->assertStringContainsString( 'Admin User', $result );
    }

    public function test_render_template_replaces_cancellation_variables(): void {
        $template = 'Cancelled by {cancelled_by_name}. Reason: {cancellation_reason}';
        $booking_data = array(
            'environment_name'    => 'Room',
            'schedule_name'       => 'Cal',
            'booking_date'        => '2026-01-01',
            'start_time'          => '08:00',
            'end_time'            => '09:00',
            'description'         => 'Event',
            'cancelled_by_name'   => 'Manager',
            'cancellation_reason' => 'Schedule conflict',
        );

        $user = new \WP_User( 1 );
        $user->display_name = 'User';
        $user->user_email   = 'user@example.com';

        $result = $this->invoke_private( 'render_template', [ $template, $booking_data, $user ] );

        $this->assertSame( 'Cancelled by Manager. Reason: Schedule conflict', $result );
    }

    public function test_render_template_replaces_site_variables(): void {
        $template = '{site_name} ({site_url})';
        $booking_data = array(
            'environment_name' => 'Room',
            'schedule_name'    => 'Cal',
            'booking_date'     => '2026-01-01',
            'start_time'       => '08:00',
            'end_time'         => '09:00',
            'description'      => '',
        );

        $user = new \WP_User( 1 );
        $user->display_name = 'U';
        $user->user_email   = 'u@example.com';

        $result = $this->invoke_private( 'render_template', [ $template, $booking_data, $user ] );

        $this->assertSame( 'Test Site (https://example.com)', $result );
    }

    public function test_render_template_handles_missing_optional_keys(): void {
        $template = 'Creator: {creator_name}, Audiences: {audiences}';
        $booking_data = array(
            'environment_name' => 'Room',
            'schedule_name'    => 'Cal',
            'booking_date'     => '2026-01-01',
            'start_time'       => '08:00',
            'end_time'         => '09:00',
            'description'      => '',
            // No 'creator_name' or 'audiences' keys
        );

        $user = new \WP_User( 1 );
        $user->display_name = 'U';
        $user->user_email   = 'u@example.com';

        $result = $this->invoke_private( 'render_template', [ $template, $booking_data, $user ] );

        // Optional keys default to empty string via ?? ''
        $this->assertSame( 'Creator: , Audiences: ', $result );
    }

    // ==================================================================
    // get_booking_subject()
    // ==================================================================

    public function test_get_booking_subject_format(): void {
        $booking_data = array(
            'environment_name' => 'Lab 2',
            'booking_date'     => '10/03/2026',
        );

        $result = $this->invoke_private( 'get_booking_subject', [ $booking_data ] );

        $this->assertStringContainsString( 'Lab 2', $result );
        $this->assertStringContainsString( '10/03/2026', $result );
    }

    // ==================================================================
    // get_cancellation_subject()
    // ==================================================================

    public function test_get_cancellation_subject_format(): void {
        $booking_data = array(
            'environment_name' => 'Room 5',
            'booking_date'     => '15/03/2026',
        );

        $result = $this->invoke_private( 'get_cancellation_subject', [ $booking_data ] );

        $this->assertStringContainsString( 'Room 5', $result );
        $this->assertStringContainsString( '15/03/2026', $result );
    }

    // ==================================================================
    // get_default_booking_template() and get_default_cancellation_template()
    // ==================================================================

    public function test_default_booking_template_contains_all_placeholders(): void {
        $template = $this->invoke_private( 'get_default_booking_template', [] );

        $expected_placeholders = array(
            '{user_name}',
            '{schedule_name}',
            '{environment_name}',
            '{booking_date}',
            '{start_time}',
            '{end_time}',
            '{description}',
            '{audiences}',
            '{creator_name}',
            '{site_name}',
        );

        foreach ( $expected_placeholders as $placeholder ) {
            $this->assertStringContainsString( $placeholder, $template, "Missing placeholder: $placeholder" );
        }
    }

    public function test_default_cancellation_template_contains_all_placeholders(): void {
        $template = $this->invoke_private( 'get_default_cancellation_template', [] );

        $expected_placeholders = array(
            '{user_name}',
            '{schedule_name}',
            '{environment_name}',
            '{booking_date}',
            '{start_time}',
            '{end_time}',
            '{description}',
            '{cancelled_by_name}',
            '{cancellation_reason}',
            '{site_name}',
        );

        foreach ( $expected_placeholders as $placeholder ) {
            $this->assertStringContainsString( $placeholder, $template, "Missing placeholder: $placeholder" );
        }
    }
}
