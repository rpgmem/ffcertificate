<?php
/**
 * Audience Check-in Code
 *
 * The rotating code a check-in QR carries (#1947).
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
 * Signs and verifies the code in a check-in QR.
 *
 * A code is `<booking>.<slot>.<signature>`, carried as `?ffc_checkin=` on the
 * site's home URL. The slot is the number of the rotation interval
 * (`floor( time / rotation )`), so the code changes every rotation without
 * anything being stored: two screens projecting one window show the same
 * code. The signature is an HMAC over booking, day, slot and window id,
 * base64url, truncated to 16 characters (96 bits) to keep the QR sparse.
 *
 * The window id is what makes a reopened day reject every code shown before
 * it, and the day is what keeps a code from one day of a range valid on the
 * next.
 *
 * The key is derived from `wp_salt( 'nonce' )` under its own context, the
 * shape of `Core\Captcha\ChallengeSigner`; rotating the site's salts voids the
 * codes in flight.
 *
 * @phpstan-import-type WindowRow from AudienceCheckinWindowReader
 *
 * @since 6.42.0
 */
class AudienceCheckinCode {

	/**
	 * Query variable the code travels in.
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'ffc_checkin';

	/**
	 * Seconds between codes an operator may choose.
	 *
	 * @var list<int>
	 */
	public const ROTATIONS = array( 60, 120, 180, 300, 600 );

	/**
	 * Seconds between codes when none is chosen.
	 *
	 * @var int
	 */
	public const DEFAULT_ROTATION = 60;

	/**
	 * Characters of signature a code carries.
	 *
	 * @var int
	 */
	public const SIGNATURE_LENGTH = 16;

	/**
	 * HMAC context, so the key differs from every other signer in the plugin.
	 *
	 * @var string
	 */
	private const CONTEXT = 'ffc_audience_checkin_v1';

	/**
	 * A rotation an operator may choose, or the default.
	 *
	 * @param int $seconds Requested seconds.
	 * @return int
	 */
	public static function normalize_rotation( int $seconds ): int {
		return in_array( $seconds, self::ROTATIONS, true ) ? $seconds : self::DEFAULT_ROTATION;
	}

	/**
	 * The rotation interval a moment falls in.
	 *
	 * @param int $rotation Seconds between codes.
	 * @param int $now      Unix time.
	 * @return int
	 */
	public static function slot( int $rotation, int $now ): int {
		return intdiv( $now, self::normalize_rotation( $rotation ) );
	}

	/**
	 * Seconds until the code shown now is replaced.
	 *
	 * @param int $rotation Seconds between codes.
	 * @param int $now      Unix time.
	 * @return int Between 1 and the rotation.
	 */
	public static function seconds_remaining( int $rotation, int $now ): int {
		$rotation = self::normalize_rotation( $rotation );

		return $rotation - ( $now % $rotation );
	}

	/**
	 * The code a window shows at a moment.
	 *
	 * @param array<string, mixed> $window Window.
	 * @phpstan-param WindowRow $window
	 * @param int                  $now    Unix time.
	 * @return string
	 */
	public static function for_window( array $window, int $now ): string {
		$slot = self::slot( $window['rotation_seconds'], $now );

		return $window['booking_id'] . '.' . $slot . '.' . self::signature( $window['booking_id'], $window['window_date'], $slot, $window['id'] );
	}

	/**
	 * The URL a window's QR encodes at a moment.
	 *
	 * @param array<string, mixed> $window Window.
	 * @phpstan-param WindowRow $window
	 * @param int                  $now    Unix time.
	 * @return string
	 */
	public static function url( array $window, int $now ): string {
		return add_query_arg( self::QUERY_VAR, self::for_window( $window, $now ), home_url( '/' ) );
	}

	/**
	 * Split a code into its parts, without checking the signature.
	 *
	 * @param string $code Code from the URL.
	 * @return array{booking_id: int, slot: int, signature: string}|null Null when malformed.
	 */
	public static function parse( string $code ): ?array {
		if ( ! preg_match( '/^([1-9]\d{0,19})\.(\d{1,12})\.([A-Za-z0-9_-]{' . self::SIGNATURE_LENGTH . '})$/', $code, $m ) ) {
			return null;
		}

		return array(
			'booking_id' => (int) $m[1],
			'slot'       => (int) $m[2],
			'signature'  => $m[3],
		);
	}

	/**
	 * Whether a code is valid for a window at a moment: well formed, for that
	 * window's booking, from the current or the previous rotation, signed for
	 * that window and day, and the window open.
	 *
	 * The previous rotation is accepted so a scan made a second before the
	 * code changes still lands.
	 *
	 * @param string               $code   Code from the URL.
	 * @param array<string, mixed> $window The window open for the code's booking today.
	 * @phpstan-param WindowRow $window
	 * @param int                  $now    Unix time.
	 * @return bool
	 */
	public static function verify( string $code, array $window, int $now ): bool {
		$parts = self::parse( $code );
		if ( null === $parts || $parts['booking_id'] !== $window['booking_id'] ) {
			return false;
		}

		if ( ! AudienceCheckinWindowReader::is_open( $window, $now ) ) {
			return false;
		}

		$current = self::slot( $window['rotation_seconds'], $now );
		if ( $parts['slot'] !== $current && $parts['slot'] !== $current - 1 ) {
			return false;
		}

		return hash_equals(
			self::signature( $window['booking_id'], $window['window_date'], $parts['slot'], $window['id'] ),
			$parts['signature']
		);
	}

	/**
	 * The truncated signature of one code.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $date       Day (Y-m-d).
	 * @param int    $slot       Rotation interval.
	 * @param int    $window_id  Window ID.
	 * @return string
	 */
	private static function signature( int $booking_id, string $date, int $slot, int $window_id ): string {
		$raw = hash_hmac( 'sha256', $booking_id . '|' . $date . '|' . $slot . '|' . $window_id, self::key(), true );

		return substr( rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ), 0, self::SIGNATURE_LENGTH ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of an HMAC digest, not obfuscation.
	}

	/**
	 * The signing key.
	 *
	 * @return string
	 */
	private static function key(): string {
		return hash_hmac( 'sha256', self::CONTEXT, wp_salt( 'nonce' ) );
	}
}
