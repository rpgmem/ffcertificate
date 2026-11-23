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
 * method, at the moment the site receives the reading.
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
	 * Read a code.
	 *
	 * @param array  $terminal Terminal row.
	 * @param string $code     The text the reader typed.
	 * @param int    $now      Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return ReadingResult
	 */
	public static function read( array $terminal, string $code, int $now ): array {
		$revoked = AudiencePresenceReader::revoked_key_ids_since( $now - AudiencePresenceAttestation::TTL );
		$reading = AudiencePresenceCode::verify( $code, $now, $revoked );
		if ( is_wp_error( $reading ) ) {
			return self::refused( (string) $reading->get_error_code(), $reading->get_error_message() );
		}

		$booking_id = $reading['target_id'];
		if ( AudienceAttendanceTarget::TYPE !== $reading['target_type'] || ! in_array( $booking_id, $terminal['bookings'], true ) ) {
			return self::refused( 'ffc_terminal_other_event', __( 'This code is for an event this terminal does not check in. Choose this event on the phone, or use the terminal at its entrance.', 'ffcertificate' ) );
		}

		$booking = AudienceBookingReader::get_by_id( $booking_id );
		$today   = (string) wp_date( 'Y-m-d', $now );
		if ( null === $booking || 'active' !== ( $booking->status ?? '' ) || ! in_array( $today, AudienceAttendanceRule::counted_days( $booking ), true ) ) {
			return self::refused( 'ffc_terminal_not_today', __( 'This event has no check-in today.', 'ffcertificate' ) );
		}

		if ( null === AudienceCheckinWindowReader::open_for( $booking_id, $today, $now ) ) {
			$latest = AudienceCheckinWindowReader::latest_for( $booking_id, $today );
			return null === $latest
				? self::refused( 'ffc_terminal_window_not_open', __( 'Check-in for this event has not opened yet. Wait for the organiser to open it.', 'ffcertificate' ) )
				: self::refused( 'ffc_terminal_window_closed', __( 'Check-in for this event has closed. Speak to the organiser.', 'ffcertificate' ) );
		}

		$user_id = $reading['user_id'];
		if ( ! $reading['ticket'] && ! in_array( $user_id, array_map( 'intval', AudienceBookingReader::get_all_affected_users( $booking_id ) ), true ) ) {
			return self::refused( 'ffc_terminal_not_enrolled', __( 'This person is not enrolled in this event. Speak to the organiser.', 'ffcertificate' ) );
		}

		foreach ( AudienceAttendanceReader::for_day( $booking_id, $today ) as $row ) {
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

		$status = AudienceAttendanceWriter::record( $booking_id, $user_id, $today, AudienceAttendanceWriter::METHOD_TERMINAL, null, $now );
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
