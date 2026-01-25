<?php
declare(strict_types=1);

/**
 * FFC_CSV_Exporter
 * Handles CSV export functionality with dynamic columns and filtering.
 * 
 * v3.3.0: Added strict types and type hints
 * v3.0.3: REFACTORED - Uses Repository Pattern instead of direct SQL
 * v3.0.2: FIXED - Use magic_token column, conditional columns for edit info
 * v3.0.1: COMPLETE - All columns including token, consent, edit history, status, auth_code
 * v3.0.0: FIXED - Decrypt email/IP and complete format_csv_row() method
 * v2.9.2: OPTIMIZED to use FFC_Utils functions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_CSV_Exporter {
    
    /**
     * @var FFC_Submission_Repository Repository instance
     */
    protected $repository;

    /**
     * Constructor
     */
    public function __construct() {
        // ✅ Use Repository Pattern instead of direct DB access
        $this->repository = new FFC_Submission_Repository();
    }

    /**
     * Get all unique dynamic field keys from submissions
     */
    private function get_dynamic_columns( array $rows ): array {
        $all_keys = array();
        
        foreach( $rows as $r ) { 
            $d = json_decode( $r['data'], true ); 
            if ( is_array( $d ) ) {
                $all_keys = array_merge( $all_keys, array_keys( $d ) ); 
            }
        }
        
        return array_unique( $all_keys );
    }

    /**
     * Generate translatable headers for fixed columns
     * v3.0.2: Made edit columns conditional
     */
    private function get_fixed_headers( bool $include_edit_columns = false ): array {
        $headers = array(
            __( 'ID', 'ffc' ),
            __( 'Form', 'ffc' ),
            __( 'Submission Date', 'ffc' ),
            __( 'E-mail', 'ffc' ),
            __( 'User IP', 'ffc' ),
            __( 'Auth Code', 'ffc' ),
            __( 'Token', 'ffc' ),
            __( 'Consent Given', 'ffc' ),
            __( 'Consent Date', 'ffc' ),
            __( 'Status', 'ffc' )
        );
        
        // Only add edit columns if there's data
        if ( $include_edit_columns ) {
            $headers[] = __( 'Was Edited', 'ffc' );
            $headers[] = __( 'Edit Date', 'ffc' );
            $headers[] = __( 'Edited By', 'ffc' );
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
            $dynamic_headers[] = __( $label, 'ffc' );
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
        $form_display = $form_title ? $form_title : __( '(Deleted)', 'ffc' );
        
        // Decrypt email
        $email = '';
        if ( !empty( $row['email_encrypted'] ) ) {
            $email = FFC_Encryption::decrypt( $row['email_encrypted'] );
        } elseif ( !empty( $row['email'] ) ) {
            $email = $row['email']; // Fallback for non-encrypted data
        }
        
        // Decrypt IP
        $user_ip = '';
        if ( !empty( $row['user_ip_encrypted'] ) ) {
            $user_ip = FFC_Encryption::decrypt( $row['user_ip_encrypted'] );
        } elseif ( !empty( $row['user_ip'] ) ) {
            $user_ip = $row['user_ip']; // Fallback for non-encrypted data
        }
        
        // Auth Code (omit if empty)
        $auth_code = !empty( $row['auth_code'] ) ? $row['auth_code'] : '';
        
        // Token (magic_token column - not encrypted)
        $token = !empty( $row['magic_token'] ) ? $row['magic_token'] : '';
        
        // Consent Given (Yes/No)
        $consent_given = '';
        if ( isset( $row['consent_given'] ) ) {
            $consent_given = $row['consent_given'] ? __( 'Yes', 'ffc' ) : __( 'No', 'ffc' );
        }
        
        // Consent Date (omit if empty)
        $consent_date = !empty( $row['consent_date'] ) ? $row['consent_date'] : '';
        
        // Status (publish, trash, etc)
        $status = !empty( $row['status'] ) ? $row['status'] : 'publish';
        
        // Fixed Columns (in order)
        $line = array(
            $row['id'],                 // ID
            $form_display,              // Form
            $row['submission_date'],    // Submission Date
            $email,                     // E-mail (decrypted)
            $user_ip,                   // User IP (decrypted)
            $auth_code,                 // Auth Code (omitted if empty)
            $token,                     // Token (magic_token column)
            $consent_given,             // Consent Given (Yes/No)
            $consent_date,              // Consent Date (omitted if empty)
            $status                     // Status
        );
        
        // ✅ CONDITIONAL: Only add edit columns if they should be included
        if ( $include_edit_columns ) {
            $was_edited = '';
            $edit_date = '';
            $edited_by = '';
            
            if ( !empty( $row['edited_at'] ) ) {
                $was_edited = __( 'Yes', 'ffc' );
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
        $data = json_decode( $row['data'], true );
        if ( ! is_array( $data ) ) {
            $data = array();
        }
        
        foreach ( $dynamic_keys as $key ) {
            $line[] = isset( $data[$key] ) ? $data[$key] : '';
        }
        
        return $line;
    }

    /**
     * Export submissions to CSV file
     * 
     * v3.0.3: REFACTORED - Uses Repository instead of direct SQL
     */
    public function export_csv( ?int $form_id = null, string $status = 'publish' ): void {
        FFC_Utils::debug_log( 'CSV export started', array(
            'form_id' => $form_id,
            'status' => $status
        ) );
        
        // ✅ Use Repository Pattern - much cleaner!
        $rows = $this->repository->getForExport( $form_id, $status );
        
        if ( empty( $rows ) ) {
            wp_die( __( 'No records available for export.', 'ffc' ) );
        }
        
        // ✅ Use Repository to check if edit columns exist
        $include_edit_columns = $this->repository->hasEditInfo();
        
        $form_title = $form_id ? get_the_title( $form_id ) : 'all-certificates';
        $filename = FFC_Utils::sanitize_filename( $form_title ) . '-' . date( 'Y-m-d' ) . '.csv';
        
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( "Content-Disposition: attachment; filename={$filename}" );
        
        $output = fopen( 'php://output', 'w' );
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) ); // BOM for Excel
        
        $dynamic_keys = $this->get_dynamic_columns( $rows );
        $headers = array_merge( 
            $this->get_fixed_headers( $include_edit_columns ), 
            $this->get_dynamic_headers( $dynamic_keys ) 
        );
        
        fputcsv( $output, $headers, ';' );
        
        foreach( $rows as $row ) {
            fputcsv( $output, $this->format_csv_row( $row, $dynamic_keys, $include_edit_columns ), ';' );
        }
        
        fclose( $output );
        exit;
    }

    /**
     * Handle export request from admin
     */
    public function handle_export_request(): void {
        if ( ! isset( $_POST['ffc_export_csv_action'] ) || 
             ! wp_verify_nonce( $_POST['ffc_export_csv_action'], 'ffc_export_csv_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'ffc' ) );
        }

        if ( ! FFC_Utils::current_user_can_manage() ) {
            wp_die( __( 'You do not have permission to export data.', 'ffc' ) );
        }

        $form_id = !empty( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : null;
        $status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'publish';
        
        $this->export_csv( $form_id, $status );
    }

    /**
     * Backward compatibility method
     */
    public function export_to_csv( ?int $form_id = null ): void {
        $this->export_csv( $form_id, 'publish' );
    }
}
