<?php
/**
 * DataSanitizer
 *
 * Data sanitization utility for migrations.
 * Handles cleaning and validating field values during migration.
 *
 * @since 3.1.0 (Extracted from FFC_Migration_Manager v3.1.0 refactor)
 * @version 3.3.0 - Added strict types and type hints for better code safety
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @version 1.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DataSanitizer {

    /**
     * Sanitize a field value based on its configuration
     *
     * @param mixed $value Field value to sanitize
     * @param array $field_config Field configuration with sanitize_callback
     * @return mixed Sanitized value
     */
    public static function sanitize_field_value( $value, array $field_config ) {
        if ( empty( $value ) ) {
            return '';
        }

        // Get sanitize callback from config
        $callback = isset( $field_config['sanitize_callback'] ) ? $field_config['sanitize_callback'] : 'sanitize_text_field';

        // Apply sanitization
        if ( is_callable( $callback ) ) {
            return call_user_func( $callback, $value );
        }

        // Fallback to default sanitization
        return sanitize_text_field( $value );
    }

    /**
     * Clean JSON data for migration
     * Removes empty values and normalizes structure
     *
     * @param string|array $data JSON data or array
     * @return array Cleaned data array
     */
    public static function clean_json_data( $data ): array {
        if ( is_string( $data ) ) {
            $data = json_decode( $data, true );
        }

        if ( ! is_array( $data ) ) {
            return array();
        }

        // Remove empty values
        $data = array_filter( $data, function( $value ) {
            return ! empty( $value ) || $value === '0' || $value === 0;
        });

        return $data;
    }

    /**
     * Extract field value from JSON data using multiple possible keys
     *
     * @param array $data JSON data array
     * @param array $possible_keys Array of possible field names
     * @return mixed Field value or empty string if not found
     */
    public static function extract_field_from_json( array $data, array $possible_keys ) {
        if ( ! is_array( $data ) || ! is_array( $possible_keys ) ) {
            return '';
        }

        foreach ( $possible_keys as $key ) {
            if ( isset( $data[ $key ] ) && ! empty( $data[ $key ] ) ) {
                return $data[ $key ];
            }
        }

        return '';
    }

    /**
     * Validate CPF/RF identifier
     *
     * @param string $identifier CPF or RF number
     * @return bool True if valid format
     */
    public static function is_valid_identifier( string $identifier ): bool {
        if ( empty( $identifier ) ) {
            return false;
        }

        // Remove non-numeric characters
        $clean = preg_replace( '/[^0-9]/', '', $identifier );

        // Check if it's a valid length (CPF: 11 digits, RF: variable but min 6)
        return strlen( $clean ) >= 6 && strlen( $clean ) <= 11;
    }

    /**
     * Validate email format
     *
     * @param string $email Email address
     * @return bool True if valid email
     */
    public static function is_valid_email( string $email ): bool {
        return is_email( $email );
    }

    /**
     * Normalize auth code format
     * Removes spaces, dashes, and converts to uppercase
     *
     * @param string $auth_code Authentication code
     * @return string Normalized code
     */
    public static function normalize_auth_code( string $auth_code ): string {
        if ( empty( $auth_code ) ) {
            return '';
        }

        // Remove spaces and dashes
        $clean = str_replace( array( ' ', '-', '_' ), '', $auth_code );

        // Convert to uppercase
        return strtoupper( $clean );
    }
}
