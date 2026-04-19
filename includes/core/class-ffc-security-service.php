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
 * @since 4.12.27
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

		return array(
			/* translators: 1: first operand (digit or word), 2: operator (symbol or word), 3: second operand (digit or word) */
			'label'  => sprintf( \esc_html__( 'Security: How much is %1$s %2$s %3$s?', 'ffcertificate' ), $display1, $display_op, $display2 ),
			'hash'   => \wp_hash( $answer . 'ffc_math_salt' ),
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
	 * Verify simple captcha answer
	 *
	 * @param string $answer User's answer.
	 * @param string $hash Expected hash.
	 * @return bool True if correct, false otherwise
	 */
	public static function verify_simple_captcha( string $answer, string $hash ): bool {
		if ( empty( $answer ) || empty( $hash ) ) {
			return false;
		}

		$check_hash = \wp_hash( trim( $answer ) . 'ffc_math_salt' );
		return $check_hash === $hash;
	}

	/**
	 * Validate security fields (honeypot + captcha)
	 *
	 * @since 2.9.11
	 * @param array<string, mixed> $data Form data containing security fields.
	 * @return bool|string True if valid, error message string if invalid
	 */
	public static function validate_security_fields( array $data ) {
		// Check honeypot.
		if ( ! empty( $data['ffc_honeypot_trap'] ) ) {
			return \__( 'Security Error: Request blocked (Honeypot).', 'ffcertificate' );
		}

		// Check captcha presence.
		if ( ! isset( $data['ffc_captcha_ans'] ) || ! isset( $data['ffc_captcha_hash'] ) ) {
			return \__( 'Error: Please answer the security question.', 'ffcertificate' );
		}

		// Validate captcha answer.
		if ( ! self::verify_simple_captcha( $data['ffc_captcha_ans'], $data['ffc_captcha_hash'] ) ) {
			return \__( 'Error: The math answer is incorrect.', 'ffcertificate' );
		}

		return true;
	}
}
