<?php
declare(strict_types=1);

/**
 * Database Helper Trait
 *
 * Shared database schema utilities used across Activator, SelfSchedulingActivator,
 * AudienceActivator and RateLimitActivator.
 *
 * Eliminates duplicated code for:
 * - Table existence checks
 * - Column existence checks (consistent method)
 * - Column migration (add column + optional index)
 * - Index existence checks
 * - Composite index creation
 *
 * @since 4.11.2
 * @package FreeFormCertificate\Core
 */

namespace FreeFormCertificate\Core;

if (!defined('ABSPATH')) {
    exit;
}

trait DatabaseHelperTrait {

    /**
     * Check if a database table exists.
     *
     * @param string $table_name Full table name (including prefix).
     * @return bool
     */
    protected static function table_exists(string $table_name): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
    }

    /**
     * Check if a column exists in a table.
     *
     * Uses SHOW COLUMNS consistently (avoids INFORMATION_SCHEMA inconsistencies).
     *
     * @param string $table_name  Full table name.
     * @param string $column_name Column name.
     * @return bool
     */
    protected static function column_exists(string $table_name, string $column_name): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_name} LIKE %s", $column_name));
        return !empty($result);
    }

    /**
     * Add a column to a table if it doesn't already exist.
     *
     * @param string      $table_name  Full table name.
     * @param string      $column_name Column name.
     * @param string      $type        Column type (e.g. 'VARCHAR(255) DEFAULT NULL').
     * @param string|null $after       Column to place after (optional).
     * @param string|null $index_name  Index name to create (optional, e.g. 'idx_column').
     * @return bool True if column was added, false if already existed.
     */
    protected static function add_column_if_missing(
        string $table_name,
        string $column_name,
        string $type,
        ?string $after = null,
        ?string $index_name = null
    ): bool {
        if (self::column_exists($table_name, $column_name)) {
            return false;
        }

        global $wpdb;
        $after_sql = $after ? "AFTER {$after}" : '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN {$column_name} {$type} {$after_sql}");

        if ($index_name) {
            self::add_index_if_missing($table_name, $index_name, "({$column_name})");
        }

        return true;
    }

    /**
     * Check if an index exists on a table.
     *
     * @param string $table_name Full table name.
     * @param string $index_name Index name (Key_name).
     * @return bool
     */
    protected static function index_exists(string $table_name, string $index_name): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM %i WHERE Key_name = %s", $table_name, $index_name));
        return !empty($result);
    }

    /**
     * Add an index to a table if it doesn't already exist.
     *
     * @param string $table_name Full table name.
     * @param string $index_name Index name.
     * @param string $columns    Column specification (e.g. '(form_id, status)').
     * @return bool True if index was added, false if already existed.
     */
    protected static function add_index_if_missing(string $table_name, string $index_name, string $columns): bool {
        if (self::index_exists($table_name, $index_name)) {
            return false;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query("ALTER TABLE {$table_name} ADD INDEX {$index_name} {$columns}");
        return true;
    }

    /**
     * Add multiple columns to a table from a configuration array.
     *
     * Each column config must have a 'type' key, and optionally 'after' and 'index'.
     *
     * @param string                $table_name Full table name.
     * @param array<string, array{type: string, after?: string, index?: string}> $columns
     * @return int Number of columns added.
     */
    protected static function add_columns_if_missing(string $table_name, array $columns): int {
        $added = 0;
        foreach ($columns as $column_name => $config) {
            $index_name = isset($config['index']) ? "idx_{$config['index']}" : null;
            if (self::add_column_if_missing(
                $table_name,
                $column_name,
                $config['type'],
                $config['after'] ?? null,
                $index_name
            )) {
                $added++;
            }
        }
        return $added;
    }

    /**
     * Add multiple indexes to a table from a configuration array.
     *
     * @param string               $table_name Full table name.
     * @param array<string, string> $indexes   Index name => column specification.
     * @return int Number of indexes added.
     */
    protected static function add_indexes_if_missing(string $table_name, array $indexes): int {
        $added = 0;
        foreach ($indexes as $index_name => $columns) {
            if (self::add_index_if_missing($table_name, $index_name, $columns)) {
                $added++;
            }
        }
        return $added;
    }
}
