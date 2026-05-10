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
 *
 * Logging contract: scalar payloads are written verbatim, but array /
 * object payloads pass through `redact_sensitive_data()` first so that
 * known PII and credential keys (email, cpf, rf, cpf_rf, phone, token,
 * magic_token, auth_code, password, recipient address) are masked
 * before reaching `error_log`. URL strings containing those values as
 * query parameters are stripped of the secret. This means callers do
 * not need to redact at the call site — the central guarantee holds
 * even when new callers land. Add new sensitive keys to
 * `SENSITIVE_KEYS` rather than redacting locally.
 */
class Debug {

	/**
	 * Map of array keys whose values must be masked before logging.
	 * Matched case-insensitively against the array key.
	 *
	 * Keep this list synchronised with the data shapes the plugin
	 * passes to `log_*()`. Adding a key here retroactively protects
	 * every existing caller.
	 */
	private const SENSITIVE_KEYS = array(
		'email',
		'recipient',
		'to',
		'cpf',
		'rf',
		'cpf_rf',
		'phone',
		'telefone',
		'password',
		'pass',
		'senha',
		'auth_code',
		'magic_token',
		'token',
		'confirmation_token',
		'api_key',
		'secret',
	);

	/**
	 * Query parameters whose values are stripped from logged URLs.
	 */
	private const SENSITIVE_URL_PARAMS = array(
		'magic_token',
		'auth_code',
		'token',
		'password',
		'api_key',
	);

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
				$redacted = self::redact_sensitive_data( $data );
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				$log_message .= ' | Data: ' . print_r( $redacted, true );
			} else {
				$log_message .= ' | Data: ' . $data;
			}
		}

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		error_log( $log_message );
	}

	/**
	 * Walk an array/object payload and mask values whose key matches
	 * `SENSITIVE_KEYS`, or whose value is a URL carrying one of the
	 * `SENSITIVE_URL_PARAMS`. Recurses through nested arrays.
	 *
	 * Masking preserves a hint of the value's shape (length + 2 leading
	 * + 2 trailing characters) so logs remain useful for "did the field
	 * get populated at all?" debugging without leaking the contents.
	 *
	 * @param mixed $data  Arbitrary scalar / array / object payload.
	 * @param int   $depth Recursion guard — stops at 6 levels.
	 * @return mixed The same shape, with sensitive values masked.
	 */
	private static function redact_sensitive_data( $data, int $depth = 0 ) {
		if ( $depth > 6 ) {
			return '[deep]';
		}

		if ( is_object( $data ) ) {
			$data = (array) $data;
		}

		if ( ! is_array( $data ) ) {
			return $data;
		}

		$out = array();
		foreach ( $data as $key => $value ) {
			$key_str = strtolower( (string) $key );
			if ( is_string( $value ) && in_array( $key_str, self::SENSITIVE_KEYS, true ) ) {
				$out[ $key ] = self::mask_value( $value );
			} elseif ( is_string( $value ) && self::url_carries_secret( $value ) ) {
				$out[ $key ] = self::strip_secret_query( $value );
			} elseif ( is_array( $value ) || is_object( $value ) ) {
				$out[ $key ] = self::redact_sensitive_data( $value, $depth + 1 );
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Replace the inner characters of a string with asterisks while
	 * preserving the first 2, last 2, and the original length. Returns
	 * `***` for short strings whose shape would otherwise reveal too
	 * much.
	 */
	private static function mask_value( string $value ): string {
		$len = strlen( $value );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return substr( $value, 0, 2 ) . str_repeat( '*', max( 3, $len - 4 ) ) . substr( $value, -2 ) . " (len:{$len})";
	}

	/**
	 * Cheap sniff for "this string looks like a URL with one of our
	 * sensitive query parameters". Avoids invoking `parse_url` for
	 * every scalar in the log payload.
	 */
	private static function url_carries_secret( string $value ): bool {
		if ( strncasecmp( $value, 'http', 4 ) !== 0 ) {
			return false;
		}
		foreach ( self::SENSITIVE_URL_PARAMS as $param ) {
			if ( false !== stripos( $value, $param . '=' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace the value of any sensitive query parameter in `$url`
	 * with `[redacted]`. Works on full URLs and bare query strings.
	 */
	private static function strip_secret_query( string $url ): string {
		$pattern = '/([?&])(' . implode( '|', array_map( 'preg_quote', self::SENSITIVE_URL_PARAMS ) ) . ')=[^&#]*/i';
		$result  = preg_replace( $pattern, '$1$2=[redacted]', $url );
		return is_string( $result ) ? $result : $url;
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
