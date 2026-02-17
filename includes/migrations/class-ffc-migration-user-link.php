<?php
declare(strict_types=1);

/**
 * MigrationUserLink
 *
 * Adds user_id column to ffc_submissions and links existing submissions to WordPress users.
 *
 * Flow for each submission without user_id:
 * 1. Check if CPF/RF already appears in another submission WITH user_id
 *    - If yes: Copy the same user_id
 * 2. If no: Check if email exists in WordPress
 *    - If yes: Link to existing user_id
 * 3. If no: Create new user + link
 * 4. Conflict handling: Log error + Skip (2 CPFs, same email)
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since 3.1.0
 */

namespace FreeFormCertificate\Migrations;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange

class MigrationUserLink {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * Run the migration
     *
     * @return array<string, mixed> Result with success status, processed count, and errors
     */
    public static function run(): array {
        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // 1. Add user_id column if not exists
        self::add_user_id_column($table);

        // 2. Get all submissions without user_id, ordered by date (oldest first)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $submissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, cpf_rf_hash, email_encrypted, data_encrypted
                 FROM %i
                 WHERE cpf_rf_hash IS NOT NULL
                 AND cpf_rf_hash != ''
                 AND user_id IS NULL
                 ORDER BY submission_date ASC",
                $table
            ),
            ARRAY_A
        );

        if (empty($submissions)) {
            return array(
                'success' => true,
                'processed' => 0,
                'errors' => 0,
                'message' => __('No submissions to migrate.', 'ffcertificate'),
            );
        }

        $processed_cpfs = array(); // cpf_rf_hash => user_id
        $email_to_user = array();  // email => user_id (for conflict detection)
        $errors = array();
        $processed_count = 0;

        foreach ($submissions as $submission) {
            $cpf_rf_hash = $submission['cpf_rf_hash'];
            $submission_id = (int) $submission['id'];

            // STEP 1: Check if CPF/RF already has user_id from previous submissions
            if (isset($processed_cpfs[$cpf_rf_hash])) {
                $user_id = $processed_cpfs[$cpf_rf_hash];

                // Update submission with existing user_id
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $table,
                    array('user_id' => $user_id),
                    array('id' => $submission_id),
                    array('%d'),
                    array('%d')
                );

                $processed_count++;
                continue;
            }

            // STEP 2: Check if CPF/RF exists in OTHER submissions with user_id
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $existing_user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM %i
                 WHERE cpf_rf_hash = %s
                 AND user_id IS NOT NULL
                 LIMIT 1",
                $table,
                $cpf_rf_hash
            ));

            if ($existing_user_id) {
                // CPF/RF already linked → use same user_id
                $user_id = (int) $existing_user_id;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $table,
                    array('user_id' => $user_id),
                    array('id' => $submission_id),
                    array('%d'),
                    array('%d')
                );

                $processed_cpfs[$cpf_rf_hash] = $user_id;
                $processed_count++;
                continue;
            }

            // STEP 3: Decrypt email
            try {
                if (empty($submission['email_encrypted'])) {
                    $errors[] = sprintf(
                        /* translators: %d: submission ID */
                        __('Submission ID %d: Email encrypted is empty', 'ffcertificate'),
                        $submission_id
                    );
                    continue;
                }

                $email = \FreeFormCertificate\Core\Encryption::decrypt($submission['email_encrypted']);

                if (empty($email) || !is_email($email)) {
                    $errors[] = sprintf(
                        /* translators: %d: submission ID */
                        __('Submission ID %d: Invalid email after decryption', 'ffcertificate'),
                        $submission_id
                    );
                    continue;
                }

            } catch (\Exception $e) {
                $errors[] = sprintf(
                    /* translators: %d: submission ID, %s: email address */
                    __('Submission ID %1$d: Failed to decrypt email - %2$s', 'ffcertificate'),
                    $submission_id,
                    $e->getMessage()
                );
                continue;
            }

            // STEP 4: Check if email already exists in WordPress
            $existing_user = get_user_by('email', $email);

            if ($existing_user) {
                $user_id = $existing_user->ID;

                // CONFLICT DETECTION: Check if this email is already linked to a DIFFERENT CPF/RF
                if (isset($email_to_user[$email]) && isset($processed_cpfs[$email_to_user[$email]])) {
                    // Find the other CPF/RF hash that used this email
                    $other_cpf_hash = array_search($user_id, $processed_cpfs);

                    if ($other_cpf_hash && $other_cpf_hash !== $cpf_rf_hash) {
                        $errors[] = sprintf(
                            /* translators: %d: submission ID, %s: email address */
                            __('Submission ID %1$d: Email "%2$s" is used by multiple CPF/RF (conflict detected)', 'ffcertificate'),
                            $submission_id,
                            $email
                        );
                        continue; // SKIP this submission
                    }
                }

                // Email exists → add capabilities (keep existing roles)
                $existing_user->add_role('ffc_user');

                // Update display name if empty
                if (empty($existing_user->display_name) || $existing_user->display_name === $existing_user->user_login) {
                    self::set_user_display_name($user_id, $submission);
                }

            } else {
                // STEP 5: Email doesn't exist → Create new user
                $name_data = self::extract_name_data($submission);
                $username = class_exists('\FreeFormCertificate\UserDashboard\UserManager')
                    ? \FreeFormCertificate\UserDashboard\UserManager::generate_username($email, $name_data)
                    : 'ffc_' . wp_generate_password(8, false, false);
                $user_id = wp_create_user(
                    $username,
                    wp_generate_password(24, true, true),
                    $email
                );

                if (is_wp_error($user_id)) {
                    $errors[] = sprintf(
                        /* translators: %d: submission ID, %s: error message */
                        __('Submission ID %1$d: Failed to create user - %2$s', 'ffcertificate'),
                        $submission_id,
                        $user_id->get_error_message()
                    );
                    continue;
                }

                // Set role
                $user = new WP_User($user_id);
                $user->set_role('ffc_user');

                // STEP 5.1: Extract and set user display name from submission data
                self::set_user_display_name($user_id, $submission);

                // STEP 5.2: Send password reset email if enabled (default: disabled)
                if (class_exists('\FreeFormCertificate\Integrations\EmailHandler')) {
                    $email_handler = new \FreeFormCertificate\Integrations\EmailHandler();
                    $email_handler->send_wp_user_notification($user_id, 'migration');
                }
            }

            // STEP 6: Update submission with user_id
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table,
                array('user_id' => $user_id),
                array('id' => $submission_id),
                array('%d'),
                array('%d')
            );

            // Track processed CPF/RF and email
            $processed_cpfs[$cpf_rf_hash] = $user_id;
            $email_to_user[$email] = $cpf_rf_hash;
            $processed_count++;
        }

        // STEP 7: Bulk update all submissions with same CPF/RF
        foreach ($processed_cpfs as $cpf_hash => $user_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "UPDATE %i
                 SET user_id = %d
                 WHERE cpf_rf_hash = %s
                 AND user_id IS NULL",
                $table,
                $user_id,
                $cpf_hash
            ));
        }

        // Log errors if any
        if (!empty($errors)) {
            \FreeFormCertificate\Core\Debug::log_migrations('Migration User Link - Errors', $errors);

            // Store errors in option for admin review
            update_option('ffc_migration_user_link_errors', $errors);
        }

        return array(
            'success' => true,
            'processed' => $processed_count,
            'errors' => count($errors),
            'message' => sprintf(
                /* translators: %d: number of records, %d: number of records */
                __('Migration completed: %1$d users linked, %2$d errors', 'ffcertificate'),
                $processed_count,
                count($errors)
            ),
        );
    }

    /**
     * Extract name data from encrypted submission for username generation
     *
     * @since 4.9.6
     * @param array<string, mixed> $submission Submission data row (includes data_encrypted)
     * @return array<string, mixed> Submission data array with name fields
     */
    private static function extract_name_data(array $submission): array {
        if (empty($submission['data_encrypted'])) {
            return array();
        }

        try {
            $data_json = \FreeFormCertificate\Core\Encryption::decrypt($submission['data_encrypted']);
            $data = json_decode($data_json, true);
            return is_array($data) ? $data : array();
        } catch (\Exception $e) {
            return array();
        }
    }

    /**
     * Extract name from submission data and set user display name
     *
     * @param int $user_id WordPress user ID
     * @param array<string, mixed> $submission Submission data row (includes data_encrypted)
     * @return void
     */
    private static function set_user_display_name(int $user_id, array $submission): void {
        // Try to decrypt and extract name from submission data
        if (empty($submission['data_encrypted'])) {
            return;
        }

        try {
            $data_json = \FreeFormCertificate\Core\Encryption::decrypt($submission['data_encrypted']);
            $data = json_decode($data_json, true);

            if (!is_array($data)) {
                return;
            }

            // Try to extract nome_completo from various field names
            $nome_completo = '';
            $possible_names = array('nome_completo', 'nome', 'name', 'full_name', 'ffc_nome');

            foreach ($possible_names as $field) {
                if (!empty($data[$field])) {
                    $nome_completo = $data[$field];
                    break;
                }
            }

            // Update WordPress user fields if name found
            if (!empty($nome_completo)) {
                wp_update_user(array(
                    'ID' => $user_id,
                    'display_name' => sanitize_text_field($nome_completo),
                    'first_name' => sanitize_text_field($nome_completo),
                ));
            }

        } catch (\Exception $e) {
            // Silently fail - name is not critical for user creation
            \FreeFormCertificate\Core\Debug::log_migrations('Failed to extract name for user', array(
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ));
        }
    }

    /**
     * Add user_id column to submissions table
     *
     * @param string $table Table name
     * @return bool True if column added or already exists
     */
    private static function add_user_id_column(string $table): bool {
        global $wpdb;

        // Add user_id column + index if missing (trait handles existence checks)
        self::add_column_if_missing($table, 'user_id', 'BIGINT UNSIGNED DEFAULT NULL', 'form_id', 'user_id');

        return true;
    }
}
