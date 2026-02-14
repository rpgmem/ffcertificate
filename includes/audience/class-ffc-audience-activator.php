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
        self::register_capabilities();
        self::add_composite_indexes();
    }

    /**
     * Register audience booking capabilities
     *
     * Adds the ffc_view_audience_bookings capability to appropriate roles.
     *
     * @return void
     */
    public static function register_capabilities(): void {
        // Get the ffc_user role
        $ffc_user_role = get_role('ffc_user');
        if ($ffc_user_role) {
            $ffc_user_role->add_cap('ffc_view_audience_bookings');
        }

        // Also add to subscriber role
        $subscriber_role = get_role('subscriber');
        if ($subscriber_role) {
            $subscriber_role->add_cap('ffc_view_audience_bookings');
        }

        // Administrator already has all caps via manage_options
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name) {
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name) {
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name) {
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name) {
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name) {
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name) {
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name) {
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name) {
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name) {
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
     * Run migrations on plugin load
     * This ensures migrations run even if plugin wasn't re-activated
     *
     * @since 4.7.0
     * @return void
     */
    public static function maybe_migrate(): void {
        global $wpdb;

        $schedules_table = $wpdb->prefix . 'ffc_audience_schedules';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $schedules_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $schedules_table ) ) == $schedules_table;

        if ($schedules_exists) {
            self::migrate_environment_label_column();
            self::migrate_booking_is_all_day_column();
            self::migrate_environment_color_column();
            self::migrate_schedule_event_list_columns();
            self::migrate_schedule_audience_badge_format_column();
            self::migrate_schedule_booking_label_columns();
        }

        self::migrate_audience_self_join_column();
    }

    /**
     * Migrate schedules table to add environment_label column
     *
     * Allows each calendar to define a custom label for its environments
     * (e.g. "Salas", "Consultórios", "Categorias") instead of the default "Ambientes".
     *
     * @since 4.7.0
     * @return void
     */
    private static function migrate_environment_label_column(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_schedules';

        // Check if environment_label column already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'environment_label'",
                DB_NAME,
                $table_name
            )
        );

        if (!empty($column_exists)) {
            return;
        }

        // Add environment_label column after description
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            "ALTER TABLE {$table_name}
            ADD COLUMN environment_label varchar(100) DEFAULT NULL COMMENT 'Custom label for environments (default: Environments)' AFTER description"
        );
    }

    /**
     * Migrate bookings table to add is_all_day column
     *
     * @since 4.8.0
     * @return void
     */
    private static function migrate_booking_is_all_day_column(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_bookings';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'is_all_day'",
                DB_NAME,
                $table_name
            )
        );

        if (!empty($column_exists)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            "ALTER TABLE {$table_name}
            ADD COLUMN is_all_day tinyint(1) DEFAULT 0 COMMENT 'All-day event flag' AFTER end_time"
        );
    }

    /**
     * Migrate environments table to add color column
     *
     * @since 4.8.0
     * @return void
     */
    private static function migrate_environment_color_column(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_environments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'color'",
                DB_NAME,
                $table_name
            )
        );

        if (!empty($column_exists)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            "ALTER TABLE {$table_name}
            ADD COLUMN color varchar(7) DEFAULT '#3788d8' COMMENT 'Hex color for visual identification' AFTER name"
        );
    }

    /**
     * Migrate schedules table to add event list columns
     *
     * @since 4.8.0
     * @return void
     */
    private static function migrate_schedule_event_list_columns(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_schedules';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'show_event_list'",
                DB_NAME,
                $table_name
            )
        );

        if (!empty($column_exists)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            "ALTER TABLE {$table_name}
            ADD COLUMN show_event_list tinyint(1) DEFAULT 0 COMMENT 'Show event list alongside calendar' AFTER include_ics,
            ADD COLUMN event_list_position enum('side','below') DEFAULT 'side' COMMENT 'Position of event list' AFTER show_event_list"
        );
    }

    /**
     * Migrate schedules table to add booking_label_singular / booking_label_plural columns.
     *
     * Custom labels for the booking count badge shown in calendar day cells
     * (e.g. "atendimento" / "atendimentos" instead of "reserva" / "reservas").
     *
     * @since 4.9.0
     * @return void
     */
    private static function migrate_schedule_booking_label_columns(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_schedules';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'booking_label_singular'",
                DB_NAME,
                $table_name
            )
        );

        if (!empty($column_exists)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            "ALTER TABLE {$table_name}
            ADD COLUMN booking_label_singular varchar(50) DEFAULT NULL COMMENT 'Custom singular label for booking badge',
            ADD COLUMN booking_label_plural varchar(50) DEFAULT NULL COMMENT 'Custom plural label for booking badge'"
        );
    }

    /**
     * Migrate schedules table to add audience_badge_format column.
     *
     * Controls how audience badges are displayed on the frontend:
     * 'name' (default) shows only the audience name,
     * 'parent_name' shows "Parent: Child".
     *
     * @since 4.9.0
     * @return void
     */
    private static function migrate_schedule_audience_badge_format_column(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_schedules';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'audience_badge_format'",
                DB_NAME,
                $table_name
            )
        );

        if (!empty($column_exists)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            "ALTER TABLE {$table_name}
            ADD COLUMN audience_badge_format enum('name','parent_name') DEFAULT 'name' COMMENT 'How audience badges display: name only or parent: child'"
        );
    }

    /**
     * Migrate audiences table to add allow_self_join column
     *
     * When enabled, logged-in users can join/leave the audience group
     * from their dashboard (max 2 groups per user).
     *
     * @since 4.9.9
     * @return void
     */
    private static function migrate_audience_self_join_column(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audiences';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        if ( $table_exists !== $table_name ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'allow_self_join'",
                DB_NAME,
                $table_name
            )
        );

        if ( ! empty( $column_exists ) ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            "ALTER TABLE {$table_name}
            ADD COLUMN allow_self_join tinyint(1) DEFAULT 0 COMMENT 'Allow users to join/leave from dashboard'"
        );
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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
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

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name;

            $count = 0;
            if ($exists) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );
            }

            $status[$key] = [
                'table' => $table_name,
                'exists' => $exists,
                'count' => $count,
            ];
        }

        return $status;
    }

    /**
     * Add composite indexes for common query patterns.
     *
     * @since 4.6.2
     */
    private static function add_composite_indexes(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_audience_bookings';

        $indexes = [
            'idx_date_status'       => '(booking_date, status)',
            'idx_created_by_date'   => '(created_by, booking_date)',
        ];

        foreach ( $indexes as $index_name => $columns ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $exists = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table_name, $index_name ) );
            if ( empty( $exists ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD INDEX {$index_name} {$columns}", $table_name ) );
            }
        }
    }
}
