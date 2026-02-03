<?php
declare(strict_types=1);

/**
 * MigrationNameNormalization
 *
 * Normalizes name fields and emails in existing submissions:
 * - Names: Proper Brazilian capitalization (lowercase connectives)
 * - Emails: Lowercase for consistent lookups
 *
 * Handles encrypted data: decrypts, normalizes, re-encrypts.
 *
 * This migration is SAFE to run multiple times - it only changes values that
 * differ from their normalized form.
 *
 * Usage:
 * - Via admin: FFC Settings > Migrations > Normalize Names
 * - Via WP-CLI: wp eval "FreeFormCertificate\Migrations\MigrationNameNormalization::run();"
 *
 * @since 4.3.0
 */

namespace FreeFormCertificate\Migrations;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class MigrationNameNormalization {

    /**
     * Name fields to normalize
     */
    private const NAME_FIELDS = array(
        'nome_completo',
        'nome',
        'name',
        'full_name',
        'ffc_nome',
        'participante',
    );

    /**
     * Run the migration
     *
     * @param int $batch_size Number of records per batch (default: 100)
     * @param bool $dry_run If true, only shows what would change without saving
     * @return array Result with success status, processed count, and changes
     */
    public static function run(int $batch_size = 100, bool $dry_run = false): array {
        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // Check if encryption is configured
        if (!class_exists('\FreeFormCertificate\Core\Encryption') || !\FreeFormCertificate\Core\Encryption::is_configured()) {
            return array(
                'success' => false,
                'processed' => 0,
                'changed' => 0,
                'errors' => 1,
                'message' => __('Encryption is not configured. Cannot process encrypted data.', 'wp-ffcertificate'),
            );
        }

        // Get total count for progress tracking
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE (data_encrypted IS NOT NULL AND data_encrypted != '')
             OR (email_encrypted IS NOT NULL AND email_encrypted != '')"
        );

        if ($total == 0) {
            return array(
                'success' => true,
                'processed' => 0,
                'changed' => 0,
                'errors' => 0,
                'message' => __('No submissions with encrypted data found.', 'wp-ffcertificate'),
            );
        }

        $processed = 0;
        $changed = 0;
        $names_changed = 0;
        $emails_changed = 0;
        $errors = array();
        $changes_log = array();
        $offset = 0;

        // Process in batches
        while ($offset < $total) {
            // Get batch of submissions (include email_encrypted)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $submissions = $wpdb->get_results($wpdb->prepare(
                "SELECT id, data_encrypted, email_encrypted FROM {$table}
                 WHERE (data_encrypted IS NOT NULL AND data_encrypted != '')
                 OR (email_encrypted IS NOT NULL AND email_encrypted != '')
                 ORDER BY id ASC
                 LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            ), ARRAY_A);

            if (empty($submissions)) {
                break;
            }

            foreach ($submissions as $submission) {
                $submission_id = (int) $submission['id'];
                $processed++;

                try {
                    $submission_changes = array();
                    $update_data = array();

                    // ===== NORMALIZE EMAIL (lowercase) =====
                    if (!empty($submission['email_encrypted'])) {
                        $email = \FreeFormCertificate\Core\Encryption::decrypt($submission['email_encrypted']);

                        if (!empty($email) && is_string($email)) {
                            $email_normalized = strtolower($email);

                            if ($email !== $email_normalized) {
                                $submission_changes['email'] = array(
                                    'from' => $email,
                                    'to' => $email_normalized,
                                );

                                if (!$dry_run) {
                                    // Re-encrypt email and recalculate hash
                                    $update_data['email_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt($email_normalized);
                                    $update_data['email_hash'] = \FreeFormCertificate\Core\Encryption::hash($email_normalized);
                                }

                                $emails_changed++;
                            }
                        }
                    }

                    // ===== NORMALIZE NAMES (Brazilian capitalization) =====
                    if (!empty($submission['data_encrypted'])) {
                        $data_json = \FreeFormCertificate\Core\Encryption::decrypt($submission['data_encrypted']);

                        if (!empty($data_json)) {
                            $data = json_decode($data_json, true);

                            if (is_array($data)) {
                                $data_changed = false;

                                foreach (self::NAME_FIELDS as $field) {
                                    if (!empty($data[$field]) && is_string($data[$field])) {
                                        $original = $data[$field];
                                        $normalized = \FreeFormCertificate\Core\Utils::normalize_brazilian_name($original);

                                        if ($original !== $normalized) {
                                            $data[$field] = $normalized;
                                            $data_changed = true;
                                            $submission_changes[$field] = array(
                                                'from' => $original,
                                                'to' => $normalized,
                                            );
                                            $names_changed++;
                                        }
                                    }
                                }

                                if ($data_changed && !$dry_run) {
                                    $new_data_json = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
                                    $update_data['data_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt($new_data_json);
                                }
                            }
                        }
                    }

                    // ===== SAVE CHANGES =====
                    if (!empty($submission_changes)) {
                        $changes_log[] = array(
                            'id' => $submission_id,
                            'changes' => $submission_changes,
                        );

                        if (!$dry_run && !empty($update_data)) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                            $wpdb->update(
                                $table,
                                $update_data,
                                array('id' => $submission_id),
                                array_fill(0, count($update_data), '%s'),
                                array('%d')
                            );
                        }

                        $changed++;
                    }

                } catch (\Exception $e) {
                    $errors[] = sprintf(
                        /* translators: %d: submission ID, %s: error message */
                        __('Submission ID %1$d: %2$s', 'wp-ffcertificate'),
                        $submission_id,
                        $e->getMessage()
                    );
                }
            }

            $offset += $batch_size;

            // Allow memory to be freed between batches
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }

        // Log errors if any
        if (!empty($errors)) {
            \FreeFormCertificate\Core\Debug::log_migrations('Migration Name Normalization - Errors', $errors);
            update_option('ffc_migration_name_normalization_errors', $errors);
        }

        // Log changes
        if (!empty($changes_log)) {
            update_option('ffc_migration_name_normalization_changes', $changes_log);
        }

        $mode = $dry_run ? __('DRY RUN', 'wp-ffcertificate') : __('EXECUTED', 'wp-ffcertificate');

        return array(
            'success' => true,
            'processed' => $processed,
            'changed' => $changed,
            'names_changed' => $names_changed,
            'emails_changed' => $emails_changed,
            'errors' => count($errors),
            'dry_run' => $dry_run,
            'changes' => $changes_log,
            'message' => sprintf(
                /* translators: 1: mode, 2: processed count, 3: names changed, 4: emails changed, 5: errors */
                __('%1$s: Processed %2$d submissions, %3$d names normalized, %4$d emails normalized, %5$d errors', 'wp-ffcertificate'),
                $mode,
                $processed,
                $names_changed,
                $emails_changed,
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
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // Check if encryption is configured
        if (!class_exists('\FreeFormCertificate\Core\Encryption') || !\FreeFormCertificate\Core\Encryption::is_configured()) {
            return array(
                'available' => false,
                'message' => __('Encryption not configured', 'wp-ffcertificate'),
            );
        }

        // Get total count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE (data_encrypted IS NOT NULL AND data_encrypted != '')
             OR (email_encrypted IS NOT NULL AND email_encrypted != '')"
        );

        // Get last run results
        $last_changes = get_option('ffc_migration_name_normalization_changes', array());
        $last_errors = get_option('ffc_migration_name_normalization_errors', array());

        return array(
            'available' => true,
            'total_submissions' => (int) $total,
            'last_run_changes' => count($last_changes),
            'last_run_errors' => count($last_errors),
            'message' => sprintf(
                /* translators: %d: number of submissions */
                __('%d submissions with encrypted data', 'wp-ffcertificate'),
                $total
            ),
        );
    }

    /**
     * Preview changes (dry run)
     *
     * @param int $limit Maximum submissions to preview
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
        delete_option('ffc_migration_name_normalization_changes');
        delete_option('ffc_migration_name_normalization_errors');
    }
}
