<?php
/**
 * Audience Presence Events
 *
 * The events a participant's app can show a presence code for (#2742).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\SigningKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bookings a user takes part in over the next days, each with a ticket.
 *
 * The app fetches this while online and keeps it, so it can still show a code
 * for the right event offline. The window is {@see self::DAYS_AHEAD} days,
 * the same horizon a terminal's queue has (#2738).
 *
 * @phpstan-import-type AttendanceConfirmation from AudienceAttendanceStatus
 * @phpstan-type PresencePending array{type: string, key: string, title: string, text: string, detail: string, url?: string}
 * @phpstan-type PresenceEvent array{target_type: string, target_id: int, title: string, place: string, date: string, time: string, first_date: string, last_date: string, expires: int, ticket: string, attendance: string}
 */
final class AudiencePresenceEvents {

	/**
	 * How far ahead the list reaches, in days.
	 */
	public const DAYS_AHEAD = 7;

	/**
	 * The user's upcoming events, soonest first.
	 *
	 * @param int $user_id User.
	 * @param int $now     Unix time.
	 * @return list<PresenceEvent>
	 */
	public static function for_user( int $user_id, int $now ): array {
		$today    = wp_date( 'Y-m-d', $now );
		$horizon  = wp_date( 'Y-m-d', $now + self::DAYS_AHEAD * DAY_IN_SECONDS );
		$filter   = array(
			'start_date'     => (string) $today,
			'end_date'       => (string) $horizon,
			'exclude_status' => 'cancelled',
		);
		$bookings = AudienceQueryService::find_user_bookings( $user_id, $filter );
		// An event open to everyone (#2748) is listed for anyone signed in,
		// so a person who is not enrolled can still pick it at a terminal.
		foreach ( AudienceQueryService::find_open_checkin_bookings( $filter ) as $open ) {
			$bookings[] = $open;
		}

		// The attendance each event already has (#2749), from one query.
		$attendance = AudienceAttendanceStatus::for_bookings( $user_id, $bookings );
		$events     = array();
		$seen       = array();
		foreach ( $bookings as $booking ) {
			$id = (int) ( $booking['id'] ?? 0 );
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$event       = self::event( $booking, $user_id, $now );
			if ( null !== $event ) {
				$event['attendance'] = isset( $attendance[ $id ] ) ? $attendance[ $id ]['label'] : '';
				$events[]            = $event;
			}
		}
		usort(
			$events,
			static fn( array $a, array $b ): int => array( $a['first_date'], $a['target_id'] ) <=> array( $b['first_date'], $b['target_id'] )
		);
		return $events;
	}

	/**
	 * What waits for the person in the app (#2749): a confirmation for each
	 * day recorded lately, and each post-event form still open to them.
	 *
	 * Built from what the site already holds and sent with the events, so the
	 * app asks for nothing per item and keeps the list for when it is offline.
	 *
	 * @param int $user_id User.
	 * @param int $now     Unix time.
	 * @return list<PresencePending>
	 */
	public static function pending( int $user_id, int $now ): array {
		// A form stays open up to the longest window after its event.
		$since    = wp_date( 'Y-m-d', $now - ( AudiencePostEventForm::MAX_WINDOW_DAYS + 1 ) * DAY_IN_SECONDS );
		$bookings = AudienceQueryService::find_user_bookings(
			$user_id,
			array(
				'start_date'     => (string) $since,
				'end_date'       => (string) wp_date( 'Y-m-d', $now ),
				'exclude_status' => 'cancelled',
			)
		);

		$items = AudienceAttendanceStatus::confirmations( $user_id, $bookings, $now );
		foreach ( $bookings as $booking ) {
			$form = AudiencePostEventPending::for_user( (object) $booking, $user_id, $now );
			if ( null === $form ) {
				continue;
			}
			$items[] = array(
				'type'   => 'form',
				'key'    => 'form:' . (int) $booking['id'],
				'title'  => AudienceAttendanceStatus::title( $booking ),
				/* translators: %s: date the form closes */
				'text'   => sprintf( __( 'Post-event form · until %s', 'ffcertificate' ), $form['closes_label'] ),
				'detail' => '' === $form['form_title'] ? AudienceAttendanceStatus::title( $booking ) : $form['form_title'],
				'url'    => $form['url'],
			);
		}
		return $items;
	}

	/**
	 * One booking as the app shows it, or null when no ticket can be signed
	 * for it or it has already ended.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param int                  $user_id User.
	 * @param int                  $now     Unix time.
	 * @return PresenceEvent|null
	 */
	public static function event( array $booking, int $user_id, int $now ): ?array {
		$id    = (int) ( $booking['id'] ?? 0 );
		$first = (string) ( $booking['booking_date'] ?? '' );
		$last  = (string) ( $booking['end_date'] ?? '' );
		$last  = '' === $last ? $first : $last;
		if ( $id <= 0 || '' === $first ) {
			return null;
		}
		$row     = (object) $booking;
		$expires = AudienceCheckinWindowWriter::auto_close_at( $row, $last );
		if ( $expires <= $now ) {
			return null;
		}
		$ticket = AudiencePresenceTicket::issue( $user_id, AudienceAttendanceTarget::TYPE, $id, $expires );
		if ( null === $ticket ) {
			return null;
		}

		$wallclock = static function ( string $date ): string {
			$formatted = DateFormatter::format_wallclock_date( $date );
			return '' === $formatted ? $date : $formatted;
		};
		$time      = static function ( string $value ): string {
			$formatted = DateFormatter::format_wallclock_time( $value );
			return '' === $formatted ? substr( $value, 0, 5 ) : $formatted;
		};
		$title     = trim( (string) ( $booking['description'] ?? '' ) );

		return array(
			'target_type' => AudienceAttendanceTarget::TYPE,
			'target_id'   => $id,
			'title'       => '' === $title ? (string) ( $booking['schedule_name'] ?? '' ) : $title,
			'place'       => (string) ( $booking['environment_name'] ?? '' ),
			'date'        => AudienceBookingRules::date_label( $first, $last === $first ? null : $last, $wallclock ),
			'time'        => ! empty( $booking['is_all_day'] ) ? __( 'All day', 'ffcertificate' ) : AudienceBookingRules::time_label(
				(string) ( $booking['start_time'] ?? '' ),
				(string) ( $booking['end_time'] ?? '' ),
				AudienceBookingRules::is_span( $row ),
				$time
			),
			'first_date'  => $first,
			'last_date'   => $last,
			'expires'     => $expires,
			'ticket'      => SigningKeys::b64( $ticket ),
			'attendance'  => '',
		);
	}
}
