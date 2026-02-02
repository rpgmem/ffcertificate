<?php
/**
 * Abstract Repository
 * Base class for all repositories
 *
 * @since 3.0.0
 * @version 3.3.0 - Added strict types and type hints for better code safety
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) { exit; }

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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );

        if ($result) {
            $this->set_cache($cache_key, $result);
        }

        return $result;
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
        $sql = "SELECT * FROM {$this->table} {$where} ORDER BY {$order_by} {$order}";

        if ($limit) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $sql = $this->wpdb->prepare($sql . " LIMIT %d OFFSET %d", $limit, $offset);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Count rows
     *
     * @param array $conditions
     * @return int
     */
    public function count( array $conditions = [] ): int {
        $where = $this->build_where_clause($conditions);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table} {$where}");
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
        }

        return $result;
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
}
