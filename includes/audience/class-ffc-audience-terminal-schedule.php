<?php
/**
 * Audience Terminal Schedule
 *
 * The check-in windows of the events a terminal holds (#2746).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\DateFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When each event of a terminal's queue takes readings.
 *
 * An event's planned check-in window on one of its days runs from the day's
 * start to the automatic close an operator's window would get
 * ({@see AudienceCheckinWindowWriter::auto_close_at()}): the end of the day's
 * hours plus the grace. An all-day event's window starts at midnight. A
 * terminal reads for one event at a time, so two events whose windows cross
 * cannot share a terminal; windows that only touch, one closing at the
 * moment the next opens, can.
 *
 * The planned window is what the queue is checked against. Whether a window
 * is actually open is the operator's: an operator opens check-in when the
 * event starts, and the terminal shows the window as open only then.
 *
 * @phpstan-import-type TerminalRow from AudienceTerminalReader
 * @phpstan-type PlannedWindow array{booking_id: int, date: string, opens: int, closes: int}
 * @phpstan-type PlanEntry array{booking_id: int, title: string, place: string, date: string, opens: int, closes: int, opens_label: string, closes_label: string, open: bool}
 */
final class AudienceTerminalSchedule {

	/**
	 * The planned check-in windows of a booking, one per day it is counted.
	 *
	 * @param object $booking Booking row.
	 * @return list<PlannedWindow>
	 */
	public static function windows( object $booking ): array {
		$out = array();
		foreach ( AudienceAttendanceRule::counted_days( $booking ) as $date ) {
			$start = ! empty( $booking->is_all_day ) ? '00:00' : AudienceBookingRules::stored_day_times( $booking, $date )['start_time'];
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) {
				$start = '00:00';
			}
			$moment = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $start, wp_timezone() );
			if ( false === $moment ) {
				continue;
			}
			$out[] = array(
				'booking_id' => (int) ( $booking->id ?? 0 ),
				'date'       => $date,
				'opens'      => $moment->getTimestamp(),
				'closes'     => AudienceCheckinWindowWriter::auto_close_at( $booking, $date ),
			);
		}
		return $out;
	}

	/**
	 * Whether two windows cross. Windows that only touch do not.
	 *
	 * @param array $a Window.
	 * @param array $b Window.
	 * @phpstan-param PlannedWindow $a
	 * @phpstan-param PlannedWindow $b
	 * @return bool
	 */
	public static function cross( array $a, array $b ): bool {
		return $a['opens'] < $b['closes'] && $b['opens'] < $a['closes'];
	}

	/**
	 * The first two bookings of a list whose windows cross, among the windows
	 * that have not closed yet.
	 *
	 * @param int[] $bookings Booking ids.
	 * @param int   $now      Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return array{0: object, 1: object}|null The two booking rows, in list order.
	 */
	public static function collision( array $bookings, int $now ): ?array {
		$rows    = array();
		$windows = array();
		foreach ( $bookings as $booking_id ) {
			$booking = AudienceBookingReader::get_by_id( $booking_id );
			if ( null === $booking ) {
				continue;
			}
			$rows[ $booking_id ]    = $booking;
			$windows[ $booking_id ] = array_values(
				array_filter(
					self::windows( $booking ),
					static fn( array $w ): bool => $w['closes'] > $now
				)
			);
		}
		$ids   = array_keys( $windows );
		$count = count( $ids );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				foreach ( $windows[ $ids[ $i ] ] as $a ) {
					foreach ( $windows[ $ids[ $j ] ] as $b ) {
						if ( self::cross( $a, $b ) ) {
							return array( $rows[ $ids[ $i ] ], $rows[ $ids[ $j ] ] );
						}
					}
				}
			}
		}
		return null;
	}

	/**
	 * The refusal a collision gives, naming both events, or null.
	 *
	 * @param int[] $bookings Booking ids.
	 * @param int   $now      Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return \WP_Error|null
	 */
	public static function collision_error( array $bookings, int $now ): ?\WP_Error {
		$pair = self::collision( $bookings, $now );
		if ( null === $pair ) {
			return null;
		}
		return new \WP_Error(
			'ffc_terminal_overlap',
			sprintf(
				/* translators: 1: an event, 2: the event its check-in collides with */
				__( 'The check-in of "%1$s" overlaps the check-in of "%2$s". A terminal checks in one event at a time: put them on different terminals.', 'ffcertificate' ),
				self::title( $pair[1] ),
				self::title( $pair[0] )
			),
			array( 'status' => 409 )
		);
	}

	/**
	 * What the terminal shows: every window of its events that has not
	 * closed, soonest first, with whether an operator has opened it.
	 *
	 * @param array $terminal Terminal row.
	 * @param int   $now      Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return list<PlanEntry>
	 */
	public static function plan( array $terminal, int $now ): array {
		$out   = array();
		$today = (string) wp_date( 'Y-m-d', $now );
		foreach ( $terminal['bookings'] as $booking_id ) {
			$booking = AudienceBookingReader::get_by_id( $booking_id );
			if ( null === $booking || 'active' !== ( $booking->status ?? '' ) ) {
				continue;
			}
			foreach ( self::windows( $booking ) as $window ) {
				if ( $window['closes'] <= $now ) {
					continue;
				}
				$open = null;
				if ( $window['date'] === $today ) {
					$open = AudienceCheckinWindowReader::covering( $booking_id, $today, $now );
				}
				$closes = null === $open ? $window['closes'] : ( null === $open['closed_at'] ? $open['closes_at'] : min( $open['closed_at'], $open['closes_at'] ) );
				$out[]  = array(
					'booking_id'   => $booking_id,
					'title'        => self::title( $booking ),
					'place'        => (string) ( $booking->environment_name ?? '' ),
					'date'         => $window['date'],
					'opens'        => $window['opens'],
					'closes'       => $closes,
					'opens_label'  => DateFormatter::format_datetime( $window['opens'] ),
					'closes_label' => DateFormatter::format_time( $closes ),
					'open'         => null !== $open,
				);
			}
		}
		usort( $out, static fn( array $a, array $b ): int => array( $a['opens'], $a['booking_id'] ) <=> array( $b['opens'], $b['booking_id'] ) );
		return $out;
	}

	/**
	 * What an event is called.
	 *
	 * @param object $booking Booking row.
	 * @return string
	 */
	private static function title( object $booking ): string {
		$title = trim( (string) ( $booking->description ?? '' ) );
		/* translators: %d: event id */
		return '' !== $title ? $title : sprintf( __( 'Event #%d', 'ffcertificate' ), (int) ( $booking->id ?? 0 ) );
	}
}
