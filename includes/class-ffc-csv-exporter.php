<?php
/**
 * FFC_CSV_Exporter
 * Handles CSV export functionality with dynamic columns and filtering.
 * * v2.9.2: OPTIMIZED to use FFC_Utils functions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_CSV_Exporter {
    
    /**
     * @var string Nome da tabela de submissões
     */
    protected $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        // Define a tabela usando a Utility centralizada
        $this->table_name = FFC_Utils::get_submissions_table();
    }

    /**
     * Get all unique dynamic field keys from submissions
     */
    private function get_dynamic_columns( $rows ) {
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
     */
    private function get_fixed_headers() {
        return array(
            __( 'ID', 'ffc' ),
            __( 'Form', 'ffc' ),
            __( 'Submission Date', 'ffc' ),
            __( 'E-mail', 'ffc' ),
            __( 'User IP', 'ffc' )
        );
    }

    /**
     * Generate translatable headers for dynamic columns
     */
    private function get_dynamic_headers( $dynamic_keys ) {
        $dynamic_headers = array();
        
        foreach ( $dynamic_keys as $key ) {
            $label = ucwords( str_replace( array('_', '-'), ' ', $key ) );
            $dynamic_headers[] = __( $label, 'ffc' );
        }
        
        return $dynamic_headers;
    }

    /**
     * Format a single CSV row
     */
    private function format_csv_row( $row, $dynamic_keys ) {
        $form_title = get_the_title( $row['form_id'] );
        $form_display = $form_title ? $form_title : __( '(Deleted)', 'ffc' );
        
        // Colunas Fixas
        $line = array(
            $row['id'], 
            $form_display, 
            $row['submission_date'], 
            $row['email'], 
            $row['user_ip']
        );
        
        // Decodificação robusta do JSON
        $d = json_decode( $row['data'], true );
        if ( ! is_array( $d ) ) {
            $d = json_decode( stripslashes( $row['data'] ), true );
        }
        $d = is_array( $d ) ? $d : array();
        
        foreach( $dynamic_keys as $key ) { 
            $val = isset( $d[$key] ) ? $d[$key] : ''; 
            
            // Formatação via FFC_Utils v2.9.2
            if ( in_array( $key, array( 'cpf', 'cpf_rf', 'rg' ) ) && ! empty( $val ) ) {
                $val = FFC_Utils::format_document( $val, 'auto' );
            }
            if ( $key === 'auth_code' && ! empty( $val ) ) {
                $val = FFC_Utils::format_auth_code( $val );
            }
            
            $line[] = is_array( $val ) ? implode( ' | ', $val ) : $val; 
        }
        
        return $line;
    }

    /**
     * Export submissions to CSV file
     */
    public function export_csv( $form_id = null, $status = 'publish' ) {
        global $wpdb;
        
        FFC_Utils::debug_log( 'CSV export started', array(
            'form_id' => $form_id,
            'status' => $status
        ) );
        
        $where_parts = array();
        if ( $status ) {
            $where_parts[] = $wpdb->prepare( "status = %s", $status );
        }
        if ( $form_id ) {
            $where_parts[] = $wpdb->prepare( "form_id = %d", absint( $form_id ) );
        }
        
        $where_clause = ! empty( $where_parts ) ? "WHERE " . implode( " AND ", $where_parts ) : "";
        
        // Busca os resultados usando a tabela definida no construtor
        $query = "SELECT * FROM {$this->table_name} {$where_clause} ORDER BY id DESC";
        $rows = $wpdb->get_results( $query, ARRAY_A );
        
        if ( empty( $rows ) ) {
            wp_die( __( 'No records available for export.', 'ffc' ) );
        }
        
        $form_title = $form_id ? get_the_title( $form_id ) : 'all-certificates';
        $filename = FFC_Utils::sanitize_filename( $form_title ) . '-' . date( 'Y-m-d' ) . '.csv';
        
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( "Content-Disposition: attachment; filename={$filename}" );
        
        $output = fopen( 'php://output', 'w' );
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) ); // BOM para Excel
        
        $dynamic_keys = $this->get_dynamic_columns( $rows );
        $headers = array_merge( $this->get_fixed_headers(), $this->get_dynamic_headers( $dynamic_keys ) );
        
        fputcsv( $output, $headers, ';' );
        
        foreach( $rows as $row ) {
            fputcsv( $output, $this->format_csv_row( $row, $dynamic_keys ), ';' );
        }
        
        fclose( $output );
        exit;
    }

    public function handle_export_request() {
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

    public function export_to_csv( $form_id = null ) {
        return $this->export_csv( $form_id, 'publish' );
    }
}