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
	 * @param array<string, mixed> $event  Event data (uid, summary, description, location, date, start_time, end_time, status).
	 * @param string               $method ICS method (REQUEST or CANCEL).
	 * @return string ICS file content.
	 */
	public static function generate( array $event, string $method = 'REQUEST' ): string {
		$site_name   = get_bloginfo( 'name' );
		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );

		$uid = ( $event['uid'] ?? 'ffc-event-' . uniqid() ) . '@' . $site_domain;

		// Build ICS datetime: YYYYMMDDTHHMMSS.
		$date_clean  = str_replace( '-', '', $event['date'] );
		$start_clean = str_replace( ':', '', $event['start_time'] );
		$end_clean   = str_replace( ':', '', $event['end_time'] );

		$dtstamp  = gmdate( 'Ymd\THis\Z' );
		$status   = $event['status'] ?? ( 'CANCEL' === $method ? 'CANCELLED' : 'CONFIRMED' );
		$sequence = ( 'CANCEL' === $method ) ? '1' : '0';

		$summary     = self::escape_text( $event['summary'] );
		$description = self::escape_text( $event['description'] );
		$location    = self::escape_text( $event['location'] ?? '' );

		$ics  = "BEGIN:VCALENDAR\r\n";
		$ics .= "VERSION:2.0\r\n";
		$ics .= "PRODID:-//{$site_name}//FFC Scheduling//PT\r\n";
		$ics .= "CALSCALE:GREGORIAN\r\n";
		$ics .= "METHOD:{$method}\r\n";
		$ics .= "BEGIN:VEVENT\r\n";
		$ics .= "UID:{$uid}\r\n";
		$ics .= "DTSTAMP:{$dtstamp}\r\n";
		$ics .= "DTSTART:{$date_clean}T{$start_clean}\r\n";
		$ics .= "DTEND:{$date_clean}T{$end_clean}\r\n";
		$ics .= "SUMMARY:{$summary}\r\n";
		$ics .= "DESCRIPTION:{$description}\r\n";
		if ( $location ) {
			$ics .= "LOCATION:{$location}\r\n";
		}
		$ics .= "STATUS:{$status}\r\n";
		$ics .= "SEQUENCE:{$sequence}\r\n";
		$ics .= "END:VEVENT\r\n";
		$ics .= "END:VCALENDAR\r\n";

		return $ics;
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
