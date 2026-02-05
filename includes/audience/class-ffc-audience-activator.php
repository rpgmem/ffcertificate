<?php
declare(strict_types=1);

/**
 * Audience Activator
 *
 * Creates database tables for audience booking system.
 * This system allows users with permission to book audiences/groups for specific environments.
 *
 * Tables created:
 * - wp_ffc_audience_schedules: Calendar configurations
 * - wp_ffc_audience_schedule_permissions: User permissions per calendar
 * - wp_ffc_audience_environments: Physical locations/rooms
 * - wp_ffc_audience_holidays: Holidays per calendar
 * - wp_ffc_audiences: Audience groups (with hierarchy)
 * - wp_ffc_audience_members: Users belonging to audience groups
 * - wp_ffc_audience_bookings: Booking records
 * - wp_ffc_audience_booking_audiences: N:N booking to audiences
 * - wp_ffc_audience_booking_users: N:N booking to individual users
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

class AudienceActivator {

    /**
     * Create all audience-related tables
     *
     * Called during plugin activation.
     *
     * @return void
     */
    public static function create_tables(): void {
        self::create_schedules_table();
        self::create_schedule_permissions_table();
        self::create_environments_table();
        self::create_holidays_table();
        self::create_audiences_table();
        self::create_audience_members_table();
        self::create_bookings_table();
        self::create_booking_audiences_table();
        self::create_booking_users_table();
    }

    /**
     * Create schedules table
     *
     * Stores calendar configurations for audience booking.
     *
     * @return void
     */
    private static function create_schedules_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_schedules';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            visibility enum('public','private') DEFAULT 'private',
            future_days_limit int unsigned DEFAULT NULL COMMENT 'NULL = no limit, only applies to non-admin',
            notify_on_booking tinyint(1) DEFAULT 1,
            notify_on_cancellation tinyint(1) DEFAULT 1,
            email_template_booking text DEFAULT NULL,
            email_template_cancellation text DEFAULT NULL,
            include_ics tinyint(1) DEFAULT 0,
            status enum('active','inactive') DEFAULT 'active',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_created_by (created_by)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create schedule permissions table
     *
     * Stores user permissions per calendar.
     *
     * @return void
     */
    private static function create_schedule_permissions_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_schedule_permissions';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            schedule_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            can_book tinyint(1) DEFAULT 1,
            can_cancel_others tinyint(1) DEFAULT 0,
            can_override_conflicts tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY unique_schedule_user (schedule_id, user_id),
            KEY idx_user (user_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create environments table
     *
     * Stores physical locations/rooms within a schedule.
     *
     * @return void
     */
    private static function create_environments_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_environments';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            schedule_id bigint(20) unsigned NOT NULL,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            working_hours longtext DEFAULT NULL COMMENT 'JSON: {mon: {start, end, closed}, ...}',
            status enum('active','inactive') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY idx_schedule (schedule_id),
            KEY idx_status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create holidays table
     *
     * Stores holidays/closed dates per calendar.
     *
     * @return void
     */
    private static function create_holidays_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_holidays';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            schedule_id bigint(20) unsigned NOT NULL,
            holiday_date date NOT NULL,
            description varchar(255) DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY unique_schedule_date (schedule_id, holiday_date),
            KEY idx_date (holiday_date)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create audiences table
     *
     * Stores audience groups with 2-level hierarchy (parent/child).
     *
     * @return void
     */
    private static function create_audiences_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audiences';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            color varchar(7) DEFAULT '#3788d8' COMMENT 'Hex color for visual identification',
            parent_id bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = parent group, otherwise child',
            status enum('active','inactive') DEFAULT 'active',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY idx_parent (parent_id),
            KEY idx_status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create audience members table
     *
     * Stores users belonging to audience groups.
     *
     * @return void
     */
    private static function create_audience_members_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_members';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            audience_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY unique_audience_user (audience_id, user_id),
            KEY idx_user (user_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create bookings table
     *
     * Stores booking records.
     *
     * @return void
     */
    private static function create_bookings_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_bookings';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            environment_id bigint(20) unsigned NOT NULL,
            booking_date date NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            booking_type enum('audience','individual') NOT NULL,
            description varchar(300) NOT NULL COMMENT 'Required, 15-300 chars',
            status enum('active','cancelled') DEFAULT 'active',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            cancelled_by bigint(20) unsigned DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL,
            cancellation_reason varchar(500) DEFAULT NULL COMMENT 'Required when cancelling',

            PRIMARY KEY (id),
            KEY idx_environment (environment_id),
            KEY idx_date (booking_date),
            KEY idx_status (status),
            KEY idx_created_by (created_by),
            KEY idx_env_date_status (environment_id, booking_date, status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create booking audiences table
     *
     * N:N relationship between bookings and audience groups.
     *
     * @return void
     */
    private static function create_booking_audiences_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_booking_audiences';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            audience_id bigint(20) unsigned NOT NULL,

            PRIMARY KEY (id),
            UNIQUE KEY unique_booking_audience (booking_id, audience_id),
            KEY idx_audience (audience_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create booking users table
     *
     * N:N relationship between bookings and individual users.
     *
     * @return void
     */
    private static function create_booking_users_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_booking_users';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,

            PRIMARY KEY (id),
            UNIQUE KEY unique_booking_user (booking_id, user_id),
            KEY idx_user (user_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Drop all audience tables (for uninstall)
     *
     * @return void
     */
    public static function drop_tables(): void {
        global $wpdb;

        // Drop in reverse order of dependencies
        $tables = array(
            $wpdb->prefix . 'ffc_audience_booking_users',
            $wpdb->prefix . 'ffc_audience_booking_audiences',
            $wpdb->prefix . 'ffc_audience_bookings',
            $wpdb->prefix . 'ffc_audience_members',
            $wpdb->prefix . 'ffc_audiences',
            $wpdb->prefix . 'ffc_audience_holidays',
            $wpdb->prefix . 'ffc_audience_environments',
            $wpdb->prefix . 'ffc_audience_schedule_permissions',
            $wpdb->prefix . 'ffc_audience_schedules',
        );

        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * Get table status information
     *
     * @return array<string, array{exists: bool, count: int}>
     */
    public static function get_tables_status(): array {
        global $wpdb;

        $tables = [
            'schedules' => 'ffc_audience_schedules',
            'permissions' => 'ffc_audience_schedule_permissions',
            'environments' => 'ffc_audience_environments',
            'holidays' => 'ffc_audience_holidays',
            'audiences' => 'ffc_audiences',
            'members' => 'ffc_audience_members',
            'bookings' => 'ffc_audience_bookings',
            'booking_audiences' => 'ffc_audience_booking_audiences',
            'booking_users' => 'ffc_audience_booking_users',
        ];

        $status = [];

        foreach ($tables as $key => $suffix) {
            $table_name = $wpdb->prefix . $suffix;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name;

            $count = 0;
            if ($exists) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            }

            $status[$key] = [
                'table' => $table_name,
                'exists' => $exists,
                'count' => $count,
            ];
        }

        return $status;
    }
}
