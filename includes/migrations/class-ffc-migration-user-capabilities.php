<?php
declare(strict_types=1);

/**
 * MigrationUserCapabilities
 *
 * Sets user capabilities based on their history:
 * - Users with submissions get certificate capabilities
 * - Users with appointments get appointment capabilities
 *
 * This migration is SAFE to run multiple times - it only adds capabilities,
 * never removes them.
 *
 * Usage:
 * - Via admin: FFC Settings > Migrations > User Capabilities
 * - Via WP-CLI: wp eval "FreeFormCertificate\Migrations\MigrationUserCapabilities::run();"
 *
 * @since 4.4.0
 */

namespace FreeFormCertificate\Migrations;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class MigrationUserCapabilities {

    /**
     * Run the migration
     *
     * @param int $batch_size Number of users per batch (default: 50)
     * @param bool $dry_run If true, only shows what would change without saving
     * @return array Result with success status, processed count, and changes
     */
    public static function run(int $batch_size = 50, bool $dry_run = false): array {
        global $wpdb;

        // Get all users with ffc_user role
        $users = get_users(array(
            'role' => 'ffc_user',
            'fields' => 'ID',
        ));

        if (empty($users)) {
            return array(
                'success' => true,
                'processed' => 0,
                'changed' => 0,
                'errors' => 0,
                'message' => __('No FFC users found.', 'ffcertificate'),
            );
        }

        $submissions_table = \FreeFormCertificate\Core\Utils::get_submissions_table();
        $appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

        $processed = 0;
        $changed = 0;
        $cert_granted = 0;
        $appt_granted = 0;
        $errors = array();
        $changes_log = array();

        foreach ($users as $user_id) {
            $user_id = (int) $user_id;
            $processed++;

            try {
                $user_changes = array();

                // Check if user has submissions (certificates)
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $has_submissions = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$submissions_table} WHERE user_id = %d LIMIT 1",
                    $user_id
                ));

                // Check if user has appointments
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $has_appointments = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$appointments_table} WHERE user_id = %d LIMIT 1",
                    $user_id
                ));

                $user = get_userdata($user_id);
                if (!$user) {
                    continue;
                }

                // First, reset all FFC capabilities to false
                if (!$dry_run) {
                    foreach (\FreeFormCertificate\UserDashboard\UserManager::CERTIFICATE_CAPABILITIES as $cap) {
                        $user->add_cap($cap, false);
                    }
                    foreach (\FreeFormCertificate\UserDashboard\UserManager::APPOINTMENT_CAPABILITIES as $cap) {
                        $user->add_cap($cap, false);
                    }
                }

                // Grant certificate capabilities if user has submissions
                if ((int) $has_submissions > 0) {
                    foreach (\FreeFormCertificate\UserDashboard\UserManager::CERTIFICATE_CAPABILITIES as $cap) {
                        if (!$dry_run) {
                            $user->add_cap($cap, true);
                        }
                        $user_changes['certificates'][] = $cap;
                    }
                    $cert_granted++;
                }

                // Grant appointment capabilities if user has appointments
                if ((int) $has_appointments > 0) {
                    foreach (\FreeFormCertificate\UserDashboard\UserManager::APPOINTMENT_CAPABILITIES as $cap) {
                        if (!$dry_run) {
                            $user->add_cap($cap, true);
                        }
                        $user_changes['appointments'][] = $cap;
                    }
                    $appt_granted++;
                }

                if (!empty($user_changes)) {
                    $changes_log[] = array(
                        'user_id' => $user_id,
                        'email' => $user->user_email,
                        'has_submissions' => (int) $has_submissions,
                        'has_appointments' => (int) $has_appointments,
                        'changes' => $user_changes,
                    );
                    $changed++;
                }

            } catch (\Exception $e) {
                $errors[] = sprintf(
                    /* translators: %d: user ID, %s: error message */
                    __('User ID %1$d: %2$s', 'ffcertificate'),
                    $user_id,
                    $e->getMessage()
                );
            }
        }

        // Log errors if any
        if (!empty($errors)) {
            \FreeFormCertificate\Core\Debug::log_migrations('Migration User Capabilities - Errors', $errors);
            update_option('ffc_migration_user_capabilities_errors', $errors);
        }

        // Log changes
        if (!empty($changes_log)) {
            update_option('ffc_migration_user_capabilities_changes', $changes_log);
        }

        // Mark migration as run
        if (!$dry_run) {
            update_option('ffc_migration_user_capabilities_last_run', current_time('mysql'));
        }

        $mode = $dry_run ? __('DRY RUN', 'ffcertificate') : __('EXECUTED', 'ffcertificate');

        return array(
            'success' => true,
            'processed' => $processed,
            'changed' => $changed,
            'cert_granted' => $cert_granted,
            'appt_granted' => $appt_granted,
            'errors' => count($errors),
            'dry_run' => $dry_run,
            'changes' => $changes_log,
            'message' => sprintf(
                /* translators: 1: mode, 2: processed count, 3: cert granted, 4: appt granted, 5: errors */
                __('%1$s: Processed %2$d users, %3$d granted certificate access, %4$d granted appointment access, %5$d errors', 'ffcertificate'),
                $mode,
                $processed,
                $cert_granted,
                $appt_granted,
                count($errors)
            ),
        );
    }

    /**
     * Get migration status
     *
     * @return array Status information
     */
    public static function get_status(): array {
        // Get total ffc_user count
        $users = get_users(array(
            'role' => 'ffc_user',
            'fields' => 'ID',
        ));

        $total = count($users);

        // Get last run results
        $last_run = get_option('ffc_migration_user_capabilities_last_run', '');
        $last_changes = get_option('ffc_migration_user_capabilities_changes', array());
        $last_errors = get_option('ffc_migration_user_capabilities_errors', array());

        // If migration was run, show as complete
        if (!empty($last_run)) {
            return array(
                'available' => true,
                'total_users' => $total,
                'last_run' => $last_run,
                'last_run_changes' => count($last_changes),
                'last_run_errors' => count($last_errors),
                'is_complete' => true,
                'message' => sprintf(
                    /* translators: %s: last run date */
                    __('Migration completed on %s', 'ffcertificate'),
                    $last_run
                ),
            );
        }

        return array(
            'available' => true,
            'total_users' => $total,
            'is_complete' => false,
            'message' => sprintf(
                /* translators: %d: number of users */
                __('%d FFC users need capability migration', 'ffcertificate'),
                $total
            ),
        );
    }

    /**
     * Preview changes (dry run)
     *
     * @param int $limit Maximum users to preview
     * @return array Preview results
     */
    public static function preview(int $limit = 50): array {
        return self::run($limit, true);
    }

    /**
     * Clear migration logs
     *
     * @return void
     */
    public static function clear_logs(): void {
        delete_option('ffc_migration_user_capabilities_changes');
        delete_option('ffc_migration_user_capabilities_errors');
        delete_option('ffc_migration_user_capabilities_last_run');
    }
}
