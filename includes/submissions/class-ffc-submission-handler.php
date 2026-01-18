<?php
/**
 * FFC_Submission_Handler v3.0.0
 * Complete refactored version with Repository Pattern
 * 
 * @since 3.0.0 Repository Pattern integration
 * @since 2.10.0 Encryption & LGPD support
 */

if (!defined('ABSPATH')) exit;

class FFC_Submission_Handler {
    
    private $repository;
    
    public function __construct() {
        $this->repository = new FFC_Submission_Repository();
    }
    
    /**
     * Generate unique magic token
     */
    private function generate_magic_token() {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * Generate unique auth code
     */
    private function generate_unique_auth_code() {
        do {
            $code = FFC_Utils::generate_auth_code();
            $existing = $this->repository->findByAuthCode($code);
        } while ($existing);
        
        return $code;
    }
    
    /**
     * Get submission by ID
     * @uses Repository::findById()
     */
    public function get_submission($id) {
        $submission = $this->repository->findById($id);
        
        if (!$submission) {
            return null;
        }
        
        return $this->decrypt_submission_data($submission);
    }
    
    /**
     * Get submission by magic token
     * @uses Repository::findByToken()
     */
    public function get_submission_by_token($token) {
        $clean_token = preg_replace('/[^a-f0-9]/i', '', $token);
        
        if (strlen($clean_token) !== 32) {
            return null;
        }
        
        $submission = $this->repository->findByToken($clean_token);
        
        if (!$submission) {
            return null;
        }
        
        return $this->decrypt_submission_data($submission);
    }
    
    /**
     * Process submission (main method)
     * @uses Repository::insert()
     */
    public function process_submission($form_id, $form_title, &$submission_data, $user_email, $fields_config, $form_config) {
        // 1. Generate auth code if not present
        if (empty($submission_data['auth_code'])) {
            $submission_data['auth_code'] = $this->generate_unique_auth_code();
        }
        
        // 2. Clean mandatory fields
        $clean_auth_code = FFC_Utils::clean_auth_code($submission_data['auth_code']);
        
        $clean_cpf_rf = null;
        if (isset($submission_data['cpf_rf']) && !empty($submission_data['cpf_rf'])) {
            $clean_cpf_rf = preg_replace('/[^0-9]/', '', $submission_data['cpf_rf']);
        }
        
        // 3. Generate magic token
        $magic_token = $this->generate_magic_token();
        
        // 4. Extract extra data
        $mandatory_keys = ['email', 'cpf_rf', 'auth_code', 'ffc_lgpd_consent'];
        $extra_data = array_diff_key($submission_data, array_flip($mandatory_keys));
        
        $data_json = wp_json_encode($extra_data);
        if ($data_json === 'null' || $data_json === false || empty($data_json)) {
            $data_json = '{}';
        }
        
        // 5. Get user IP
        $user_ip = FFC_Utils::get_user_ip();
        
        // 6. Encryption
        $email_encrypted = null;
        $email_hash = null;
        $cpf_encrypted = null;
        $cpf_hash = null;
        $ip_encrypted = null;
        $data_encrypted = null;
        
        if (class_exists('FFC_Encryption') && FFC_Encryption::is_configured()) {
            if (!empty($user_email)) {
                $email_encrypted = FFC_Encryption::encrypt($user_email);
                $email_hash = FFC_Encryption::hash($user_email);
            }
            
            if (!empty($clean_cpf_rf)) {
                $cpf_encrypted = FFC_Encryption::encrypt($clean_cpf_rf);
                $cpf_hash = FFC_Encryption::hash($clean_cpf_rf);
            }
            
            if (!empty($user_ip)) {
                $ip_encrypted = FFC_Encryption::encrypt($user_ip);
            }
            
            if (!empty($data_json) && $data_json !== '{}') {
                $data_encrypted = FFC_Encryption::encrypt($data_json);
            }
        }
        
        // 7. LGPD Consent
        $consent_given = isset($submission_data['ffc_lgpd_consent']) && $submission_data['ffc_lgpd_consent'] == '1' ? 1 : 0;
        $consent_date = $consent_given ? current_time('mysql') : null;
        $consent_text = $consent_given ? __('User agreed to Privacy Policy and data storage', 'ffc') : null;

        // 8. Link to WordPress user (v3.1.0)
        $user_id = null;
        if (!empty($cpf_hash) && !empty($user_email)) {
            // Load User Manager if not already loaded
            if (!class_exists('FFC_User_Manager')) {
                $user_manager_file = FFC_PLUGIN_DIR . 'includes/user-dashboard/class-ffc-user-manager.php';
                if (file_exists($user_manager_file)) {
                    require_once $user_manager_file;
                }
            }

            if (class_exists('FFC_User_Manager')) {
                $user_result = FFC_User_Manager::get_or_create_user($cpf_hash, $user_email, $submission_data);

                if (!is_wp_error($user_result)) {
                    $user_id = $user_result;
                }
            }
        }

        // 9. Prepare insert data
        $insert_data = [
            'form_id' => $form_id,
            'user_id' => $user_id,  // v3.1.0: Link to WordPress user
            'submission_date' => current_time('mysql'),
            'auth_code' => $clean_auth_code,
            'status' => 'publish',
            'magic_token' => $magic_token,
            'email_encrypted' => $email_encrypted,
            'email_hash' => $email_hash,
            'cpf_rf_encrypted' => $cpf_encrypted,
            'cpf_rf_hash' => $cpf_hash,
            'user_ip_encrypted' => $ip_encrypted,
            'data_encrypted' => $data_encrypted,
            'consent_given' => $consent_given,
            'consent_date' => $consent_date,
            'consent_text' => $consent_text
        ];
        
        // Old columns - only if encryption NOT configured
        if (class_exists('FFC_Encryption') && FFC_Encryption::is_configured()) {
            $insert_data['data'] = null;
            $insert_data['user_ip'] = null;
            $insert_data['email'] = null;
            $insert_data['cpf_rf'] = null;
        } else {
            $insert_data['data'] = $data_json;
            $insert_data['user_ip'] = $user_ip;
            $insert_data['email'] = $user_email;
            $insert_data['cpf_rf'] = $clean_cpf_rf;
        }
        
        // 9. Insert using repository
        $submission_id = $this->repository->insert($insert_data);
        
        if (!$submission_id) {
            return new WP_Error('db_error', __('Error saving submission to the database.', 'ffc'));
        }
        
        // 10. Log activity
        if (class_exists('FFC_Activity_Log')) {
            FFC_Activity_Log::log_submission_created($submission_id, [
                'form_id' => $form_id,
                'has_cpf' => !empty($clean_cpf_rf),
                'encrypted' => !empty($email_encrypted)
            ]);
        }
        
        return $submission_id;
    }
    
    /**
     * Update submission - FIXED v3.0.1
     * @uses Repository::updateWithEditTracking()
     */
    public function update_submission($id, $new_email, $clean_data) {
        $update_data = [];
        
        // Update email if provided
        if ($new_email !== null) {
            if (class_exists('FFC_Encryption') && FFC_Encryption::is_configured()) {
                $update_data['email_encrypted'] = FFC_Encryption::encrypt($new_email);
                $update_data['email_hash'] = FFC_Encryption::hash($new_email);
                $update_data['email'] = null;
            } else {
                $update_data['email'] = $new_email;
            }
        }
        
        // Update data if provided
        if ($clean_data !== null && is_array($clean_data)) {
            // ✅ Remove edit tracking from JSON data (should be in columns)
            unset($clean_data['is_edited']);
            unset($clean_data['edited_at']);
            
            $data_json = wp_json_encode($clean_data, JSON_UNESCAPED_UNICODE);
            
            if (class_exists('FFC_Encryption') && FFC_Encryption::is_configured()) {
                $update_data['data_encrypted'] = FFC_Encryption::encrypt($data_json);
                $update_data['data'] = null;
            } else {
                $update_data['data'] = $data_json;
            }
        }
        
        // ✅ Use Repository method with automatic edit tracking
        if (method_exists($this->repository, 'updateWithEditTracking')) {
            $result = $this->repository->updateWithEditTracking($id, $update_data);
        } else {
            // Fallback: manual tracking in columns (not JSON)
            $update_data['edited_at'] = current_time('mysql');
            $update_data['edited_by'] = get_current_user_id();
            $result = $this->repository->update($id, $update_data);
        }
        
        if ($result !== false && class_exists('FFC_Activity_Log')) {
            FFC_Activity_Log::log_submission_updated($id, [
                'fields_updated' => array_keys($update_data)
            ]);
        }
        
        return $result;
    }
    
    /**
     * Decrypt submission data
     */
    public function decrypt_submission_data($submission) {
        if (!$submission || !class_exists('FFC_Encryption')) {
            return $submission;
        }
        
        if (!empty($submission['email_encrypted'])) {
            $decrypted = FFC_Encryption::decrypt($submission['email_encrypted']);
            if ($decrypted !== false) {
                $submission['email'] = $decrypted;
            }
        }
        
        if (!empty($submission['cpf_rf_encrypted'])) {
            $decrypted = FFC_Encryption::decrypt($submission['cpf_rf_encrypted']);
            if ($decrypted !== false) {
                $submission['cpf_rf'] = $decrypted;
            }
        }
        
        if (!empty($submission['user_ip_encrypted'])) {
            $decrypted = FFC_Encryption::decrypt($submission['user_ip_encrypted']);
            if ($decrypted !== false) {
                $submission['user_ip'] = $decrypted;
            }
        }
        
        if (!empty($submission['data_encrypted'])) {
            $decrypted = FFC_Encryption::decrypt($submission['data_encrypted']);
            if ($decrypted !== false) {
                $submission['data'] = $decrypted;
            }
        }
        
        return $submission;
    }
    
    /**
     * Trash submission
     * @uses Repository::updateStatus()
     */
    public function trash_submission($id) {
        $result = $this->repository->updateStatus($id, 'trash');
        
        if ($result && class_exists('FFC_Activity_Log')) {
            FFC_Activity_Log::log_submission_trashed($id);
        }
        
        return $result;
    }
    
    /**
     * Restore submission
     * @uses Repository::updateStatus()
     */
    public function restore_submission($id) {
        $result = $this->repository->updateStatus($id, 'publish');
        
        if ($result && class_exists('FFC_Activity_Log')) {
            FFC_Activity_Log::log_submission_restored($id);
        }
        
        return $result;
    }
    
    /**
     * Permanently delete submission
     * @uses Repository::delete()
     */
    public function delete_submission($id) {
        $result = $this->repository->delete($id);
        
        if ($result && class_exists('FFC_Activity_Log')) {
            FFC_Activity_Log::log_submission_deleted($id);
        }
        
        return $result;
    }
    
    /**
     * Delete all submissions for a form
     * @uses Repository::deleteByFormId()
     */
    /**
     * Delete all submissions (optionally by form_id)
     * 
     * @param int|null $form_id Form ID to delete from, or null for all forms
     * @param bool $reset_auto_increment Reset ID counter to 1
     * @return int|false Number of rows deleted or false on error
     */
    public function delete_all_submissions($form_id = null, $reset_auto_increment = false) {
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        if ($form_id) {
            // Delete from specific form using repository
            $result = $this->repository->deleteByFormId($form_id);
            
            // Reset AUTO_INCREMENT if table is empty and requested
            if ($reset_auto_increment) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
                if ($count == 0) {
                    $wpdb->query("ALTER TABLE {$table} AUTO_INCREMENT = 1");
                }
            }
            
            return $result;
        }
        
        // Delete ALL submissions from ALL forms
        $result = false;

        if ($reset_auto_increment) {
            // TRUNCATE resets AUTO_INCREMENT automatically
            $result = $wpdb->query("TRUNCATE TABLE {$table}");

            // Also reset migration counters when resetting auto increment
            // This ensures migration panel shows correct stats after cleanup
            if ($result !== false) {
                $this->reset_migration_counters();
            }
        } else {
            // DELETE keeps AUTO_INCREMENT
            $result = $wpdb->query("DELETE FROM {$table}");
        }

        return $result;
    }

    /**
     * Reset all migration completion flags
     * Called when all submissions are deleted and counter is reset
     *
     * @return void
     */
    private function reset_migration_counters() {
        global $wpdb;

        // Delete all migration completion flags
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE 'ffc_migration_%_completed'"
        );

        // Clear object cache for affected options
        wp_cache_delete('alloptions', 'options');
    }
    
    /**
     * Reset AUTO_INCREMENT counter
     * 
     * @return int|false Query result
     */
    public function reset_submission_counter() {
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        // Get current max ID
        $max_id = $wpdb->get_var("SELECT MAX(id) FROM {$table}");
        
        if ($max_id === null) {
            // Table is empty, reset to 1
            $next_id = 1;
        } else {
            // Table has data, set to max_id + 1
            $next_id = intval($max_id) + 1;
        }
        
        return $wpdb->query("ALTER TABLE {$table} AUTO_INCREMENT = {$next_id}");
    }
    
    /**
     * Run data cleanup (old submissions)
     */
    public function run_data_cleanup() {
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        $cleanup_days = absint(get_option('ffc_cleanup_days', 0));
        
        if ($cleanup_days <= 0) {
            return 0;
        }
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$cleanup_days} days"));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE submission_date < %s AND status = 'publish'",
            $cutoff_date
        ));
        
        if ($deleted && class_exists('FFC_Activity_Log')) {
            FFC_Activity_Log::log('data_cleanup', FFC_Activity_Log::LEVEL_INFO, [
                'deleted_count' => $deleted,
                'cutoff_date' => $cutoff_date
            ]);
        }
        
        return $deleted;
    }
    
    /**
     * Ensure magic token exists
     */
    public function ensure_magic_token($submission_id) {
        $submission = $this->repository->findById($submission_id);
        
        if (!$submission || !empty($submission['magic_token'])) {
            return true;
        }
        
        $magic_token = $this->generate_magic_token();
        
        return $this->repository->update($submission_id, [
            'magic_token' => $magic_token
        ]);
    }
    
    /**
     * Migration: Move emails to encrypted column
     */
    public function migrate_emails_to_column() {
        if (!class_exists('FFC_Encryption') || !FFC_Encryption::is_configured()) {
            return ['success' => false, 'message' => 'Encryption not configured'];
        }
        
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();
        
        $submissions = $wpdb->get_results(
            "SELECT id, email FROM {$table} WHERE email IS NOT NULL AND email_encrypted IS NULL LIMIT 100",
            ARRAY_A
        );
        
        $migrated = 0;
        
        foreach ($submissions as $sub) {
            if (empty($sub['email'])) continue;
            
            $encrypted = FFC_Encryption::encrypt($sub['email']);
            $hash = FFC_Encryption::hash($sub['email']);
            
            if ($encrypted && $hash) {
                $this->repository->update($sub['id'], [
                    'email_encrypted' => $encrypted,
                    'email_hash' => $hash,
                    'email' => null
                ]);
                
                $migrated++;
            }
        }
        
        return [
            'success' => true,
            'migrated' => $migrated,
            'remaining' => count($submissions) - $migrated
        ];
    }
}