<?php
declare(strict_types=1);

/**
 * CsvExporter (formerly CSVExporter)
 * Handles CSV export functionality with dynamic columns and filtering.
 *
 * @version 4.0.0 - HOTFIX 21: All DB columns in CSV, UTF-8 encoding fix, multi-form filters
 * @version 4.0.0 - Renamed to CsvExporter for PSR-4 compliance (Phase 4 hotfix)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * v3.0.3: REFACTORED - Uses Repository Pattern instead of direct SQL
 * v3.0.2: FIXED - Use magic_token column, conditional columns for edit info
 * v3.0.1: COMPLETE - All columns including token, consent, edit history, status, auth_code
 * v3.0.0: FIXED - Decrypt email/IP and complete format_csv_row() method
 * v2.9.2: OPTIMIZED to use FFC_Utils functions
 */

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CsvExporter {

    use \FreeFormCertificate\Core\CsvExportTrait;

    /**
     * @var SubmissionRepository Repository instance
     */
    protected $repository;

    /**
     * Constructor
     */
    public function __construct() {
        $this->repository = new SubmissionRepository();
    }

    /**
     * Get all unique dynamic field keys from submissions.
     * Delegates to CsvExportTrait::extract_dynamic_keys().
     */
    private function get_dynamic_columns( array $rows ): array {
        return $this->extract_dynamic_keys( $rows, 'data', 'data_encrypted' );
    }

    /**
     * Get submission data from a row, handling encryption.
     * Delegates to CsvExportTrait::decode_json_field().
     */
    private function get_submission_data( array $row ): array {
        return $this->decode_json_field( $row, 'data', 'data_encrypted' );
    }

    /**
     * Generate translatable headers for fixed columns
     * v3.0.2: Made edit columns conditional
     */
    private function get_fixed_headers( bool $include_edit_columns = false ): array {
        $headers = array(
            __( 'ID', 'ffcertificate' ),
            __( 'Form', 'ffcertificate' ),
            __( 'User ID', 'ffcertificate' ),
            __( 'Submission Date', 'ffcertificate' ),
            __( 'E-mail', 'ffcertificate' ),
            __( 'User IP', 'ffcertificate' ),
            __( 'CPF/RF', 'ffcertificate' ),
            __( 'Auth Code', 'ffcertificate' ),
            __( 'Token', 'ffcertificate' ),
            __( 'Consent Given', 'ffcertificate' ),
            __( 'Consent Date', 'ffcertificate' ),
            __( 'Consent IP', 'ffcertificate' ),
            __( 'Consent Text', 'ffcertificate' ),
            __( 'Status', 'ffcertificate' )
        );

        // Only add edit columns if there's data
        if ( $include_edit_columns ) {
            $headers[] = __( 'Was Edited', 'ffcertificate' );
            $headers[] = __( 'Edit Date', 'ffcertificate' );
            $headers[] = __( 'Edited By', 'ffcertificate' );
        }

        return $headers;
    }

    /**
     * Generate translatable headers for dynamic columns.
     * Delegates to CsvExportTrait::build_dynamic_headers().
     */
    private function get_dynamic_headers( array $dynamic_keys ): array {
        return $this->build_dynamic_headers( $dynamic_keys );
    }

    /**
     * Format a single CSV row
     * 
     * v3.0.3: Added edited_by column
     * v3.0.2: Use magic_token column, conditional edit columns
     * v3.0.1: Added all requested columns with conditional display
     * v3.0.0: FIXED - Added return statement and dynamic columns processing
     */
    private function format_csv_row( array $row, array $dynamic_keys, bool $include_edit_columns = false ): array {
        $form_title = get_the_title( (int) $row['form_id'] );
        $form_display = $form_title ? $form_title : __( '(Deleted)', 'ffcertificate' );
        
        // Decrypt sensitive fields (encrypted → plain fallback)
        $email   = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'email' );
        $user_ip = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'user_ip' );
        $cpf_rf  = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'cpf_rf' );

        // User ID
        $user_id = !empty( $row['user_id'] ) ? $row['user_id'] : '';

        // Auth Code (omit if empty)
        $auth_code = !empty( $row['auth_code'] ) ? $row['auth_code'] : '';

        // Token (magic_token column - not encrypted)
        $token = !empty( $row['magic_token'] ) ? $row['magic_token'] : '';

        // Consent Given (Yes/No)
        $consent_given = '';
        if ( isset( $row['consent_given'] ) ) {
            $consent_given = $row['consent_given'] ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' );
        }

        // Consent Date (omit if empty)
        $consent_date = !empty( $row['consent_date'] ) ? $row['consent_date'] : '';

        // Consent IP (omit if empty)
        $consent_ip = !empty( $row['consent_ip'] ) ? $row['consent_ip'] : '';

        // Consent Text (omit if empty)
        $consent_text = !empty( $row['consent_text'] ) ? $row['consent_text'] : '';

        // Status (publish, trash, etc)
        $status = !empty( $row['status'] ) ? $row['status'] : 'publish';

        // Fixed Columns (in order)
        $line = array(
            $row['id'],                 // ID
            $form_display,              // Form
            $user_id,                   // User ID
            $row['submission_date'],    // Submission Date
            $email,                     // E-mail (decrypted)
            $user_ip,                   // User IP (decrypted)
            $cpf_rf,                    // CPF/RF (decrypted)
            $auth_code,                 // Auth Code (omitted if empty)
            $token,                     // Token (magic_token column)
            $consent_given,             // Consent Given (Yes/No)
            $consent_date,              // Consent Date (omitted if empty)
            $consent_ip,                // Consent IP (omitted if empty)
            $consent_text,              // Consent Text (omitted if empty)
            $status                     // Status
        );
        
        if ( $include_edit_columns ) {
            $was_edited = '';
            $edit_date = '';
            $edited_by = '';
            
            if ( !empty( $row['edited_at'] ) ) {
                $was_edited = __( 'Yes', 'ffcertificate' );
                $edit_date = $row['edited_at'];
                
                // Get editor name if edited_by exists
                if ( !empty( $row['edited_by'] ) ) {
                    $user = get_userdata( (int) $row['edited_by'] );
                    $edited_by = $user ? $user->display_name : 'ID: ' . $row['edited_by'];
                }
            }
            
            $line[] = $was_edited;      // Was Edited
            $line[] = $edit_date;       // Edit Date
            $line[] = $edited_by;       // Edited By
        }
        
        // Dynamic Columns (each field from 'data' column in separate CSV column)
        $data = $this->get_submission_data( $row );

        foreach ( $dynamic_keys as $key ) {
            $value = $data[$key] ?? '';
            // Flatten arrays/objects to string
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }
            $line[] = $value;
        }
        
        return $line;
    }

    /**
     * Export submissions to CSV file
     *
     * v4.0.0: ENHANCED - Support multiple form IDs
     * v3.0.3: REFACTORED - Uses Repository instead of direct SQL
     *
     * @param int|array|null $form_ids Single form ID, array of IDs, or null for all
     * @param string $status Status filter
     */
    public function export_csv( $form_ids = null, string $status = 'publish' ): void {
        // Normalize form_ids to array
        if ( $form_ids !== null && !is_array( $form_ids ) ) {
            $form_ids = [ (int) $form_ids ];
        }

        \FreeFormCertificate\Core\Utils::debug_log( 'CSV export started', array(
            'form_ids' => $form_ids,
            'status' => $status
        ) );

        $rows = $this->repository->getForExport( $form_ids, $status );

        /**
         * Filters submission rows before CSV export.
         *
         * @since 4.6.4
         * @param array      $rows     Submission rows to export.
         * @param array|null $form_ids Form IDs filter (null for all).
         * @param string     $status   Status filter.
         */
        $rows = apply_filters( 'ffcertificate_csv_export_data', $rows, $form_ids, $status );

        if ( empty( $rows ) ) {
            wp_die( esc_html__( 'No records available for export.', 'ffcertificate' ) );
        }

        $include_edit_columns = $this->repository->hasEditInfo();

        // Generate filename based on filters
        if ( $form_ids && count( $form_ids ) === 1 ) {
            $form_title = get_the_title( $form_ids[0] );
        } elseif ( $form_ids && count( $form_ids ) > 1 ) {
            $form_title = count( $form_ids ) . '-forms';
        } else {
            $form_title = 'all-forms';
        }

        $filename = \FreeFormCertificate\Core\Utils::sanitize_filename( $form_title ) . '-' . gmdate( 'Y-m-d' ) . '.csv';
        
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( "Content-Disposition: attachment; filename={$filename}" );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // BOM for Excel UTF-8 recognition
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV binary output, not HTML context
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );

        $dynamic_keys = $this->get_dynamic_columns( $rows );
        $headers = array_merge(
            $this->get_fixed_headers( $include_edit_columns ),
            $this->get_dynamic_headers( $dynamic_keys )
        );

        // Convert all headers to UTF-8
        $headers = array_map( function( $header ) {
            return mb_convert_encoding( $header, 'UTF-8', 'UTF-8' );
        }, $headers );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file output, not HTML context
        fputcsv( $output, $headers, ';' );

        foreach( $rows as $row ) {
            $csv_row = $this->format_csv_row( $row, $dynamic_keys, $include_edit_columns );

            // Convert all row data to UTF-8
            $csv_row = array_map( function( $value ) {
                if ( is_string( $value ) ) {
                    return mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
                }
                return $value;
            }, $csv_row );

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file output, not HTML context
            fputcsv( $output, $csv_row, ';' );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream for CSV export.
        fclose( $output );
        exit;
    }

    /**
     * Handle export request from admin
     */
    public function handle_export_request(): void {
        try {
            // Debug logging
            // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Debug logging before nonce check; POST superglobal used as-is for debug.
            \FreeFormCertificate\Core\Utils::debug_log( 'CSV export handler called', array(
                'POST' => $_POST,
                'has_nonce' => isset( $_POST['ffc_export_csv_action'] )
            ) );
            // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only.
            if ( ! isset( $_POST['ffc_export_csv_action'] ) ||
                 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ffc_export_csv_action'] ) ), 'ffc_export_csv_nonce' ) ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'CSV export nonce failed' );
                wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
            }

            if ( ! \FreeFormCertificate\Core\Utils::current_user_can_manage() ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'CSV export permission denied' );
                wp_die( esc_html__( 'You do not have permission to export data.', 'ffcertificate' ) );
            }

            $form_ids = null;
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- empty()/is_array() existence and type checks only.
            if ( !empty( $_POST['form_ids'] ) && is_array( $_POST['form_ids'] ) ) {
                $form_ids = array_map( 'absint', wp_unslash( $_POST['form_ids'] ) );
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- empty() existence check only.
            } elseif ( !empty( $_POST['form_id'] ) ) {
                $form_ids = [ absint( wp_unslash( $_POST['form_id'] ) ) ];
            }

            $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'publish';

            \FreeFormCertificate\Core\Utils::debug_log( 'CSV export starting', array(
                'form_ids' => $form_ids,
                'status' => $status
            ) );

            $this->export_csv( $form_ids, $status );
        } catch ( \Exception $e ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'CSV export exception', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ) );
            wp_die( esc_html__( 'Error generating CSV: ', 'ffcertificate' ) . esc_html( $e->getMessage() ) );
        }
    }
}
