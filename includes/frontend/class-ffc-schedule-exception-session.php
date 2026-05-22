<?php
/**
 * ScheduleExceptionSession
 *
 * One-use, HMAC-signed session bridge between the operator (who creates
 * a schedule exception in the public CSV-download panel) and the
 * participant (who, on a separate tab, submits the form whose schedule
 * is overridden).
 *
 * Flow, end to end:
 *   1. Operator submits the AJAX endpoint in Sprint 4. Server calls
 *      {@see create()} with `(form_id, start_override, end_override,
 *      operator_cpf_hash)`. That signs a payload with HMAC-SHA256
 *      (key derived from `wp_salt('nonce')`, scoped with a module
 *      string) and sets cookie `ffc_exception_<form_id>` carrying the
 *      signed token. Cookie is HttpOnly, SameSite=Lax, 30 min expiry.
 *      The same token is returned so Sprint 4 can return it to the
 *      modal JS for diagnostic display.
 *   2. Sprint 5 renders the public form. It reads the cookie via
 *      {@see read_from_cookie()}, verifies signature + expiry, and
 *      either embeds the token + override values as hidden inputs OR
 *      bails out silently if the token is missing/tampered/expired.
 *      Immediately after a successful read, the cookie is cleared via
 *      {@see clear()} so the same operator-issued exception cannot be
 *      consumed twice by a refresh.
 *   3. Sprint 6's submission handler re-verifies the hidden-input
 *      token via {@see verify_token()} before persisting the override.
 *
 * Why split cookie storage from token verification:
 *   - The cookie carries the token through the first navigation
 *     (operator → participant tab). One-use semantics demand we
 *     delete the cookie before the participant types anything, so
 *     Sprint 5 reads + clears in one shot.
 *   - The token itself is then carried in the form body, where the
 *     submission handler is the sole verifier. Same crypto, two
 *     transports.
 *
 * @package FreeFormCertificate\Frontend
 * @since 6.7.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cookie + HMAC-signed token plumbing for the operator-driven
 * schedule-exception flow (#366).
 */
class ScheduleExceptionSession {

	/**
	 * Token-format version. Bump if the payload shape changes so old
	 * tokens fail verification cleanly instead of silently mis-parsing.
	 */
	private const TOKEN_VERSION = 1;

	/**
	 * Lifetime of both the cookie and the embedded token, in seconds.
	 * 30 minutes covers a slow operator → participant handover without
	 * leaving a stale exception sitting in the browser for hours.
	 */
	public const TTL_SECONDS = 1800;

	/**
	 * Key-derivation scope. Prevents a token signed for one HMAC use
	 * (schedule exception) from being mistaken for one signed elsewhere
	 * — `wp_salt('nonce')` alone would be cross-feature.
	 */
	private const KEY_SCOPE = '|ffc_schedule_exception_v1';

	/**
	 * Build the per-form cookie name. The form_id suffix lets an
	 * operator carry multiple in-flight exceptions across different
	 * forms without collision.
	 *
	 * @param int $form_id Form post ID.
	 * @return string
	 */
	public static function cookie_name( int $form_id ): string {
		return 'ffc_exception_' . $form_id;
	}

	/**
	 * Create a signed payload, set the cookie, return the token.
	 *
	 * @param int         $form_id              Form post ID.
	 * @param string|null $start_override       TIME string (HH:MM[:SS]) or null
	 *                                          to leave start at baseline.
	 * @param string|null $end_override         TIME string (HH:MM[:SS]) or null
	 *                                          to leave end at baseline.
	 * @param string      $operator_cpf_hash    SHA-256 hex of the operator
	 *                                          CPF (already hashed by caller).
	 * @param string      $operator_cpf_masked  Pre-masked operator CPF in
	 *                                          `***.***.123-45` shape, for
	 *                                          human-readable audit rows
	 *                                          (Sprint 6). Empty when the
	 *                                          caller did not collect a CPF
	 *                                          (e.g. forms with CPF mode off).
	 * @return string The signed token (also the cookie value).
	 */
	public static function create(
		int $form_id,
		?string $start_override,
		?string $end_override,
		string $operator_cpf_hash,
		string $operator_cpf_masked = ''
	): string {
		$payload = array(
			'v'                   => self::TOKEN_VERSION,
			'form_id'             => $form_id,
			'start'               => $start_override,
			'end'                 => $end_override,
			'operator_cpf_hash'   => $operator_cpf_hash,
			'operator_cpf_masked' => $operator_cpf_masked,
			'exp'                 => time() + self::TTL_SECONDS,
			'jti'                 => self::random_jti(),
		);

		$token = self::sign_token( $payload );

		setcookie(
			self::cookie_name( $form_id ),
			$token,
			array(
				'expires'  => $payload['exp'],
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		return $token;
	}

	/**
	 * Read and verify the cookie. Returns the decoded payload on
	 * success, or null when the cookie is missing, tampered, expired,
	 * or scoped to a different form.
	 *
	 * @param int $form_id Form post ID the caller is rendering for.
	 * @return array<string, mixed>|null
	 */
	public static function read_from_cookie( int $form_id ): ?array {
		$name = self::cookie_name( $form_id );
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}

		$raw     = (string) wp_unslash( $_COOKIE[ $name ] );
		$payload = self::verify_token( $raw );
		if ( null === $payload ) {
			return null;
		}

		// Defense-in-depth: a cookie scoped to form A must not be
		// accepted on the render path of form B even if both signatures
		// validate (e.g. an operator session pasted into the wrong tab).
		if ( (int) ( $payload['form_id'] ?? 0 ) !== $form_id ) {
			return null;
		}

		return $payload;
	}

	/**
	 * Clear the per-form cookie. Mirrors the create() call shape so
	 * the browser actually deletes it (empty value + past expiry +
	 * matching path/secure/httponly).
	 *
	 * @param int $form_id Form post ID.
	 * @return void
	 */
	public static function clear( int $form_id ): void {
		setcookie(
			self::cookie_name( $form_id ),
			'',
			array(
				'expires'  => time() - self::TTL_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		unset( $_COOKIE[ self::cookie_name( $form_id ) ] );
	}

	/**
	 * HMAC-sign a payload and return a compact `body.signature` token.
	 * Both halves are base64url-encoded so the result is safe to embed
	 * in URLs, form bodies, and cookie values without escaping.
	 *
	 * @param array<string, mixed> $payload Payload to sign.
	 * @return string
	 */
	public static function sign_token( array $payload ): string {
		$body_json = (string) wp_json_encode( $payload );
		$body_b64  = self::base64url_encode( $body_json );
		$sig       = hash_hmac( 'sha256', $body_b64, self::hmac_key() );
		$sig_b64   = self::base64url_encode( (string) hex2bin( $sig ) );
		return $body_b64 . '.' . $sig_b64;
	}

	/**
	 * Verify a token. Returns the decoded payload on success, null on
	 * any failure (bad shape, bad signature, expired, wrong version).
	 *
	 * @param string $token Token produced by sign_token().
	 * @return array<string, mixed>|null
	 */
	public static function verify_token( string $token ): ?array {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		list( $body_b64, $sig_b64 ) = $parts;

		$expected_sig_hex = hash_hmac( 'sha256', $body_b64, self::hmac_key() );
		$expected_sig_b64 = self::base64url_encode( (string) hex2bin( $expected_sig_hex ) );

		if ( ! hash_equals( $expected_sig_b64, $sig_b64 ) ) {
			return null;
		}

		$body_json = self::base64url_decode( $body_b64 );
		if ( null === $body_json ) {
			return null;
		}
		$payload = json_decode( $body_json, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		if ( ( $payload['v'] ?? 0 ) !== self::TOKEN_VERSION ) {
			return null;
		}
		if ( ( (int) ( $payload['exp'] ?? 0 ) ) <= time() ) {
			return null;
		}

		return $payload;
	}

	/**
	 * HMAC key. `wp_salt('nonce')` rotates with the WP nonce salt and
	 * is consistent across the cluster; the scope suffix prevents
	 * cross-feature replay.
	 */
	private static function hmac_key(): string {
		return wp_salt( 'nonce' ) . self::KEY_SCOPE;
	}

	/**
	 * URL-safe base64 encoder (RFC 4648 §5). No padding, no `+`, no `/`
	 * — keeps the token clean as a cookie value and in URLs.
	 *
	 * @param string $bin Binary (or text) input to encode.
	 * @return string
	 */
	private static function base64url_encode( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- token-format encoding, not obfuscation.
	}

	/**
	 * URL-safe base64 decoder. Returns null on malformed input rather
	 * than `false` so the caller's null-check covers both shape and
	 * decode failures.
	 *
	 * @param string $b64 Base64url-encoded input.
	 * @return string|null
	 */
	private static function base64url_decode( string $b64 ): ?string {
		$pad = strlen( $b64 ) % 4;
		if ( $pad ) {
			$b64 .= str_repeat( '=', 4 - $pad );
		}
		$out = base64_decode( strtr( $b64, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $out ) {
			return null;
		}
		return $out;
	}

	/**
	 * Random JWT-style identifier. Used purely as a uniqueness marker
	 * inside the payload so two exceptions created at the same second
	 * for the same form produce different tokens.
	 */
	private static function random_jti(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			// random_bytes can throw only when the platform CSPRNG is
			// unavailable. Fall back to wp_rand-derived bytes — the JTI
			// is not a security primitive, the signature is.
			return bin2hex( pack( 'NN', wp_rand(), wp_rand() ) );
		}
	}
}
