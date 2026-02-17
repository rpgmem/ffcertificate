<?php
declare(strict_types=1);

/**
 * AuthCodeService
 *
 * Focused service class for authentication code generation,
 * including random string generation and globally unique code generation.
 *
 * Extracted from Utils.php (Sprint 31) for single-responsibility compliance.
 *
 * @since 4.12.27
 * @package FreeFormCertificate\Core
 */

namespace FreeFormCertificate\Core;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class AuthCodeService {

    /**
     * Generate random string
     *
     * @param int $length Length of random string
     * @param string $chars Characters to use (default: alphanumeric)
     * @return string Random string
     */
    public static function generate_random_string( int $length = 12, string $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' ): string {
        $string = '';
        $chars_length = strlen( $chars );

        for ( $i = 0; $i < $length; $i++ ) {
            $string .= $chars[ wp_rand( 0, $chars_length - 1 ) ];
        }

        return $string;
    }

    /**
     * Generate authentication code in format XXXX-XXXX-XXXX
     *
     * @since 3.0.0
     * @return string Auth code (e.g., "A1B2-C3D4-E5F6")
     */
    public static function generate_auth_code(): string {
        return strtoupper(
            self::generate_random_string(4) . '-' .
            self::generate_random_string(4) . '-' .
            self::generate_random_string(4)
        );
    }

    /**
     * Generate a globally unique auth code across all plugin tables.
     *
     * Checks certificates (ffc_submissions), reregistrations
     * (ffc_reregistration_submissions), and appointments
     * (ffc_self_scheduling_appointments) to ensure no cross-table collisions.
     *
     * @since 4.12.0
     * @return string Clean auth code (12 uppercase alphanumeric characters, no hyphens).
     */
    public static function generate_globally_unique_auth_code(): string {
        global $wpdb;

        $max_attempts = 10;

        for ( $i = 0; $i < $max_attempts; $i++ ) {
            $code = DocumentFormatter::clean_auth_code( self::generate_auth_code() );

            // Check ffc_submissions
            $table_subs = $wpdb->prefix . 'ffc_submissions';
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM %i WHERE auth_code = %s LIMIT 1",
                $table_subs,
                $code
            ) );

            if ( $exists ) {
                continue;
            }

            // Check ffc_reregistration_submissions
            $table_rereg = $wpdb->prefix . 'ffc_reregistration_submissions';
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM %i WHERE auth_code = %s LIMIT 1",
                $table_rereg,
                $code
            ) );

            if ( $exists ) {
                continue;
            }

            // Check ffc_self_scheduling_appointments
            $table_apt = $wpdb->prefix . 'ffc_self_scheduling_appointments';
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM %i WHERE validation_code = %s LIMIT 1",
                $table_apt,
                $code
            ) );

            if ( ! $exists ) {
                return $code;
            }
        }

        // Fallback: extremely unlikely to reach here
        return DocumentFormatter::clean_auth_code( self::generate_auth_code() );
    }
}
