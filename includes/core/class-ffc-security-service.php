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
	 * @return array<string, mixed> Array with 'label', 'hash', and 'answer'
	 */
	public static function generate_simple_captcha(): array {
		list( $n1, $n2, $answer, $operator_symbol ) = self::pick_operation();

		$display1   = \wp_rand( 0, 1 ) ? self::number_to_word( $n1 ) : (string) $n1;
		$display2   = \wp_rand( 0, 1 ) ? self::number_to_word( $n2 ) : (string) $n2;
		$display_op = \wp_rand( 0, 1 ) ? self::operator_to_word( $operator_symbol ) : $operator_symbol;

		$expires = time() + self::CHALLENGE_TTL;

		return array(
			/* translators: 1: first operand (digit or word), 2: operator (symbol or word), 3: second operand (digit or word) */
			'label'  => sprintf( \esc_html__( 'Security: How much is %1$s %2$s %3$s?', 'ffcertificate' ), $display1, $display_op, $display2 ),
			'hash'   => self::issue_token( (string) $answer, $expires ),
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
	 * @param string $answer  Expected answer.
	 * @param int    $expires Unix UTC timestamp the challenge dies at.
	 * @return string Opaque token.
	 */
	private static function issue_token( string $answer, int $expires ): string {
		// The nonce is what makes every issued challenge distinct. Without it
		// two visitors drawing the same answer in the same second would share
		// a token, and the first to submit would burn the other's challenge.
		$nonce = bin2hex( random_bytes( 8 ) );

		return $expires . '.' . $nonce . '.' . Captcha\ChallengeSigner::sign( self::payload( $answer, $expires, $nonce ) );
	}

	/**
	 * Canonical string a challenge signature covers.
	 *
	 * @param string $answer  Expected answer.
	 * @param int    $expires Unix UTC timestamp the challenge dies at.
	 * @param string $nonce   Per-challenge random value.
	 * @return string Canonical payload.
	 */
	private static function payload( string $answer, int $expires, string $nonce ): string {
		return 'math|' . $answer . '|' . $expires . '|' . $nonce;
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
	 * @param string $answer User's answer.
	 * @param string $hash   Token issued with the challenge.
	 * @return bool True if correct, false otherwise
	 */
	public static function verify_simple_captcha( string $answer, string $hash ): bool {
		// Note: '' === trim() handles both empty and whitespace-only, and — unlike empty() —
		// does not reject a valid answer of "0" (which can happen for n - n subtraction).
		$answer = trim( $answer );
		if ( '' === $answer || '' === $hash ) {
			return false;
		}

		$parts = explode( '.', $hash );
		if ( 3 !== count( $parts )
			|| 1 !== preg_match( '/^\d+$/', $parts[0] )
			|| 1 !== preg_match( '/^[0-9a-f]{16}$/', $parts[1] )
		) {
			return false;
		}

		$expires   = (int) $parts[0];
		$nonce     = $parts[1];
		$signature = $parts[2];

		if ( $expires <= time() ) {
			return false;
		}

		if ( ! Captcha\ChallengeSigner::matches( self::payload( $answer, $expires, $nonce ), $signature ) ) {
			return false;
		}

		// Burn the token last: an unauthentic or expired proof must not be
		// able to evict a legitimate one from the ledger.
		return Captcha\ChallengeStore::redeem( $signature, $expires - time() );
	}

	/**
	 * Validate security fields (honeypot + captcha)
	 *
	 * @since 2.10.0
	 * @param array<string, mixed> $data Form data containing security fields.
	 * @return bool|string True if valid, error message string if invalid
	 */
	public static function validate_security_fields( array $data ) {
		// Check honeypot.
		if ( ! empty( $data['ffc_honeypot_trap'] ) ) {
			return \__( 'Security Error: Request blocked (Honeypot).', 'ffcertificate' );
		}

		// The captcha half belongs to whichever strategy is configured; the
		// honeypot above is provider-independent, which is why it stays here.
		return Captcha\CaptchaProvider::resolve()->verify( $data );
	}

	/**
	 * Render the security fields: honeypot plus the active challenge.
	 *
	 * The canonical source of that block. It used to exist twice — once here
	 * (via `Shortcodes`) and once inline in the self-scheduling booking form —
	 * and the copies had already drifted apart.
	 *
	 * @return string Escaped HTML.
	 */
	public static function render_security_fields(): string {
		$ffc_captcha_fields = Captcha\CaptchaProvider::resolve()->render_fields();

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
	 * @return array<string, mixed> Payload with a usable challenge attached.
	 */
	public static function with_fresh_challenge( array $payload ): array {
		if ( ! empty( $payload['refresh_captcha'] ) ) {
			return $payload;
		}

		$payload['refresh_captcha'] = true;

		return array_merge( $payload, Captcha\CaptchaProvider::resolve()->challenge_payload() );
	}
}
