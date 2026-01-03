<?php
/**
 * FFC_Submission_Handler
 * Manages CRUD operations for submissions (database only).
 * 
 * Email logic moved to: FFC_Email_Handler
 * CSV export logic moved to: FFC_CSV_Exporter
 * 
 * v2.8.0: Added magic token support for magic links
 * v2.9.0: Passes submission_id to email handler for QR Code caching
 * v2.9.1: Uses FFC_Utils::get_user_ip() instead of local method
 * v2.9.13: Added cpf_rf column for performance optimization
 * v2.9.16: CLEAN - Removed property, uses FFC_Utils::get_submissions_table()
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Submission_Handler {
    
    /**
     * Generate unique magic token for magic links
     * 
     * @since 2.8.0 Magic Links feature
     * @return string 32-character hex token
     */
    private function generate_magic_token() {
        return bin2hex( random_bytes(16) );
    }

    /**
     * Retrieve a submission by ID.
     */
    public function get_submission( $id ) {
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        return $wpdb->get_row( $wpdb->prepare( 
            "SELECT * FROM {$table} WHERE id = %d", 
            $id 
        ), ARRAY_A );
    }

    /**
     * Retrieve a submission by magic token.
     * 
     * @since 2.8.0 Magic Links feature
     * @param string $token Magic token (32 hex characters)
     * @return array|null Submission data or null if not found
     */
    public function get_submission_by_token( $token ) {
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        // Sanitize token (only allow alphanumeric)
        $clean_token = preg_replace( '/[^a-f0-9]/i', '', $token );
        
        if ( strlen( $clean_token ) !== 32 ) {
            return null; // Invalid token length
        }
        
        return $wpdb->get_row( $wpdb->prepare( 
            "SELECT * FROM {$table} WHERE magic_token = %s LIMIT 1", 
            $clean_token 
        ), ARRAY_A );
    }

    /**
     * Process submission
     * 
     * ✅ v2.9.15: SEPARAÇÃO DE DADOS - Campos obrigatórios em colunas, extras em JSON
     * ✅ v2.9.16: CLEAN - Uses centralized table name
     */
    public function process_submission( $form_id, $form_title, &$submission_data, $user_email, $fields_config, $form_config ) {
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        // 1. Generate auth code if needed
        if ( empty( $submission_data['auth_code'] ) ) {
            $submission_data['auth_code'] = strtoupper( wp_generate_password( 12, false ) );
        }
        
        // 2. Clean and extract mandatory fields
        $clean_auth_code = FFC_Utils::clean_auth_code( $submission_data['auth_code'] );
        
        $clean_cpf_rf = null;
        if ( isset( $submission_data['cpf_rf'] ) && ! empty( $submission_data['cpf_rf'] ) ) {
            $clean_cpf_rf = preg_replace( '/[^0-9]/', '', $submission_data['cpf_rf'] );
        }
        
        // 3. Generate magic token
        $magic_token = $this->generate_magic_token();
        
        // 4. ✅ SEPARAÇÃO: Extrair campos extras (remover obrigatórios do JSON)
        $mandatory_keys = array('email', 'cpf_rf', 'auth_code');
        $extra_data = array_diff_key($submission_data, array_flip($mandatory_keys));

        // ✅ v2.9.16: Garantir que data nunca seja "null" string
        $data_json = wp_json_encode($extra_data);
        if ($data_json === 'null' || $data_json === false || empty($data_json)) {
            $data_json = '{}';  // JSON vazio válido
        }
        
        // 5. Get user IP
        $user_ip = FFC_Utils::get_user_ip();

        // 6. Insert into database
        $inserted = $wpdb->insert(
            $table,
            array(
                'form_id'         => $form_id,
                'submission_date' => current_time('mysql'),
                'data'            => $data_json,
                'user_ip'         => $user_ip,
                'email'           => $user_email,
                'cpf_rf'          => $clean_cpf_rf,
                'auth_code'       => $clean_auth_code,
                'status'          => 'publish',
                'magic_token'     => $magic_token
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ( ! $inserted ) {
            return new WP_Error( 'db_error', __( 'Error saving submission to the database.', 'ffc' ) );
        }
        
        $submission_id = $wpdb->insert_id;
        
        // 7. Schedule asynchronous email delivery (processed by FFC_Email_Handler)
        wp_schedule_single_event( 
            time() + 2, 
            'ffc_process_submission_hook', 
            array( 
                $submission_id,
                $form_id, 
                $form_title, 
                $submission_data,  // ✅ Dados completos para email
                $user_email, 
                $fields_config, 
                $form_config,
                $magic_token
            ) 
        );

        return $submission_id;
    }

    /**
     * Update an existing submission.
     * Called from admin edit screen.
     */
    public function update_submission( $id, $new_email, $clean_data ) {
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        // Remove email from data (stored separately in email column)
        $data_to_save = $clean_data;
        $email_keys = array( 'email', 'user_email', 'your-email', 'ffc_email' );
        foreach ( $email_keys as $key ) {
            if ( isset( $data_to_save[$key] ) ) {
                unset( $data_to_save[$key] );
            }
        }
        
        return $wpdb->update(
            $table,
            array(
                'email' => $new_email,
                'data'  => wp_json_encode( $data_to_save )
            ),
            array( 'id' => absint( $id ) ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Move submission to trash.
     */
    public function trash_submission( $id ) { 
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        return $wpdb->update(
            $table, 
            array('status'=>'trash'), 
            array('id'=>absint($id)),
            array('%s'),
            array('%d')
        ); 
    }
    
    /**
     * Restore submission from trash.
     */
    public function restore_submission( $id ) { 
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        return $wpdb->update(
            $table, 
            array('status'=>'publish'), 
            array('id'=>absint($id)),
            array('%s'),
            array('%d')
        ); 
    }
    
    /**
     * Permanently delete submission.
     */
    public function delete_submission( $id ) { 
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        return $wpdb->delete(
            $table, 
            array('id'=>absint($id)),
            array('%d')
        ); 
    }

    /**
     * Delete all submissions or submissions from a specific form.
     * Used in settings danger zone.
     * 
     * @param int|null $form_id If null, deletes all. If set, deletes only from that form.
     */
    public function delete_all_submissions( $form_id = null ) {
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        if ( $form_id === null ) {
            // Delete all submissions
            return $wpdb->query( "TRUNCATE TABLE {$table}" );
        } else {
            // Delete submissions from specific form
            return $wpdb->delete( 
                $table, 
                array( 'form_id' => absint( $form_id ) ), 
                array( '%d' ) 
            );
        }
    }

    /**
     * Automatic cleanup of old submissions.
     * Called by daily WP-Cron event.
     */
    public function run_data_cleanup() {
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        $settings = get_option( 'ffc_settings', array() );
        $cleanup_days = isset( $settings['cleanup_days'] ) ? absint( $settings['cleanup_days'] ) : 0;
        
        if ( $cleanup_days <= 0 ) {
            return; // Cleanup disabled
        }
        
        $cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-{$cleanup_days} days" ) );
        
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE submission_date < %s",
            $cutoff_date
        ) );
    }

    /**
     * Ensure submission has a magic token (fallback for old submissions)
     * 
     * This is a safety fallback for submissions created before v2.8.0
     * or if token generation failed during creation.
     * 
     * @since 2.8.0 Magic Links feature
     * @param int $submission_id
     * @return string Magic token
     */
    public function ensure_magic_token( $submission_id ) {
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        $submission = $this->get_submission( $submission_id );
        
        if ( ! $submission ) {
            return '';
        }
        
        // If token exists, return it
        if ( ! empty( $submission['magic_token'] ) ) {
            return $submission['magic_token'];
        }
        
        // Generate new token
        $token = $this->generate_magic_token();
        
        // Save to database
        $wpdb->update(
            $table,
            array( 'magic_token' => $token ),
            array( 'id' => $submission_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        return $token;
    }

    /**
     * Migrate emails from data JSON to dedicated email column
     * 
     * @since 2.8.0
     * @deprecated 2.9.15 Moved to FFC_Migration_Manager::migrate_emails()
     * @see FFC_Migration_Manager::migrate_emails()
     * @return int Number of migrated emails
     */
    public function migrate_emails_to_column() {
        // Redirect to Migration Manager
        if ( class_exists( 'FFC_Migration_Manager' ) ) {
            $migration_manager = new FFC_Migration_Manager();
            $result = $migration_manager->run_migration( 'emails', 0 );
            return isset( $result['migrated'] ) ? $result['migrated'] : 0;
        }
        
        return 0;
    }
}