<?php
/**
 * ECDSA over P-256, in the shapes WebCrypto speaks.
 *
 * @package FreeFormCertificate\Core
 * @since   6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ECDSA P-256 with SHA-256 through openssl (#2741).
 *
 * A browser's WebCrypto creates the presence device keys, and WebCrypto
 * speaks two shapes openssl does not: a public key exported `raw` is the
 * 65-byte uncompressed point (`0x04 || X || Y`), and a signature is the
 * 64-byte `r || s` of IEEE P1363, where openssl writes and reads DER. This
 * class converts at the edge, so every caller deals in the browser's shapes
 * only, and the site's own presence key uses the same curve, which keeps a
 * terminal's verifier to one algorithm.
 *
 * Every method answers `null` or `false` rather than throwing: a malformed
 * key or signature from a request is an ordinary refusal.
 */
final class EcdsaP256 {

	/**
	 * Bytes in a raw public key: 0x04, then X and Y.
	 */
	public const PUBLIC_BYTES = 65;

	/**
	 * Bytes in a raw signature: r and s.
	 */
	public const SIGNATURE_BYTES = 64;

	/**
	 * The DER prefix of a P-256 SubjectPublicKeyInfo, before the 65-byte
	 * point: SEQUENCE { SEQUENCE { id-ecPublicKey, prime256v1 }, BIT STRING }.
	 */
	private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

	/**
	 * Whether the server can do this at all.
	 */
	public static function available(): bool {
		return function_exists( 'openssl_pkey_new' ) && in_array( 'prime256v1', (array) openssl_get_curve_names(), true );
	}

	/**
	 * A new key pair.
	 *
	 * @return array{public: string, secret: string}|null Raw public point and the PEM secret, or null when openssl failed.
	 */
	public static function generate(): ?array {
		if ( ! self::available() ) {
			return null;
		}
		$key = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		if ( false === $key ) {
			return null;
		}
		$pem = '';
		if ( ! openssl_pkey_export( $key, $pem ) ) {
			return null;
		}
		$details = openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) || ! isset( $details['ec']['x'], $details['ec']['y'] ) ) {
			return null;
		}
		return array(
			'public' => "\x04" . self::pad( (string) $details['ec']['x'] ) . self::pad( (string) $details['ec']['y'] ),
			'secret' => (string) $pem,
		);
	}

	/**
	 * Sign a message.
	 *
	 * @param string $message The bytes to sign.
	 * @param string $secret  PEM secret from {@see self::generate()}.
	 * @return string|null The raw 64-byte signature, or null when signing failed.
	 */
	public static function sign( string $message, string $secret ): ?string {
		if ( ! self::available() ) {
			return null;
		}
		$key = openssl_pkey_get_private( $secret );
		if ( false === $key ) {
			return null;
		}
		$der = '';
		if ( ! openssl_sign( $message, $der, $key, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}
		return self::der_to_raw( (string) $der );
	}

	/**
	 * Whether a raw signature is valid for a message under a raw public key.
	 *
	 * @param string $message   The signed bytes.
	 * @param string $signature Raw 64-byte signature.
	 * @param string $point    Raw 65-byte public point.
	 */
	public static function verify( string $message, string $signature, string $point ): bool {
		if ( self::SIGNATURE_BYTES !== strlen( $signature ) ) {
			return false;
		}
		$key = self::public_key( $point );
		if ( null === $key ) {
			return false;
		}
		return 1 === openssl_verify( $message, self::raw_to_der( $signature ), $key, OPENSSL_ALGO_SHA256 );
	}

	/**
	 * Whether bytes are a public point on the curve.
	 *
	 * @param string $point Raw bytes.
	 */
	public static function valid_public( string $point ): bool {
		return null !== self::public_key( $point );
	}

	/**
	 * The openssl key for a raw public point, or null when it is not one.
	 *
	 * Openssl refuses a point that is not on the curve, so this is also the
	 * check {@see self::valid_public()} answers.
	 *
	 * @param string $point Raw bytes.
	 * @return \OpenSSLAsymmetricKey|null
	 */
	private static function public_key( string $point ) {
		if ( ! self::available() || self::PUBLIC_BYTES !== strlen( $point ) || "\x04" !== $point[0] ) {
			return null;
		}
		$der = (string) hex2bin( self::SPKI_PREFIX ) . $point;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- benign: PEM armour around a public key.
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		$key = openssl_pkey_get_public( $pem );
		return false === $key ? null : $key;
	}

	/**
	 * A DER ECDSA signature as raw `r || s`.
	 *
	 * @param string $der DER SEQUENCE { INTEGER r, INTEGER s }.
	 * @return string|null
	 */
	private static function der_to_raw( string $der ): ?string {
		$offset = 0;
		if ( "\x30" !== substr( $der, $offset, 1 ) ) {
			return null;
		}
		$offset += 2;
		if ( ord( $der[1] ) & 0x80 ) {
			$offset += ord( $der[1] ) & 0x7f;
		}
		$parts = array();
		for ( $i = 0; $i < 2; $i++ ) {
			if ( "\x02" !== substr( $der, $offset, 1 ) ) {
				return null;
			}
			$length  = ord( $der[ $offset + 1 ] );
			$value   = substr( $der, $offset + 2, $length );
			$offset += 2 + $length;
			$value   = ltrim( $value, "\x00" );
			if ( strlen( $value ) > 32 ) {
				return null;
			}
			$parts[] = self::pad( $value );
		}
		return $parts[0] . $parts[1];
	}

	/**
	 * A raw `r || s` signature as DER.
	 *
	 * @param string $raw 64 bytes.
	 */
	private static function raw_to_der( string $raw ): string {
		$integers = '';
		foreach ( array( substr( $raw, 0, 32 ), substr( $raw, 32, 32 ) ) as $value ) {
			$value = ltrim( $value, "\x00" );
			if ( '' === $value || ( ord( $value[0] ) & 0x80 ) ) {
				$value = "\x00" . $value;
			}
			$integers .= "\x02" . chr( strlen( $value ) ) . $value;
		}
		return "\x30" . chr( strlen( $integers ) ) . $integers;
	}

	/**
	 * A coordinate or integer left-padded to 32 bytes.
	 *
	 * @param string $value Big-endian bytes.
	 */
	private static function pad( string $value ): string {
		return str_pad( $value, 32, "\x00", STR_PAD_LEFT );
	}
}
