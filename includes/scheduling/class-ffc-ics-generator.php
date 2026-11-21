<?php
/**
 * ICS Calendar Generator
 *
 * Builds RFC 5545 iCalendar (.ics) payloads for scheduling/audience booking
 * invites and cancellations. Extracted from the retired EmailTemplateService
 * so calendar-file generation has a single, focused home (#653).
 *
 * @package FreeFormCertificate\Scheduling
 * @since 6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Scheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates ICS (iCalendar) content for booking events.
 */
class IcsGenerator {

	/**
	 * Generate ICS calendar file content.
	 *
	 * @param array<string, mixed> $event  Event data (uid, summary, description, location, date, start_time, end_time, status, and optionally end_date, rrule, exdates for a range).
	 * @param string               $method ICS method (REQUEST or CANCEL).
	 * @return string ICS file content.
	 */
	public static function generate( array $event, string $method = 'REQUEST' ): string {
		return self::generate_many( array( $event ), $method );
	}

	/**
	 * Generate one ICS file holding several events (#2243).
	 *
	 * Each event keeps its own UID, so a later cancellation of one of them,
	 * sent with that UID, removes that event alone from the calendar.
	 *
	 * @param list<array<string, mixed>> $events Events, each as {@see self::generate()} reads it.
	 * @param string                     $method ICS method (REQUEST or CANCEL).
	 * @return string ICS file content.
	 */
	public static function generate_many( array $events, string $method = 'REQUEST' ): string {
		$site_name = get_bloginfo( 'name' );
		$zone      = self::site_zone();

		$ics  = "BEGIN:VCALENDAR\r\n";
		$ics .= "VERSION:2.0\r\n";
		$ics .= "PRODID:-//{$site_name}//FFC Scheduling//PT\r\n";
		$ics .= "CALSCALE:GREGORIAN\r\n";
		$ics .= "METHOD:{$method}\r\n";
		if ( self::is_named_zone( $zone ) ) {
			list( $from, $to ) = self::span_of( $events, $zone );
			$ics              .= self::vtimezone( $zone, $from, $to );
		}
		foreach ( $events as $event ) {
			$ics .= self::vevent( $event, $method, $zone );
		}
		$ics .= "END:VCALENDAR\r\n";

		return $ics;
	}

	/**
	 * One VEVENT block.
	 *
	 * @param array<string, mixed> $event  Event data, as {@see self::generate()} reads it.
	 * @param string               $method ICS method (REQUEST or CANCEL).
	 * @param \DateTimeZone        $zone   The site's zone.
	 * @return string
	 */
	private static function vevent( array $event, string $method, \DateTimeZone $zone ): string {
		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );

		$uid = ( $event['uid'] ?? 'ffc-event-' . uniqid() ) . '@' . $site_domain;

		$date     = (string) $event['date'];
		$end_date = (string) ( $event['end_date'] ?? $event['date'] );
		$start    = (string) $event['start_time'];

		$dtstamp  = gmdate( 'Ymd\THis\Z' );
		$status   = $event['status'] ?? ( 'CANCEL' === $method ? 'CANCELLED' : 'CONFIRMED' );
		$sequence = ( 'CANCEL' === $method ) ? '1' : '0';

		$summary     = self::escape_text( $event['summary'] );
		$description = self::escape_text( $event['description'] );
		$location    = self::escape_text( $event['location'] ?? '' );

		$ics  = "BEGIN:VEVENT\r\n";
		$ics .= "UID:{$uid}\r\n";
		$ics .= "DTSTAMP:{$dtstamp}\r\n";
		$ics .= 'DTSTART' . self::date_time( $date, $start, $zone ) . "\r\n";
		$ics .= 'DTEND' . self::date_time( $end_date, (string) $event['end_time'], $zone ) . "\r\n";
		if ( ! empty( $event['rrule'] ) ) {
			$ics .= 'RRULE:' . self::rrule( (string) $event['rrule'], $zone ) . "\r\n";
		}
		foreach ( $event['exdates'] ?? array() as $exdate ) {
			$ics .= 'EXDATE' . self::date_time( (string) $exdate, $start, $zone ) . "\r\n";
		}
		$ics .= "SUMMARY:{$summary}\r\n";
		$ics .= "DESCRIPTION:{$description}\r\n";
		if ( $location ) {
			$ics .= "LOCATION:{$location}\r\n";
		}
		$ics .= "STATUS:{$status}\r\n";
		$ics .= "SEQUENCE:{$sequence}\r\n";
		$ics .= "END:VEVENT\r\n";

		return $ics;
	}

	/**
	 * The site's time zone, which every date and time in the file is read in.
	 *
	 * The plugin stores appointments, bookings and presentations as wall-clock
	 * values in this zone. Writing them with no zone ("floating") made a
	 * calendar on a device in another zone show the wrong hour (#2689).
	 *
	 * @return \DateTimeZone
	 */
	private static function site_zone(): \DateTimeZone {
		return wp_timezone();
	}

	/**
	 * Whether the zone has an IANA name a calendar can carry as its TZID.
	 *
	 * A zone set as a bare UTC offset ("+03:00") has no such name, and "UTC"
	 * needs none; both are written in UTC with the "Z" suffix instead.
	 *
	 * @param \DateTimeZone $zone Zone.
	 * @return bool
	 */
	private static function is_named_zone( \DateTimeZone $zone ): bool {
		return false !== strpos( $zone->getName(), '/' );
	}

	/**
	 * A date-time property's parameter and value, from its colon on.
	 *
	 * @param string        $date Date `Y-m-d`.
	 * @param string        $time Time `H:i` or `H:i:s`.
	 * @param \DateTimeZone $zone The site's zone.
	 * @return string `;TZID=Zone:YYYYMMDDTHHMMSS`, or `:YYYYMMDDTHHMMSSZ` for a zone with no name.
	 */
	private static function date_time( string $date, string $time, \DateTimeZone $zone ): string {
		$local = str_replace( '-', '', $date ) . 'T' . str_pad( str_replace( ':', '', $time ), 6, '0' );
		if ( self::is_named_zone( $zone ) ) {
			return ';TZID=' . $zone->getName() . ':' . $local;
		}
		$moment = \DateTimeImmutable::createFromFormat( 'Ymd\THis', $local, $zone );
		if ( false === $moment ) {
			return ':' . $local;
		}
		return ':' . $moment->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}

	/**
	 * A recurrence rule whose UNTIL matches the form of its DTSTART.
	 *
	 * RFC 5545 §3.3.10 wants UNTIL in UTC when DTSTART is in UTC or carries a
	 * zone, so a local UNTIL is converted to UTC.
	 *
	 * @param string        $rrule Rule.
	 * @param \DateTimeZone $zone  The site's zone.
	 * @return string
	 */
	private static function rrule( string $rrule, \DateTimeZone $zone ): string {
		return (string) preg_replace_callback(
			'/UNTIL=(\d{8}T\d{6})(?!Z)/',
			static function ( array $m ) use ( $zone ): string {
				$moment = \DateTimeImmutable::createFromFormat( 'Ymd\THis', $m[1], $zone );
				return false === $moment ? $m[0] : 'UNTIL=' . $moment->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
			},
			$rrule
		);
	}

	/**
	 * The span of time a VTIMEZONE must cover for these events.
	 *
	 * A year before the first date to two years after the last, so a range or
	 * a recurrence ending inside it is covered too.
	 *
	 * @param list<array<string, mixed>> $events Events.
	 * @param \DateTimeZone              $zone   Zone.
	 * @return array{0: int, 1: int}
	 */
	private static function span_of( array $events, \DateTimeZone $zone ): array {
		$dates = array();
		foreach ( $events as $event ) {
			foreach ( array( $event['date'] ?? '', $event['end_date'] ?? '' ) as $date ) {
				if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
					$dates[] = (string) $date;
				}
			}
		}
		if ( empty( $dates ) ) {
			$dates[] = gmdate( 'Y-m-d' );
		}
		$first = new \DateTimeImmutable( min( $dates ), $zone );
		$last  = new \DateTimeImmutable( max( $dates ), $zone );
		return array( $first->modify( '-1 year' )->getTimestamp(), $last->modify( '+2 years' )->getTimestamp() );
	}

	/**
	 * A VTIMEZONE block for a named zone, listing its offsets over a span.
	 *
	 * Each change of offset in the span is written as its own STANDARD or
	 * DAYLIGHT part, from PHP's own transition table. A zone with no change
	 * there (America/Sao_Paulo since 2019) gets one part holding its offset.
	 *
	 * @param \DateTimeZone $zone Zone with an IANA name.
	 * @param int           $from Span start (Unix time).
	 * @param int           $to   Span end (Unix time).
	 * @return string
	 */
	public static function vtimezone( \DateTimeZone $zone, int $from, int $to ): string {
		$transitions = $zone->getTransitions( $from, $to );
		if ( empty( $transitions ) ) {
			return '';
		}

		$out    = "BEGIN:VTIMEZONE\r\nTZID:" . $zone->getName() . "\r\n";
		$offset = (int) $transitions[0]['offset'];
		foreach ( $transitions as $index => $transition ) {
			// The first entry is the state at $from, not a change: it starts
			// with the offset it already has.
			$before = 0 === $index ? (int) $transition['offset'] : $offset;
			$kind   = ! empty( $transition['isdst'] ) ? 'DAYLIGHT' : 'STANDARD';
			$out   .= "BEGIN:{$kind}\r\n";
			$out   .= 'DTSTART:' . gmdate( 'Ymd\THis', (int) $transition['ts'] + $before ) . "\r\n";
			$out   .= 'TZOFFSETFROM:' . self::utc_offset( $before ) . "\r\n";
			$out   .= 'TZOFFSETTO:' . self::utc_offset( (int) $transition['offset'] ) . "\r\n";
			$out   .= 'TZNAME:' . self::escape_text( (string) $transition['abbr'] ) . "\r\n";
			$out   .= "END:{$kind}\r\n";
			$offset = (int) $transition['offset'];
		}
		return $out . "END:VTIMEZONE\r\n";
	}

	/**
	 * The site's zone as a browser-built calendar file needs it (#2689).
	 *
	 * The user dashboard writes its own .ics in JavaScript. A named zone comes
	 * with its VTIMEZONE block, built here from PHP's transition table over a
	 * year back and three ahead. A zone set as an offset comes with the offset
	 * in seconds, which the script subtracts to write UTC.
	 *
	 * @return array{tzid: string, vtimezone: string, offset: int}
	 */
	public static function script_zone(): array {
		$zone = self::site_zone();
		$now  = time();
		if ( ! self::is_named_zone( $zone ) ) {
			return array(
				'tzid'      => '',
				'vtimezone' => '',
				'offset'    => $zone->getOffset( new \DateTimeImmutable( '@' . $now ) ),
			);
		}
		return array(
			'tzid'      => $zone->getName(),
			'vtimezone' => self::vtimezone( $zone, $now - 366 * 86400, $now + 3 * 366 * 86400 ),
			'offset'    => 0,
		);
	}

	/**
	 * A UTC offset in seconds as iCalendar writes it: `-0300`, `+0530`.
	 *
	 * @param int $seconds Offset.
	 * @return string
	 */
	private static function utc_offset( int $seconds ): string {
		$sign    = $seconds < 0 ? '-' : '+';
		$seconds = abs( $seconds );
		return sprintf( '%s%02d%02d', $sign, intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
	}

	/**
	 * Escape text for ICS format (RFC 5545 §3.3.11).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function escape_text( string $text ): string {
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( "\n", '\\n', $text );
		$text = str_replace( "\r", '', $text );
		$text = str_replace( ',', '\\,', $text );
		$text = str_replace( ';', '\\;', $text );

		return $text;
	}
}
