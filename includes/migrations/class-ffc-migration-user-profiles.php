<?php
declare(strict_types=1);

/**
 * MigrationUserProfiles
 *
 * Populates ffc_user_profiles table with data from existing ffc_users.
 * Sources: wp_users.display_name, wp_usermeta.ffc_registration_date,
 * and names extracted from submissions.
 *
 * Safe to run multiple times — skips users that already have a profile.
 *
 * @since 4.9.4
 */

namespace FreeFormCertificate\Migrations;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

class MigrationUserProfiles {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * Run the migration
     *
     * @param int $batch_size Number of users per batch
     * @param bool $dry_run If true, only shows what would change
     * @return array Result with success status, processed count, and changes
     */
    public static function run(int $batch_size = 50, bool $dry_run = false): array {
        global $wpdb;

        $profiles_table = $wpdb->prefix . 'ffc_user_profiles';

        // Check if table exists
        if (!self::table_exists($profiles_table)) {
            return array(
                'success' => false,
                'processed' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => 1,
                'message' => __('User profiles table does not exist. Reactivate the plugin.', 'ffcertificate'),
            );
        }

        // Get all ffc_users that don't have a profile yet
        $users = get_users(array(
            'role' => 'ffc_user',
            'fields' => 'ID',
        ));

        if (empty($users)) {
            return array(
                'success' => true,
                'processed' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => 0,
                'message' => __('No FFC users found.', 'ffcertificate'),
            );
        }

        $submissions_table = $wpdb->prefix . 'ffc_submissions';
        $processed = 0;
        $created = 0;
        $skipped = 0;
        $errors = array();

        foreach ($users as $user_id) {
            $user_id = (int) $user_id;
            $processed++;

            try {
                // Skip if profile already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$profiles_table} WHERE user_id = %d",
                    $user_id
                ));

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $user = get_userdata($user_id);
                if (!$user) {
                    $skipped++;
                    continue;
                }

                // Get registration date from user_meta (set by UserManager)
                $ffc_reg_date = get_user_meta($user_id, 'ffc_registration_date', true);
                $created_at = !empty($ffc_reg_date) ? $ffc_reg_date : $user->user_registered;

                if (!$dry_run) {
                    $wpdb->insert(
                        $profiles_table,
                        array(
                            'user_id' => $user_id,
                            'display_name' => $user->display_name,
                            'created_at' => $created_at,
                            'updated_at' => current_time('mysql'),
                        ),
                        array('%d', '%s', '%s', '%s')
                    );
                }

                $created++;

            } catch (\Exception $e) {
                $errors[] = sprintf(
                    /* translators: %d: user ID, %s: error message */
                    __('User ID %1$d: %2$s', 'ffcertificate'),
                    $user_id,
                    $e->getMessage()
                );
            }
        }

        // Log errors
        if (!empty($errors)) {
            update_option('ffc_migration_user_profiles_errors', $errors);
        }

        if (!$dry_run) {
            update_option('ffc_migration_user_profiles_last_run', current_time('mysql'));
        }

        $mode = $dry_run ? __('DRY RUN', 'ffcertificate') : __('EXECUTED', 'ffcertificate');

        return array(
            'success' => true,
            'processed' => $processed,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => count($errors),
            'dry_run' => $dry_run,
            'message' => sprintf(
                /* translators: 1: mode, 2: processed, 3: created, 4: skipped, 5: errors */
                __('%1$s: Processed %2$d users, %3$d profiles created, %4$d skipped, %5$d errors', 'ffcertificate'),
                $mode,
                $processed,
                $created,
                $skipped,
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
        global $wpdb;

        $profiles_table = $wpdb->prefix . 'ffc_user_profiles';
        $table_exists = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $profiles_table));

        if (!$table_exists) {
            return array(
                'available' => false,
                'is_complete' => false,
                'message' => __('User profiles table does not exist.', 'ffcertificate'),
            );
        }

        $total_users = count(get_users(array('role' => 'ffc_user', 'fields' => 'ID')));

        $profiles_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$profiles_table}");

        $last_run = get_option('ffc_migration_user_profiles_last_run', '');

        return array(
            'available' => true,
            'total_users' => $total_users,
            'profiles_created' => $profiles_count,
            'pending' => max(0, $total_users - $profiles_count),
            'is_complete' => $profiles_count >= $total_users,
            'last_run' => $last_run,
            'message' => $profiles_count >= $total_users
                ? __('All user profiles created.', 'ffcertificate')
                : sprintf(
                    /* translators: 1: pending count, 2: total count */
                    __('%1$d of %2$d users need profile creation', 'ffcertificate'),
                    $total_users - $profiles_count,
                    $total_users
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
}
