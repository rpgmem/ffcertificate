<?php
/**
 * Abstract Repository
 * Base class for all repositories
 * 
 * @since 3.0.0
 */

if (!defined('ABSPATH')) exit;

abstract class FFC_Abstract_Repository {
    
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
    
    abstract protected function get_table_name();
    abstract protected function get_cache_group();
    
    /**
     * Find by ID
     */
    public function findById($id) {
        $cache_key = "id_{$id}";
        $cached = $this->get_cache($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
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
     */
    public function findAll($conditions = [], $order_by = 'id', $order = 'DESC', $limit = null, $offset = 0) {
        $where = $this->build_where_clause($conditions);
        $sql = "SELECT * FROM {$this->table} {$where} ORDER BY {$order_by} {$order}";
        
        if ($limit) {
            $sql = $this->wpdb->prepare($sql . " LIMIT %d OFFSET %d", $limit, $offset);
        }
        
        return $this->wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Count rows
     */
    public function count($conditions = []) {
        $where = $this->build_where_clause($conditions);
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table} {$where}");
    }
    
    /**
     * Insert
     */
    public function insert($data) {
        $result = $this->wpdb->insert($this->table, $data);
        
        if ($result) {
            $this->clear_cache();
            return $this->wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Update
     */
    public function update($id, $data) {
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
     */
    public function delete($id) {
        $result = $this->wpdb->delete($this->table, ['id' => $id]);
        
        if ($result) {
            $this->clear_cache("id_{$id}");
        }
        
        return $result;
    }
    
    /**
     * Build WHERE clause
     */
    protected function build_where_clause($conditions) {
        if (empty($conditions)) {
            return '';
        }
        
        $where_parts = [];
        foreach ($conditions as $key => $value) {
            if (is_array($value)) {
                $placeholders = implode(',', array_fill(0, count($value), '%s'));
                $where_parts[] = $this->wpdb->prepare("{$key} IN ({$placeholders})", ...$value);
            } else {
                $where_parts[] = $this->wpdb->prepare("{$key} = %s", $value);
            }
        }
        
        return 'WHERE ' . implode(' AND ', $where_parts);
    }
    
    /**
     * Cache methods
     */
    protected function get_cache($key) {
        return wp_cache_get($key, $this->cache_group);
    }
    
    protected function set_cache($key, $value) {
        wp_cache_set($key, $value, $this->cache_group, $this->cache_expiration);
    }
    
    protected function clear_cache($key = null) {
        if ($key) {
            wp_cache_delete($key, $this->cache_group);
        } else {
            wp_cache_flush();
        }
    }
}