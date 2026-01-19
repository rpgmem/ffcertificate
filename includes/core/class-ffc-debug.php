<?php
/**
 * FFC_Debug
 * Centralized debug logging with per-area control
 *
 * @package FFC
 * @since 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Debug {

    /**
     * Available debug areas
     */
    const AREA_PDF_GENERATOR = 'debug_pdf_generator';
    const AREA_EMAIL_HANDLER = 'debug_email_handler';
    const AREA_FORM_PROCESSOR = 'debug_form_processor';
    const AREA_ENCRYPTION = 'debug_encryption';
    const AREA_GEOFENCE = 'debug_geofence';
    const AREA_USER_MANAGER = 'debug_user_manager';
    const AREA_REST_API = 'debug_rest_api';
    const AREA_MIGRATIONS = 'debug_migrations';
    const AREA_ACTIVITY_LOG = 'debug_activity_log';

    /**
     * Check if debug is enabled for a specific area
     *
     * @param string $area Debug area constant
     * @return bool True if debug is enabled for this area
     */
    public static function is_enabled( $area ) {
        $settings = get_option( 'ffc_settings', array() );
        return isset( $settings[ $area ] ) && $settings[ $area ] == 1;
    }

    /**
     * Log a debug message if debug is enabled for the area
     *
     * @param string $area Debug area constant
     * @param string $message Message to log
     * @param mixed $data Optional data to include (will be converted to string)
     */
    public static function log( $area, $message, $data = null ) {
        if ( ! self::is_enabled( $area ) ) {
            return;
        }

        $log_message = '[FFC Debug] ' . $message;

        if ( $data !== null ) {
            if ( is_array( $data ) || is_object( $data ) ) {
                $log_message .= ' | Data: ' . print_r( $data, true );
            } else {
                $log_message .= ' | Data: ' . $data;
            }
        }

        error_log( $log_message );
    }

    /**
     * Log for PDF Generator area
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include
     */
    public static function log_pdf( $message, $data = null ) {
        self::log( self::AREA_PDF_GENERATOR, $message, $data );
    }

    /**
     * Log for Email Handler area
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include
     */
    public static function log_email( $message, $data = null ) {
        self::log( self::AREA_EMAIL_HANDLER, $message, $data );
    }

    /**
     * Log for Form Processor area
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include
     */
    public static function log_form( $message, $data = null ) {
        self::log( self::AREA_FORM_PROCESSOR, $message, $data );
    }

    /**
     * Log for Encryption area
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include (NEVER log actual encrypted data)
     */
    public static function log_encryption( $message, $data = null ) {
        self::log( self::AREA_ENCRYPTION, $message, $data );
    }

    /**
     * Log for Geofence area
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include
     */
    public static function log_geofence( $message, $data = null ) {
        self::log( self::AREA_GEOFENCE, $message, $data );
    }

    /**
     * Log for User Manager area
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include
     */
    public static function log_user_manager( $message, $data = null ) {
        self::log( self::AREA_USER_MANAGER, $message, $data );
    }

    /**
     * Log for REST API area
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include
     */
    public static function log_rest_api( $message, $data = null ) {
        self::log( self::AREA_REST_API, $message, $data );
    }

    /**
     * Log for Migrations area
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include
     */
    public static function log_migrations( $message, $data = null ) {
        self::log( self::AREA_MIGRATIONS, $message, $data );
    }

    /**
     * Log for Activity Log area
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include
     */
    public static function log_activity_log( $message, $data = null ) {
        self::log( self::AREA_ACTIVITY_LOG, $message, $data );
    }
}
