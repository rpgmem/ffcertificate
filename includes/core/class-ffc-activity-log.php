<?php
declare(strict_types=1);

/**
 * ActivityLog
 * Tracks important activities for audit and debugging
 *
 * Features:
 * - Multiple log levels (info, warning, error, debug)
 * - Automatic context capture (user, IP, timestamp)
 * - Query helpers for admin dashboard
 * - Automatic table creation on activation
 * - Cleanup of old logs
 * - 8 actively used convenience methods (v3.1.3: added trashed/restored)
 * - LGPD-specific logging methods (v2.10.0)
 * - Optional context encryption (v2.10.0)
 * - Toggle on/off via Settings > General (v3.1.1)
 * - Column caching to avoid repeated DESCRIBE queries (v3.1.2)
 * - Bulk operation support with temporary logging suspension (v3.1.2)
 * - Fixed: Admin settings now properly enforced (v3.1.4)
 * - Batch write buffer with shutdown flush (v4.6.9)
 * - Automatic cleanup via daily cron (v4.6.9)
 * - Stats caching with transient (v4.6.9)
 *
 * @version 4.6.9 - Batch writes, auto-cleanup, stats caching
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since 2.9.1
 */

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

class ActivityLog {

    /**
     * Log levels
     */
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_DEBUG = 'debug';

    /**
     * Cache for table columns (performance optimization)
     * Prevents repeated DESCRIBE queries on each log
     * @var array|null
     */
    private static $table_columns_cache = null;

    /**
     * Flag to temporarily disable logging (for bulk operations)
     * @var bool
     */
    private static $logging_disabled = false;

    /**
     * Write buffer for batch inserts
     * @var array
     */
    private static array $write_buffer = [];

    /**
     * Whether shutdown hook is registered
     * @var bool
     */
    private static bool $shutdown_registered = false;

    /**
     * Max entries before auto-flushing buffer
     */
    private const BUFFER_THRESHOLD = 20;

    /**
     * Log an activity
     *
     * @param string $action Action performed (e.g., 'submission_created', 'pdf_generated')
     * @param string $level Log level (info, warning, error, debug)
     * @param array $context Additional context data
     * @param int $user_id User ID (0 for anonymous/system)
     * @param int $submission_id Submission ID (0 if not related to submission) - v2.10.0
     * @return bool Success
     */
    public static function log( string $action, string $level = self::LEVEL_INFO, array $context = array(), int $user_id = 0, int $submission_id = 0 ): bool {
        // CRITICAL: Check admin settings FIRST (before temporary flag)
        $settings = get_option( 'ffc_settings', array() );
        $is_enabled = isset( $settings['enable_activity_log'] ) && absint( $settings['enable_activity_log'] ) === 1;

        if ( ! $is_enabled ) {
            return false;
        }

        // Check if logging is temporarily disabled (bulk operations)
        if ( self::$logging_disabled ) {
            return false;
        }

        // Validate level
        $valid_levels = array( self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR, self::LEVEL_DEBUG );
        if ( ! in_array( $level, $valid_levels ) ) {
            $level = self::LEVEL_INFO;
        }

        // Encrypt context if contains sensitive data
        $context_json = wp_json_encode( $context );
        $context_encrypted = null;

        if ( class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
            $sensitive_actions = array(
                'submission_created',
                'data_accessed',
                'data_modified',
                'admin_searched',
                'encryption_migration_batch'
            );

            if ( in_array( $action, $sensitive_actions ) ) {
                $context_encrypted = \FreeFormCertificate\Core\Encryption::encrypt( $context_json );
            }
        }

        // Prepare log entry for buffer
        $log_data = array(
            'action' => sanitize_text_field( $action ),
            'level' => sanitize_key( $level ),
            'context' => $context_json,
            'context_encrypted' => $context_encrypted,
            'user_id' => absint( $user_id ),
            'user_ip' => \FreeFormCertificate\Core\Utils::get_user_ip(),
            'submission_id' => absint( $submission_id ),
            'created_at' => current_time( 'mysql' )
        );

        // Add to write buffer
        self::$write_buffer[] = $log_data;

        // Register shutdown hook on first buffered entry
        if ( ! self::$shutdown_registered ) {
            add_action( 'shutdown', [ self::class, 'flush_buffer' ] );
            self::$shutdown_registered = true;
        }

        // Auto-flush when buffer reaches threshold
        if ( count( self::$write_buffer ) >= self::BUFFER_THRESHOLD ) {
            self::flush_buffer();
        }

        // Debug system logging (immediate, lightweight)
        if ( class_exists( '\\FreeFormCertificate\\Core\\Debug' ) ) {
            \FreeFormCertificate\Core\Debug::log_activity_log( $action, array(
                'level' => strtoupper( $level ),
                'user_id' => $user_id,
                'ip' => $log_data['user_ip'],
                'submission_id' => $submission_id,
                'context' => $context
            ) );
        }

        return true;
    }

    /**
     * Flush the write buffer to database using a single multi-row INSERT
     *
     * Called automatically on shutdown or when buffer reaches threshold.
     *
     * @since 4.6.9
     * @return int Number of rows inserted
     */
    public static function flush_buffer(): int {
        if ( empty( self::$write_buffer ) ) {
            return 0;
        }

        // Re-check admin setting before flushing — entries may have been
        // buffered while logging was enabled, then disabled before shutdown.
        if ( ! self::is_enabled() ) {
            self::$write_buffer = [];
            return 0;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';

        // Get available columns (cached)
        $columns = self::get_table_columns( $table_name );
        $has_submission_id = in_array( 'submission_id', $columns );
        $has_context_encrypted = in_array( 'context_encrypted', $columns );

        // Build column list based on available columns
        $insert_columns = [ 'action', 'level', 'context', 'user_id', 'user_ip', 'created_at' ];
        if ( $has_submission_id ) {
            $insert_columns[] = 'submission_id';
        }
        if ( $has_context_encrypted ) {
            $insert_columns[] = 'context_encrypted';
        }

        $column_list = implode( ', ', $insert_columns );
        $placeholders = [];
        $values = [];

        foreach ( self::$write_buffer as $entry ) {
            $row_placeholders = [ '%s', '%s', '%s', '%d', '%s', '%s' ];
            $row_values = [
                $entry['action'],
                $entry['level'],
                $entry['context'],
                $entry['user_id'],
                $entry['user_ip'],
                $entry['created_at'],
            ];

            if ( $has_submission_id ) {
                $row_placeholders[] = '%d';
                $row_values[] = $entry['submission_id'];
            }

            if ( $has_context_encrypted ) {
                $row_placeholders[] = '%s';
                $row_values[] = $entry['context_encrypted'] ?? '';
            }

            $placeholders[] = '(' . implode( ', ', $row_placeholders ) . ')';
            $values = array_merge( $values, $row_values );
        }

        $count = count( self::$write_buffer );
        self::$write_buffer = [];

        $placeholders_sql = implode( ', ', $placeholders );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table_name} ({$column_list}) VALUES {$placeholders_sql}",
            $values
        ) );

        return $count;
    }

    /**
     * Create activity log table
     * Called during plugin activation
     *
     * @return bool Success
     */
    public static function create_table(): bool {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';
        $charset_collate = $wpdb->get_charset_collate();

        // Check if table already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name ) {
            return true; // Table exists
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action varchar(100) NOT NULL,
            level varchar(20) NOT NULL DEFAULT 'info',
            context longtext,
            user_id bigint(20) unsigned DEFAULT 0,
            user_ip varchar(100),
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY action (action),
            KEY level (level),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY user_ip (user_ip)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta( $sql );

        return true;
    }

    /**
     * Get recent activities with filters
     *
     * @param array $args Query arguments
     * @return array Activities
     */
    public static function get_activities( array $args = array() ): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';

        // Default arguments
        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'level' => null,
            'action' => null,
            'user_id' => null,
            'user_ip' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null,
            'orderby' => 'created_at',
            'order' => 'DESC'
        );

        $args = wp_parse_args( $args, $defaults );

        // Build WHERE clause
        $where = array( '1=1' );

        if ( $args['level'] ) {
            $where[] = $wpdb->prepare( 'level = %s', sanitize_key( $args['level'] ) );
        }

        if ( $args['action'] ) {
            $where[] = $wpdb->prepare( 'action = %s', sanitize_text_field( $args['action'] ) );
        }

        if ( $args['user_id'] ) {
            $where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
        }

        if ( $args['user_ip'] ) {
            $where[] = $wpdb->prepare( 'user_ip = %s', sanitize_text_field( $args['user_ip'] ) );
        }

        if ( $args['date_from'] ) {
            $where[] = $wpdb->prepare( 'created_at >= %s', sanitize_text_field( $args['date_from'] ) );
        }

        if ( $args['date_to'] ) {
            $where[] = $wpdb->prepare( 'created_at <= %s', sanitize_text_field( $args['date_to'] ) );
        }

        if ( $args['search'] ) {
            $search = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
        }

        $where_clause = implode( ' AND ', $where );

        // Validate orderby
        $allowed_orderby = array( 'id', 'action', 'level', 'user_id', 'user_ip', 'created_at' );
        $orderby = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'created_at';

        // Validate order
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        // Execute query
        $query = "SELECT * FROM {$table_name}
                  WHERE {$where_clause}
                  ORDER BY {$orderby} {$order}
                  LIMIT {$args['offset']}, {$args['limit']}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $results = $wpdb->get_results( $query, ARRAY_A );

        // Decode context JSON
        foreach ( $results as &$result ) {
            $result['context'] = json_decode( $result['context'], true );
            if ( ! is_array( $result['context'] ) ) {
                $result['context'] = array();
            }
        }

        return $results;
    }

    /**
     * Get activity count with filters
     *
     * @param array $args Same as get_activities()
     * @return int Count
     */
    public static function count_activities( array $args = array() ): int {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';

        // Use same WHERE logic as get_activities
        $defaults = array(
            'level' => null,
            'action' => null,
            'user_id' => null,
            'user_ip' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null
        );

        $args = wp_parse_args( $args, $defaults );

        $where = array( '1=1' );

        if ( $args['level'] ) {
            $where[] = $wpdb->prepare( 'level = %s', sanitize_key( $args['level'] ) );
        }

        if ( $args['action'] ) {
            $where[] = $wpdb->prepare( 'action = %s', sanitize_text_field( $args['action'] ) );
        }

        if ( $args['user_id'] ) {
            $where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
        }

        if ( $args['user_ip'] ) {
            $where[] = $wpdb->prepare( 'user_ip = %s', sanitize_text_field( $args['user_ip'] ) );
        }

        if ( $args['date_from'] ) {
            $where[] = $wpdb->prepare( 'created_at >= %s', sanitize_text_field( $args['date_from'] ) );
        }

        if ( $args['date_to'] ) {
            $where[] = $wpdb->prepare( 'created_at <= %s', sanitize_text_field( $args['date_to'] ) );
        }

        if ( $args['search'] ) {
            $search = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
        }

        $where_clause = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}" );
    }

    /**
     * Clean old logs
     *
     * @param int $days Keep logs from last N days (default: 90)
     * @return int Number of deleted rows
     */
    public static function cleanup( int $days = 90 ): int {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';

        $cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $cutoff_date
        ) );

        // Invalidate stats cache after cleanup
        delete_transient( 'ffc_activity_stats_7' );
        delete_transient( 'ffc_activity_stats_30' );
        delete_transient( 'ffc_activity_stats_90' );

        return (int) $deleted;
    }

    /**
     * Run automatic log cleanup (called by daily cron)
     *
     * Uses configurable retention period from settings, defaults to 90 days.
     *
     * @since 4.6.9
     * @return int Number of deleted rows
     */
    public static function run_cleanup(): int {
        $settings = get_option( 'ffc_settings', array() );
        $retention_days = isset( $settings['activity_log_retention_days'] )
            ? absint( $settings['activity_log_retention_days'] )
            : 90;

        if ( $retention_days <= 0 ) {
            return 0; // Retention disabled (keep indefinitely)
        }

        return self::cleanup( $retention_days );
    }

    /**
     * Get statistics
     *
     * @param int $days Number of days to analyze (default: 30)
     * @return array Statistics
     */
    public static function get_stats( int $days = 30 ): array {
        // Check transient cache first
        $cache_key = 'ffc_activity_stats_' . $days;
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';

        $date_from = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // Total activities
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s",
            $date_from
        ) );

        // By level
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $by_level = $wpdb->get_results( $wpdb->prepare(
            "SELECT level, COUNT(*) as count FROM {$table_name}
             WHERE created_at >= %s
             GROUP BY level",
            $date_from
        ), ARRAY_A );

        // Top actions
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $top_actions = $wpdb->get_results( $wpdb->prepare(
            "SELECT action, COUNT(*) as count FROM {$table_name}
             WHERE created_at >= %s
             GROUP BY action
             ORDER BY count DESC
             LIMIT 10",
            $date_from
        ), ARRAY_A );

        // Top users
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $top_users = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, COUNT(*) as count FROM {$table_name}
             WHERE created_at >= %s AND user_id > 0
             GROUP BY user_id
             ORDER BY count DESC
             LIMIT 10",
            $date_from
        ), ARRAY_A );

        $stats = array(
            'total' => (int) $total,
            'by_level' => $by_level,
            'top_actions' => $top_actions,
            'top_users' => $top_users,
            'period_days' => $days
        );

        // Cache for 1 hour
        set_transient( $cache_key, $stats, HOUR_IN_SECONDS );

        return $stats;
    }

    // ============================================
    // CONVENIENCE METHODS FOR COMMON ACTIONS
    // ✅ v3.1.1: Kept only 6 actively used methods
    // Removed 18 unused methods (75% reduction)
    // ============================================

    /**
     * ✅ USED: Log submission created (v2.10.0 LGPD)
     * Called by: FFC_Submission_Handler
     *
     * @param int $submission_id Submission ID
     * @param array $data Additional data (form_id, encrypted status, etc)
     * @return bool Success
     */
    public static function log_submission_created( int $submission_id, array $data = array() ): bool {
        return self::log(
            'submission_created',
            self::LEVEL_INFO,
            $data,
            get_current_user_id(),
            $submission_id
        );
    }

    /**
     * ✅ USED: Log submission updated
     * Called by: FFC_Submission_Handler
     */
    public static function log_submission_updated( int $submission_id, int $admin_user_id ): bool {
        return self::log( 'submission_updated', self::LEVEL_INFO, array(
            'submission_id' => $submission_id
        ), $admin_user_id );
    }

    /**
     * ✅ USED: Log submission deleted
     * Called by: FFC_Submission_Handler
     */
    public static function log_submission_deleted( int $submission_id, int $admin_user_id = 0 ): bool {
        return self::log( 'submission_deleted', self::LEVEL_WARNING, array(
            'submission_id' => $submission_id
        ), $admin_user_id );
    }

    /**
     * ✅ USED: Log submission trashed
     * Called by: FFC_Submission_Handler
     *
     * @param int $submission_id Submission ID
     * @return bool Success
     */
    public static function log_submission_trashed( int $submission_id ): bool {
        return self::log(
            'submission_trashed',
            self::LEVEL_INFO,
            array( 'submission_id' => $submission_id ),
            get_current_user_id(),
            $submission_id
        );
    }

    /**
     * ✅ USED: Log submission restored
     * Called by: FFC_Submission_Handler
     *
     * @param int $submission_id Submission ID
     * @return bool Success
     */
    public static function log_submission_restored( int $submission_id ): bool {
        return self::log(
            'submission_restored',
            self::LEVEL_INFO,
            array( 'submission_id' => $submission_id ),
            get_current_user_id(),
            $submission_id
        );
    }

    /**
     * ✅ USED: Log data access (v2.10.0 LGPD)
     * Called by: FFC_Verification_Handler (magic link, admin view)
     *
     * @param int $submission_id Submission ID
     * @param array $context Access context (method, IP, etc)
     * @return bool Success
     */
    public static function log_data_accessed( int $submission_id, array $context = array() ): bool {
        return self::log(
            'data_accessed',
            self::LEVEL_INFO,
            $context,
            get_current_user_id(),
            $submission_id
        );
    }

    /**
     * ✅ USED: Log access denied
     * Called by: FFC_Geofence (datetime/geo restrictions)
     */
    public static function log_access_denied( string $reason, string $identifier ): bool {
        return self::log( 'access_denied', self::LEVEL_WARNING, array(
            'reason' => $reason,
            'identifier' => $identifier
        ) );
    }

    /**
     * ✅ USED: Log settings changed
     * Called by: FFC_Tab_Geolocation
     */
    public static function log_settings_changed( string $setting_key, int $admin_user_id ): bool {
        return self::log( 'settings_changed', self::LEVEL_INFO, array(
            'setting' => $setting_key
        ), $admin_user_id );
    }

    /**
     * ✅ v2.10.0: Get logs for specific submission (LGPD audit trail)
     *
     * @param int $submission_id Submission ID
     * @param int $limit Maximum number of logs to retrieve
     * @return array Logs related to this submission
     */
    public static function get_submission_logs( int $submission_id, int $limit = 100 ): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';

        // Check if submission_id column exists (uses cached DESCRIBE)
        $columns = self::get_table_columns( $table_name );
        if ( ! in_array( 'submission_id', $columns ) ) {
            return array(); // Column doesn't exist yet
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name}
                 WHERE submission_id = %d
                 ORDER BY created_at DESC
                 LIMIT %d",
                $submission_id,
                $limit
            ),
            ARRAY_A
        );

        // Decrypt encrypted contexts if available
        if ( class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
            foreach ( $logs as &$log ) {
                if ( ! empty( $log['context_encrypted'] ) ) {
                    $decrypted = \FreeFormCertificate\Core\Encryption::decrypt( $log['context_encrypted'] );
                    if ( $decrypted !== null ) {
                        $log['context_decrypted'] = $decrypted;
                    }
                }
            }
        }

        return $logs;
    }

    /**
     * Get table columns with caching
     * Prevents repeated DESCRIBE queries (performance optimization)
     *
     * @param string $table_name Table name
     * @return array Column names
     */
    private static function get_table_columns( string $table_name ): array {
        global $wpdb;

        // Return cached value if available
        if ( self::$table_columns_cache !== null ) {
            return self::$table_columns_cache;
        }

        // Query columns and cache result
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        self::$table_columns_cache = $wpdb->get_col( "DESCRIBE {$table_name}", 0 );

        return self::$table_columns_cache;
    }

    /**
     * Temporarily disable logging (for bulk operations)
     * Improves performance when performing many operations
     *
     * @return void
     */
    public static function disable_logging(): void {
        self::$logging_disabled = true;
    }

    /**
     * Re-enable logging after bulk operations
     *
     * @return void
     */
    public static function enable_logging(): void {
        self::$logging_disabled = false;
    }

    /**
     * Clear column cache (call after table structure changes)
     *
     * @return void
     */
    public static function clear_column_cache() {
        self::$table_columns_cache = null;
    }

    /**
     * Check if Activity Log is enabled in admin settings
     * Use this method to check before performing logging operations
     *
     * @return bool True if enabled, false otherwise
     */
    public static function is_enabled() {
        $settings = get_option( 'ffc_settings', array() );
        return isset( $settings['enable_activity_log'] ) && absint( $settings['enable_activity_log'] ) === 1;
    }
}
