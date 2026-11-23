<?php
/**
 * Audience Presence Attestation
 *
 * What binds a device key to a person, signed by the site (#2741).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\EcdsaP256;
use FreeFormCertificate\Core\SigningKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The attestation a phone carries in its presence QR code.
 *
 * Binary, so the QR stays small, then base64url. Version 1:
 *
 * | Bytes | Field |
 * | --- | --- |
 * | 1 | version, `0x01` |
 * | 1 + n | site key id, length-prefixed ASCII |
 * | 4 | user id, unsigned big-endian |
 * | 65 | device public key, the raw P-256 point |
 * | 4 | issued, Unix time, unsigned big-endian |
 * | 4 | expires, Unix time, unsigned big-endian |
 * | 1 + n | short display name, length-prefixed UTF-8, at most 48 bytes |
 * | 64 | site signature, raw `r || s` |
 *
 * The signature covers {@see self::DOMAIN} followed by every byte before it,
 * so a signature made by the presence key for anything else can never pass
 * as an attestation.
 *
 * It carries the device's public key, not only its id, because a terminal
 * verifies the reading offline and holds nothing but the site's public key
 * and a revocation list. The short display name is the only personal data in
 * it; a CPF or RF never is.
 */
final class AudiencePresenceAttestation {

	/**
	 * The format version.
	 */
	public const VERSION = 1;

	/**
	 * How long an attestation is valid, in seconds: 7 days.
	 */
	public const TTL = 604800;

	/**
	 * The longest display name, in bytes.
	 */
	public const NAME_BYTES = 48;

	/**
	 * What the signature covers ahead of the body.
	 */
	public const DOMAIN = "FFC-PRESENCE-ATTESTATION\x00";

	/**
	 * A device key's id: the first 8 bytes of its SHA-256, base64url.
	 *
	 * The id a revocation list carries. It names a key, never a person.
	 *
	 * @param string $point Raw public point.
	 * @return string 11 characters.
	 */
	public static function key_id( string $point ): string {
		return SigningKeys::b64( substr( hash( 'sha256', $point, true ), 0, 8 ) );
	}

	/**
	 * The short name a terminal shows: the first word of the display name and
	 * the initial of its last word.
	 *
	 * "Maria da Silva" reads "Maria S.". The display name is what WordPress
	 * shows of the account everywhere else, so the terminal says nothing the
	 * site does not already say.
	 *
	 * @param \WP_User $user User.
	 * @return string At most {@see self::NAME_BYTES} bytes.
	 */
	public static function display_name( \WP_User $user ): string {
		$words   = preg_split( '/\s+/u', trim( (string) $user->display_name ) );
		$words   = is_array( $words ) ? array_values( array_filter( $words, static fn( string $word ): bool => '' !== $word ) ) : array();
		$first   = (string) ( $words[0] ?? '' );
		$last    = count( $words ) > 1 ? (string) end( $words ) : '';
		$initial = '' === $last ? '' : ' ' . mb_strtoupper( mb_substr( $last, 0, 1 ) ) . '.';
		return mb_strcut( trim( $first . $initial ), 0, self::NAME_BYTES, 'UTF-8' );
	}

	/**
	 * Sign an attestation.
	 *
	 * @param int    $user_id User.
	 * @param string $name    Short display name.
	 * @param string $point  Raw device public point.
	 * @param int    $now     Unix time.
	 * @return array{attestation: string, key_id: string, expires: int}|null Null when the key or the site key is unusable.
	 */
	public static function issue( int $user_id, string $name, string $point, int $now ): ?array {
		if ( $user_id <= 0 || ! EcdsaP256::valid_public( $point ) ) {
			return null;
		}
		$expires = $now + self::TTL;
		$name    = mb_strcut( $name, 0, self::NAME_BYTES, 'UTF-8' );

		// The site key id is known only once the key exists; sign() creates it.
		$site_key = AudiencePresenceKeys::ensure_active();
		if ( '' === $site_key ) {
			return null;
		}
		$body   = self::body( $site_key, $user_id, $point, $now, $expires, $name );
		$signed = AudiencePresenceKeys::sign( self::DOMAIN . $body );
		if ( null === $signed ) {
			return null;
		}
		if ( $signed['key'] !== $site_key ) {
			// The active key was rotated out while signing; sign again under the new id.
			$body   = self::body( $signed['key'], $user_id, $point, $now, $expires, $name );
			$signed = AudiencePresenceKeys::sign( self::DOMAIN . $body );
			if ( null === $signed ) {
				return null;
			}
		}

		return array(
			'attestation' => SigningKeys::b64( $body . $signed['signature'] ),
			'key_id'      => self::key_id( $point ),
			'expires'     => $expires,
		);
	}

	/**
	 * Read and verify an attestation.
	 *
	 * @param string $text Base64url attestation.
	 * @param int    $now  Unix time.
	 * @return array{site_key: string, user_id: int, public_key: string, key_id: string, issued: int, expires: int, name: string}|\WP_Error
	 */
	public static function verify( string $text, int $now ) {
		return self::verify_raw( SigningKeys::unb64( $text ), $now );
	}

	/**
	 * Read and verify an attestation from its raw bytes, as the presence code
	 * carries it (#2742).
	 *
	 * @param string $raw Bytes.
	 * @param int    $now Unix time.
	 * @return array{site_key: string, user_id: int, public_key: string, key_id: string, issued: int, expires: int, name: string}|\WP_Error
	 */
	public static function verify_raw( string $raw, int $now ) {
		$parsed = self::parse( $raw );
		if ( null === $parsed ) {
			return new \WP_Error( 'ffc_presence_attestation_malformed', __( 'This presence code could not be read.', 'ffcertificate' ) );
		}
		if ( ! AudiencePresenceKeys::verify( self::DOMAIN . $parsed['body'], $parsed['signature'], $parsed['site_key'], $now ) ) {
			return new \WP_Error( 'ffc_presence_attestation_signature', __( 'This presence code was not issued by this site.', 'ffcertificate' ) );
		}
		if ( $parsed['expires'] < $now ) {
			return new \WP_Error( 'ffc_presence_attestation_expired', __( 'This presence code has expired. Open the app while online to renew it.', 'ffcertificate' ) );
		}
		unset( $parsed['body'], $parsed['signature'] );
		return $parsed;
	}

	/**
	 * The signed body.
	 *
	 * @param string $site_key Site key id.
	 * @param int    $user_id  User.
	 * @param string $point   Raw device public point.
	 * @param int    $issued   Unix time.
	 * @param int    $expires  Unix time.
	 * @param string $name     Short display name.
	 */
	private static function body( string $site_key, int $user_id, string $point, int $issued, int $expires, string $name ): string {
		return chr( self::VERSION )
			. chr( strlen( $site_key ) ) . $site_key
			. pack( 'N', $user_id )
			. $point
			. pack( 'N', $issued )
			. pack( 'N', $expires )
			. chr( strlen( $name ) ) . $name;
	}

	/**
	 * Split raw bytes into fields, or null when they are not a version 1
	 * attestation.
	 *
	 * @param string $raw Bytes.
	 * @return array{site_key: string, user_id: int, public_key: string, key_id: string, issued: int, expires: int, name: string, body: string, signature: string}|null
	 */
	private static function parse( string $raw ): ?array {
		$length = strlen( $raw );
		if ( $length < 2 || self::VERSION !== ord( $raw[0] ) ) {
			return null;
		}
		$offset   = 1;
		$kid_len  = ord( $raw[ $offset ] );
		$site_key = (string) substr( $raw, $offset + 1, $kid_len );
		$offset  += 1 + $kid_len;
		if ( $kid_len < 1 || $kid_len > 16 || $length < $offset + 4 + EcdsaP256::PUBLIC_BYTES + 8 + 1 ) {
			return null;
		}
		$user     = unpack( 'N', (string) substr( $raw, $offset, 4 ) );
		$offset  += 4;
		$point    = (string) substr( $raw, $offset, EcdsaP256::PUBLIC_BYTES );
		$offset  += EcdsaP256::PUBLIC_BYTES;
		$times    = unpack( 'Nissued/Nexpires', (string) substr( $raw, $offset, 8 ) );
		$offset  += 8;
		$name_len = ord( $raw[ $offset ] );
		$name     = (string) substr( $raw, $offset + 1, $name_len );
		$offset  += 1 + $name_len;
		if ( $name_len > self::NAME_BYTES || $length !== $offset + EcdsaP256::SIGNATURE_BYTES || ! is_array( $user ) || ! is_array( $times ) ) {
			return null;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $site_key ) || ! mb_check_encoding( $name, 'UTF-8' ) ) {
			return null;
		}

		return array(
			'site_key'   => $site_key,
			'user_id'    => (int) $user[1],
			'public_key' => $point,
			'key_id'     => self::key_id( $point ),
			'issued'     => (int) $times['issued'],
			'expires'    => (int) $times['expires'],
			'name'       => $name,
			'body'       => (string) substr( $raw, 0, $offset ),
			'signature'  => (string) substr( $raw, $offset ),
		);
	}
}
