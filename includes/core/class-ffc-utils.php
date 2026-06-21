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
	 * Get submissions table name with current prefix
	 *
	 * Centralizes table name generation for consistency across all classes.
	 * Works correctly with WordPress Multisite (different prefixes per site).
	 *
	 * @since 2.9.16
	 * @return string Full table name with WordPress prefix
	 */
	public static function get_submissions_table(): string {
		global $wpdb;
		// Returns the real table name, WITHOUT calling this function again.
		return $wpdb->prefix . 'ffc_submissions';
	}

	/**
	 * Returns the list of allowed HTML tags and attributes.
	 * Centralized here so Frontend, Email, and PDF Generator use the same validation rules.
	 *
	 * @return array<string, array<string, array<never, never>>> Allowed HTML tags with their attributes
	 */
	public static function get_allowed_html_tags(): array {
		$allowed = array(
			'b'      => array(),
			'strong' => array(),
			'i'      => array(),
			'em'     => array(),
			'u'      => array(),
			'br'     => array(),
			'hr'     => array(
				'style' => array(),
				'class' => array(),
			),
			'p'      => array(
				'style' => array(),
				'class' => array(),
				'align' => array(),
			),
			'span'   => array(
				'style' => array(),
				'class' => array(),
			),
			'div'    => array(
				'style' => array(),
				'class' => array(),
				'id'    => array(),
			),
			'font'   => array(
				'color' => array(),
				'size'  => array(),
				'face'  => array(),
			),
			'img'    => array(
				'src'    => array(),
				'alt'    => array(),
				'style'  => array(),
				'width'  => array(),
				'height' => array(),
			),
			// Table tags (essential for signature alignment).
			'table'  => array(
				'style'       => array(),
				'class'       => array(),
				'width'       => array(),
				'border'      => array(),
				'cellpadding' => array(),
				'cellspacing' => array(),
				'role'        => array(),
			),
			'tr'     => array(
				'style' => array(),
				'class' => array(),
			),
			'td'     => array(
				'style'   => array(),
				'width'   => array(),
				'colspan' => array(),
				'rowspan' => array(),
				'align'   => array(),
				'valign'  => array(),
			),
			'th'     => array(
				'style'   => array(),
				'width'   => array(),
				'colspan' => array(),
				'rowspan' => array(),
				'align'   => array(),
				'valign'  => array(),
			),
			// Headings.
			'h1'     => array(
				'style' => array(),
				'class' => array(),
			),
			'h2'     => array(
				'style' => array(),
				'class' => array(),
			),
			'h3'     => array(
				'style' => array(),
				'class' => array(),
			),
			'h4'     => array(
				'style' => array(),
				'class' => array(),
			),

			// Lists (useful for syllabus content on the back or body).
			'ul'     => array(
				'style' => array(),
				'class' => array(),
			),
			'ol'     => array(
				'style' => array(),
				'class' => array(),
			),
			'li'     => array(
				'style' => array(),
				'class' => array(),
			),
		);

		/**
		 * Allows developers to filter or add new tags
		 * without modifying the plugin core.
		 */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffcertificate is the plugin prefix
		return apply_filters( 'ffcertificate_allowed_html_tags', $allowed );
	}

	/**
	 * Get user IP address with proxy support
	 *
	 * Checks multiple headers to get real IP even behind proxies/CDNs
	 *
	 * Get user ip.
	 *
	 * @return string IP address
	 */
	public static function get_user_ip(): string {
		$ip_keys = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $ip_keys as $key ) {
			if ( array_key_exists( $key, $_SERVER ) ) {
				foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) as $ip ) {
					$ip = trim( $ip );

					// Validate IP (exclude private/reserved ranges).
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
						return $ip;
					}
				}
			}
		}

		return '0.0.0.0';
	}

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

	/**
	 * Generate success HTML response for frontend form submission
	 *
	 * Generate success html.
	 *
	 * Generate success html.
	 *
	 * Generate success html.
	 *
	 * Generate success html.
	 *
	 * Generate success html.
	 *
	 * @since 2.9.16
	 * @param array<string, mixed> $submission_data Submission data.
	 * @param int                  $form_id Form ID.
	 * @param int|string           $submission_date Submission date — unix UTC int (since 6.6.0, reprint flow)
	 *                                              or MySQL `Y-m-d H:i:s` string (fresh submission via `current_time('mysql')`).
	 *                                              DateFormatter::format_datetime accepts both.
	 * @param string               $success_message Success message.
	 * @param int                  $submission_id Submission ID (used to surface the magic link in the success card; 0 to skip).
	 * @param object|null          $submission_handler Handler that knows how to ensure/load the submission's magic token.
	 * @return string HTML content
	 */
	public static function generate_success_html( array $submission_data, int $form_id, int|string $submission_date, string $success_message = '', int $submission_id = 0, ?object $submission_handler = null ): string {
		// Get form configuration.
		$form_config = get_post_meta( $form_id, '_ffc_form_config', true );
		if ( ! is_array( $form_config ) ) {
			$form_config = array();
		}

		// Get form title.
		$form_post  = get_post( $form_id );
		$form_title = $form_post ? $form_post->post_title : __( 'Certificate', 'ffcertificate' );

		// Default success message.
		if ( empty( $success_message ) ) {
			$success_message = isset( $form_config['success_message'] ) && ! empty( $form_config['success_message'] )
				? $form_config['success_message']
				: __( 'Success! Your certificate has been generated.', 'ffcertificate' );
		}

		$date_formatted = \FreeFormCertificate\Core\DateFormatter::format_datetime( $submission_date );

		// Auth code (formatted for display with certificate prefix).
		$auth_code = isset( $submission_data['auth_code'] ) ? DocumentFormatter::format_auth_code( $submission_data['auth_code'], DocumentFormatter::PREFIX_CERTIFICATE ) : '';

		// Magic link — survives the tab close, so the user can come back
		// later from a different device and re-issue the certificate.
		$magic_link = '';
		if ( $submission_id > 0 && $submission_handler && class_exists( '\FreeFormCertificate\Generators\MagicLinkHelper' ) ) {
			$magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::get_submission_magic_link( $submission_id, $submission_handler );
		}

		// Load template.
		ob_start();
		include FFC_PLUGIN_DIR . 'templates/submission-success.php';
		$rendered = ob_get_clean();
		return $rendered ? $rendered : '';
	}
}
