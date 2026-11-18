<?php
/**
 * The site's certificate signing keys.
 *
 * @package FreeFormCertificate\Core
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ed25519 keys that sign certificate records (#2639).
 *
 * One key is active and signs; earlier ones stay, marked retired, so every
 * certificate they signed still verifies. A retired key is never deleted.
 *
 * Storage is one option, `ffc_signing_keys`:
 * `{ active: 'k2', next: 3, keys: { k1: {public, secret, created, retired}, … } }`.
 * The public key is base64url, the secret is the 64-byte Ed25519 secret key
 * encrypted with {@see Encryption::encrypt()}; on an install without an
 * encryption key it is stored base64url as it is, the same fallback the SMTP
 * password takes, and {@see self::secret_is_encrypted()} reports it.
 *
 * `sodium` ships with PHP and WordPress carries sodium_compat, so signing has
 * no dependency of its own.
 */
final class SigningKeys {

	/**
	 * The option holding the keys.
	 */
	public const OPTION = 'ffc_signing_keys';

	/**
	 * The algorithm, as the settings card names it.
	 */
	public const ALGORITHM = 'Ed25519';

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
	 * The active key's id, or '' when no key was generated.
	 */
	public static function active_id(): string {
		return self::all()['active'];
	}

	/**
	 * Whether a key can sign now.
	 */
	public static function has_active(): bool {
		return '' !== self::active_id();
	}

	/**
	 * Generate the first key, or a new active one, retiring the current.
	 *
	 * @return string|null The new key's id, or null when sodium failed.
	 */
	public static function rotate(): ?string {
		try {
			$pair = sodium_crypto_sign_keypair();
		} catch ( \Throwable $e ) {
			return null;
		}
		$state = self::all();
		$now   = time();
		if ( '' !== $state['active'] ) {
			$state['keys'][ $state['active'] ]['retired'] = $now;
		}
		$id                   = 'k' . $state['next'];
		$state['keys'][ $id ] = array(
			'public'  => self::b64( sodium_crypto_sign_publickey( $pair ) ),
			'secret'  => self::store_secret( sodium_crypto_sign_secretkey( $pair ) ),
			'created' => $now,
			'retired' => 0,
		);
		$state['active']      = $id;
		$state['next']        = $state['next'] + 1;
		update_option( self::OPTION, $state, false );
		return $id;
	}

	/**
	 * A key's public half, raw bytes, or null when the id is unknown.
	 *
	 * @param string $id Key id.
	 */
	public static function public_key( string $id ): ?string {
		$keys = self::all()['keys'];
		if ( ! isset( $keys[ $id ] ) ) {
			return null;
		}
		$raw = self::unb64( $keys[ $id ]['public'] );
		return SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $raw ) ? $raw : null;
	}

	/**
	 * Sign a message with the active key.
	 *
	 * @param string $message The bytes to sign.
	 * @return array{key: string, signature: string}|null Key id and base64url signature, or null when no key can sign.
	 */
	public static function sign( string $message ): ?array {
		$id     = self::active_id();
		$secret = '' === $id ? null : self::secret( $id );
		if ( null === $secret || '' === $secret ) {
			return null;
		}
		try {
			$signature = sodium_crypto_sign_detached( $message, $secret );
		} catch ( \Throwable $e ) {
			return null;
		}
		return array(
			'key'       => $id,
			'signature' => self::b64( $signature ),
		);
	}

	/**
	 * Whether a signature is valid for a message under a key.
	 *
	 * @param string $message   The signed bytes.
	 * @param string $signature Base64url signature.
	 * @param string $id        Key id.
	 */
	public static function verify( string $message, string $signature, string $id ): bool {
		$public = self::public_key( $id );
		$raw    = self::unb64( $signature );
		if ( null === $public || '' === $public || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $raw ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $raw, $message, $public );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * The CPF/RF as the record holds it: an HMAC keyed by the key's secret,
	 * so the record hash the QR code publishes cannot be reversed by trying
	 * every CPF, and does not change when the site's encryption key does.
	 *
	 * @param string $identifier The digits.
	 * @param string $id         Key id.
	 * @return string 64 hex characters, or '' for an empty identifier or an unknown key.
	 */
	public static function identifier_mac( string $identifier, string $id ): string {
		$digits = DataSanitizer::normalize_cpf_rf( $identifier );
		$secret = '' === $digits ? null : self::secret( $id );
		if ( null === $secret ) {
			return '';
		}
		return hash_hmac( 'sha256', $digits, hash( 'sha256', 'ffc-certificate-identifier|' . $secret, true ) );
	}

	/**
	 * A short readable fingerprint of a public key, for the settings card.
	 *
	 * @param string $id Key id.
	 */
	public static function fingerprint( string $id ): string {
		$public = self::public_key( $id );
		return null === $public ? '' : implode( ' ', str_split( substr( hash( 'sha256', $public ), 0, 16 ), 4 ) );
	}

	/**
	 * Whether the active key's secret is stored encrypted.
	 */
	public static function secret_is_encrypted(): bool {
		$state = self::all();
		return '' !== $state['active'] && 0 === strpos( $state['keys'][ $state['active'] ]['secret'], Encryption::V2_PREFIX );
	}

	/**
	 * Every public key by id, base64url, for a verifier.
	 *
	 * @return array<string, string>
	 */
	public static function public_keys(): array {
		return array_map( static fn( array $key ): string => $key['public'], self::all()['keys'] );
	}

	/**
	 * A key's secret, raw bytes.
	 *
	 * @param string $id Key id.
	 */
	private static function secret( string $id ): ?string {
		$keys = self::all()['keys'];
		if ( ! isset( $keys[ $id ] ) || '' === $keys[ $id ]['secret'] ) {
			return null;
		}
		$stored = $keys[ $id ]['secret'];
		$text   = 0 === strpos( $stored, Encryption::V2_PREFIX ) ? (string) Encryption::decrypt( $stored ) : $stored;
		$raw    = self::unb64( $text );
		return SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen( $raw ) ? $raw : null;
	}

	/**
	 * A secret as stored.
	 *
	 * @param string $raw Raw secret.
	 */
	private static function store_secret( string $raw ): string {
		$text      = self::b64( $raw );
		$encrypted = Encryption::encrypt( $text );
		return null === $encrypted ? $text : $encrypted;
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $raw Bytes.
	 */
	public static function b64( string $raw ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- benign: keys and signatures travel as base64url text.
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * Bytes from base64url; '' when it does not decode.
	 *
	 * @param string $text Base64url.
	 */
	public static function unb64( string $text ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- benign: reading back a base64url key or signature.
		$raw = base64_decode( strtr( $text, '-_', '+/' ), true );
		return false === $raw ? '' : $raw;
	}
}
