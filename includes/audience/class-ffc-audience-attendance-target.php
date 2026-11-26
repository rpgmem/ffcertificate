<?php
/**
 * Audience Attendance Target
 *
 * The audience booking as an attendance target (#2740): what
 * {@see \FreeFormCertificate\Core\Attendance::record()} calls for the type
 * `audience_booking`, so a caller outside this module — the self-service
 * terminal — records attendance without knowing the audience tables.
 *
 * It is also what a terminal reads for a booking (#2890): its windows, what
 * the screen shows and what a reading means. The rules are the ones #2745
 * and #2746 set for bookings; they moved here from the terminal code so the
 * terminal can hold a target of another type beside them.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.46.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\TerminalTargetInterface;
use FreeFormCertificate\Core\TerminalVerdict;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records attendance at an audience booking, and reads one at a terminal.
 *
 * @phpstan-import-type Verdict from \FreeFormCertificate\Core\TerminalVerdict
 * @phpstan-import-type TerminalWindow from \FreeFormCertificate\Core\TerminalTargetInterface
 * @phpstan-import-type TerminalEntry from \FreeFormCertificate\Core\TerminalTargetInterface
 */
final class AudienceAttendanceTarget implements TerminalTargetInterface {

	/**
	 * Target type id.
	 *
	 * @var string
	 */
	public const TYPE = 'audience_booking';

	/**
	 * Target type id.
	 *
	 * @return string
	 */
	public function type(): string {
		return self::TYPE;
	}

	/**
	 * Record a person present at a booking, on the site-local day of the
	 * reading.
	 *
	 * The day comes from the reading's own time in the site's timezone, so a
	 * reading synced after midnight still lands on the day it was taken. The
	 * table's unique key on booking, person and day is what makes every path
	 * — projected QR, by hand, terminal — count a person once a day.
	 *
	 * @param int      $target_id  Booking id.
	 * @param int      $user_id    The person.
	 * @param int      $checked_at Unix UTC seconds of the reading.
	 * @param string   $method     One of {@see \FreeFormCertificate\Core\Attendance::METHODS}.
	 * @param int|null $marked_by  The operator, for a mark by hand.
	 * @return string
	 */
	public function record( int $target_id, int $user_id, int $checked_at, string $method, ?int $marked_by ): string {
		$day = wp_date( 'Y-m-d', $checked_at );

		return AudienceAttendanceWriter::record( $target_id, $user_id, false === $day ? '' : $day, $method, $marked_by, $checked_at );
	}

	/**
	 * One window per counted day of an active booking: from that day's
	 * planned open to its automatic close (#2746).
	 *
	 * @param int $target_id Booking id.
	 * @return list<TerminalWindow>
	 */
	public function terminal_windows( int $target_id ): array {
		$booking = self::active_booking( $target_id );
		if ( null === $booking ) {
			return array();
		}
		$out = array();
		foreach ( AudienceAttendanceRule::counted_days( $booking ) as $date ) {
			$out[] = array(
				'date'   => $date,
				'opens'  => AudienceCheckinWindowWriter::planned_open_at( $booking, $date ),
				'closes' => AudienceCheckinWindowWriter::auto_close_at( $booking, $date ),
			);
		}
		return $out;
	}

	/**
	 * When the booking's last day ends.
	 *
	 * @param int $target_id Booking id.
	 * @return int|null
	 */
	public function terminal_ends_at( int $target_id ): ?int {
		return AudienceTerminalPairing::booking_end( $target_id );
	}

	/**
	 * A booking whose Check-in tab turned the terminal path off is refused.
	 *
	 * @param int $target_id Booking id.
	 * @return \WP_Error|null
	 */
	public function terminal_queue_error( int $target_id ): ?\WP_Error {
		$booking = AudienceBookingReader::get_by_id( $target_id );
		if ( null !== $booking && ! AudienceCheckinPolicy::terminal_allowed( $booking ) ) {
			return new \WP_Error( 'ffc_terminal_path_off', __( 'One of these events does not take check-in at a terminal. Turn the terminal path on in its Check-in tab, or leave it out.', 'ffcertificate' ), array( 'status' => 400 ) );
		}
		return null;
	}

	/**
	 * The booking's description, or "Event #id".
	 *
	 * @param int $target_id Booking id.
	 * @return string
	 */
	public function terminal_title( int $target_id ): string {
		$booking = AudienceBookingReader::get_by_id( $target_id );
		$title   = null === $booking ? '' : trim( (string) ( $booking->description ?? '' ) );
		/* translators: %d: event id */
		return '' !== $title ? $title : sprintf( __( 'Event #%d', 'ffcertificate' ), $target_id );
	}

	/**
	 * What the terminal shows for one day of the booking: the window an
	 * operator opened, when there is one today, and today's counter (#2780).
	 *
	 * @param int   $target_id Booking id.
	 * @param array $window    One of {@see self::terminal_windows()}.
	 * @param int   $now       Unix time.
	 * @phpstan-param TerminalWindow $window
	 * @return TerminalEntry
	 */
	public function terminal_entry( int $target_id, array $window, int $now ): array {
		$booking = AudienceBookingReader::get_by_id( $target_id );
		$today   = (string) wp_date( 'Y-m-d', $now ) === $window['date'];
		$open    = $today ? AudienceCheckinWindowReader::covering( $target_id, $window['date'], $now ) : null;
		$closes  = null === $open ? $window['closes'] : ( null === $open['closed_at'] ? $open['closes_at'] : min( $open['closed_at'], $open['closes_at'] ) );
		return array(
			'title'    => $this->terminal_title( $target_id ),
			'place'    => null === $booking ? '' : (string) ( $booking->environment_name ?? '' ),
			'hours'    => null === $booking ? '' : self::hours( $booking, $window['date'] ),
			'open'     => null !== $open,
			'closes'   => $closes,
			'present'  => $today ? count( array_unique( array_column( AudienceAttendanceReader::for_day( $target_id, $window['date'] ), 'user_id' ) ) ) : 0,
			'enrolled' => $today ? count( AudiencePostEventForm::participants( $target_id ) ) : 0,
		);
	}

	/**
	 * What one reading means for the booking (#2745):
	 *
	 * 1. the booking runs today and takes check-in at a terminal;
	 * 2. its check-in window is open;
	 * 3. the person is on the booking, or carries the site's ticket for it;
	 * 4. nobody recorded them today already.
	 *
	 * The window's geofence does not apply: the terminal stands at the
	 * entrance, and that is the point of it.
	 *
	 * @param int    $target_id Booking id.
	 * @param int    $user_id   The person.
	 * @param string $name      Their short display name.
	 * @param bool   $ticket    Whether the code carries the site's ticket.
	 * @param int    $at        The moment of the reading.
	 * @return Verdict
	 */
	public function terminal_read( int $target_id, int $user_id, string $name, bool $ticket, int $at ): array {
		$booking = AudienceBookingReader::get_by_id( $target_id );
		$day     = (string) wp_date( 'Y-m-d', $at );
		if ( null === $booking || 'active' !== ( $booking->status ?? '' ) || ! in_array( $day, AudienceAttendanceRule::counted_days( $booking ), true ) ) {
			return TerminalVerdict::refused( 'ffc_terminal_not_today', __( 'This event has no check-in today.', 'ffcertificate' ) );
		}

		if ( ! AudienceCheckinPolicy::terminal_allowed( $booking ) ) {
			return TerminalVerdict::refused( 'ffc_terminal_path_off', __( 'This event does not take check-in at a terminal. Speak to the organiser.', 'ffcertificate' ) );
		}

		if ( null === AudienceCheckinWindowReader::covering( $target_id, $day, $at ) ) {
			return ! AudienceCheckinWindowReader::opened_by( $target_id, $day, $at )
				? TerminalVerdict::refused( 'ffc_terminal_window_not_open', __( 'Check-in for this event has not opened yet. Wait for the organiser to open it.', 'ffcertificate' ) )
				: TerminalVerdict::refused( 'ffc_terminal_window_closed', __( 'Check-in for this event has closed. Speak to the organiser.', 'ffcertificate' ) );
		}

		if ( ! $ticket && ! AudienceCheckinPolicy::may_check_in( $booking, $user_id ) ) {
			return TerminalVerdict::refused( 'ffc_terminal_not_enrolled', __( 'This person is not enrolled in this event. Speak to the organiser.', 'ffcertificate' ) );
		}

		foreach ( AudienceAttendanceReader::for_day( $target_id, $day ) as $row ) {
			if ( $row['user_id'] === $user_id ) {
				return array(
					'status'  => TerminalVerdict::ALREADY,
					'code'    => 'ffc_terminal_already',
					/* translators: %s: the time attendance was recorded */
					'message' => sprintf( __( 'Attendance already recorded at %s.', 'ffcertificate' ), DateFormatter::format_time( (int) $row['checked_at'] ) ),
					'name'    => $name,
				);
			}
		}

		$status = AudienceAttendanceWriter::record( $target_id, $user_id, $day, AudienceAttendanceWriter::METHOD_TERMINAL, null, $at );
		if ( AudienceAttendanceWriter::FAILED === $status ) {
			return TerminalVerdict::refused( 'ffc_terminal_failed', __( 'The attendance could not be saved. Show the code again.', 'ffcertificate' ) );
		}

		$already = AudienceAttendanceWriter::ALREADY === $status;
		return array(
			'status'  => $already ? TerminalVerdict::ALREADY : TerminalVerdict::RECORDED,
			'code'    => $already ? 'ffc_terminal_already' : 'ffc_terminal_recorded',
			'message' => $already ? __( 'Attendance already recorded.', 'ffcertificate' ) : __( 'Attendance recorded.', 'ffcertificate' ),
			'name'    => $name,
			// Recorded only because the event is open to everyone (#2748):
			// the terminal says so, as the organiser's list does (#2780).
			'open'    => AudienceCheckinPolicy::is_open( $booking ) && ! in_array( $user_id, AudiencePostEventForm::participants( $target_id ), true ),
			'time'    => DateFormatter::format_time( $at ),
		);
	}

	/**
	 * An active booking, or null.
	 *
	 * @param int $booking_id Booking.
	 * @return object|null
	 */
	private static function active_booking( int $booking_id ): ?object {
		$booking = AudienceBookingReader::get_by_id( $booking_id );
		return null !== $booking && 'active' === ( $booking->status ?? '' ) ? $booking : null;
	}

	/**
	 * The day's hours, as the terminal shows them.
	 *
	 * @param object $booking Booking.
	 * @param string $date    Day.
	 * @return string
	 */
	private static function hours( object $booking, string $date ): string {
		if ( ! empty( $booking->is_all_day ) ) {
			return __( 'All day', 'ffcertificate' );
		}
		$times = AudienceBookingRules::stored_day_times( $booking, $date );
		$start = DateFormatter::format_wallclock_time( $times['start_time'] . ':00' );
		$end   = DateFormatter::format_wallclock_time( $times['end_time'] . ':00' );
		return '' === $start ? '' : ( '' === $end ? $start : $start . '–' . $end );
	}
}
