<?php
/**
 * Audience Presence Keys
 *
 * The site's presence key, which signs device attestations (#2741).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\EcdsaP256;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SigningKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ECDSA P-256 keys that sign presence attestations.
 *
 * Separate from the certificate signing key on purpose: a terminal holds this
 * key's public half, and nothing it holds should verify a certificate, nor
 * should rotating one key disturb the other.
 *
 * One key is active and signs. Rotating retires it without deleting it, so an
 * attestation it signed still verifies until that attestation expires; a key
 * retired longer than an attestation lives can verify nothing any more and is
 * dropped on the next rotation.
 *
 * Storage is one option, `ffc_presence_keys`:
 * `{ active: 'p2', next: 3, keys: { p1: {public, secret, created, retired}, … } }`,
 * the public key base64url of the raw point and the secret the PEM encrypted
 * with {@see Encryption::encrypt()}, falling back to the plain PEM on an
 * install without an encryption key, as {@see SigningKeys} does.
 */
final class AudiencePresenceKeys {

	/**
	 * The option holding the keys.
	 */
	public const OPTION = 'ffc_presence_keys';

	/**
	 * The stored keys, normalized.
	 *
	 * @return array{active: string, next: int, keys: array<string, array{public: string, secret: string, created: int, retired: int}>}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$keys   = array();
		foreach ( (array) ( $stored['keys'] ?? array() ) as $id => $key ) {
			if ( ! is_string( $id ) || ! is_array( $key ) || empty( $key['public'] ) ) {
				continue;
			}
			$keys[ $id ] = array(
				'public'  => (string) $key['public'],
				'secret'  => (string) ( $key['secret'] ?? '' ),
				'created' => (int) ( $key['created'] ?? 0 ),
				'retired' => (int) ( $key['retired'] ?? 0 ),
			);
		}
		$active = isset( $stored['active'] ) && is_string( $stored['active'] ) && isset( $keys[ $stored['active'] ] ) ? $stored['active'] : '';
		return array(
			'active' => $active,
			'next'   => max( 1, (int) ( $stored['next'] ?? count( $keys ) + 1 ) ),
			'keys'   => $keys,
		);
	}

	/**
	 * The active key's id, generating the first key when there is none.
	 *
	 * @return string The id, or '' when no key could be generated.
	 */
	public static function ensure_active(): string {
		$active = self::all()['active'];
		if ( '' !== $active ) {
			return $active;
		}
		return (string) self::rotate( time() );
	}

	/**
	 * Generate a new active key, retiring the current one.
	 *
	 * @param int $now Unix time.
	 * @return string|null The new key's id, or null when openssl failed.
	 */
	public static function rotate( int $now ): ?string {
		$pair = EcdsaP256::generate();
		if ( null === $pair ) {
			return null;
		}
		$state = self::all();
		if ( '' !== $state['active'] ) {
			$state['keys'][ $state['active'] ]['retired'] = $now;
		}
		foreach ( $state['keys'] as $id => $key ) {
			if ( $key['retired'] > 0 && $key['retired'] + AudiencePresenceAttestation::TTL < $now ) {
				unset( $state['keys'][ $id ] );
			}
		}
		$id                   = 'p' . $state['next'];
		$state['keys'][ $id ] = array(
			'public'  => SigningKeys::b64( $pair['public'] ),
			'secret'  => self::store_secret( $pair['secret'] ),
			'created' => $now,
			'retired' => 0,
		);
		$state['active']      = $id;
		$state['next']        = $state['next'] + 1;
		update_option( self::OPTION, $state, false );
		return $id;
	}

	/**
	 * Sign a message with the active key.
	 *
	 * An active key whose secret no longer reads -- encrypted under an
	 * encryption key that was since replaced -- is rotated out rather than
	 * left to refuse every enrolment: an attestation lives a week, so a new
	 * key costs nothing, and the old public half still verifies what it
	 * signed. This is why the key-rotation migration has nothing to do here.
	 *
	 * @param string $message The bytes to sign.
	 * @return array{key: string, signature: string}|null Key id and raw signature, or null when no key can sign.
	 */
	public static function sign( string $message ): ?array {
		$id = self::ensure_active();
		if ( '' === $id ) {
			return null;
		}
		$secret = self::secret( $id );
		if ( null === $secret ) {
			$id     = (string) self::rotate( time() );
			$secret = '' === $id ? null : self::secret( $id );
		}
		$signature = null === $secret ? null : EcdsaP256::sign( $message, $secret );
		return null === $signature ? null : array(
			'key'       => $id,
			'signature' => $signature,
		);
	}

	/**
	 * Whether a raw signature is valid under one of the keys that may still
	 * verify at a given moment.
	 *
	 * @param string $message   The signed bytes.
	 * @param string $signature Raw signature.
	 * @param string $id        Key id.
	 * @param int    $now       Unix time.
	 */
	public static function verify( string $message, string $signature, string $id, int $now ): bool {
		$keys = self::public_keys( $now );
		if ( ! isset( $keys[ $id ] ) ) {
			return false;
		}
		return EcdsaP256::verify( $message, $signature, SigningKeys::unb64( $keys[ $id ] ) );
	}

	/**
	 * The public keys a verifier needs at a given moment, base64url by id:
	 * the active one, and every retired one an unexpired attestation may
	 * still carry.
	 *
	 * @param int $now Unix time.
	 * @return array<string, string>
	 */
	public static function public_keys( int $now ): array {
		$keys = array();
		foreach ( self::all()['keys'] as $id => $key ) {
			if ( 0 === $key['retired'] || $key['retired'] + AudiencePresenceAttestation::TTL >= $now ) {
				$keys[ $id ] = $key['public'];
			}
		}
		return $keys;
	}

	/**
	 * A key's PEM secret.
	 *
	 * @param string $id Key id.
	 */
	private static function secret( string $id ): ?string {
		$keys = self::all()['keys'];
		if ( ! isset( $keys[ $id ] ) || '' === $keys[ $id ]['secret'] ) {
			return null;
		}
		$stored = $keys[ $id ]['secret'];
		$pem    = 0 === strpos( $stored, Encryption::V2_PREFIX ) ? (string) Encryption::decrypt( $stored ) : $stored;
		return false === strpos( $pem, 'PRIVATE KEY' ) ? null : $pem;
	}

	/**
	 * A secret as stored.
	 *
	 * @param string $pem PEM secret.
	 */
	private static function store_secret( string $pem ): string {
		$encrypted = Encryption::encrypt( $pem );
		return null === $encrypted ? $pem : $encrypted;
	}
}
