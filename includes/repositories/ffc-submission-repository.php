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

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

class SubmissionRepository extends AbstractRepository {

    /**
     * Cached column existence checks to avoid repeated INFORMATION_SCHEMA queries
     *
     * @since 4.6.13
     * @var array<string, bool>
     */
    private static array $column_exists_cache = array();

    /**
     * Check if a column exists in the submissions table (cached per request)
     *
     * @since 4.6.13
     * @param string $column_name Column name to check
     * @return bool
     */
    private function column_exists( string $column_name ): bool {
        $cache_key = $this->table . '.' . $column_name;
        if ( isset( self::$column_exists_cache[ $cache_key ] ) ) {
            return self::$column_exists_cache[ $cache_key ];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = (bool) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s
                AND COLUMN_NAME = %s",
                DB_NAME,
                $this->table,
                $column_name
            )
        );

        self::$column_exists_cache[ $cache_key ] = $result;
        return $result;
    }

    protected function get_table_name(): string {
        return $this->wpdb->prefix . 'ffc_submissions';
    }

    protected function get_cache_group(): string {
        return 'ffc_submissions';
    }

    /**
     * @return array<int, string>
     */
    protected function get_allowed_order_columns(): array {
        return [ 'id', 'form_id', 'auth_code', 'status', 'submission_date', 'created_at', 'updated_at' ];
    }

    /**
     * Find by auth code
     *
     * @param string $auth_code
     * @return array<string, mixed>|null
     */
    public function findByAuthCode( string $auth_code ) {
        $cache_key = "auth_{$auth_code}";
        $cached = $this->get_cache($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare( 'SELECT * FROM %i WHERE auth_code = %s', $this->table, $auth_code ),
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
     * @return array<string, mixed>|null
     */
    public function findByToken( string $token ) {
        $cache_key = "token_{$token}";
        $cached = $this->get_cache($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare( 'SELECT * FROM %i WHERE magic_token = %s', $this->table, $token ),
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
     * @return array<int, array<string, mixed>>
     */
    public function findByEmail( string $email, int $limit = 10 ): array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE email_hash = %s ORDER BY id DESC LIMIT %d',
                $this->table,
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
     * @return array<int, array<string, mixed>>
     */
    public function findByCpfRf( string $cpf, int $limit = 10 ): array {
        $clean_cpf = preg_replace('/[^0-9]/', '', $cpf);
        $id_hash = $this->hash($clean_cpf);
        $hash_column = strlen( $clean_cpf ) === 7 ? 'rf_hash' : 'cpf_hash';

        // Search the specific split column based on digit count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM %i WHERE {$hash_column} = %s ORDER BY id DESC LIMIT %d",
                $this->table,
                $id_hash,
                $limit
            ),
            ARRAY_A
        );

        return $results;
    }

    /**
     * Find by form ID
     *
     * @param int $form_id
     * @param int $limit
     * @param int $offset
     * @return array<int, array<string, mixed>>
     */
    public function findByFormId( int $form_id, int $limit = 100, int $offset = 0 ): array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE form_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
                $this->table,
                $form_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }

    /**
     * ✅ NEW v4.0.0: Get all submissions by form_id(s) and status for export
     *
     * @param int|array<int, int>|null $form_ids Single form ID, array of IDs, or null for all forms
     * @param string|null $status Status filter (publish, trash, null = all)
     * @return array<int, array<string, mixed>> Array of submissions
     */
    public function getForExport( $form_ids = null, ?string $status = 'publish' ): array {
        // Handle multiple form IDs with custom query
        if ( is_array( $form_ids ) && !empty( $form_ids ) ) {
            $form_ids_int = array_map( 'absint', $form_ids );
            $form_ids_placeholders = implode( ', ', array_fill( 0, count( $form_ids_int ), '%d' ) );

            $where = [];
            $prepare_args = [];

            // Add form_id filter
            $where[] = "form_id IN ({$form_ids_placeholders})";
            $prepare_args = array_merge( $prepare_args, $form_ids_int );

            // Add status filter
            if ( $status ) {
                $where[] = "status = %s";
                $prepare_args[] = $status;
            }

            $where_clause = 'WHERE ' . implode( ' AND ', $where );

            $prepare_args = array_merge( [ $this->table ], $prepare_args );
            $query = "SELECT * FROM %i {$where_clause} ORDER BY id DESC";

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $query = $this->wpdb->prepare( $query, ...$prepare_args );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return $this->wpdb->get_results( $query, ARRAY_A );
        }

        // Single form ID or no filter - use existing logic
        $conditions = [];

        if ( $status ) {
            $conditions['status'] = $status;
        }

        if ( is_int( $form_ids ) ) {
            $conditions['form_id'] = $form_ids;
        }

        // Use inherited findAll() method with no limit
        return $this->findAll( $conditions, 'id', 'DESC', null, 0 );
    }

    /**
     * Build WHERE clause and prepare args for export queries.
     *
     * @since 5.0.0
     * @param array<int, int>|null $form_ids Form IDs filter.
     * @param string|null          $status   Status filter.
     * @return array{string, array<int, mixed>} [where_clause, prepare_args] — args include table as first element.
     */
    private function build_export_where( ?array $form_ids, ?string $status ): array {
        $where = array();
        $prepare_args = array( $this->table );

        if ( ! empty( $form_ids ) ) {
            $form_ids_int = array_map( 'absint', $form_ids );
            $placeholders = implode( ', ', array_fill( 0, count( $form_ids_int ), '%d' ) );
            $where[] = "form_id IN ({$placeholders})";
            $prepare_args = array_merge( $prepare_args, $form_ids_int );
        }

        if ( $status ) {
            $where[] = 'status = %s';
            $prepare_args[] = $status;
        }

        $where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        return array( $where_clause, $prepare_args );
    }

    /**
     * Get a batch of submissions for export using cursor-based pagination.
     *
     * Uses `id < $cursor` instead of OFFSET for O(1) index seeks regardless of page depth.
     *
     * @since 5.0.0
     * @param array<int, int>|null $form_ids  Form IDs filter (null = all forms).
     * @param string|null          $status    Status filter.
     * @param int                  $cursor_id Cursor: fetch rows with id < this value. Use PHP_INT_MAX for first batch.
     * @param int                  $limit     Batch size.
     * @return array<int, array<string, mixed>>
     */
    public function getExportBatch( ?array $form_ids, ?string $status, int $cursor_id, int $limit ): array {
        list( $where_clause, $prepare_args ) = $this->build_export_where( $form_ids, $status );

        // Append cursor condition
        $cursor_condition = 'id < %d';
        if ( $where_clause === '' ) {
            $where_clause = 'WHERE ' . $cursor_condition;
        } else {
            $where_clause .= ' AND ' . $cursor_condition;
        }
        $prepare_args[] = $cursor_id;
        $prepare_args[] = $limit;

        $query = "SELECT * FROM %i {$where_clause} ORDER BY id DESC LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query = $this->wpdb->prepare( $query, ...$prepare_args );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results( $query, ARRAY_A );
    }

    /**
     * Get only JSON data columns in batches for dynamic-key discovery.
     *
     * Much lighter than SELECT * — skips all encrypted/heavy columns.
     *
     * @since 5.0.0
     * @param array<int, int>|null $form_ids  Form IDs filter.
     * @param string|null          $status    Status filter.
     * @param int                  $cursor_id Cursor: fetch rows with id < this value.
     * @param int                  $limit     Batch size.
     * @return array<int, array<string, mixed>>
     */
    public function getExportKeysBatch( ?array $form_ids, ?string $status, int $cursor_id, int $limit ): array {
        list( $where_clause, $prepare_args ) = $this->build_export_where( $form_ids, $status );

        $cursor_condition = 'id < %d';
        if ( $where_clause === '' ) {
            $where_clause = 'WHERE ' . $cursor_condition;
        } else {
            $where_clause .= ' AND ' . $cursor_condition;
        }
        $prepare_args[] = $cursor_id;
        $prepare_args[] = $limit;

        $query = "SELECT id, data, data_encrypted FROM %i {$where_clause} ORDER BY id DESC LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query = $this->wpdb->prepare( $query, ...$prepare_args );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results( $query, ARRAY_A );
    }

    /**
     * Count total matching rows for export progress reporting.
     *
     * @since 5.0.0
     * @param array<int, int>|null $form_ids Form IDs filter.
     * @param string|null          $status   Status filter.
     * @return int
     */
    public function countForExport( ?array $form_ids, ?string $status ): int {
        list( $where_clause, $prepare_args ) = $this->build_export_where( $form_ids, $status );

        $query = "SELECT COUNT(*) FROM %i {$where_clause}";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query = $this->wpdb->prepare( $query, ...$prepare_args );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $this->wpdb->get_var( $query );
    }

    /**
     * ✅ NEW v3.0.1: Check if any submission has edit information
     *
     * @return bool True if edited_at column exists and has data
     */
    public function hasEditInfo(): bool {
        // Check if edited_at column exists (cached per request)
        if ( ! $this->column_exists( 'edited_at' ) ) {
            return false;
        }

        // Check if any row has edit data
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $has_data = $this->wpdb->get_var(
            $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE edited_at IS NOT NULL', $this->table )
        );

        return (int) $has_data > 0;
    }

    /**
     * Find with pagination and filters
     * Optimized search for encrypted data (v3.0.2)
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function findPaginated( array $args = [] ): array {
        $defaults = [
            'status' => 'publish',
            'search' => '',
            'per_page' => 20,
            'page' => 1,
            'orderby' => 'id',
            'order' => 'DESC',
            'form_ids' => []
        ];

        $args = wp_parse_args($args, $defaults);

        $where = [$this->wpdb->prepare("status = %s", $args['status'])];

        if ( !empty( $args['form_ids'] ) && is_array( $args['form_ids'] ) ) {
            $form_ids_int = array_map( 'absint', $args['form_ids'] );
            $form_ids_placeholders = implode( ', ', array_fill( 0, count( $form_ids_int ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $where[] = $this->wpdb->prepare(
                "form_id IN ({$form_ids_placeholders})",
                ...$form_ids_int
            );
        }

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

            // 3. Search by email/CPF/RF hash (for encrypted data)
            $search_hash = $this->hash($search_term);
            $search_conditions[] = $this->wpdb->prepare("email_hash = %s", $search_hash);
            $search_conditions[] = $this->wpdb->prepare("cpf_hash = %s", $search_hash);
            $search_conditions[] = $this->wpdb->prepare("rf_hash = %s", $search_hash);

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
        $orderby = $this->sanitize_order_column( $args['orderby'] );
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $items = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM %i {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $this->table,
                $args['per_page'],
                $offset
            ),
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM %i {$where_clause}", $this->table ) );

        return [
            'items' => $items,
            'total' => (int) $total,
            'pages' => ceil($total / $args['per_page'])
        ];
    }

    /**
     * Count by status
     *
     * @return array<string, int>
     */
    public function countByStatus(): array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare( 'SELECT status, COUNT(*) as count FROM %i GROUP BY status', $this->table ),
            OBJECT_K
        );

        return [
            'publish' => isset($results['publish']) ? (int) $results['publish']->count : 0,
            'trash' => isset($results['trash']) ? (int) $results['trash']->count : 0,
            'quiz_in_progress' => isset($results['quiz_in_progress']) ? (int) $results['quiz_in_progress']->count : 0,
            'quiz_failed' => isset($results['quiz_failed']) ? (int) $results['quiz_failed']->count : 0,
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
     * @param array<int, int> $ids
     * @param string $status
     * @return int|false
     */
    public function bulkUpdateStatus( array $ids, string $status ) {
        if (empty($ids)) {
            return 0;
        }

        $safe_ids     = array_map( 'absint', $ids );
        $placeholders = implode( ', ', array_fill( 0, count( $safe_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated via array_fill().
        $query = $this->wpdb->prepare(
            "UPDATE %i SET status = %s WHERE id IN ({$placeholders})",
            $this->table,
            $status,
            ...$safe_ids
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->query($query);

        if ($result) {
            $this->clear_cache();
        }

        return $result;
    }

    /**
     * Bulk delete
     *
     * @param array<int, int> $ids
     * @return int|false
     */
    public function bulkDelete( array $ids ) {
        if (empty($ids)) {
            return 0;
        }

        $safe_ids     = array_map( 'absint', $ids );
        $placeholders = implode( ', ', array_fill( 0, count( $safe_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated via array_fill().
        $query = $this->wpdb->prepare(
            "DELETE FROM %i WHERE id IN ({$placeholders})",
            $this->table,
            ...$safe_ids
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
     * @param array<string, mixed> $data Data to update
     * @return int|false Number of rows updated or false on error
     */
    public function updateWithEditTracking( int $id, array $data ) {
        // Check if edited_at column exists (cached per request)
        if ( $this->column_exists( 'edited_at' ) ) {
            $data['edited_at'] = current_time('mysql');

            // Add edited_by if column exists (cached per request)
            if ( $this->column_exists( 'edited_by' ) ) {
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
        return class_exists('\FreeFormCertificate\Core\Encryption') ? \FreeFormCertificate\Core\Encryption::hash($value) : hash('sha256', $value);
    }
}
