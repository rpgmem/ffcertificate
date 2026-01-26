<?php
/**
 * Submission Repository
 * Handles all database operations for submissions
 *
 * v3.3.0: Added strict types and type hints for better code safety
 * v3.2.0: Migrated to namespace (Phase 2)
 * v3.0.2: Fixed search to work with encrypted data (removed data_encrypted LIKE, added auth_code/magic_token search)
 * v3.0.1: Added methods for CSV export
 * @since 3.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if (!defined('ABSPATH')) exit;

class SubmissionRepository extends AbstractRepository {
    
    protected function get_table_name(): string {
        return $this->wpdb->prefix . 'ffc_submissions';
    }

    protected function get_cache_group(): string {
        return 'ffc_submissions';
    }
    
    /**
     * Find by auth code
     *
     * @param string $auth_code
     * @return array|null|false
     */
    public function findByAuthCode( string $auth_code ) {
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
     *
     * @param string $token
     * @return array|null|false
     */
    public function findByToken( string $token ) {
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
     *
     * @param string $email
     * @param int $limit
     * @return array
     */
    public function findByEmail( string $email, int $limit = 10 ): array {
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
     *
     * @param string $cpf
     * @param int $limit
     * @return array
     */
    public function findByCpfRf( string $cpf, int $limit = 10 ): array {
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
     *
     * @param int $form_id
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function findByFormId( int $form_id, int $limit = 100, int $offset = 0 ): array {
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
     * ✅ NEW v3.0.1: Get all submissions by form_id and status for export
     *
     * @param int|null $form_id Form ID (null = all forms)
     * @param string|null $status Status filter (publish, trash, null = all)
     * @return array Array of submissions
     */
    public function getForExport( ?int $form_id = null, ?string $status = 'publish' ): array {
        $conditions = [];
        
        if ($status) {
            $conditions['status'] = $status;
        }
        
        if ($form_id) {
            $conditions['form_id'] = $form_id;
        }
        
        // Use inherited findAll() method with no limit
        return $this->findAll($conditions, 'id', 'DESC', null, 0);
    }
    
    /**
     * ✅ NEW v3.0.1: Check if any submission has edit information
     *
     * @return bool True if edited_at column exists and has data
     */
    public function hasEditInfo(): bool {
        // Check if edited_at column exists
        $column_exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'edited_at'",
                DB_NAME,
                $this->table
            )
        );

        if ((int) $column_exists === 0) {
            return false;
        }

        // Check if any row has edit data
        $has_data = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE edited_at IS NOT NULL"
        );

        return (int) $has_data > 0;
    }
    
    /**
     * Find with pagination and filters
     * Optimized search for encrypted data (v3.0.2)
     *
     * @param array $args
     * @return array
     */
    public function findPaginated( array $args = [] ): array {
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
            $search_term = $args['search'];
            $search_conditions = [];

            // 1. Search by ID (if numeric)
            if (is_numeric($search_term)) {
                $search_conditions[] = $this->wpdb->prepare("id = %d", intval($search_term));
            }

            // 2. Search by auth_code (exact match, case insensitive)
            $search_conditions[] = $this->wpdb->prepare(
                "UPPER(auth_code) = UPPER(%s)",
                $search_term
            );

            // 3. Search by email/CPF hash (for encrypted data)
            $search_hash = $this->hash($search_term);
            $search_conditions[] = $this->wpdb->prepare("email_hash = %s", $search_hash);
            $search_conditions[] = $this->wpdb->prepare("cpf_rf_hash = %s", $search_hash);

            // 4. Search in unencrypted data field (legacy/fallback)
            // Only search if data column has content (not NULL, not empty)
            $search_conditions[] = $this->wpdb->prepare(
                "(data IS NOT NULL AND data != '' AND data LIKE %s)",
                '%' . $this->wpdb->esc_like($search_term) . '%'
            );

            // 5. Search by magic_token (partial match for admin convenience)
            $search_conditions[] = $this->wpdb->prepare(
                "magic_token LIKE %s",
                '%' . $this->wpdb->esc_like($search_term) . '%'
            );

            // Combine all search conditions with OR
            $where[] = '(' . implode(' OR ', $search_conditions) . ')';
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
     *
     * @return array
     */
    public function countByStatus(): array {
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
     *
     * @param int $id
     * @param string $status
     * @return int|false
     */
    public function updateStatus( int $id, string $status ) {
        return $this->update($id, ['status' => $status]);
    }
    
    /**
     * Bulk update status
     *
     * @param array $ids
     * @param string $status
     * @return int|false
     */
    public function bulkUpdateStatus( array $ids, string $status ) {
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
     *
     * @param array $ids
     * @return int|false
     */
    public function bulkDelete( array $ids ) {
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
     *
     * @param int $form_id
     * @return int|false
     */
    public function deleteByFormId( int $form_id ) {
        $result = $this->wpdb->delete($this->table, ['form_id' => $form_id]);
        
        if ($result) {
            $this->clear_cache();
        }
        
        return $result;
    }
    
    /**
     * ✅ NEW v3.0.1: Update submission with edit tracking
     *
     * @param int $id Submission ID
     * @param array $data Data to update
     * @return int|false Number of rows updated or false on error
     */
    public function updateWithEditTracking( int $id, array $data ) {
        // Check if edited_at column exists
        $column_exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'edited_at'",
                DB_NAME,
                $this->table
            )
        );
        
        if ($column_exists) {
            $data['edited_at'] = current_time('mysql');
            
            // Add edited_by if column exists
            $edited_by_exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = %s 
                    AND TABLE_NAME = %s 
                    AND COLUMN_NAME = 'edited_by'",
                    DB_NAME,
                    $this->table
                )
            );
            
            if ($edited_by_exists) {
                $data['edited_by'] = get_current_user_id();
            }
        }
        
        return $this->update($id, $data);
    }
    
    /**
     * Hash helper
     *
     * @param string $value
     * @return string|null
     */
    private function hash( string $value ): ?string {
        return class_exists('\FFC_Encryption') ? \FFC_Encryption::hash($value) : hash('sha256', $value);
    }
}