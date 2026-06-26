<?php
/**
 * Utils
 * Utility class shared between Frontend and Admin.
 *
 * V3.3.0: Added strict types and type hints for better code safety
 * v3.2.0: Migrated to namespace (Phase 2) + Added mask_email() for privacy masking
 * v2.9.1: Added CPF validation, document formatting, and helper functions
 * v2.9.11: Added validate_security_fields() and recursive_sanitize()
 *
 * @package FreeFormCertificate\Core
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utils.
 */
class Utils {

	/**
	 * Convert bytes to human-readable format
	 *
	 * @param int $bytes Number of bytes.
	 * @param int $precision Decimal precision.
	 * @return string Formatted size (e.g., "1.5 MB")
	 */
	public static function format_bytes( int $bytes, int $precision = 2 ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$bytes /= pow( 1024, $pow );

		return round( $bytes, $precision ) . ' ' . $units[ (int) $pow ];
	}

	/**
	 * Truncate string to specific length
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * Truncate.
	 *
	 * @param string $text Text to truncate.
	 * @param int    $length Maximum length.
	 * @param string $suffix Suffix to add (default: '...').
	 * @return string Truncated text
	 */
	public static function truncate( string $text, int $length = 100, string $suffix = '...' ): string {
		if ( strlen( $text ) <= $length ) {
			return $text;
		}

		return substr( $text, 0, $length - strlen( $suffix ) ) . $suffix;
	}

	/**
	 * Return the day-of-week (0=Sunday..6=Saturday) for a given timestamp,
	 * defaulting to the current time. Uses UTC (`gmdate`) for consistency
	 * with the rest of the scheduling pipeline.
	 *
	 * @since 6.6.1
	 * @param int|null $timestamp Unix timestamp; `null` means current time.
	 * @return int 0..6
	 */
	public static function get_day_of_week_number( ?int $timestamp = null ): int {
		return (int) gmdate( 'w', $timestamp ?? time() );
	}

	/**
	 * Build a stable username slug from a free-form value (typically a name
	 * or an email local-part). Strips accents, lowercases, drops invalid
	 * characters, collapses repeated separators to a single `.`, and trims
	 * leading/trailing dots.
	 *
	 * @since 6.6.1
	 * @param string $value Free-form input.
	 * @return string Slug suitable for a WP `user_login`. May be empty.
	 */
	public static function sanitize_username_slug( string $value ): string {
		$slug = sanitize_user( remove_accents( $value ), true );
		$slug = preg_replace( '/[^a-z0-9._-]/', '', $slug ) ?? '';
		$slug = preg_replace( '/[-_.]+/', '.', $slug ) ?? '';
		return trim( $slug, '.' );
	}
}
