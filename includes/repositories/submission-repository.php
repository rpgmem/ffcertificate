<?php
/**
 * Submission Repository
 * Handles all database operations for submissions
 * 
 * @since 3.0.0
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/abstract-repository.php';

class FFC_Submission_Repository extends FFC_Abstract_Repository {
    
    protected function get_table_name() {
        return $this->wpdb->prefix . 'ffc_submissions';
    }
    
    protected function get_cache_group() {
        return 'ffc_submissions';
    }
    
    /**
     * Find by auth code
     */
    public function findByAuthCode($auth_code) {
        $cache_key = "auth_{$auth_code}";
        $cached = $this->get_cache($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE auth_code = %s", $auth_code),
            ARRAY_A
        );
        
        if ($result) {
            $this->set_cache($cache_key, $result);
        }
        
        return $result;
    }
    
    /**
     * Find by magic token
     */
    public function findByToken($token) {
        $cache_key = "token_{$token}";
        $cached = $this->get_cache($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE magic_token = %s", $token),
            ARRAY_A
        );
        
        if ($result) {
            $this->set_cache($cache_key, $result);
        }
        
        return $result;
    }
    
    /**
     * Find by email
     */
    public function findByEmail($email, $limit = 10) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE email = %s OR email_hash = %s ORDER BY id DESC LIMIT %d",
                $email,
                $this->hash($email),
                $limit
            ),
            ARRAY_A
        );
    }
    
    /**
     * Find by CPF/RF
     */
    public function findByCpfRf($cpf, $limit = 10) {
        $clean_cpf = preg_replace('/[^0-9]/', '', $cpf);
        
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE cpf_rf = %s OR cpf_rf_hash = %s ORDER BY id DESC LIMIT %d",
                $clean_cpf,
                $this->hash($clean_cpf),
                $limit
            ),
            ARRAY_A
        );
    }
    
    /**
     * Find by form ID
     */
    public function findByFormId($form_id, $limit = 100, $offset = 0) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE form_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
                $form_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }
    
    /**
     * Find with pagination and filters
     */
    public function findPaginated($args = []) {
        $defaults = [
            'status' => 'publish',
            'search' => '',
            'per_page' => 20,
            'page' => 1,
            'orderby' => 'id',
            'order' => 'DESC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where = [$this->wpdb->prepare("status = %s", $args['status'])];
        
        if (!empty($args['search'])) {
            $search_hash = $this->hash($args['search']);
            $where[] = $this->wpdb->prepare(
                "(email_hash = %s OR cpf_rf_hash = %s OR data LIKE %s OR data_encrypted LIKE %s)",
                $search_hash,
                $search_hash,
                '%' . $this->wpdb->esc_like($args['search']) . '%',
                '%' . $this->wpdb->esc_like($args['search']) . '%'
            );
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where);
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        $items = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} {$where_clause} ORDER BY {$args['orderby']} {$args['order']} LIMIT %d OFFSET %d",
                $args['per_page'],
                $offset
            ),
            ARRAY_A
        );
        
        $total = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table} {$where_clause}");
        
        return [
            'items' => $items,
            'total' => (int) $total,
            'pages' => ceil($total / $args['per_page'])
        ];
    }
    
    /**
     * Count by status
     */
    public function countByStatus() {
        $results = $this->wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status",
            OBJECT_K
        );
        
        return [
            'publish' => isset($results['publish']) ? (int) $results['publish']->count : 0,
            'trash' => isset($results['trash']) ? (int) $results['trash']->count : 0
        ];
    }
    
    /**
     * Update status
     */
    public function updateStatus($id, $status) {
        return $this->update($id, ['status' => $status]);
    }
    
    /**
     * Bulk update status
     */
    public function bulkUpdateStatus($ids, $status) {
        if (empty($ids)) {
            return 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query = $this->wpdb->prepare(
            "UPDATE {$this->table} SET status = %s WHERE id IN ({$placeholders})",
            $status,
            ...$ids
        );
        
        $result = $this->wpdb->query($query);
        
        if ($result) {
            $this->clear_cache();
        }
        
        return $result;
    }
    
    /**
     * Bulk delete
     */
    public function bulkDelete($ids) {
        if (empty($ids)) {
            return 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query = $this->wpdb->prepare(
            "DELETE FROM {$this->table} WHERE id IN ({$placeholders})",
            ...$ids
        );
        
        $result = $this->wpdb->query($query);
        
        if ($result) {
            $this->clear_cache();
        }
        
        return $result;
    }
    
    /**
     * Delete by form ID
     */
    public function deleteByFormId($form_id) {
        $result = $this->wpdb->delete($this->table, ['form_id' => $form_id]);
        
        if ($result) {
            $this->clear_cache();
        }
        
        return $result;
    }
    
    /**
     * Hash helper
     */
    private function hash($value) {
        return class_exists('FFC_Encryption') ? FFC_Encryption::hash($value) : hash('sha256', $value);
    }
}