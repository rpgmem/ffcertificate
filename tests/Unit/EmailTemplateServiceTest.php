<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Scheduling\EmailTemplateService;

/**
 * Tests for EmailTemplateService: template rendering, HTML wrapping,
 * date/time formatting, ICS generation, ICS text escaping.
 */
class EmailTemplateServiceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'date_format' ) return 'Y-m-d';
            if ( $key === 'time_format' ) return 'H:i';
            return $default;
        } );
        Functions\when( 'date_i18n' )->alias( function ( $format, $ts = false ) {
            return gmdate( $format, $ts ?: time() );
        } );
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) {
            return parse_url( $url, $component );
        } );
        Functions\when( 'wp_mail' )->justReturn( true );
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // render_template() — pure string replacement
    // ==================================================================

    public function test_render_replaces_single_variable(): void {
        $result = EmailTemplateService::render_template( 'Hello {name}!', array( 'name' => 'João' ) );
        $this->assertSame( 'Hello João!', $result );
    }

    public function test_render_replaces_multiple_variables(): void {
        $template = '{name} has an appointment on {date} at {time}.';
        $vars = array( 'name' => 'Maria', 'date' => '2030-01-15', 'time' => '10:00' );
        $result = EmailTemplateService::render_template( $template, $vars );
        $this->assertSame( 'Maria has an appointment on 2030-01-15 at 10:00.', $result );
    }

    public function test_render_leaves_unknown_placeholders_intact(): void {
        $result = EmailTemplateService::render_template( '{known} and {unknown}', array( 'known' => 'X' ) );
        $this->assertSame( 'X and {unknown}', $result );
    }

    public function test_render_empty_variables(): void {
        $result = EmailTemplateService::render_template( 'No vars here', array() );
        $this->assertSame( 'No vars here', $result );
    }

    public function test_render_empty_template(): void {
        $result = EmailTemplateService::render_template( '', array( 'x' => 'y' ) );
        $this->assertSame( '', $result );
    }

    // ==================================================================
    // wrap_html()
    // ==================================================================

    public function test_wrap_html_contains_body_content(): void {
        $html = EmailTemplateService::wrap_html( '<p>Test content</p>' );
        $this->assertStringContainsString( '<p>Test content</p>', $html );
    }

    public function test_wrap_html_has_doctype(): void {
        $html = EmailTemplateService::wrap_html( 'body' );
        $this->assertStringContainsString( '<!DOCTYPE html>', $html );
    }

    public function test_wrap_html_includes_site_name(): void {
        $html = EmailTemplateService::wrap_html( 'body' );
        $this->assertStringContainsString( 'Test Site', $html );
    }

    public function test_wrap_html_has_header_content_footer(): void {
        $html = EmailTemplateService::wrap_html( 'body' );
        $this->assertStringContainsString( "class='header'", $html );
        $this->assertStringContainsString( "class='content'", $html );
        $this->assertStringContainsString( "class='footer'", $html );
    }

    // ==================================================================
    // format_date() / format_time()
    // ==================================================================

    public function test_format_date_uses_wp_format(): void {
        $result = EmailTemplateService::format_date( '2030-01-15' );
        $this->assertSame( '2030-01-15', $result );
    }

    public function test_format_time_returns_hi_format(): void {
        $result = EmailTemplateService::format_time( '14:30:00' );
        $this->assertSame( '14:30', $result );
    }

    public function test_format_time_short_input(): void {
        $result = EmailTemplateService::format_time( '09:00' );
        $this->assertSame( '09:00', $result );
    }

    // ==================================================================
    // send()
    // ==================================================================

    public function test_send_wraps_body_by_default(): void {
        $sent_body = null;
        Functions\when( 'wp_mail' )->alias( function ( $to, $subj, $body ) use ( &$sent_body ) {
            $sent_body = $body;
            return true;
        } );

        EmailTemplateService::send( 'test@example.com', 'Subject', '<p>Body</p>' );
        $this->assertStringContainsString( '<!DOCTYPE html>', $sent_body );
        $this->assertStringContainsString( '<p>Body</p>', $sent_body );
    }

    public function test_send_skip_wrap_when_false(): void {
        $sent_body = null;
        Functions\when( 'wp_mail' )->alias( function ( $to, $subj, $body ) use ( &$sent_body ) {
            $sent_body = $body;
            return true;
        } );

        EmailTemplateService::send( 'test@example.com', 'Subject', '<p>Raw</p>', array(), false );
        $this->assertSame( '<p>Raw</p>', $sent_body );
    }

    public function test_send_returns_wp_mail_result(): void {
        Functions\when( 'wp_mail' )->justReturn( false );
        $this->assertFalse( EmailTemplateService::send( 'test@example.com', 'Subj', 'Body' ) );
    }

    // ==================================================================
    // generate_ics()
    // ==================================================================

    private function ics_event(): array {
        return array(
            'uid'         => 'test-event-123',
            'summary'     => 'Appointment',
            'description' => 'Doctor visit',
            'location'    => 'Room 101',
            'date'        => '2030-01-15',
            'start_time'  => '10:00',
            'end_time'    => '11:00',
        );
    }

    public function test_ics_begins_and_ends_with_vcalendar(): void {
        $ics = EmailTemplateService::generate_ics( $this->ics_event() );
        $this->assertStringStartsWith( "BEGIN:VCALENDAR\r\n", $ics );
        $this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
    }

    public function test_ics_contains_vevent(): void {
        $ics = EmailTemplateService::generate_ics( $this->ics_event() );
        $this->assertStringContainsString( "BEGIN:VEVENT\r\n", $ics );
        $this->assertStringContainsString( "END:VEVENT\r\n", $ics );
    }

    public function test_ics_date_time_formatted(): void {
        $ics = EmailTemplateService::generate_ics( $this->ics_event() );
        $this->assertStringContainsString( 'DTSTART:20300115T1000', $ics );
        $this->assertStringContainsString( 'DTEND:20300115T1100', $ics );
    }

    public function test_ics_uid_includes_domain(): void {
        $ics = EmailTemplateService::generate_ics( $this->ics_event() );
        $this->assertStringContainsString( 'UID:test-event-123@example.com', $ics );
    }

    public function test_ics_default_method_is_request(): void {
        $ics = EmailTemplateService::generate_ics( $this->ics_event() );
        $this->assertStringContainsString( 'METHOD:REQUEST', $ics );
        $this->assertStringContainsString( 'STATUS:CONFIRMED', $ics );
        $this->assertStringContainsString( 'SEQUENCE:0', $ics );
    }

    public function test_ics_cancel_method(): void {
        $ics = EmailTemplateService::generate_ics( $this->ics_event(), 'CANCEL' );
        $this->assertStringContainsString( 'METHOD:CANCEL', $ics );
        $this->assertStringContainsString( 'STATUS:CANCELLED', $ics );
        $this->assertStringContainsString( 'SEQUENCE:1', $ics );
    }

    public function test_ics_summary_and_description(): void {
        $ics = EmailTemplateService::generate_ics( $this->ics_event() );
        $this->assertStringContainsString( 'SUMMARY:Appointment', $ics );
        $this->assertStringContainsString( 'DESCRIPTION:Doctor visit', $ics );
    }

    public function test_ics_location_present(): void {
        $ics = EmailTemplateService::generate_ics( $this->ics_event() );
        $this->assertStringContainsString( 'LOCATION:Room 101', $ics );
    }

    public function test_ics_location_omitted_when_empty(): void {
        $event = $this->ics_event();
        $event['location'] = '';
        $ics = EmailTemplateService::generate_ics( $event );
        $this->assertStringNotContainsString( 'LOCATION:', $ics );
    }

    public function test_ics_escapes_special_chars(): void {
        $event = $this->ics_event();
        $event['summary'] = 'Meeting, with; special\nchars';
        $ics = EmailTemplateService::generate_ics( $event );
        // escape_ics_text doubles backslashes first, then escapes , and ;
        $this->assertStringContainsString( 'SUMMARY:Meeting\\, with\\; special\\\\nchars', $ics );
    }

    public function test_ics_prodid_includes_site_name(): void {
        $ics = EmailTemplateService::generate_ics( $this->ics_event() );
        $this->assertStringContainsString( 'PRODID:-//Test Site//FFC Scheduling//PT', $ics );
    }
}
