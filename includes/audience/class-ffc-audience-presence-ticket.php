<?php
/**
 * Audience Presence Ticket
 *
 * The site's word that a person is enrolled in an event (#2742).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A ticket an "enrolled only" event's terminal checks offline.
 *
 * The app fetches one, while online, for each event the user is enrolled in,
 * and adds it to the presence code. A terminal then knows the person is on
 * the list without ever holding the list. Binary, version 1:
 *
 * | Bytes | Field |
 * | --- | --- |
 * | 1 | version, `0x01` |
 * | 1 + n | site key id, length-prefixed ASCII |
 * | 1 | target type ({@see AudiencePresenceCode::TARGETS}) |
 * | 4 | target id, unsigned big-endian |
 * | 4 | user id, unsigned big-endian |
 * | 4 | expires, Unix time, unsigned big-endian |
 * | 64 | site signature, raw `r || s` |
 *
 * The signature covers {@see self::DOMAIN} followed by every byte before it.
 * It carries no name and no identifier: the user id is what ties it to the
 * attestation beside it in the same code.
 */
final class AudiencePresenceTicket {

	/**
	 * The format version.
	 */
	public const VERSION = 1;

	/**
	 * What the signature covers ahead of the body.
	 */
	public const DOMAIN = "FFC-PRESENCE-TICKET\x00";

	/**
	 * Sign a ticket.
	 *
	 * @param int    $user_id     User.
	 * @param string $target_type Attendance target type.
	 * @param int    $target_id   Target id.
	 * @param int    $expires     Unix time.
	 * @return string|null Raw bytes, or null when the target type has no code or the site key cannot sign.
	 */
	public static function issue( int $user_id, string $target_type, int $target_id, int $expires ): ?string {
		$code = array_search( $target_type, AudiencePresenceCode::TARGETS, true );
		if ( false === $code || $user_id <= 0 || $target_id <= 0 ) {
			return null;
		}
		$site_key = AudiencePresenceKeys::ensure_active();
		if ( '' === $site_key ) {
			return null;
		}
		$body   = self::body( $site_key, (int) $code, $target_id, $user_id, $expires );
		$signed = AudiencePresenceKeys::sign( self::DOMAIN . $body );
		if ( null !== $signed && $signed['key'] !== $site_key ) {
			$body   = self::body( $signed['key'], (int) $code, $target_id, $user_id, $expires );
			$signed = AudiencePresenceKeys::sign( self::DOMAIN . $body );
		}
		return null === $signed ? null : $body . $signed['signature'];
	}

	/**
	 * Read and verify a ticket.
	 *
	 * @param string $raw Raw bytes.
	 * @param int    $now Unix time.
	 * @return array{target_type: string, target_id: int, user_id: int, expires: int}|\WP_Error
	 */
	public static function verify( string $raw, int $now ) {
		$length = strlen( $raw );
		$kid    = $length > 1 ? ord( $raw[1] ) : 0;
		if ( $length !== 2 + $kid + 13 + 64 || self::VERSION !== ord( $raw[0] ) || $kid < 1 || $kid > 16 ) {
			return new \WP_Error( 'ffc_presence_ticket_malformed', __( 'The event ticket in this presence code could not be read.', 'ffcertificate' ) );
		}
		$site_key = (string) substr( $raw, 2, $kid );
		$body     = (string) substr( $raw, 0, $length - 64 );
		$fields   = unpack( 'Ctype/Ntarget/Nuser/Nexpires', (string) substr( $raw, 2 + $kid, 13 ) );
		if ( ! is_array( $fields ) || ! isset( AudiencePresenceCode::TARGETS[ $fields['type'] ] ) ) {
			return new \WP_Error( 'ffc_presence_ticket_malformed', __( 'The event ticket in this presence code could not be read.', 'ffcertificate' ) );
		}
		if ( ! AudiencePresenceKeys::verify( self::DOMAIN . $body, (string) substr( $raw, -64 ), $site_key, $now ) ) {
			return new \WP_Error( 'ffc_presence_ticket_signature', __( 'The event ticket in this presence code was not issued by this site.', 'ffcertificate' ) );
		}
		if ( (int) $fields['expires'] < $now ) {
			return new \WP_Error( 'ffc_presence_ticket_expired', __( 'The event ticket in this presence code has expired.', 'ffcertificate' ) );
		}
		return array(
			'target_type' => AudiencePresenceCode::TARGETS[ $fields['type'] ],
			'target_id'   => (int) $fields['target'],
			'user_id'     => (int) $fields['user'],
			'expires'     => (int) $fields['expires'],
		);
	}

	/**
	 * The signed body.
	 *
	 * @param string $site_key  Site key id.
	 * @param int    $type      Target type code.
	 * @param int    $target_id Target id.
	 * @param int    $user_id   User.
	 * @param int    $expires   Unix time.
	 */
	private static function body( string $site_key, int $type, int $target_id, int $user_id, int $expires ): string {
		return chr( self::VERSION ) . chr( strlen( $site_key ) ) . $site_key . chr( $type ) . pack( 'NNN', $target_id, $user_id, $expires );
	}
}
