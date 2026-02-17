<?php
declare(strict_types=1);

/**
 * ReprintDetector
 *
 * Extracted from FormProcessor (Sprint 16 refactoring).
 * Detects existing submissions for reprint by ticket or CPF/RF,
 * supporting both encrypted and plaintext storage with JSON fallback.
 *
 * @since 4.12.17
 */

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


class ReprintDetector {

    /**
     * Check for existing submission (reprint detection)
     *
     * v2.9.13: OPTIMIZED - Uses dedicated cpf_rf column with fallback to JSON
     *
     * @param int $form_id Form ID
     * @param string $val_cpf CPF/RF value
     * @param string $val_ticket Ticket value
     * @return array{is_reprint: bool, data: array, id: int, email: string, date: string}
     */
    public static function detect( int $form_id, string $val_cpf, string $val_ticket ): array {
        global $wpdb;
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
        $existing_submission = null;

        // Check by ticket first (if provided)
        if ( ! empty( $val_ticket ) ) {
            // Hash-based lookup (works with encrypted data)
            if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
                $ticket_hash = \FreeFormCertificate\Core\Encryption::hash( strtoupper( trim( $val_ticket ) ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $existing_submission = $wpdb->get_row( $wpdb->prepare(
                    'SELECT * FROM %i WHERE form_id = %d AND ticket_hash = %s ORDER BY id DESC LIMIT 1',
                    $table_name,
                    $form_id,
                    $ticket_hash
                ) );
            }

            // Fallback: LIKE on plaintext data (legacy / non-encrypted)
            if ( ! $existing_submission ) {
                $like_query = '%' . $wpdb->esc_like( '"ticket":"' . $val_ticket . '"' ) . '%';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $existing_submission = $wpdb->get_row( $wpdb->prepare(
                    'SELECT * FROM %i WHERE form_id = %d AND data LIKE %s ORDER BY id DESC LIMIT 1',
                    $table_name,
                    $form_id,
                    $like_query
                ) );
            }
        }

        // Check by CPF/RF (if ticket not provided)
        elseif ( ! empty( $val_cpf ) ) {
            // Remove formatting for comparison
            $clean_cpf = preg_replace( '/[^0-9]/', '', $val_cpf );

            // Check if encryption is enabled
            if (class_exists('\FreeFormCertificate\Core\Encryption') && \FreeFormCertificate\Core\Encryption::is_configured()) {
                // Use HASH for encrypted data
                $cpf_hash = \FreeFormCertificate\Core\Encryption::hash($clean_cpf);

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $existing_submission = $wpdb->get_row( $wpdb->prepare(
                    'SELECT * FROM %i WHERE form_id = %d AND cpf_rf_hash = %s ORDER BY id DESC LIMIT 1',
                    $table_name,
                    $form_id,
                    $cpf_hash
                ) );
            } else {
                // Use plain CPF for non-encrypted data
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $existing_submission = $wpdb->get_row( $wpdb->prepare(
                    'SELECT * FROM %i WHERE form_id = %d AND cpf_rf = %s ORDER BY id DESC LIMIT 1',
                    $table_name,
                    $form_id,
                    $clean_cpf
                ) );
            }

            // Fallback: If column doesn't exist or is NULL, search in JSON
            if ( ! $existing_submission ) {
                $like_query = '%' . $wpdb->esc_like( '"cpf_rf":"' . $val_cpf . '"' ) . '%';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $existing_submission = $wpdb->get_row( $wpdb->prepare(
                    'SELECT * FROM %i WHERE form_id = %d AND data LIKE %s ORDER BY id DESC LIMIT 1',
                    $table_name,
                    $form_id,
                    $like_query
                ) );
            }
        }

        if ( $existing_submission ) {
            return self::build_reprint_result( $existing_submission );
        }

        return array(
            'is_reprint' => false,
            'data' => array(),
            'id' => 0,
            'email' => '',
            'date' => ''
        );
    }

    /**
     * Build reprint result from a database row
     *
     * @param object $existing_submission Database row
     * @return array Reprint result array
     */
    private static function build_reprint_result( object $existing_submission ): array {
        // Ensure data is not null before json_decode (strict types requirement)
        $data_json = $existing_submission->data ?? '';

        // Only decode if we have actual data (not null, not empty string)
        if (!empty($data_json) && is_string($data_json)) {
            $decoded_data = json_decode( $data_json, true );
            if( !is_array($decoded_data) ) {
                $decoded_data = json_decode( stripslashes( $data_json ), true );
            }
        } else {
            $decoded_data = null;
        }

        // If still not an array, initialize empty
        if ( !is_array($decoded_data) ) {
            $decoded_data = array();
        }

        // Ensure required column fields are included
        if ( ! isset( $decoded_data['email'] ) && ! empty( $existing_submission->email ) ) {
            $decoded_data['email'] = $existing_submission->email;
        }
        if ( ! isset( $decoded_data['cpf_rf'] ) && ! empty( $existing_submission->cpf_rf ) ) {
            $decoded_data['cpf_rf'] = $existing_submission->cpf_rf;
        }
        if ( ! isset( $decoded_data['auth_code'] ) && ! empty( $existing_submission->auth_code ) ) {
            $decoded_data['auth_code'] = $existing_submission->auth_code;
        }

        return array(
            'is_reprint' => true,
            'data' => $decoded_data,
            'id' => $existing_submission->id,
            'email' => $existing_submission->email,
            'date' => $existing_submission->submission_date
        );
    }
}
