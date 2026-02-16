<?php
declare(strict_types=1);

/**
 * MigrationForeignKeys
 *
 * Adds FOREIGN KEY constraints to FFC tables for referential integrity.
 * Safety net: if the deleted_user hook (UserCleanup) fails, the database
 * enforces ON DELETE SET NULL / CASCADE automatically.
 *
 * Requires InnoDB engine on all involved tables. If any table uses MyISAM,
 * that specific FK is skipped with a warning.
 *
 * @since 4.9.7
 * @package FreeFormCertificate\Migrations
 */

namespace FreeFormCertificate\Migrations;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter

class MigrationForeignKeys {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * Run the migration
     *
     * @return array Result with success status and details
     */
    public static function run(): array {
        global $wpdb;

        $results = array(
            'added' => array(),
            'skipped' => array(),
            'errors' => array(),
        );

        // Tables that should SET NULL on user deletion (preserve audit trail)
        $set_null_tables = array(
            $wpdb->prefix . 'ffc_submissions' => 'fk_ffc_submissions_user',
            $wpdb->prefix . 'ffc_self_scheduling_appointments' => 'fk_ffc_appointments_user',
            $wpdb->prefix . 'ffc_activity_log' => 'fk_ffc_activity_log_user',
        );

        // Tables that should CASCADE delete (no audit value)
        $cascade_tables = array(
            $wpdb->prefix . 'ffc_audience_members' => 'fk_ffc_audience_members_user',
            $wpdb->prefix . 'ffc_audience_booking_users' => 'fk_ffc_booking_users_user',
            $wpdb->prefix . 'ffc_audience_schedule_permissions' => 'fk_ffc_schedule_perms_user',
            $wpdb->prefix . 'ffc_user_profiles' => 'fk_ffc_user_profiles_user',
        );

        // Check wp_users engine first
        $users_engine = self::get_table_engine($wpdb->users);
        if ($users_engine !== 'InnoDB') {
            return array(
                'success' => false,
                'added' => array(),
                'skipped' => array(),
                'errors' => array(
                    sprintf('wp_users uses %s engine (InnoDB required for FK). Migration skipped.', $users_engine ?: 'unknown')
                ),
                'message' => __('Foreign keys require InnoDB engine. Migration skipped.', 'ffcertificate'),
            );
        }

        // Process SET NULL constraints
        foreach ($set_null_tables as $table => $constraint_name) {
            $result = self::add_foreign_key($table, $constraint_name, 'SET NULL');
            self::record_result($results, $table, $constraint_name, $result);
        }

        // Process CASCADE constraints
        foreach ($cascade_tables as $table => $constraint_name) {
            $result = self::add_foreign_key($table, $constraint_name, 'CASCADE');
            self::record_result($results, $table, $constraint_name, $result);
        }

        // Log results
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'migration_foreign_keys',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
                $results
            );
        }

        $total_added = count($results['added']);
        $total_skipped = count($results['skipped']);
        $total_errors = count($results['errors']);

        return array(
            'success' => $total_errors === 0,
            'added' => $results['added'],
            'skipped' => $results['skipped'],
            'errors' => $results['errors'],
            'message' => sprintf(
                /* translators: 1: added count, 2: skipped count, 3: error count */
                __('Foreign keys: %1$d added, %2$d skipped, %3$d errors', 'ffcertificate'),
                $total_added,
                $total_skipped,
                $total_errors
            ),
        );
    }

    /**
     * Add a foreign key constraint to a table
     *
     * @param string $table Full table name
     * @param string $constraint_name Constraint name
     * @param string $on_delete ON DELETE action ('SET NULL' or 'CASCADE')
     * @return array{status: string, message: string}
     */
    private static function add_foreign_key(string $table, string $constraint_name, string $on_delete): array {
        global $wpdb;

        // Check if table and column exist
        if (!self::table_exists($table)) {
            return array('status' => 'skipped', 'message' => 'Table does not exist');
        }
        if (!self::column_exists($table, 'user_id')) {
            return array('status' => 'skipped', 'message' => 'No user_id column');
        }

        // Check engine
        $engine = self::get_table_engine($table);
        if ($engine !== 'InnoDB') {
            return array('status' => 'skipped', 'message' => "Engine is {$engine}, not InnoDB");
        }

        // Check if FK already exists
        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
             AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            DB_NAME,
            $table,
            $constraint_name
        ));

        if (!empty($existing)) {
            return array('status' => 'skipped', 'message' => 'FK already exists');
        }

        // For SET NULL, ensure user_id allows NULL
        if ($on_delete === 'SET NULL') {
            $col_info = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'user_id')); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($col_info && strtoupper($col_info->Null) !== 'YES') {
                $wpdb->query("ALTER TABLE {$table} MODIFY user_id BIGINT(20) UNSIGNED DEFAULT NULL");
            }
        }

        // Clean up orphaned references (user_id values that don't exist in wp_users)
        $wpdb->query(
            "UPDATE {$table} SET user_id = NULL WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT ID FROM {$wpdb->users})"
        );

        // Add the FK constraint
        $sql = "ALTER TABLE {$table}
                ADD CONSTRAINT {$constraint_name}
                FOREIGN KEY (user_id) REFERENCES {$wpdb->users}(ID) ON DELETE {$on_delete}";

        $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with internal table/constraint names

        if ($result === false) {
            return array('status' => 'error', 'message' => $wpdb->last_error ?: 'Unknown error adding FK');
        }

        return array('status' => 'added', 'message' => "FK added with ON DELETE {$on_delete}");
    }

    /**
     * Get the storage engine for a table
     *
     * @param string $table Table name
     * @return string|null Engine name (e.g. 'InnoDB', 'MyISAM') or null
     */
    private static function get_table_engine(string $table): ?string {
        global $wpdb;

        $engine = $wpdb->get_var($wpdb->prepare(
            "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));

        return $engine ?: null;
    }

    /**
     * Record a result into the results array
     *
     * @param array &$results Results accumulator
     * @param string $table Table name
     * @param string $constraint Constraint name
     * @param array $result Result from add_foreign_key
     */
    private static function record_result(array &$results, string $table, string $constraint, array $result): void {
        $entry = array(
            'table' => $table,
            'constraint' => $constraint,
            'message' => $result['message'],
        );

        switch ($result['status']) {
            case 'added':
                $results['added'][] = $entry;
                break;
            case 'skipped':
                $results['skipped'][] = $entry;
                break;
            case 'error':
                $results['errors'][] = $entry;
                break;
        }
    }

    /**
     * Get migration status
     *
     * @return array Status information
     */
    public static function get_status(): array {
        global $wpdb;

        $all_constraints = array(
            'fk_ffc_submissions_user',
            'fk_ffc_appointments_user',
            'fk_ffc_activity_log_user',
            'fk_ffc_audience_members_user',
            'fk_ffc_booking_users_user',
            'fk_ffc_schedule_perms_user',
            'fk_ffc_user_profiles_user',
        );

        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'
             AND CONSTRAINT_NAME LIKE %s",
            DB_NAME,
            'fk_ffc_%'
        ));

        $existing_count = count($existing);
        $total = count($all_constraints);

        return array(
            'available' => true,
            'total_constraints' => $total,
            'existing_constraints' => $existing_count,
            'is_complete' => $existing_count >= $total,
            'existing' => $existing,
            'message' => sprintf(
                /* translators: 1: existing count, 2: total count */
                __('%1$d of %2$d FK constraints exist', 'ffcertificate'),
                $existing_count,
                $total
            ),
        );
    }
}
