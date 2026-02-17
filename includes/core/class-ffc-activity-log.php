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
 * - Refactored: query/stats/cleanup moved to ActivityLogQuery (v4.12.2)
 *
 * @version 4.12.2 - Split query/stats/cleanup to ActivityLogQuery
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

    use DatabaseHelperTrait;

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
        $columns = self::get_table_columns_cached( $table_name );
        $has_submission_id = in_array( 'submission_id', $columns );
        $has_context_encrypted = in_array( 'context_encrypted', $columns );

        $count = count( self::$write_buffer );
        $entries = self::$write_buffer;
        self::$write_buffer = [];

        foreach ( $entries as $entry ) {
            $row_data = [
                'action'     => $entry['action'],
                'level'      => $entry['level'],
                'context'    => $entry['context'],
                'user_id'    => $entry['user_id'],
                'user_ip'    => $entry['user_ip'],
                'created_at' => $entry['created_at'],
            ];
            $row_format = [ '%s', '%s', '%s', '%d', '%s', '%s' ];

            if ( $has_submission_id ) {
                $row_data['submission_id'] = $entry['submission_id'];
                $row_format[] = '%d';
            }

            if ( $has_context_encrypted ) {
                $row_data['context_encrypted'] = $entry['context_encrypted'] ?? '';
                $row_format[] = '%s';
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->insert( $table_name, $row_data, $row_format );
        }

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
        if ( self::table_exists( $table_name ) ) {
            return true;
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

    // =====================================================================
    // Backward-compatible delegation → ActivityLogQuery (v4.12.2)
    // =====================================================================

    /** @see ActivityLogQuery::get_activities() */
    public static function get_activities( array $args = array() ): array {
        return ActivityLogQuery::get_activities( $args );
    }

    /** @see ActivityLogQuery::count_activities() */
    public static function count_activities( array $args = array() ): int {
        return ActivityLogQuery::count_activities( $args );
    }

    /** @see ActivityLogQuery::cleanup() */
    public static function cleanup( int $days = 90 ): int {
        return ActivityLogQuery::cleanup( $days );
    }

    /** @see ActivityLogQuery::run_cleanup() */
    public static function run_cleanup(): int {
        return ActivityLogQuery::run_cleanup();
    }

    /** @see ActivityLogQuery::get_stats() */
    public static function get_stats( int $days = 30 ): array {
        return ActivityLogQuery::get_stats( $days );
    }

    /**
     * Log submission created
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
     * Log submission updated
     */
    public static function log_submission_updated( int $submission_id, int $admin_user_id ): bool {
        return self::log( 'submission_updated', self::LEVEL_INFO, array(
            'submission_id' => $submission_id
        ), $admin_user_id );
    }

    /**
     * Log submission deleted
     */
    public static function log_submission_deleted( int $submission_id, int $admin_user_id = 0 ): bool {
        return self::log( 'submission_deleted', self::LEVEL_WARNING, array(
            'submission_id' => $submission_id
        ), $admin_user_id );
    }

    /**
     * Log submission trashed
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
     * Log submission restored
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
     * Log data access (LGPD audit trail)
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
     * Log access denied
     */
    public static function log_access_denied( string $reason, string $identifier ): bool {
        return self::log( 'access_denied', self::LEVEL_WARNING, array(
            'reason' => $reason,
            'identifier' => $identifier
        ) );
    }

    /**
     * Log settings changed
     */
    public static function log_settings_changed( string $setting_key, int $admin_user_id ): bool {
        return self::log( 'settings_changed', self::LEVEL_INFO, array(
            'setting' => $setting_key
        ), $admin_user_id );
    }

    /**
     * Log password changed
     *
     * @since 4.9.9
     * @param int $user_id User who changed their password
     * @return bool
     */
    public static function log_password_changed( int $user_id ): bool {
        return self::log( 'password_changed', self::LEVEL_INFO, array(), $user_id );
    }

    /**
     * Log user profile updated
     *
     * @since 4.9.9
     * @param int   $user_id User whose profile was updated
     * @param array $fields  Fields that were changed
     * @return bool
     */
    public static function log_profile_updated( int $user_id, array $fields = array() ): bool {
        return self::log( 'profile_updated', self::LEVEL_INFO, array(
            'fields' => $fields
        ), $user_id );
    }

    /**
     * Log capabilities granted to a user
     *
     * @since 4.9.9
     * @param int    $user_id      Target user
     * @param string $context      Context: 'certificate', 'appointment', 'audience'
     * @param array  $capabilities Capabilities granted
     * @return bool
     */
    public static function log_capabilities_granted( int $user_id, string $context, array $capabilities = array() ): bool {
        return self::log( 'capabilities_granted', self::LEVEL_INFO, array(
            'context'      => $context,
            'capabilities' => $capabilities,
        ), get_current_user_id() > 0 ? get_current_user_id() : 0, 0 );
    }

    /**
     * Log privacy request created
     *
     * @since 4.9.9
     * @param int    $user_id User who requested
     * @param string $type    Request type (export_personal_data | remove_personal_data)
     * @return bool
     */
    public static function log_privacy_request( int $user_id, string $type ): bool {
        return self::log( 'privacy_request_created', self::LEVEL_INFO, array(
            'type' => $type,
        ), $user_id );
    }

    /** @see ActivityLogQuery::get_submission_logs() */
    public static function get_submission_logs( int $submission_id, int $limit = 100 ): array {
        return ActivityLogQuery::get_submission_logs( $submission_id, $limit );
    }

    /**
     * Get table columns with caching (public since v4.12.2 for ActivityLogQuery)
     *
     * @param string $table_name Table name
     * @return array Column names
     */
    public static function get_table_columns_cached( string $table_name ): array {
        global $wpdb;

        // Return cached value if available
        if ( self::$table_columns_cache !== null ) {
            return self::$table_columns_cache;
        }

        // Query columns and cache result
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        self::$table_columns_cache = $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', $table_name ), 0 );

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
