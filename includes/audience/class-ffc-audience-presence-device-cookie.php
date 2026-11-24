<?php
/**
 * AudiencePresenceDeviceCookie
 *
 * Which phone a request comes from (#2828). A page request carries no device
 * key, so the site cannot tell the phone whose presence is active from
 * another phone of the same account. When the app activates presence,
 * renews it, or refreshes its list with this phone's key, the site writes
 * this cookie on that phone: the key id and an HMAC of the account and the
 * key. A later request from the same phone carries it back, and it counts
 * while the key is still the account's active device.
 *
 * The HMAC is keyed with `wp_salt( 'auth' )`, so the value cannot be made
 * for another account or another key without the site's salt. The cookie
 * is `HttpOnly`, so no script reads it.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.46.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\ArrayValue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The phone-bound marker of active presence.
 */
final class AudiencePresenceDeviceCookie {

	/**
	 * Cookie name.
	 */
	public const NAME = 'ffc_presence_device';

	/**
	 * How long the cookie lasts. The key is checked on every read, so a
	 * long life only spares a phone that rarely opens Presence from losing
	 * its operator screens.
	 */
	public const TTL = 31536000;

	/**
	 * The cookie value for an account's key: the key id, a dot, the HMAC.
	 *
	 * @param int    $user_id Account.
	 * @param string $key_id  Device key id.
	 * @return string
	 */
	public static function value( int $user_id, string $key_id ): string {
		return $key_id . '.' . hash_hmac( 'sha256', $user_id . '|' . $key_id, wp_salt( 'auth' ) );
	}

	/**
	 * Mark this phone as the one holding the account's key.
	 *
	 * @param int    $user_id Account.
	 * @param string $key_id  Device key id.
	 * @return void
	 */
	public static function remember( int $user_id, string $key_id ): void {
		if ( $user_id <= 0 || '' === $key_id || headers_sent() ) {
			return;
		}
		$value = self::value( $user_id, $key_id );
		setcookie(
			self::NAME,
			$value,
			array(
				'expires'  => time() + self::TTL,
				'path'     => '/',
				'secure'   => is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::NAME ] = $value;
	}

	/**
	 * The key this request's phone holds for the account, while it is still
	 * the account's active device; null otherwise.
	 *
	 * @param int         $user_id Account.
	 * @param string|null $raw     The cookie value; read from the request when null.
	 * @return string|null
	 */
	public static function active_key( int $user_id, ?string $raw = null ): ?string {
		if ( $user_id <= 0 ) {
			return null;
		}
		if ( null === $raw ) {
			$raw = isset( $_COOKIE[ self::NAME ] ) ? ArrayValue::string( wp_unslash( $_COOKIE ), self::NAME ) : '';
		}
		$parts = explode( '.', $raw, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || ! hash_equals( self::value( $user_id, $parts[0] ), $raw ) ) {
			return null;
		}
		$device = AudiencePresenceEnrolment::active_device( $user_id, $parts[0] );
		return is_wp_error( $device ) ? null : $parts[0];
	}
}
