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
}
