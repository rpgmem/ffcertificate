<?php
/**
 * Audience Check-in
 *
 * What happens between a participant scanning the QR and the attendance row
 * (#1947).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a scan into a receipt and a confirmed receipt into attendance.
 *
 * Two steps on purpose. A scan only proves the person saw a live code, so it
 * is exchanged for a receipt ({@see AudienceCheckinReceipt}) before anything
 * else, including a login, can make the code expire. The confirmation then
 * checks everything that needs a known user: that the booking still runs,
 * that its window is still the one open today, that the person is on the
 * booking, and, when the window asks for it, that they are inside one of its
 * geofence areas. Nothing is written until the confirmation.
 *
 * @phpstan-import-type WindowRow from AudienceCheckinWindowReader
 *
 * @since 6.42.0
 */
class AudienceCheckin {

	public const ERROR_INVALID_CODE    = 'ffc_checkin_invalid_code';
	public const ERROR_EXPIRED_RECEIPT = 'ffc_checkin_expired_receipt';
	public const ERROR_CLOSED          = 'ffc_checkin_closed';
	public const ERROR_UNAVAILABLE     = 'ffc_checkin_unavailable';
	public const ERROR_NOT_ON_BOOKING  = 'ffc_checkin_not_on_booking';
	public const ERROR_NEEDS_LOCATION  = 'ffc_checkin_needs_location';
	public const ERROR_OUTSIDE_AREA    = 'ffc_checkin_outside_area';
	public const ERROR_INACCURATE      = 'ffc_checkin_inaccurate';
	public const ERROR_FAILED          = 'ffc_checkin_failed';

	/**
	 * Exchange a scanned code for a receipt.
	 *
	 * The window looked up is the one open today for the code's booking, so a
	 * code can never be checked against a window it was not signed for.
	 *
	 * @param string $code Code from the QR.
	 * @param int    $now  Unix time.
	 * @return string|\WP_Error The receipt.
	 */
	public static function scan( string $code, int $now ) {
		$parts = AudienceCheckinCode::parse( $code );
		if ( null === $parts ) {
			return self::error( self::ERROR_INVALID_CODE );
		}

		$window = AudienceCheckinWindowReader::open_for( $parts['booking_id'], AudienceBookingRules::today(), $now );
		if ( null === $window || ! AudienceCheckinCode::verify( $code, $window, $now ) ) {
			return self::error( self::ERROR_INVALID_CODE );
		}

		return AudienceCheckinReceipt::issue( $window['booking_id'], $window['window_date'], $window['id'], $now );
	}

	/**
	 * Everything the confirmation page needs, or why it cannot be shown.
	 *
	 * @param string $receipt Receipt.
	 * @param int    $user_id Logged-in user.
	 * @param int    $now     Unix time.
	 * @return array{booking: object, window: array<string, mixed>, date: string, present_at: int|null}|\WP_Error
	 * @phpstan-return array{booking: object, window: WindowRow, date: string, present_at: int|null}|\WP_Error
	 */
	public static function context( string $receipt, int $user_id, int $now ) {
		$claim = AudienceCheckinReceipt::verify( $receipt, $now );
		if ( null === $claim ) {
			return self::error( self::ERROR_EXPIRED_RECEIPT );
		}

		$window = AudienceCheckinWindowReader::open_for( $claim['booking_id'], $claim['date'], $now );
		if ( null === $window || $window['id'] !== $claim['window_id'] ) {
			return self::error( self::ERROR_CLOSED );
		}

		$booking = AudienceBookingReader::get_by_id( $claim['booking_id'] );
		if ( null === $booking || 'active' !== ( $booking->status ?? '' ) || ! in_array( $claim['date'], AudienceAttendanceRule::counted_days( $booking ), true ) ) {
			return self::error( self::ERROR_UNAVAILABLE );
		}

		if ( $user_id <= 0 || ! in_array( $user_id, array_map( 'intval', AudienceBookingReader::get_all_affected_users( $claim['booking_id'] ) ), true ) ) {
			return self::error( self::ERROR_NOT_ON_BOOKING );
		}

		$present_at = null;
		foreach ( AudienceAttendanceReader::for_day( $claim['booking_id'], $claim['date'] ) as $row ) {
			if ( $row['user_id'] === $user_id ) {
				$present_at = $row['checked_at'];
				break;
			}
		}

		return array(
			'booking'    => $booking,
			'window'     => $window,
			'date'       => $claim['date'],
			'present_at' => $present_at,
		);
	}

	/**
	 * Record the attendance a receipt and a logged-in user stand for.
	 *
	 * @param string                                              $receipt  Receipt.
	 * @param int                                                 $user_id  Logged-in user.
	 * @param array{lat: float, lng: float, accuracy: float}|null $position The browser's position, when it gave one.
	 * @param int                                                 $now      Unix time.
	 * @return array{status: string, booking: object, checked_at: int}|\WP_Error `status` is a {@see AudienceAttendanceWriter} result.
	 */
	public static function confirm( string $receipt, int $user_id, ?array $position, int $now ) {
		$context = self::context( $receipt, $user_id, $now );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( null !== $context['present_at'] ) {
			return array(
				'status'     => AudienceAttendanceWriter::ALREADY,
				'booking'    => $context['booking'],
				'checked_at' => $context['present_at'],
			);
		}

		$where = self::check_position( $context['window']['geofence_locations'], $position );
		if ( is_wp_error( $where ) ) {
			return $where;
		}

		$status = AudienceAttendanceWriter::record(
			$context['window']['booking_id'],
			$user_id,
			$context['date'],
			AudienceAttendanceWriter::METHOD_QR,
			null,
			$now
		);
		if ( AudienceAttendanceWriter::FAILED === $status ) {
			return self::error( self::ERROR_FAILED );
		}

		return array(
			'status'     => $status,
			'booking'    => $context['booking'],
			'checked_at' => $now,
		);
	}

	/**
	 * Whether a position satisfies a window's geofence.
	 *
	 * The position comes from the participant's browser, so it can be forged:
	 * this adds friction to checking in from elsewhere, it does not prove
	 * presence. The rule is the certificate form's (#2040): a reading whose
	 * margin of error is larger than the smallest area cannot tell inside
	 * from outside, and is refused. A check-in has no "When GPS fails"
	 * policy: no usable position, no check-in.
	 *
	 * @param string[]                                            $location_ids Saved geofence locations; empty for none.
	 * @param array{lat: float, lng: float, accuracy: float}|null $position     Browser position.
	 * @return true|\WP_Error
	 */
	public static function check_position( array $location_ids, ?array $position ) {
		if ( array() === $location_ids ) {
			return true;
		}

		$areas = array();
		foreach ( \FreeFormCertificate\Security\GeofenceLocationRegistry::get_by_ids( $location_ids ) as $location ) {
			$areas[] = array(
				'lat'    => (float) ( $location['lat'] ?? 0 ),
				'lng'    => (float) ( $location['lng'] ?? 0 ),
				'radius' => (float) ( $location['radius'] ?? 0 ),
			);
		}

		// A window naming only locations that were deleted since cannot be met;
		// refusing keeps a typo from turning the geofence off.
		if ( null === $position || array() === $areas ) {
			return self::error( null === $position ? self::ERROR_NEEDS_LOCATION : self::ERROR_OUTSIDE_AREA );
		}

		// The page reads a fresh position (no cached reading), so its age is
		// zero; every failure case blocks.
		$verdict = \FreeFormCertificate\Security\GpsReading::decide(
			$areas,
			array(
				'reading' => array(
					'lat'      => $position['lat'],
					'lng'      => $position['lng'],
					'accuracy' => $position['accuracy'],
					'age'      => 0,
				),
				'status'  => '',
			),
			array(),
			0
		);
		if ( $verdict['pass'] ) {
			return true;
		}

		return self::error( \FreeFormCertificate\Security\GpsReading::REASON_INACCURATE === $verdict['reason'] ? self::ERROR_INACCURATE : self::ERROR_OUTSIDE_AREA );
	}

	/**
	 * A position posted by the confirmation page, if it is one: a latitude,
	 * a longitude and the reading's margin of error in metres.
	 *
	 * @param mixed $lat      Latitude.
	 * @param mixed $lng      Longitude.
	 * @param mixed $accuracy Margin of error, in metres.
	 * @return array{lat: float, lng: float, accuracy: float}|null
	 */
	public static function position( $lat, $lng, $accuracy ): ?array {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) || ! is_numeric( $accuracy ) ) {
			return null;
		}

		$lat      = (float) $lat;
		$lng      = (float) $lng;
		$accuracy = (float) $accuracy;
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || $accuracy < 0 ) {
			return null;
		}

		return array(
			'lat'      => $lat,
			'lng'      => $lng,
			'accuracy' => $accuracy,
		);
	}

	/**
	 * The message a participant reads for an error.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function message( string $code ): string {
		switch ( $code ) {
			case self::ERROR_INVALID_CODE:
				return __( 'This check-in code is not valid any more. Scan the QR code shown at the event again.', 'ffcertificate' );
			case self::ERROR_EXPIRED_RECEIPT:
				return __( 'This check-in link has expired. Scan the QR code shown at the event again.', 'ffcertificate' );
			case self::ERROR_CLOSED:
				return __( 'Check-in for this event is closed.', 'ffcertificate' );
			case self::ERROR_UNAVAILABLE:
				return __( 'This event is not taking check-ins today.', 'ffcertificate' );
			case self::ERROR_NOT_ON_BOOKING:
				return __( 'You are not on the participant list of this event. Ask the organiser to mark your attendance.', 'ffcertificate' );
			case self::ERROR_NEEDS_LOCATION:
				return __( 'This check-in needs your location. Allow it in your browser and try again.', 'ffcertificate' );
			case self::ERROR_OUTSIDE_AREA:
				return __( 'Your location is outside the event area. Check in from the event, or ask the organiser to mark your attendance.', 'ffcertificate' );
			case self::ERROR_INACCURATE:
				return __( 'Your location is not precise enough to confirm you are at the event. Move to an open area or turn on precise location, then try again.', 'ffcertificate' );
			default:
				return __( 'Your attendance could not be recorded. Try again, or ask the organiser to mark it.', 'ffcertificate' );
		}
	}

	/**
	 * An error carrying its participant-facing message.
	 *
	 * @param string $code Error code.
	 * @return \WP_Error
	 */
	private static function error( string $code ): \WP_Error {
		return new \WP_Error( $code, self::message( $code ) );
	}
}
