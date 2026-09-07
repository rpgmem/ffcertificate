<?php
/**
 * ChallengeSigner
 *
 * Keyed signing for captcha challenges. Both the math challenge and any
 * future proof-of-work provider derive their signature from here, so the
 * key derivation lives in exactly one place.
 *
 * The key is derived from `wp_salt( 'nonce' )` rather than stored in an
 * option: there is nothing to create on activation, nothing to declare in
 * `uninstall.php`, and nothing for the fresh-install manifest gate to
 * reconcile. Rotating the site salts invalidates challenges already in
 * flight, which is harmless at the ten-minute lifetime they carry.
 *
 * @package FreeFormCertificate\Core\Captcha
 * @since 6.23.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core\Captcha;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HMAC signer for captcha challenges.
 */
class ChallengeSigner {

	/**
	 * Domain-separation context mixed into the derived key.
	 *
	 * Bump the suffix to invalidate every outstanding challenge at once.
	 *
	 * @var string
	 */
	private const CONTEXT = 'ffc_captcha_v1';

	/**
	 * Sign a challenge payload.
	 *
	 * @param string $payload Canonical payload string.
	 * @return string Lowercase hex SHA-256 HMAC.
	 */
	public static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, self::secret() );
	}

	/**
	 * Verify a signature against a payload in constant time.
	 *
	 * @param string $payload   Canonical payload string.
	 * @param string $signature Signature received from the client.
	 * @return bool True when the signature is authentic.
	 */
	public static function matches( string $payload, string $signature ): bool {
		if ( '' === $signature ) {
			return false;
		}

		return hash_equals( self::sign( $payload ), $signature );
	}

	/**
	 * Derive the signing key from the site's nonce salt.
	 *
	 * @return string Lowercase hex SHA-256 HMAC.
	 */
	private static function secret(): string {
		return hash_hmac( 'sha256', self::CONTEXT, \wp_salt( 'nonce' ) );
	}
}
