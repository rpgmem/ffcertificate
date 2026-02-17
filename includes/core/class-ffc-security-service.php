<?php
declare(strict_types=1);

/**
 * SecurityService
 *
 * Focused service class for captcha generation/verification and
 * honeypot-based security field validation.
 *
 * Extracted from Utils.php (Sprint 31) for single-responsibility compliance.
 *
 * @since 4.12.27
 * @package FreeFormCertificate\Core
 */

namespace FreeFormCertificate\Core;

if (!defined('ABSPATH')) exit;

class SecurityService {

    /**
     * Generate simple math captcha
     *
     * @return array<string, mixed> Array with 'label', 'hash', and 'answer'
     */
    public static function generate_simple_captcha(): array {
        $n1 = wp_rand( 1, 9 );
        $n2 = wp_rand( 1, 9 );
        $answer = $n1 + $n2;

        return array(
            /* translators: 1: first number, 2: second number */
            'label' => sprintf( esc_html__( 'Security: How much is %1$d + %2$d?', 'ffcertificate' ), $n1, $n2 ),
            'hash'  => wp_hash( $answer . 'ffc_math_salt' ),
            'answer' => $answer  // For internal use only
        );
    }

    /**
     * Verify simple captcha answer
     *
     * @param string $answer User's answer
     * @param string $hash Expected hash
     * @return bool True if correct, false otherwise
     */
    public static function verify_simple_captcha( string $answer, string $hash ): bool {
        if ( empty( $answer ) || empty( $hash ) ) {
            return false;
        }

        $check_hash = wp_hash( trim( $answer ) . 'ffc_math_salt' );
        return $check_hash === $hash;
    }

    /**
     * Validate security fields (honeypot + captcha)
     *
     * @since 2.9.11
     * @param array<string, mixed> $data Form data containing security fields
     * @return bool|string True if valid, error message string if invalid
     */
    public static function validate_security_fields( array $data ) {
        // Check honeypot
        if ( ! empty( $data['ffc_honeypot_trap'] ) ) {
            return __( 'Security Error: Request blocked (Honeypot).', 'ffcertificate' );
        }

        // Check captcha presence
        if ( ! isset( $data['ffc_captcha_ans'] ) || ! isset( $data['ffc_captcha_hash'] ) ) {
            return __( 'Error: Please answer the security question.', 'ffcertificate' );
        }

        // Validate captcha answer
        if ( ! self::verify_simple_captcha( $data['ffc_captcha_ans'], $data['ffc_captcha_hash'] ) ) {
            return __( 'Error: The math answer is incorrect.', 'ffcertificate' );
        }

        return true;
    }
}
