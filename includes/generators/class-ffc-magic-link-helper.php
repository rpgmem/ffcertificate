<?php
/**
 * MagicLinkHelper
 *
 * Centralizes magic link generation and validation logic
 *
 * @package FreeFormCertificate\Generators
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 * @since 2.10.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper utilities for magic link.
 */
class MagicLinkHelper {

	/**
	 * Get verification page URL
	 *
	 * @return string URL of verification page
	 */
	public static function get_verification_page_url(): string {
		// Try to get stored page ID.
		$page_id = get_option( 'ffc_verification_page_id', 0 );

		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return trailingslashit( $url );
			}
		}

		// Fallback to /valid/.
		return home_url( '/valid/' );
	}

	/**
	 * Generate magic link from token
	 *
	 * Centralizes the logic of building magic links throughout the plugin
	 *
	 * @param string $token Magic token (32 hex characters).
	 * @return string Complete magic link URL
	 */
	public static function generate_magic_link( string $token ): string {
		if ( empty( $token ) ) {
			return '';
		}

		// Get base URL.
		$base_url = self::get_verification_page_url();

		// Build magic link with hash format.
		// Format: /valid/#token=abc123.
		return $base_url . '#token=' . $token;
	}

	/**
	 * Ensure submission has magic token
	 *
	 * Asks the handler for the submission's stored token, creating one there
	 * when it is missing.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param object $handler Submission handler instance.
	 * @return string Magic token (32 hex characters), or '' when the handler could not provide a stored one.
	 */
	public static function ensure_token( int $submission_id, object $handler ): string {
		if ( ! method_exists( $handler, 'ensure_magic_token' ) ) {
			return '';
		}

		$token = (string) $handler->ensure_magic_token( $submission_id );

		// Only a token the handler stored can open the verification page, so
		// an invalid answer (a missing submission, a failed save) yields no
		// token rather than a fresh one nothing stores (#2187).
		return self::is_valid_token( $token ) ? $token : '';
	}

	/**
	 * Get magic link for submission
	 *
	 * Combines ensure_token + generate_magic_link
	 *
	 * @param int    $submission_id Submission ID.
	 * @param object $handler Submission handler instance.
	 * @return string Complete magic link URL, or '' when no stored token exists.
	 */
	public static function get_submission_magic_link( int $submission_id, object $handler ): string {
		$token = self::ensure_token( $submission_id, $handler );

		if ( empty( $token ) ) {
			return '';
		}

		return self::generate_magic_link( $token );
	}

	/**
	 * Validate magic token format
	 *
	 * @param string $token Token to validate.
	 * @return bool True if valid format
	 */
	public static function is_valid_token( string $token ): bool {
		// Accept both 32-char (certificate magic_token) and 64-char (appointment confirmation_token).
		return ! empty( $token ) && preg_match( '/^[a-f0-9]{32}([a-f0-9]{32})?$/i', $token );
	}

	/**
	 * Generate QR code URL for magic link
	 *
	 * @param string $token Magic token.
	 * @param int    $size QR code size (default: 200).
	 * @return string QR code image URL
	 */
	public static function get_magic_link_qr_code( string $token, int $size = 200 ): string {
		if ( empty( $token ) || ! self::is_valid_token( $token ) ) {
			return '';
		}

		$magic_link = self::generate_magic_link( $token );

		// Use the local QR code generator to avoid leaking magic tokens to an external service.
		if ( class_exists( '\FreeFormCertificate\Generators\QRCodeGenerator' ) ) {
			try {
				$qr = new \FreeFormCertificate\Generators\QRCodeGenerator();
				return $qr->generate( $magic_link, array( 'size' => $size ) );
			} catch ( \Throwable $e ) {
				return '';
			}
		}

		return '';
	}
}
