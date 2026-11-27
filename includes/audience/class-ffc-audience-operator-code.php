<?php
/**
 * Audience Operator Code
 *
 * The operator QR code that unlocks a paired terminal (#2744).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\Base45;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A short-lived statement, signed by the site's presence key, that a given
 * operator may unlock a terminal.
 *
 * The terminal already holds the site's public keys, so it checks the
 * signature offline, the same way it checks an attestation. The code unlocks
 * the terminal's menu; it pairs nothing and records nothing, and it names
 * the operator only by account number.
 *
 * Text: `FFO1` followed by Base45 (RFC 9285) of:
 *
 * | Bytes | Field |
 * | --- | --- |
 * | 1 | version ({@see self::VERSION}) |
 * | 1 | site key id length, then the id |
 * | 4 | operator user id, unsigned big-endian |
 * | 4 | expires, Unix time, unsigned big-endian |
 * | 64 | site signature, raw `r || s` |
 *
 * The signature covers {@see self::DOMAIN} followed by every byte before it.
 */
final class AudienceOperatorCode {

	/**
	 * The format version.
	 */
	public const VERSION = 1;

	/**
	 * What every code starts with.
	 */
	public const PREFIX = 'FFO1';

	/**
	 * What the signature covers ahead of the body.
	 */
	public const DOMAIN = "FFC-OPERATOR-UNLOCK\x00";

	/**
	 * The window a code belongs to, in seconds (#2907). Codes expire on the
	 * site clock's window boundaries (10:30:00, 10:32:00, …), so reopening
	 * the screen within a window shows the time that window has left
	 * rather than a fresh full count.
	 */
	public const TTL = 120;

	/**
	 * The least a fresh code may have left, in seconds: below it the code
	 * of the next window is issued, since the app asks for a new one 20 s
	 * before expiry and a nearly spent code would be replaced at once.
	 */
	public const MIN_LEFT = 30;

	/**
	 * Clock tolerance on the verifying side, in seconds.
	 */
	public const SKEW = 60;

	/**
	 * When a code issued now expires: the end of the current window, or of
	 * the next one when this one has under {@see self::MIN_LEFT} left. A
	 * code therefore lives between MIN_LEFT and TTL + MIN_LEFT seconds.
	 *
	 * @param int $now Unix time.
	 * @return int Unix time.
	 */
	public static function expiry( int $now ): int {
		$end = ( intdiv( $now, self::TTL ) + 1 ) * self::TTL;
		return $end - $now < self::MIN_LEFT ? $end + self::TTL : $end;
	}

	/**
	 * Sign a code for an operator.
	 *
	 * @param int $user_id The operator.
	 * @param int $now     Unix time.
	 * @return array{code: string, expires_at: int}|null Null when the site key cannot sign.
	 */
	public static function issue( int $user_id, int $now ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		$expires  = self::expiry( $now );
		$site_key = AudiencePresenceKeys::ensure_active();
		if ( '' === $site_key ) {
			return null;
		}
		$body   = self::body( $site_key, $user_id, $expires );
		$signed = AudiencePresenceKeys::sign( self::DOMAIN . $body );
		if ( null !== $signed && $signed['key'] !== $site_key ) {
			$body   = self::body( $signed['key'], $user_id, $expires );
			$signed = AudiencePresenceKeys::sign( self::DOMAIN . $body );
		}
		if ( null === $signed ) {
			return null;
		}
		return array(
			'code'       => self::PREFIX . Base45::encode( $body . $signed['signature'] ),
			'expires_at' => $expires,
		);
	}

	/**
	 * Read and verify a code.
	 *
	 * @param string $text The code.
	 * @param int    $now  Unix time.
	 * @return array{user_id: int, expires: int}|\WP_Error
	 */
	public static function verify( string $text, int $now ) {
		$raw = 0 === strpos( $text, self::PREFIX ) ? Base45::decode( (string) substr( $text, strlen( self::PREFIX ) ) ) : null;
		if ( null === $raw ) {
			return new \WP_Error( 'ffc_operator_code_malformed', __( 'This is not an operator code.', 'ffcertificate' ) );
		}
		$length = strlen( $raw );
		$kid    = $length > 1 ? ord( $raw[1] ) : 0;
		if ( 2 + $kid + 8 + 64 !== $length || self::VERSION !== ord( $raw[0] ) || $kid < 1 || $kid > 16 ) {
			return new \WP_Error( 'ffc_operator_code_malformed', __( 'This is not an operator code.', 'ffcertificate' ) );
		}
		$site_key = (string) substr( $raw, 2, $kid );
		$body     = (string) substr( $raw, 0, $length - 64 );
		$fields   = unpack( 'Nuser/Nexpires', (string) substr( $raw, 2 + $kid, 8 ) );
		if ( ! is_array( $fields ) || ! AudiencePresenceKeys::verify( self::DOMAIN . $body, (string) substr( $raw, -64 ), $site_key, $now ) ) {
			return new \WP_Error( 'ffc_operator_code_signature', __( 'This operator code was not issued by this site.', 'ffcertificate' ) );
		}
		$expires = (int) $fields['expires'];
		if ( $expires + self::SKEW < $now ) {
			return new \WP_Error( 'ffc_operator_code_expired', __( 'This operator code has expired. Show a new one.', 'ffcertificate' ) );
		}
		return array(
			'user_id' => (int) $fields['user'],
			'expires' => $expires,
		);
	}

	/**
	 * The signed body.
	 *
	 * @param string $site_key Site key id.
	 * @param int    $user_id  Operator.
	 * @param int    $expires  Unix time.
	 * @return string
	 */
	private static function body( string $site_key, int $user_id, int $expires ): string {
		return chr( self::VERSION ) . chr( strlen( $site_key ) ) . $site_key . pack( 'NN', $user_id, $expires );
	}
}
