<?php
/**
 * Audience Terminal Reading
 *
 * What a paired terminal does with a presence code it reads (#2745).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\DateFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns one reading into attendance, or into a refusal that says what to do.
 *
 * The code is checked by its one verifier ({@see AudiencePresenceCode}), with
 * the revocation list, and then against what the terminal was paired for:
 *
 * 1. the event is one of the terminal's;
 * 2. the event runs today and its check-in window is open;
 * 3. the person is on the booking, or carries the site's ticket for it;
 * 4. nobody recorded them today already.
 *
 * The window's geofence does not apply: the terminal stands at the entrance,
 * and that is the point of it. Attendance is recorded with the `terminal`
 * method, at the moment of the reading: when the site receives it online,
 * or the moment the terminal read it when it sends a queue it kept offline.
 *
 * What a result shows is the person's short name ("Maria S.") and nothing
 * else; a refusal shows no name at all, so a terminal never says who tried.
 *
 * @phpstan-import-type TerminalRow from AudienceTerminalReader
 * @phpstan-type ReadingResult array{status: string, code: string, message: string, name: string}
 */
final class AudienceTerminalReading {

	/**
	 * Attendance was recorded.
	 */
	public const RECORDED = 'recorded';

	/**
	 * The person was already recorded today.
	 */
	public const ALREADY = 'already';

	/**
	 * The reading was refused.
	 */
	public const REFUSED = 'refused';

	/**
	 * How many readings one sync may carry.
	 */
	public const SYNC_BATCH = 100;

	/**
	 * Read a code now, online.
	 *
	 * @param array  $terminal Terminal row.
	 * @param string $code     The text the reader typed.
	 * @param int    $now      Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return ReadingResult
	 */
	public static function read( array $terminal, string $code, int $now ): array {
		return self::evaluate( $terminal, $code, $now );
	}

	/**
	 * Read a code the terminal queued offline, as of the moment it read it
	 * (#2745).
	 *
	 * Everything is checked at that moment: the code's own freshness, the
	 * keys revoked by then, the window open then. A phone replaced after the
	 * reading does not undo it; a reading taken after the replacement is
	 * refused. The moment is the terminal's clock, so it is bounded: not
	 * before the terminal was paired, not after the site's now plus the
	 * clock tolerance, and not older than an attestation lives.
	 *
	 * @param array  $terminal Terminal row.
	 * @param string $code     The text the reader typed.
	 * @param int    $at       When the terminal read it.
	 * @param int    $now      The site's Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return ReadingResult
	 */
	public static function read_queued( array $terminal, string $code, int $at, int $now ): array {
		if ( $at > $now + AudiencePresenceCode::SKEW || $at < $terminal['paired_at'] - AudiencePresenceCode::SKEW || $at < $now - AudiencePresenceAttestation::TTL ) {
			return self::refused( 'ffc_terminal_reading_time', __( 'This reading was taken at a time this terminal cannot vouch for, and was not recorded.', 'ffcertificate' ) );
		}
		return self::evaluate( $terminal, $code, $at );
	}

	/**
	 * Read a batch the terminal queued offline. Sending the same batch twice
	 * records nothing twice: a person already recorded that day comes back
	 * as already recorded.
	 *
	 * A refused reading is logged with its reason, never with the code or a
	 * name, so a reading taken after a revocation leaves a trace.
	 *
	 * @param array $terminal Terminal row.
	 * @param array $readings Each `{id, code, at}`.
	 * @param int   $now      Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @phpstan-param list<array{id: string, code: string, at: int}> $readings
	 * @return array<string, ReadingResult> Results by the terminal's reading id.
	 */
	public static function sync( array $terminal, array $readings, int $now ): array {
		$results = array();
		$refused = array();
		foreach ( array_slice( $readings, 0, self::SYNC_BATCH ) as $reading ) {
			$result                    = self::read_queued( $terminal, $reading['code'], $reading['at'], $now );
			$results[ $reading['id'] ] = $result;
			if ( self::REFUSED === $result['status'] ) {
				$refused[ $result['code'] ] = ( $refused[ $result['code'] ] ?? 0 ) + 1;
			}
		}
		if ( array() !== $refused ) {
			ActivityLog::log(
				'terminal_readings_refused',
				ActivityLog::LEVEL_WARNING,
				array(
					'terminal_id' => $terminal['id'],
					'reasons'     => $refused,
				)
			);
		}
		return $results;
	}

	/**
	 * Read a code as of a moment.
	 *
	 * @param array  $terminal Terminal row.
	 * @param string $code     The text the reader typed.
	 * @param int    $at       The moment of the reading.
	 * @phpstan-param TerminalRow $terminal
	 * @return ReadingResult
	 */
	private static function evaluate( array $terminal, string $code, int $at ): array {
		$revoked = AudiencePresenceReader::revoked_key_ids_between( $at - AudiencePresenceAttestation::TTL, $at );
		$reading = AudiencePresenceCode::verify( $code, $at, $revoked );
		if ( is_wp_error( $reading ) ) {
			return self::refused( (string) $reading->get_error_code(), $reading->get_error_message() );
		}

		$booking_id = $reading['target_id'];
		if ( AudienceAttendanceTarget::TYPE !== $reading['target_type'] || ! in_array( $booking_id, $terminal['bookings'], true ) ) {
			return self::refused( 'ffc_terminal_other_event', __( 'This code is for an event this terminal does not check in. Choose this event on the phone, or use the terminal at its entrance.', 'ffcertificate' ) );
		}

		$booking = AudienceBookingReader::get_by_id( $booking_id );
		$day     = (string) wp_date( 'Y-m-d', $at );
		if ( null === $booking || 'active' !== ( $booking->status ?? '' ) || ! in_array( $day, AudienceAttendanceRule::counted_days( $booking ), true ) ) {
			return self::refused( 'ffc_terminal_not_today', __( 'This event has no check-in today.', 'ffcertificate' ) );
		}

		if ( null === AudienceCheckinWindowReader::covering( $booking_id, $day, $at ) ) {
			return ! AudienceCheckinWindowReader::opened_by( $booking_id, $day, $at )
				? self::refused( 'ffc_terminal_window_not_open', __( 'Check-in for this event has not opened yet. Wait for the organiser to open it.', 'ffcertificate' ) )
				: self::refused( 'ffc_terminal_window_closed', __( 'Check-in for this event has closed. Speak to the organiser.', 'ffcertificate' ) );
		}

		$user_id = $reading['user_id'];
		if ( ! $reading['ticket'] && ! in_array( $user_id, array_map( 'intval', AudienceBookingReader::get_all_affected_users( $booking_id ) ), true ) ) {
			return self::refused( 'ffc_terminal_not_enrolled', __( 'This person is not enrolled in this event. Speak to the organiser.', 'ffcertificate' ) );
		}

		foreach ( AudienceAttendanceReader::for_day( $booking_id, $day ) as $row ) {
			if ( $row['user_id'] === $user_id ) {
				return array(
					'status'  => self::ALREADY,
					'code'    => 'ffc_terminal_already',
					/* translators: %s: the time attendance was recorded */
					'message' => sprintf( __( 'Attendance already recorded at %s.', 'ffcertificate' ), DateFormatter::format_time( (int) $row['checked_at'] ) ),
					'name'    => $reading['name'],
				);
			}
		}

		$status = AudienceAttendanceWriter::record( $booking_id, $user_id, $day, AudienceAttendanceWriter::METHOD_TERMINAL, null, $at );
		if ( AudienceAttendanceWriter::FAILED === $status ) {
			return self::refused( 'ffc_terminal_failed', __( 'The attendance could not be saved. Show the code again.', 'ffcertificate' ) );
		}

		return array(
			'status'  => AudienceAttendanceWriter::ALREADY === $status ? self::ALREADY : self::RECORDED,
			'code'    => AudienceAttendanceWriter::ALREADY === $status ? 'ffc_terminal_already' : 'ffc_terminal_recorded',
			'message' => AudienceAttendanceWriter::ALREADY === $status ? __( 'Attendance already recorded.', 'ffcertificate' ) : __( 'Attendance recorded.', 'ffcertificate' ),
			'name'    => $reading['name'],
		);
	}

	/**
	 * A refusal, with no name in it.
	 *
	 * @param string $code    Reason code.
	 * @param string $message What to do.
	 * @return ReadingResult
	 */
	private static function refused( string $code, string $message ): array {
		return array(
			'status'  => self::REFUSED,
			'code'    => $code,
			'message' => $message,
			'name'    => '',
		);
	}
}
