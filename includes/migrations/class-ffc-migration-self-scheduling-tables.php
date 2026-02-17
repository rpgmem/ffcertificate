<?php
declare(strict_types=1);

/**
 * Migration: Rename Calendar tables to Self-Scheduling
 *
 * Renames the following tables:
 * - wp_ffc_calendars → wp_ffc_self_scheduling_calendars
 * - wp_ffc_appointments → wp_ffc_self_scheduling_appointments
 * - wp_ffc_blocked_dates → wp_ffc_self_scheduling_blocked_dates
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Migrations
 */

namespace FreeFormCertificate\Migrations;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange

class MigrationSelfSchedulingTables {

    /**
     * Option key to track migration status
     */
    private const MIGRATION_OPTION = 'ffc_migration_self_scheduling_tables_completed';

    /**
     * Table rename mappings (old => new)
     *
     * @var array<string, string>
     */
    private static array $table_mappings = [
        'ffc_calendars' => 'ffc_self_scheduling_calendars',
        'ffc_appointments' => 'ffc_self_scheduling_appointments',
        'ffc_blocked_dates' => 'ffc_self_scheduling_blocked_dates',
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
        global $wpdb;

        // Check if already completed
        if (self::is_completed()) {
            return [
                'success' => true,
                'message' => __('Migration already completed.', 'ffcertificate'),
                'details' => [],
            ];
        }

        $results = [];
        $all_success = true;

        foreach (self::$table_mappings as $old_suffix => $new_suffix) {
            $old_table = $wpdb->prefix . $old_suffix;
            $new_table = $wpdb->prefix . $new_suffix;

            $result = self::rename_table($old_table, $new_table);
            $results[$old_suffix] = $result;

            if (!$result['success']) {
                $all_success = false;
            }
        }

        // Mark as completed if all tables were renamed successfully
        if ($all_success) {
            update_option(self::MIGRATION_OPTION, true);
        }

        return [
            'success' => $all_success,
            'message' => $all_success
                ? __('All tables renamed successfully.', 'ffcertificate')
                : __('Some tables could not be renamed. Check details.', 'ffcertificate'),
            'details' => $results,
        ];
    }

    /**
     * Rename a single table
     *
     * @param string $old_table Old table name (with prefix)
     * @param string $new_table New table name (with prefix)
     * @return array{success: bool, message: string}
     */
    private static function rename_table(string $old_table, string $new_table): array {
        global $wpdb;

        // Check if old table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $old_exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
                DB_NAME,
                $old_table
            )
        );

        // Check if new table already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $new_exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
                DB_NAME,
                $new_table
            )
        );

        // If new table already exists, skip
        if ($new_exists) {
            return [
                'success' => true,
                'message' => sprintf(
                    /* translators: %s: table name */
                    __('Table %s already exists, skipping.', 'ffcertificate'),
                    $new_table
                ),
            ];
        }

        // If old table doesn't exist, nothing to do
        if (!$old_exists) {
            return [
                'success' => true,
                'message' => sprintf(
                    /* translators: %s: table name */
                    __('Table %s does not exist, nothing to rename.', 'ffcertificate'),
                    $old_table
                ),
            ];
        }

        // Rename the table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old_table, $new_table ) );

        if ($result === false) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: 1: old table name, 2: new table name, 3: error message */
                    __('Failed to rename %1$s to %2$s: %3$s', 'ffcertificate'),
                    $old_table,
                    $new_table,
                    $wpdb->last_error
                ),
            ];
        }

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: 1: old table name, 2: new table name */
                __('Successfully renamed %1$s to %2$s.', 'ffcertificate'),
                $old_table,
                $new_table
            ),
        ];
    }

    /**
     * Rollback the migration (for emergencies)
     *
     * @return array{success: bool, message: string, details: array}
     */
    public static function rollback(): array {
        global $wpdb;

        $results = [];
        $all_success = true;

        // Reverse the mappings
        foreach (self::$table_mappings as $old_suffix => $new_suffix) {
            $current_table = $wpdb->prefix . $new_suffix;
            $original_table = $wpdb->prefix . $old_suffix;

            $result = self::rename_table($current_table, $original_table);
            $results[$new_suffix] = $result;

            if (!$result['success']) {
                $all_success = false;
            }
        }

        // Remove migration flag
        if ($all_success) {
            delete_option(self::MIGRATION_OPTION);
        }

        return [
            'success' => $all_success,
            'message' => $all_success
                ? __('Rollback completed successfully.', 'ffcertificate')
                : __('Some tables could not be rolled back. Check details.', 'ffcertificate'),
            'details' => $results,
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

        foreach (self::$table_mappings as $old_suffix => $new_suffix) {
            $old_table = $wpdb->prefix . $old_suffix;
            $new_table = $wpdb->prefix . $new_suffix;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $old_exists = (bool) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
                    DB_NAME,
                    $old_table
                )
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $new_exists = (bool) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
                    DB_NAME,
                    $new_table
                )
            );

            $tables_info[$old_suffix] = [
                'old_table' => $old_table,
                'new_table' => $new_table,
                'old_exists' => $old_exists,
                'new_exists' => $new_exists,
                'migrated' => !$old_exists && $new_exists,
            ];
        }

        return [
            'completed' => self::is_completed(),
            'tables' => $tables_info,
        ];
    }
}
