<?php
declare(strict_types=1);

/**
 * Migration: Create Custom Fields & Reregistration Tables
 *
 * Ensures tables created in Sprints 6-7 exist when upgrading
 * from versions prior to 4.11.0. Safe to run multiple times
 * (uses IF NOT EXISTS via dbDelta).
 *
 * Tables:
 * - wp_ffc_custom_fields
 * - wp_ffc_reregistrations
 * - wp_ffc_reregistration_submissions
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Migrations
 */

namespace FreeFormCertificate\Migrations;

if (!defined('ABSPATH')) {
    exit;
}

class MigrationCustomFieldsTables {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * Option key to track migration status
     */
    private const MIGRATION_OPTION = 'ffc_migration_custom_fields_tables_completed';

    /**
     * Tables that this migration creates (suffix only)
     *
     * @var array<string>
     */
    private static array $tables = [
        'ffc_custom_fields',
        'ffc_reregistrations',
        'ffc_reregistration_submissions',
    ];

    /**
     * Check if migration has been completed
     *
     * @return bool
     */
    public static function is_completed(): bool {
        return (bool) get_option(self::MIGRATION_OPTION, false);
    }

    /**
     * Run the migration
     *
     * @return array{success: bool, message: string, details: array}
     */
    public static function run(): array {
        if (self::is_completed()) {
            return [
                'success' => true,
                'message' => __('Migration already completed.', 'ffcertificate'),
                'details' => [],
            ];
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $results = [];
        $all_success = true;

        $results['ffc_custom_fields'] = self::create_custom_fields_table();
        $results['ffc_reregistrations'] = self::create_reregistrations_table();
        $results['ffc_reregistration_submissions'] = self::create_reregistration_submissions_table();

        foreach ($results as $result) {
            if (!$result['success']) {
                $all_success = false;
            }
        }

        if ($all_success) {
            update_option(self::MIGRATION_OPTION, true);
        }

        return [
            'success' => $all_success,
            'message' => $all_success
                ? __('All tables created successfully.', 'ffcertificate')
                : __('Some tables could not be created. Check details.', 'ffcertificate'),
            'details' => $results,
        ];
    }

    /**
     * Create ffc_custom_fields table
     *
     * @return array{success: bool, message: string}
     */
    private static function create_custom_fields_table(): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_custom_fields';
        $charset_collate = $wpdb->get_charset_collate();

        if (self::table_exists($table_name)) {
            return [
                'success' => true,
                'message' => sprintf(
                    /* translators: %s: table name */
                    __('Table %s already exists, skipping.', 'ffcertificate'),
                    $table_name
                ),
            ];
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            audience_id bigint(20) unsigned NOT NULL,
            field_key varchar(100) NOT NULL,
            field_label varchar(250) NOT NULL,
            field_type varchar(50) NOT NULL DEFAULT 'text',
            field_options json DEFAULT NULL,
            validation_rules json DEFAULT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            is_required tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_audience_id (audience_id),
            KEY idx_field_key (field_key),
            KEY idx_sort_order (audience_id, sort_order)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta($sql);

        $exists = self::table_exists($table_name);

        return [
            'success' => $exists,
            'message' => $exists
                ? sprintf(
                    /* translators: %s: table name */
                    __('Table %s created successfully.', 'ffcertificate'),
                    $table_name
                )
                : sprintf(
                    /* translators: %s: table name */
                    __('Failed to create table %s.', 'ffcertificate'),
                    $table_name
                ),
        ];
    }

    /**
     * Create ffc_reregistrations table
     *
     * @return array{success: bool, message: string}
     */
    private static function create_reregistrations_table(): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_reregistrations';
        $charset_collate = $wpdb->get_charset_collate();

        if (self::table_exists($table_name)) {
            return [
                'success' => true,
                'message' => sprintf(
                    /* translators: %s: table name */
                    __('Table %s already exists, skipping.', 'ffcertificate'),
                    $table_name
                ),
            ];
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(250) NOT NULL,
            audience_id bigint(20) unsigned NOT NULL,
            start_date datetime NOT NULL,
            end_date datetime NOT NULL,
            auto_approve tinyint(1) NOT NULL DEFAULT 0,
            email_invitation_enabled tinyint(1) NOT NULL DEFAULT 0,
            email_reminder_enabled tinyint(1) NOT NULL DEFAULT 0,
            email_confirmation_enabled tinyint(1) NOT NULL DEFAULT 0,
            reminder_days int(11) NOT NULL DEFAULT 7,
            status varchar(20) NOT NULL DEFAULT 'draft',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_audience_id (audience_id),
            KEY idx_status (status),
            KEY idx_dates (start_date, end_date)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta($sql);

        $exists = self::table_exists($table_name);

        return [
            'success' => $exists,
            'message' => $exists
                ? sprintf(
                    /* translators: %s: table name */
                    __('Table %s created successfully.', 'ffcertificate'),
                    $table_name
                )
                : sprintf(
                    /* translators: %s: table name */
                    __('Failed to create table %s.', 'ffcertificate'),
                    $table_name
                ),
        ];
    }

    /**
     * Create ffc_reregistration_submissions table
     *
     * @return array{success: bool, message: string}
     */
    private static function create_reregistration_submissions_table(): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_reregistration_submissions';
        $charset_collate = $wpdb->get_charset_collate();

        if (self::table_exists($table_name)) {
            return [
                'success' => true,
                'message' => sprintf(
                    /* translators: %s: table name */
                    __('Table %s already exists, skipping.', 'ffcertificate'),
                    $table_name
                ),
            ];
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reregistration_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            data json DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            submitted_at datetime DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_reregistration_user (reregistration_id, user_id),
            KEY idx_user_id (user_id),
            KEY idx_status (status)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta($sql);

        $exists = self::table_exists($table_name);

        return [
            'success' => $exists,
            'message' => $exists
                ? sprintf(
                    /* translators: %s: table name */
                    __('Table %s created successfully.', 'ffcertificate'),
                    $table_name
                )
                : sprintf(
                    /* translators: %s: table name */
                    __('Failed to create table %s.', 'ffcertificate'),
                    $table_name
                ),
        ];
    }

    /**
     * Get migration status information
     *
     * @return array{completed: bool, tables: array}
     */
    public static function get_status(): array {
        global $wpdb;

        $tables_info = [];

        foreach (self::$tables as $table_suffix) {
            $table_name = $wpdb->prefix . $table_suffix;

            $exists = self::table_exists($table_name);

            $tables_info[$table_suffix] = [
                'table' => $table_name,
                'exists' => $exists,
            ];
        }

        return [
            'completed' => self::is_completed(),
            'tables' => $tables_info,
        ];
    }
}
