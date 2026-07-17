<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Scheduling\IcsGenerator;

/**
 * Tests for IcsGenerator: ICS (iCalendar) generation and text escaping.
 *
 * @covers \FreeFormCertificate\Scheduling\IcsGenerator
 */
class IcsGeneratorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) {
            return parse_url( $url, $component );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

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
        $ics = IcsGenerator::generate( $this->ics_event() );
        $this->assertStringStartsWith( "BEGIN:VCALENDAR\r\n", $ics );
        $this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
    }

    public function test_ics_contains_vevent(): void {
        $ics = IcsGenerator::generate( $this->ics_event() );
        $this->assertStringContainsString( "BEGIN:VEVENT\r\n", $ics );
        $this->assertStringContainsString( "END:VEVENT\r\n", $ics );
    }

    public function test_ics_date_time_formatted(): void {
        $ics = IcsGenerator::generate( $this->ics_event() );
        $this->assertStringContainsString( 'DTSTART:20300115T1000', $ics );
        $this->assertStringContainsString( 'DTEND:20300115T1100', $ics );
    }

    public function test_ics_uid_includes_domain(): void {
        $ics = IcsGenerator::generate( $this->ics_event() );
        $this->assertStringContainsString( 'UID:test-event-123@example.com', $ics );
    }

    public function test_ics_default_method_is_request(): void {
        $ics = IcsGenerator::generate( $this->ics_event() );
        $this->assertStringContainsString( 'METHOD:REQUEST', $ics );
        $this->assertStringContainsString( 'STATUS:CONFIRMED', $ics );
        $this->assertStringContainsString( 'SEQUENCE:0', $ics );
    }

    public function test_ics_cancel_method(): void {
        $ics = IcsGenerator::generate( $this->ics_event(), 'CANCEL' );
        $this->assertStringContainsString( 'METHOD:CANCEL', $ics );
        $this->assertStringContainsString( 'STATUS:CANCELLED', $ics );
        $this->assertStringContainsString( 'SEQUENCE:1', $ics );
    }

    public function test_ics_summary_and_description(): void {
        $ics = IcsGenerator::generate( $this->ics_event() );
        $this->assertStringContainsString( 'SUMMARY:Appointment', $ics );
        $this->assertStringContainsString( 'DESCRIPTION:Doctor visit', $ics );
    }

    public function test_ics_location_present(): void {
        $ics = IcsGenerator::generate( $this->ics_event() );
        $this->assertStringContainsString( 'LOCATION:Room 101', $ics );
    }

    public function test_ics_location_omitted_when_empty(): void {
        $event = $this->ics_event();
        $event['location'] = '';
        $ics = IcsGenerator::generate( $event );
        $this->assertStringNotContainsString( 'LOCATION:', $ics );
    }

    public function test_ics_escapes_special_chars(): void {
        $event = $this->ics_event();
        $event['summary'] = 'Meeting, with; special\nchars';
        $ics = IcsGenerator::generate( $event );
        // escape_text doubles backslashes first, then escapes , and ;
        $this->assertStringContainsString( 'SUMMARY:Meeting\\, with\\; special\\\\nchars', $ics );
    }

    public function test_ics_prodid_includes_site_name(): void {
        $ics = IcsGenerator::generate( $this->ics_event() );
        $this->assertStringContainsString( 'PRODID:-//Test Site//FFC Scheduling//PT', $ics );
    }

    public function test_ics_generates_uid_when_absent(): void {
        $event = $this->ics_event();
        unset( $event['uid'] );
        $ics = IcsGenerator::generate( $event );
        $this->assertStringContainsString( 'UID:ffc-event-', $ics );
        $this->assertStringContainsString( '@example.com', $ics );
    }
}
