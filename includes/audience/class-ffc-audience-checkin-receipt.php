<?php
/**
 * Audience Check-in Receipt
 *
 * What a valid scan turns into, so a login does not outlive the code (#1947).
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
 * Signs and verifies a scan receipt.
 *
 * A code lives one or two rotations; a participant who has to log in takes
 * longer than that. So a valid scan is exchanged at once for a receipt that
 * names the booking, the day and the window and is valid for two of that
 * window's rotations ({@see self::ttl()}, #2243); the receipt is what crosses
 * the login, and the confirmation is checked against it. A photo of an old code stays useless,
 * because only a scan of a live code earns a receipt.
 *
 * Shape: `<booking>.<Ymd>.<window>.<issued_at>.<signature>`, with its own HMAC
 * context, apart from the code's.
 *
 * @since 6.42.0
 */
class AudienceCheckinReceipt {

	/**
	 * Rotations a receipt stays valid for (#2243).
	 *
	 * @var int
	 */
	public const TTL_ROTATIONS = 2;

	/**
	 * Clock skew tolerated on a receipt's issue time.
	 *
	 * @var int
	 */
	private const SKEW = 60;

	/**
	 * Characters of signature a receipt carries.
	 *
	 * @var int
	 */
	private const SIGNATURE_LENGTH = 32;

	/**
	 * HMAC context.
	 *
	 * @var string
	 */
	private const CONTEXT = 'ffc_audience_checkin_receipt_v1';

	/**
	 * Issue a receipt for a valid scan.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $date       Day (Y-m-d).
	 * @param int    $window_id  Window ID.
	 * @param int    $now        Unix time.
	 * @return string
	 */
	public static function issue( int $booking_id, string $date, int $window_id, int $now ): string {
		$payload = $booking_id . '.' . str_replace( '-', '', $date ) . '.' . $window_id . '.' . $now;

		return $payload . '.' . self::signature( $payload );
	}

	/**
	 * Read a receipt back, if it is genuine and young enough for the longest
	 * rotation.
	 *
	 * Whether it is still valid for its own window depends on that window's
	 * rotation, which the caller reads from the window the receipt names:
	 * {@see self::is_fresh()}. A receipt older than any rotation allows is
	 * refused here, before anything is looked up.
	 *
	 * @param string $receipt Receipt.
	 * @param int    $now     Unix time.
	 * @return array{booking_id: int, date: string, window_id: int, issued_at: int}|null
	 */
	public static function verify( string $receipt, int $now ): ?array {
		if ( ! preg_match( '/^([1-9]\d{0,19})\.(\d{4})(\d{2})(\d{2})\.([1-9]\d{0,19})\.(\d{1,12})\.([A-Za-z0-9_-]{' . self::SIGNATURE_LENGTH . '})$/', $receipt, $m ) ) {
			return null;
		}

		$payload = $m[1] . '.' . $m[2] . $m[3] . $m[4] . '.' . $m[5] . '.' . $m[6];
		if ( ! hash_equals( self::signature( $payload ), $m[7] ) ) {
			return null;
		}

		$issued_at = (int) $m[6];
		if ( $issued_at > $now + self::SKEW || ! self::is_fresh( $issued_at, max( AudienceCheckinCode::ROTATIONS ), $now ) ) {
			return null;
		}

		if ( ! checkdate( (int) $m[3], (int) $m[4], (int) $m[2] ) ) {
			return null;
		}

		return array(
			'booking_id' => (int) $m[1],
			'date'       => $m[2] . '-' . $m[3] . '-' . $m[4],
			'window_id'  => (int) $m[5],
			'issued_at'  => $issued_at,
		);
	}

	/**
	 * Seconds a receipt stays valid for a window: two of its rotations.
	 *
	 * @param int $rotation The window's seconds between codes.
	 * @return int
	 */
	public static function ttl( int $rotation ): int {
		return self::TTL_ROTATIONS * AudienceCheckinCode::normalize_rotation( $rotation );
	}

	/**
	 * Whether a receipt issued at a moment is still valid for a window.
	 *
	 * @param int $issued_at The receipt's issue time.
	 * @param int $rotation  The window's seconds between codes.
	 * @param int $now       Unix time.
	 * @return bool
	 */
	public static function is_fresh( int $issued_at, int $rotation, int $now ): bool {
		return $now - $issued_at <= self::ttl( $rotation );
	}

	/**
	 * The truncated signature of a payload.
	 *
	 * @param string $payload Payload.
	 * @return string
	 */
	private static function signature( string $payload ): string {
		$raw = hash_hmac( 'sha256', $payload, hash_hmac( 'sha256', self::CONTEXT, wp_salt( 'nonce' ) ), true );

		return substr( rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ), 0, self::SIGNATURE_LENGTH ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of an HMAC digest, not obfuscation.
	}
}
