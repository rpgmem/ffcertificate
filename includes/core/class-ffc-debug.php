<?php
/**
 * Debug
 * Centralized debug logging with per-area control
 *
 * @package FFC
 * @since 3.1.0
 * @version 3.3.0 - Added strict types and type hints for better code safety
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug.
 */
class Debug {

	/**
	 * Available debug areas.
	 *
	 * @since 3.1.0
	 * @since 6.2.0 Added `AREA_FRONTEND`, `AREA_ADMIN`, `AREA_SELF_SCHEDULING`,
	 *              `AREA_AUDIENCE`, `AREA_QRCODE` to absorb the 76 calls
	 *              that used to live on the legacy `Utils::debug_log()` —
	 *              every call is now toggleable per-area in admin Settings
	 *              instead of firing whenever `WP_DEBUG=true`.
	 */
	const AREA_PDF_GENERATOR   = 'debug_pdf_generator';
	const AREA_EMAIL_HANDLER   = 'debug_email_handler';
	const AREA_FORM_PROCESSOR  = 'debug_form_processor';
	const AREA_ENCRYPTION      = 'debug_encryption';
	const AREA_GEOFENCE        = 'debug_geofence';
	const AREA_USER_MANAGER    = 'debug_user_manager';
	const AREA_REST_API        = 'debug_rest_api';
	const AREA_MIGRATIONS      = 'debug_migrations';
	const AREA_ACTIVITY_LOG    = 'debug_activity_log';
	const AREA_FRONTEND        = 'debug_frontend';
	const AREA_ADMIN           = 'debug_admin';
	const AREA_SELF_SCHEDULING = 'debug_self_scheduling';
	const AREA_AUDIENCE        = 'debug_audience';
	const AREA_QRCODE          = 'debug_qrcode';

	/**
	 * Check if debug is enabled for a specific area
	 *
	 * @param string $area Debug area constant.
	 * @return bool True if debug is enabled for this area
	 */
	public static function is_enabled( string $area ): bool {
		// Defensive: in unit-test contexts where WP isn't fully loaded
		// (e.g. Brain Monkey tests that exercise code paths now reaching
		// `Debug::log_*()` after the 6.2.0 legacy migration), `get_option`
		// may not exist. Fail-closed (debug disabled) rather than fatal —
		// matches the pre-6.2.0 behaviour of `Utils::debug_log()` which
		// short-circuited on a missing `WP_DEBUG` constant too.
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$settings = get_option( 'ffc_settings', array() );
		return isset( $settings[ $area ] ) && 1 === $settings[ $area ];
	}

	/**
	 * Log a debug message if debug is enabled for the area
	 *
	 * @param string $area Debug area constant.
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include (will be converted to string).
	 * @return void
	 */
	public static function log( string $area, string $message, $data = null ): void {
		if ( ! self::is_enabled( $area ) ) {
			return;
		}

		$log_message = '[FFC Debug] ' . $message;

		if ( null !== $data ) {
			if ( is_array( $data ) || is_object( $data ) ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				$log_message .= ' | Data: ' . print_r( $data, true );
			} else {
				$log_message .= ' | Data: ' . $data;
			}
		}

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		error_log( $log_message );
	}

	/**
	 * Log for PDF Generator area
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_pdf( string $message, $data = null ): void {
		self::log( self::AREA_PDF_GENERATOR, $message, $data );
	}

	/**
	 * Log for Email Handler area
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_email( string $message, $data = null ): void {
		self::log( self::AREA_EMAIL_HANDLER, $message, $data );
	}

	/**
	 * Log for Form Processor area
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_form( string $message, $data = null ): void {
		self::log( self::AREA_FORM_PROCESSOR, $message, $data );
	}

	/**
	 * Log for Encryption area
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include (NEVER log actual encrypted data).
	 * @return void
	 */
	public static function log_encryption( string $message, $data = null ): void {
		self::log( self::AREA_ENCRYPTION, $message, $data );
	}

	/**
	 * Log for Geofence area
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_geofence( string $message, $data = null ): void {
		self::log( self::AREA_GEOFENCE, $message, $data );
	}

	/**
	 * Log for User Manager area
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_user_manager( string $message, $data = null ): void {
		self::log( self::AREA_USER_MANAGER, $message, $data );
	}

	/**
	 * Log for REST API area
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_rest_api( string $message, $data = null ): void {
		self::log( self::AREA_REST_API, $message, $data );
	}

	/**
	 * Log for Migrations area
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_migrations( string $message, $data = null ): void {
		self::log( self::AREA_MIGRATIONS, $message, $data );
	}

	/**
	 * Log for Activity Log area
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_activity_log( string $message, $data = null ): void {
		self::log( self::AREA_ACTIVITY_LOG, $message, $data );
	}

	/**
	 * Log for Frontend area (shortcodes, public pages).
	 *
	 * @since 6.2.0
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_frontend( string $message, $data = null ): void {
		self::log( self::AREA_FRONTEND, $message, $data );
	}

	/**
	 * Log for Admin area (admin pages, CPT handlers, submission edits).
	 *
	 * @since 6.2.0
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_admin( string $message, $data = null ): void {
		self::log( self::AREA_ADMIN, $message, $data );
	}

	/**
	 * Log for Self-Scheduling module.
	 *
	 * @since 6.2.0
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_self_scheduling( string $message, $data = null ): void {
		self::log( self::AREA_SELF_SCHEDULING, $message, $data );
	}

	/**
	 * Log for Audience module.
	 *
	 * @since 6.2.0
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_audience( string $message, $data = null ): void {
		self::log( self::AREA_AUDIENCE, $message, $data );
	}

	/**
	 * Log for QR Code generator.
	 *
	 * @since 6.2.0
	 * @param string $message Message to log.
	 * @param mixed  $data Optional data to include.
	 * @return void
	 */
	public static function log_qrcode( string $message, $data = null ): void {
		self::log( self::AREA_QRCODE, $message, $data );
	}
}
