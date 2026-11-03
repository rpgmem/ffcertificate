<?php
/**
 * SecurityService
 *
 * Focused service class for captcha generation/verification and
 * honeypot-based security field validation.
 *
 * Extracted from Utils.php (Sprint 31) for single-responsibility compliance.
 *
 * @package FreeFormCertificate\Core
 * @since 5.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service class for security operations.
 */
class SecurityService {

	/**
	 * How long an issued challenge stays valid, in seconds.
	 *
	 * @var int
	 */
	public const CHALLENGE_TTL = 600;

	/**
	 * Generate a math captcha with random operator and mixed display.
	 *
	 * Operands are randomly shown as digits or translatable words, and
	 * the operator alternates between its symbol and a translatable word
	 * (e.g. "5 plus three", "oito - 2", "4 times três"). This makes
	 * automated parsing significantly harder while keeping the challenge
	 * trivial for humans.
	 *
	 * @param string $context What the challenge is issued for; part of the signed payload (#2339).
	 * @return array<string, mixed> Array with 'label', 'hash', and 'answer'
	 */
	public static function generate_simple_captcha( string $context = '' ): array {
		list( $n1, $n2, $answer, $operator_symbol ) = self::pick_operation();

		$display1   = \wp_rand( 0, 1 ) ? self::number_to_word( $n1 ) : (string) $n1;
		$display2   = \wp_rand( 0, 1 ) ? self::number_to_word( $n2 ) : (string) $n2;
		$display_op = \wp_rand( 0, 1 ) ? self::operator_to_word( $operator_symbol ) : $operator_symbol;

		$expires = time() + self::CHALLENGE_TTL;

		return array(
			/* translators: 1: first operand (digit or word), 2: operator (symbol or word), 3: second operand (digit or word) */
			'label'  => sprintf( \esc_html__( 'Security: How much is %1$s %2$s %3$s?', 'ffcertificate' ), $display1, $display_op, $display2 ),
			'hash'   => self::issue_token( (string) $answer, $expires, $context ),
			'answer' => $answer,
		);
	}

	/**
	 * Pick a random operation and return operands + answer.
	 *
	 * Addition: 1-9 + 1-9 (answer 2-18).
	 * Subtraction: n1 >= n2 so the answer is always >= 0.
	 * Multiplication: 2-9 × 2-5 to keep answers easy (max 45).
	 *
	 * @return array{int, int, int, string} [n1, n2, answer, operator_symbol]
	 */
	private static function pick_operation(): array {
		$op = \wp_rand( 0, 2 );

		if ( 0 === $op ) {
			$n1 = \wp_rand( 1, 9 );
			$n2 = \wp_rand( 1, 9 );
			return array( $n1, $n2, $n1 + $n2, '+' );
		}

		if ( 1 === $op ) {
			$n1 = \wp_rand( 2, 9 );
			$n2 = \wp_rand( 1, $n1 );
			return array( $n1, $n2, $n1 - $n2, '-' );
		}

		$n1 = \wp_rand( 2, 9 );
		$n2 = \wp_rand( 2, 5 );
		return array( $n1, $n2, $n1 * $n2, '×' );
	}

	/**
	 * Return a translatable word for a single-digit number.
	 *
	 * @param int $number Number between 1 and 9.
	 * @return string Translated word.
	 */
	private static function number_to_word( int $number ): string {
		$words = array(
			1 => \__( 'one', 'ffcertificate' ),
			2 => \__( 'two', 'ffcertificate' ),
			3 => \__( 'three', 'ffcertificate' ),
			4 => \__( 'four', 'ffcertificate' ),
			5 => \__( 'five', 'ffcertificate' ),
			6 => \__( 'six', 'ffcertificate' ),
			7 => \__( 'seven', 'ffcertificate' ),
			8 => \__( 'eight', 'ffcertificate' ),
			9 => \__( 'nine', 'ffcertificate' ),
		);

		return $words[ $number ] ?? (string) $number;
	}

	/**
	 * Return a translatable word for an arithmetic operator.
	 *
	 * @param string $symbol One of +, -, ×.
	 * @return string Translated word.
	 */
	private static function operator_to_word( string $symbol ): string {
		$words = array(
			'+' => \__( 'plus', 'ffcertificate' ),
			'-' => \__( 'minus', 'ffcertificate' ),
			'×' => \__( 'times', 'ffcertificate' ),
		);

		return $words[ $symbol ] ?? $symbol;
	}

	/**
	 * Build the token handed to the client alongside a challenge.
	 *
	 * Shape: `<expires>.<nonce>.<signature>`. It travels in the single existing
	 * `ffc_captcha_hash` field, so renderers and the JS refresh path need no
	 * change — the expiry rides inside the value instead of in a new input
	 * that four render sites and three scripts would have to learn about.
	 *
	 * The context is signed and not carried: the verifier supplies the one it
	 * expects, so a token issued for another form fails its signature.
	 *
	 * @param string $answer  Expected answer.
	 * @param int    $expires Unix UTC timestamp the challenge dies at.
	 * @param string $context What the challenge is issued for.
	 * @return string Opaque token.
	 */
	private static function issue_token( string $answer, int $expires, string $context ): string {
		// The nonce is what makes every issued challenge distinct. Without it
		// two visitors drawing the same answer in the same second would share
		// a token, and the first to submit would burn the other's challenge.
		$nonce = bin2hex( random_bytes( 8 ) );

		return $expires . '.' . $nonce . '.' . Captcha\ChallengeSigner::sign( self::payload( $answer, $expires, $nonce, $context ) );
	}

	/**
	 * Canonical string a challenge signature covers.
	 *
	 * @param string $answer  Expected answer.
	 * @param int    $expires Unix UTC timestamp the challenge dies at.
	 * @param string $nonce   Per-challenge random value.
	 * @param string $context What the challenge is issued for.
	 * @return string Canonical payload.
	 */
	private static function payload( string $answer, int $expires, string $nonce, string $context ): string {
		return 'math|' . $answer . '|' . $expires . '|' . $nonce . '|' . $context;
	}

	/**
	 * Verify a captcha answer against the token issued with it.
	 *
	 * Before 6.23.0 the token was `wp_hash( $answer . $fixed_salt )`, which
	 * derived from the answer alone: it carried no expiry, was bound to no
	 * request, and was never spent. One captured pair therefore authenticated
	 * every later submission, on any form, indefinitely — and with 46 possible
	 * answers the pair did not even need capturing. Three properties close
	 * that: the token is signed with a site-derived key, it carries an expiry
	 * inside the signed payload, and redeeming it burns it.
	 *
	 * Since #2339 the token is bound to the context it was issued for, so a
	 * challenge solved on one form is refused on another.
	 *
	 * @param string $answer  User's answer.
	 * @param string $hash    Token issued with the challenge.
	 * @param string $context Context the caller expects.
	 * @return bool True if correct, false otherwise
	 */
	public static function verify_simple_captcha( string $answer, string $hash, string $context = '' ): bool {
		$proof = self::authenticate_token( $answer, $hash, $context );
		if ( null === $proof ) {
			// A wrong answer spends the token too (#2339). It used to leave the
			// token live, so one challenge could be tried against every one of
			// its 46 answers. Only a token of the right shape and still in date
			// is burnt, so a forged string writes nothing.
			$token = self::token_parts( $hash );
			if ( null !== $token ) {
				Captcha\ChallengeStore::redeem( $token['signature'], $token['ttl'] );
			}
			return false;
		}

		return Captcha\ChallengeStore::redeem( $proof['signature'], $proof['ttl'] );
	}

	/**
	 * The signature and remaining life of a token of the right shape that is
	 * still in date, without checking the answer.
	 *
	 * @param string $hash Token issued with the challenge.
	 * @return array{signature: string, ttl: int}|null
	 */
	private static function token_parts( string $hash ): ?array {
		$parts = explode( '.', $hash );
		if ( 3 !== count( $parts )
			|| 1 !== preg_match( '/^\d+$/', $parts[0] )
			|| 1 !== preg_match( '/^[0-9a-f]{16}$/', $parts[1] )
			|| 1 !== preg_match( '/^[0-9a-f]{64}$/', $parts[2] )
		) {
			return null;
		}
		$ttl = (int) $parts[0] - time();
		if ( $ttl <= 0 ) {
			return null;
		}
		return array(
			'signature' => $parts[2],
			'ttl'       => $ttl,
		);
	}

	/**
	 * Check a captcha answer without spending the challenge.
	 *
	 * The read-only sibling of {@see verify_simple_captcha()}, for a flow that
	 * validates on one request and acts on a later one. The public CSV
	 * download is the case: its info screen checks the answer, and the
	 * download that follows carries the same token and is what actually
	 * consumes it. Consuming on the check instead would reject, one screen
	 * later, a challenge the visitor had just been told was correct.
	 *
	 * A challenge already in the ledger fails here too — reporting a spent
	 * token as valid would only move the contradiction downstream.
	 *
	 * @since 6.23.0
	 * @param string $answer  User's answer.
	 * @param string $hash    Token issued with the challenge.
	 * @param string $context Context the caller expects.
	 * @return bool True when the answer is correct and the token is unspent.
	 */
	public static function peek_simple_captcha( string $answer, string $hash, string $context = '' ): bool {
		$proof = self::authenticate_token( $answer, $hash, $context );

		return null !== $proof && ! Captcha\ChallengeStore::is_spent( $proof['signature'] );
	}

	/**
	 * Authenticate a token against an answer, without touching the ledger.
	 *
	 * Everything {@see verify_simple_captcha()} and {@see peek_simple_captcha()}
	 * agree on: shape, expiry and signature. Redemption is deliberately left
	 * to the callers, because that is the only thing they differ on.
	 *
	 * @param string $answer  User's answer.
	 * @param string $hash    Token issued with the challenge.
	 * @param string $context Context the caller expects.
	 * @return array{signature: string, ttl: int}|null Null when the token
	 *                                                 does not authenticate.
	 */
	private static function authenticate_token( string $answer, string $hash, string $context ): ?array {
		// Note: '' === trim() handles both empty and whitespace-only, and — unlike empty() —
		// does not reject a valid answer of "0" (which can happen for n - n subtraction).
		$answer = trim( $answer );
		if ( '' === $answer || '' === $hash ) {
			return null;
		}

		$parts = explode( '.', $hash );
		if ( 3 !== count( $parts )
			|| 1 !== preg_match( '/^\d+$/', $parts[0] )
			|| 1 !== preg_match( '/^[0-9a-f]{16}$/', $parts[1] )
		) {
			return null;
		}

		$expires   = (int) $parts[0];
		$nonce     = $parts[1];
		$signature = $parts[2];

		if ( $expires <= time() ) {
			return null;
		}

		if ( ! Captcha\ChallengeSigner::matches( self::payload( $answer, $expires, $nonce, $context ), $signature ) ) {
			return null;
		}

		return array(
			'signature' => $signature,
			'ttl'       => $expires - time(),
		);
	}

	/**
	 * Validate security fields (honeypot + captcha)
	 *
	 * @since 2.10.0
	 * @param array<string, mixed> $data    Form data containing security fields.
	 * @param string               $context What the request acts on ({@see Captcha\CaptchaContext}), never read from `$data`.
	 * @return bool|string True if valid, error message string if invalid
	 */
	public static function validate_security_fields( array $data, string $context ) {
		return self::run_security_gate( $data, $context, true );
	}

	/**
	 * Run the security gate without spending the challenge.
	 *
	 * For the first leg of a multi-request flow: it answers "would this pass?"
	 * so the visitor is told about a wrong answer immediately, while leaving
	 * the challenge for the request that actually performs the action. Use
	 * {@see validate_security_fields()} everywhere else — a single-request
	 * surface that only peeks never spends the challenge at all, which is the
	 * replay hole this gate exists to close.
	 *
	 * @since 6.23.0
	 * @param array<string, mixed> $data    Form data containing security fields.
	 * @param string               $context What the request acts on, never read from `$data`.
	 * @return bool|string True if valid, error message string if invalid.
	 */
	public static function peek_security_fields( array $data, string $context ) {
		return self::run_security_gate( $data, $context, false );
	}

	/**
	 * Shared body of the two security gates.
	 *
	 * @param array<string, mixed> $data    Form data containing security fields.
	 * @param string               $context What the request acts on.
	 * @param bool                 $consume Whether to spend the challenge.
	 * @return bool|string True if valid, error message string if invalid.
	 */
	private static function run_security_gate( array $data, string $context, bool $consume ) {
		// Honeypot: the trap field and the render-time stamp (#2339). Its
		// refusal never says which of the two fired.
		$honeypot = Honeypot::check( $data );
		if ( true !== $honeypot ) {
			return $honeypot;
		}

		// The captcha half belongs to whichever strategy is configured; the
		// honeypot above is provider-independent, which is why it stays here.
		$provider = Captcha\CaptchaProvider::resolve();
		$result   = $consume ? $provider->verify( $data, $context ) : $provider->peek( $data, $context );

		if ( true !== $result ) {
			/**
			 * Fires when a submitted captcha does not verify (#2339), so the
			 * failed attempt counts against the caller's address the way a
			 * submission does.
			 *
			 * @since 6.44.0
			 * @param array<string, mixed> $data Form data the challenge came with.
			 */
			do_action( 'ffcertificate_captcha_failed', $data );
		}

		return $result;
	}

	/**
	 * Render the security fields: honeypot plus the active challenge.
	 *
	 * The canonical source of that block. It used to exist twice — once here
	 * (via `Shortcodes`) and once inline in the self-scheduling booking form —
	 * and the copies had already drifted apart.
	 *
	 * The context travels on the container as `data-ffc-captcha-context`, so
	 * the cached-page refresh can ask for a challenge bound to the same thing.
	 *
	 * @param string $context What the challenge is issued for ({@see Captcha\CaptchaContext}).
	 * @return string Escaped HTML.
	 */
	public static function render_security_fields( string $context ): string {
		$ffc_captcha_context = $context;
		$ffc_captcha_fields  = Captcha\CaptchaProvider::resolve()->render_fields( $context );

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/security-fields.php';
		$html = ob_get_clean();

		return false === $html ? '' : $html;
	}

	/**
	 * Attach a freshly issued challenge to an error payload.
	 *
	 * Since 6.23.0 a redeemed challenge is spent, so any rejection raised
	 * *after* the security-fields gate leaves the client holding a token that
	 * will never verify again. Without a replacement the visitor's next
	 * attempt fails on the captcha rather than on whatever actually rejected
	 * them — a wrong and unactionable message. Every error response on a
	 * surface that gates on the captcha therefore routes through here.
	 *
	 * A payload that already carries a challenge (the security-fields gate
	 * mints its own) is returned untouched.
	 *
	 * @param array<string, mixed> $payload Error payload for wp_send_json_error().
	 * @param string               $context What the replacement is issued for: the surface that rejected.
	 * @return array<string, mixed> Payload with a usable challenge attached.
	 */
	public static function with_fresh_challenge( array $payload, string $context ): array {
		if ( ! empty( $payload['refresh_captcha'] ) ) {
			return $payload;
		}

		$payload['refresh_captcha'] = true;

		return array_merge( $payload, Captcha\CaptchaProvider::resolve()->challenge_payload( $context ) );
	}
}
