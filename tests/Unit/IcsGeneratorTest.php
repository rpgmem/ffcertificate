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
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Sao_Paulo' ) );
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

	public function test_several_events_share_one_calendar_and_keep_their_own_uids(): void {
		$second         = $this->ics_event();
		$second['uid']  = 'test-event-456';
		$second['date'] = '2030-01-22';

		$ics = IcsGenerator::generate_many( array( $this->ics_event(), $second ) );

		$this->assertSame( 1, substr_count( $ics, 'BEGIN:VCALENDAR' ) );
		$this->assertSame( 1, substr_count( $ics, 'METHOD:REQUEST' ) );
		$this->assertSame( 2, substr_count( $ics, 'BEGIN:VEVENT' ) );
		$this->assertStringContainsString( 'UID:test-event-123@example.com', $ics );
		$this->assertStringContainsString( 'UID:test-event-456@example.com', $ics );
		$this->assertStringContainsString( 'DTSTART;TZID=America/Sao_Paulo:20300122T1000', $ics );
	}

	public function test_one_event_reads_the_same_through_either_entry_point(): void {
		$one  = preg_replace( '/DTSTAMP:\S+/', '', IcsGenerator::generate( $this->ics_event() ) );
		$many = preg_replace( '/DTSTAMP:\S+/', '', IcsGenerator::generate_many( array( $this->ics_event() ) ) );

		$this->assertSame( $one, $many );
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
		$this->assertStringContainsString( 'DTSTART;TZID=America/Sao_Paulo:20300115T1000', $ics );
		$this->assertStringContainsString( 'DTEND;TZID=America/Sao_Paulo:20300115T1100', $ics );
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

	public function test_a_range_span_runs_from_its_first_start_to_its_last_end(): void {
		$event             = $this->ics_event();
		$event['end_date'] = '2030-01-17';

		$ics = IcsGenerator::generate( $event );

		$this->assertStringContainsString( 'DTSTART;TZID=America/Sao_Paulo:20300115T1000', $ics );
		$this->assertStringContainsString( 'DTEND;TZID=America/Sao_Paulo:20300117T1100', $ics );
		$this->assertStringNotContainsString( 'RRULE:', $ics );
	}

	public function test_a_daily_range_repeats_its_hours_and_leaves_out_the_skipped_days(): void {
		$event             = $this->ics_event();
		$event['end_date'] = '2030-01-15';
		$event['rrule']    = 'FREQ=DAILY;UNTIL=20300117T235959';
		$event['exdates']  = array( '2030-01-16' );

		$ics = IcsGenerator::generate( $event );

		$this->assertStringContainsString( 'RRULE:FREQ=DAILY;UNTIL=20300118T025959Z', $ics, 'UNTIL in UTC, since DTSTART carries a zone.' );
		$this->assertStringContainsString( 'EXDATE;TZID=America/Sao_Paulo:20300116T1000', $ics );
		$this->assertStringContainsString( 'DTEND;TZID=America/Sao_Paulo:20300115T1100', $ics );
	}

	// ==================================================================
	// Time zone (#2689)
	// ==================================================================

	public function test_a_named_zone_is_declared_once_and_carried_by_every_date(): void {
		$ics = IcsGenerator::generate( $this->ics_event() );

		$this->assertSame( 1, substr_count( $ics, "BEGIN:VTIMEZONE\r\nTZID:America/Sao_Paulo\r\n" ) );
		$this->assertLessThan( strpos( $ics, 'BEGIN:VEVENT' ), strpos( $ics, 'BEGIN:VTIMEZONE' ), 'The zone is declared before the event that names it.' );
		$this->assertStringContainsString( "TZOFFSETFROM:-0300\r\nTZOFFSETTO:-0300\r\n", $ics, 'No change of offset in the span: one part holds it.' );
		$this->assertStringNotContainsString( 'DAYLIGHT', $ics );
	}

	public function test_a_zone_with_daylight_saving_lists_each_change(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/New_York' ) );

		$ics = IcsGenerator::generate( $this->ics_event() );

		$this->assertStringContainsString( 'DTSTART;TZID=America/New_York:20300115T100000', $ics );
		$this->assertStringContainsString( "BEGIN:DAYLIGHT\r\nDTSTART:20300310T020000\r\nTZOFFSETFROM:-0500\r\nTZOFFSETTO:-0400\r\nTZNAME:EDT\r\n", $ics );
		$this->assertStringContainsString( "BEGIN:STANDARD\r\nDTSTART:20301103T020000\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nTZNAME:EST\r\n", $ics );
	}

	public function test_a_zone_set_as_an_offset_writes_utc(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( '-03:00' ) );

		$event            = $this->ics_event();
		$event['rrule']   = 'FREQ=DAILY;UNTIL=20300117T235959';
		$event['exdates'] = array( '2030-01-16' );
		$ics              = IcsGenerator::generate( $event );

		$this->assertStringNotContainsString( 'VTIMEZONE', $ics, 'An offset has no name to declare.' );
		$this->assertStringContainsString( "DTSTART:20300115T130000Z\r\n", $ics );
		$this->assertStringContainsString( "DTEND:20300115T140000Z\r\n", $ics );
		$this->assertStringContainsString( "EXDATE:20300116T130000Z\r\n", $ics );
		$this->assertStringContainsString( 'UNTIL=20300118T025959Z', $ics );
	}

	public function test_utc_writes_utc_unchanged(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$ics = IcsGenerator::generate( $this->ics_event() );

		$this->assertStringContainsString( "DTSTART:20300115T100000Z\r\n", $ics );
		$this->assertStringNotContainsString( 'VTIMEZONE', $ics );
	}
}
