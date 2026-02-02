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

    /**
     * @var SubmissionRepository Repository instance
     */
    protected $repository;

    /**
     * Constructor
     */
    public function __construct() {
        // ✅ Use Repository Pattern instead of direct DB access
        $this->repository = new SubmissionRepository();
    }

    /**
     * Get all unique dynamic field keys from submissions
     */
    private function get_dynamic_columns( array $rows ): array {
        $all_keys = array();

        foreach( $rows as $r ) {
            $d = $this->get_submission_data( $r );
            if ( is_array( $d ) ) {
                $all_keys = array_merge( $all_keys, array_keys( $d ) );
            }
        }

        return array_unique( $all_keys );
    }

    /**
     * Get submission data from a row, handling encryption
     *
     * @param array $row
     * @return array
     */
    private function get_submission_data( array $row ): array {
        $json = null;

        // Try encrypted first
        if ( !empty( $row['data_encrypted'] ) ) {
            try {
                if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
                    $json = \FreeFormCertificate\Core\Encryption::decrypt( $row['data_encrypted'] );
                }
            } catch ( \Exception $e ) {
                $json = null;
            }
        }

        // Fallback to plain text
        if ( $json === null && !empty( $row['data'] ) ) {
            $json = $row['data'];
        }

        if ( empty( $json ) ) {
            return array();
        }

        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Generate translatable headers for fixed columns
     * v3.0.2: Made edit columns conditional
     */
    private function get_fixed_headers( bool $include_edit_columns = false ): array {
        $headers = array(
            __( 'ID', 'wp-ffcertificate' ),
            __( 'Form', 'wp-ffcertificate' ),
            __( 'User ID', 'wp-ffcertificate' ),
            __( 'Submission Date', 'wp-ffcertificate' ),
            __( 'E-mail', 'wp-ffcertificate' ),
            __( 'User IP', 'wp-ffcertificate' ),
            __( 'CPF/RF', 'wp-ffcertificate' ),
            __( 'Auth Code', 'wp-ffcertificate' ),
            __( 'Token', 'wp-ffcertificate' ),
            __( 'Consent Given', 'wp-ffcertificate' ),
            __( 'Consent Date', 'wp-ffcertificate' ),
            __( 'Consent IP', 'wp-ffcertificate' ),
            __( 'Consent Text', 'wp-ffcertificate' ),
            __( 'Status', 'wp-ffcertificate' )
        );

        // Only add edit columns if there's data
        if ( $include_edit_columns ) {
            $headers[] = __( 'Was Edited', 'wp-ffcertificate' );
            $headers[] = __( 'Edit Date', 'wp-ffcertificate' );
            $headers[] = __( 'Edited By', 'wp-ffcertificate' );
        }

        return $headers;
    }

    /**
     * Generate translatable headers for dynamic columns
     */
    private function get_dynamic_headers( array $dynamic_keys ): array {
        $dynamic_headers = array();
        
        foreach ( $dynamic_keys as $key ) {
            $label = ucwords( str_replace( array('_', '-'), ' ', $key ) );
            $dynamic_headers[] = __( $label, 'wp-ffcertificate' );
        }
        
        return $dynamic_headers;
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
        $form_display = $form_title ? $form_title : __( '(Deleted)', 'wp-ffcertificate' );
        
        // Decrypt email
        $email = '';
        if ( !empty( $row['email_encrypted'] ) ) {
            $email = \FreeFormCertificate\Core\Encryption::decrypt( $row['email_encrypted'] );
        } elseif ( !empty( $row['email'] ) ) {
            $email = $row['email']; // Fallback for non-encrypted data
        }
        
        // Decrypt IP
        $user_ip = '';
        if ( !empty( $row['user_ip_encrypted'] ) ) {
            $user_ip = \FreeFormCertificate\Core\Encryption::decrypt( $row['user_ip_encrypted'] );
        } elseif ( !empty( $row['user_ip'] ) ) {
            $user_ip = $row['user_ip']; // Fallback for non-encrypted data
        }

        // Decrypt CPF/RF
        $cpf_rf = '';
        if ( !empty( $row['cpf_rf_encrypted'] ) ) {
            $cpf_rf = \FreeFormCertificate\Core\Encryption::decrypt( $row['cpf_rf_encrypted'] );
        } elseif ( !empty( $row['cpf_rf'] ) ) {
            $cpf_rf = $row['cpf_rf']; // Fallback for non-encrypted data
        }

        // User ID
        $user_id = !empty( $row['user_id'] ) ? $row['user_id'] : '';

        // Auth Code (omit if empty)
        $auth_code = !empty( $row['auth_code'] ) ? $row['auth_code'] : '';

        // Token (magic_token column - not encrypted)
        $token = !empty( $row['magic_token'] ) ? $row['magic_token'] : '';

        // Consent Given (Yes/No)
        $consent_given = '';
        if ( isset( $row['consent_given'] ) ) {
            $consent_given = $row['consent_given'] ? __( 'Yes', 'wp-ffcertificate' ) : __( 'No', 'wp-ffcertificate' );
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
        
        // ✅ CONDITIONAL: Only add edit columns if they should be included
        if ( $include_edit_columns ) {
            $was_edited = '';
            $edit_date = '';
            $edited_by = '';
            
            if ( !empty( $row['edited_at'] ) ) {
                $was_edited = __( 'Yes', 'wp-ffcertificate' );
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

        // ✅ Use Repository Pattern - much cleaner!
        $rows = $this->repository->getForExport( $form_ids, $status );

        if ( empty( $rows ) ) {
            wp_die( esc_html__( 'No records available for export.', 'wp-ffcertificate' ) );
        }

        // ✅ Use Repository to check if edit columns exist
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

        // ✅ BOM for Excel UTF-8 recognition
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );

        $dynamic_keys = $this->get_dynamic_columns( $rows );
        $headers = array_merge(
            $this->get_fixed_headers( $include_edit_columns ),
            $this->get_dynamic_headers( $dynamic_keys )
        );

        // ✅ Convert all headers to UTF-8
        $headers = array_map( function( $header ) {
            return mb_convert_encoding( $header, 'UTF-8', 'UTF-8' );
        }, $headers );

        fputcsv( $output, $headers, ';' );

        foreach( $rows as $row ) {
            $csv_row = $this->format_csv_row( $row, $dynamic_keys, $include_edit_columns );

            // ✅ Convert all row data to UTF-8
            $csv_row = array_map( function( $value ) {
                if ( is_string( $value ) ) {
                    return mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
                }
                return $value;
            }, $csv_row );

            fputcsv( $output, $csv_row, ';' );
        }

        fclose( $output );
        exit;
    }

    /**
     * Handle export request from admin
     */
    public function handle_export_request(): void {
        try {
            // Debug logging
            \FreeFormCertificate\Core\Utils::debug_log( 'CSV export handler called', array(
                'POST' => $_POST,
                'has_nonce' => isset( $_POST['ffc_export_csv_action'] )
            ) );

            if ( ! isset( $_POST['ffc_export_csv_action'] ) ||
                 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ffc_export_csv_action'] ) ), 'ffc_export_csv_nonce' ) ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'CSV export nonce failed' );
                wp_die( esc_html__( 'Security check failed.', 'wp-ffcertificate' ) );
            }

            if ( ! \FreeFormCertificate\Core\Utils::current_user_can_manage() ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'CSV export permission denied' );
                wp_die( esc_html__( 'You do not have permission to export data.', 'wp-ffcertificate' ) );
            }

            // ✅ Support multiple form IDs or single form_id
            $form_ids = null;
            if ( !empty( $_POST['form_ids'] ) && is_array( $_POST['form_ids'] ) ) {
                $form_ids = array_map( 'absint', $_POST['form_ids'] );
            } elseif ( !empty( $_POST['form_id'] ) ) {
                $form_ids = [ absint( $_POST['form_id'] ) ];
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
            wp_die( esc_html__( 'Error generating CSV: ', 'wp-ffcertificate' ) . esc_html( $e->getMessage() ) );
        }
    }

    /**
     * Backward compatibility method
     *
     * @param int|null $form_id Single form ID or null
     */
    public function export_to_csv( ?int $form_id = null ): void {
        $this->export_csv( $form_id, 'publish' );
    }
}
