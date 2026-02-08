<?php
/**
 * Abstract Repository
 * Base class for all repositories
 *
 * @since 3.0.0
 * @version 4.6.10 - Added transaction support (begin/commit/rollback)
 * @version 3.3.0 - Added strict types and type hints for better code safety
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

abstract class AbstractRepository {

    protected $wpdb;
    protected $table;
    protected $cache_group;
    protected $cache_expiration = 3600;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $this->get_table_name();
        $this->cache_group = $this->get_cache_group();
    }

    abstract protected function get_table_name(): string;
    abstract protected function get_cache_group(): string;

    /**
     * Find by ID
     *
     * @param int $id
     * @return array|null|false
     */
    public function findById( int $id ) {
        $cache_key = "id_{$id}";
        $cached = $this->get_cache($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ),
            ARRAY_A
        );

        if ($result) {
            $this->set_cache($cache_key, $result);
        }

        return $result;
    }

    /**
     * Find multiple records by IDs in a single query.
     *
     * @param array $ids Array of integer IDs
     * @return array Associative array keyed by ID => row data
     */
    public function findByIds( array $ids ): array {
        $ids = array_unique( array_filter( array_map( 'intval', $ids ) ) );

        if ( empty( $ids ) ) {
            return [];
        }

        // Check cache first, collect misses
        $results = [];
        $missing = [];
        foreach ( $ids as $id ) {
            $cached = $this->get_cache( "id_{$id}" );
            if ( $cached !== false ) {
                $results[ $id ] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        // Batch load cache misses
        if ( ! empty( $missing ) ) {
            $safe_ids = array_map( 'absint', $missing );
            $id_list  = implode( ',', $safe_ids );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare( "SELECT * FROM %i WHERE id IN ({$id_list})", $this->table ),
                ARRAY_A
            );

            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $row_id = (int) $row['id'];
                    $this->set_cache( "id_{$row_id}", $row );
                    $results[ $row_id ] = $row;
                }
            }
        }

        return $results;
    }

    /**
     * Find all with conditions
     *
     * @param array $conditions
     * @param string $order_by
     * @param string $order
     * @param int|null $limit
     * @param int $offset
     * @return array
     */
    public function findAll( array $conditions = [], string $order_by = 'id', string $order = 'DESC', ?int $limit = null, int $offset = 0 ): array {
        $where = $this->build_where_clause($conditions);
        $order_by = $this->sanitize_order_column( $order_by );
        $order    = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
        if ($limit) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return $this->wpdb->get_results(
                $this->wpdb->prepare( "SELECT * FROM %i {$where} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d", $this->table, $limit, $offset ),
                ARRAY_A
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $this->wpdb->get_results(
            $this->wpdb->prepare( "SELECT * FROM %i {$where} ORDER BY {$order_by} {$order}", $this->table ),
            ARRAY_A
        );
    }

    /**
     * Count rows
     *
     * @param array $conditions
     * @return int
     */
    public function count( array $conditions = [] ): int {
        $where = $this->build_where_clause($conditions);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", $this->table ) );
    }

    /**
     * Insert
     *
     * @param array $data
     * @return int|false Insert ID on success, false on failure
     */
    public function insert( array $data ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $this->wpdb->insert($this->table, $data);

        if ($result) {
            $this->clear_cache();
            return $this->wpdb->insert_id;
        }

        $this->log_db_error( 'insert' );
        return false;
    }

    /**
     * Update
     *
     * @param int $id
     * @param array $data
     * @return int|false Number of rows updated, or false on error
     */
    public function update( int $id, array $data ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->update(
            $this->table,
            $data,
            ['id' => $id]
        );

        if ($result !== false) {
            $this->clear_cache("id_{$id}");
        } else {
            $this->log_db_error( 'update', $id );
        }

        return $result;
    }

    /**
     * Delete
     *
     * @param int $id
     * @return int|false Number of rows deleted, or false on error
     */
    public function delete( int $id ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->delete($this->table, ['id' => $id]);

        if ($result) {
            $this->clear_cache("id_{$id}");
        } elseif ( $result === false ) {
            $this->log_db_error( 'delete', $id );
        }

        return $result;
    }

    /**
     * Sanitize ORDER BY column name against an allowlist.
     *
     * @param string $column Requested column name
     * @return string Sanitized column name (defaults to 'id' if not allowed)
     */
    protected function sanitize_order_column( string $column ): string {
        $allowed = $this->get_allowed_order_columns();
        return in_array( $column, $allowed, true ) ? $column : 'id';
    }

    /**
     * Get allowed ORDER BY columns. Override in child classes to extend.
     *
     * @return array
     */
    protected function get_allowed_order_columns(): array {
        return [ 'id', 'created_at', 'updated_at', 'status' ];
    }

    /**
     * Build WHERE clause
     *
     * @param array $conditions
     * @return string
     */
    protected function build_where_clause( array $conditions ): string {
        if (empty($conditions)) {
            return '';
        }

        $where_parts = [];
        foreach ($conditions as $key => $value) {
            if (is_array($value)) {
                $placeholders = implode(',', array_fill(0, count($value), '%s'));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $where_parts[] = $this->wpdb->prepare("{$key} IN ({$placeholders})", ...$value);
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $where_parts[] = $this->wpdb->prepare("{$key} = %s", $value);
            }
        }

        return 'WHERE ' . implode(' AND ', $where_parts);
    }

    /**
     * Cache methods
     *
     * @param string $key
     * @return mixed
     */
    protected function get_cache( string $key ) {
        return wp_cache_get($key, $this->cache_group);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    protected function set_cache( string $key, $value ): bool {
        return wp_cache_set($key, $value, $this->cache_group, $this->cache_expiration);
    }

    /**
     * @param string|null $key
     * @return void
     */
    protected function clear_cache( ?string $key = null ): void {
        if ($key) {
            wp_cache_delete($key, $this->cache_group);
        } else {
            wp_cache_flush();
        }
    }

    /**
     * Start a database transaction.
     *
     * @since 4.6.10
     * @return bool
     */
    public function begin_transaction(): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->query( 'START TRANSACTION' ) !== false;
    }

    /**
     * Commit the current transaction.
     *
     * @since 4.6.10
     * @return bool
     */
    public function commit(): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->query( 'COMMIT' ) !== false;
    }

    /**
     * Rollback the current transaction.
     *
     * @since 4.6.10
     * @return bool
     */
    public function rollback(): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->query( 'ROLLBACK' ) !== false;
    }

    /**
     * Log database error from $wpdb->last_error.
     *
     * @since 4.6.6
     * @param string   $operation Operation name (insert, update, delete).
     * @param int|null $id        Record ID if applicable.
     */
    protected function log_db_error( string $operation, ?int $id = null ): void {
        if ( empty( $this->wpdb->last_error ) ) {
            return;
        }

        if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
            \FreeFormCertificate\Core\Utils::debug_log( "Database {$operation} failed", array(
                'table' => $this->table,
                'id'    => $id,
                'error' => $this->wpdb->last_error,
            ) );
        }
    }
}
