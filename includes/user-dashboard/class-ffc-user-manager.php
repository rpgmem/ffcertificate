<?php
declare(strict_types=1);

/**
 * UserManager
 *
 * Manages WordPress user creation and linking for FFC submissions.
 *
 * Logic:
 * 1. Check if CPF/RF already has user_id (in submissions table)
 * 2. If not, check if email exists in WordPress
 * 3. If not, create new user
 *
 * Important:
 * - Same user can have BOTH CPF and RF linked (via same email)
 * - Email is used as the linking key when CPF/RF is new
 * - Priority: CPF/RF > Email
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since 3.1.0
 */

namespace FreeFormCertificate\UserDashboard;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class UserManager {

    /**
     * Get or create WordPress user based on CPF/RF and email
     *
     * Flow:
     * 1. Check if CPF/RF hash already has user_id in submissions table
     * 2. If yes: return existing user_id
     * 3. If no: check if email exists in WordPress
     * 4. If yes: link to existing user (add role/capabilities)
     * 5. If no: create new user
     *
     * @param string $cpf_rf_hash Hash of CPF or RF
     * @param string $email Plain email address
     * @param array $submission_data Optional submission data for user creation
     * @return int|WP_Error User ID or error
     */
    public static function get_or_create_user(string $cpf_rf_hash, string $email, array $submission_data = array()) {
        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // STEP 1: Check if CPF/RF already has user_id in submissions
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing_user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$table}
             WHERE cpf_rf_hash = %s
             AND user_id IS NOT NULL
             LIMIT 1",
            $cpf_rf_hash
        ));

        if ($existing_user_id) {
            // CPF/RF already linked → return existing user_id
            return (int) $existing_user_id;
        }

        // STEP 2: CPF/RF is new → check if email exists in WordPress
        $existing_user = get_user_by('email', $email);

        if ($existing_user) {
            // Email exists → add FFC capabilities (keep existing roles)
            $user_id = $existing_user->ID;

            // Add ffc_user role (keeps other roles)
            $existing_user->add_role('ffc_user');

            // Update display name if empty (username as fallback is not user-friendly)
            if (empty($existing_user->display_name) || $existing_user->display_name === $existing_user->user_login) {
                self::sync_user_metadata($user_id, $submission_data);
            }

            return $user_id;
        }

        // STEP 3: Email is also new → create new user
        $user_id = self::create_ffc_user($email, $submission_data);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        return $user_id;
    }

    /**
     * Create new WordPress user for FFC
     *
     * @param string $email Email address (used as username and email)
     * @param array $submission_data Submission data for user metadata
     * @return int|WP_Error User ID or error
     */
    private static function create_ffc_user(string $email, array $submission_data = array()) {
        // Generate strong random password
        $password = wp_generate_password(24, true, true);

        // Create user (username = email)
        $user_id = wp_create_user($email, $password, $email);

        if (is_wp_error($user_id)) {
            // Use centralized debug system for critical errors
            if (class_exists('\FreeFormCertificate\Core\Debug')) {
                \FreeFormCertificate\Core\Debug::log_user_manager(
                    'Failed to create user',
                    array(
                        'email' => $email,
                        'error' => $user_id->get_error_message()
                    )
                );
            }
            return $user_id;
        }

        // Set role
        $user = new WP_User($user_id);
        $user->set_role('ffc_user');

        // Sync user metadata from submission (only on creation)
        self::sync_user_metadata($user_id, $submission_data);

        // Send password reset email via Email Handler (respects settings)
        if (!isset($submission_data['skip_email'])) {
            // Load Email Handler if not already loaded
            if (!class_exists('\FreeFormCertificate\Integrations\EmailHandler')) {
                $email_handler_file = FFC_PLUGIN_DIR . 'includes/integrations/class-ffc-email-handler.php';
                if (file_exists($email_handler_file)) {
                    require_once $email_handler_file;
                }
            }

            if (class_exists('\FreeFormCertificate\Integrations\EmailHandler')) {
                $email_handler = new \FreeFormCertificate\Integrations\EmailHandler();
                $email_handler->send_wp_user_notification($user_id, 'submission');
            }
        }

        return $user_id;
    }

    /**
     * Sync user metadata from submission data
     *
     * Only runs on user creation, NOT on subsequent submissions
     *
     * @param int $user_id WordPress user ID
     * @param array $submission_data Submission data
     * @return void
     */
    private static function sync_user_metadata(int $user_id, array $submission_data): void {
        if (empty($submission_data)) {
            return;
        }

        // Try to extract nome_completo from various field names
        $nome_completo = '';
        $possible_names = array('nome_completo', 'nome', 'name', 'full_name', 'ffc_nome');

        foreach ($possible_names as $field) {
            if (!empty($submission_data[$field])) {
                $nome_completo = $submission_data[$field];
                break;
            }
        }

        // Update WordPress user fields
        if (!empty($nome_completo)) {
            wp_update_user(array(
                'ID' => $user_id,
                'display_name' => $nome_completo,
                'first_name' => $nome_completo,
            ));
        }

        // Store registration date
        update_user_meta($user_id, 'ffc_registration_date', current_time('mysql'));
    }

    /**
     * Register ffc_user role on plugin activation
     *
     * @return void
     */
    public static function register_role(): void {
        // Check if role already exists
        if (get_role('ffc_user')) {
            return;
        }

        // Add ffc_user role
        add_role(
            'ffc_user',
            __('FFC User', 'wp-ffcertificate'),
            array(
                'read' => true,
                'view_own_certificates' => true,
                'download_own_certificates' => true,
                'view_certificate_history' => true,

                // Future capabilities (disabled by default)
                'ffc_reregistration' => false,
                'ffc_certificate_update' => false,
            )
        );
    }

    /**
     * Remove ffc_user role on plugin deactivation
     *
     * @return void
     */
    public static function remove_role(): void {
        remove_role('ffc_user');
    }

    /**
     * Get user's CPF/RF (masked)
     *
     * Returns the first CPF/RF found in user's submissions (masked)
     *
     * @param int $user_id WordPress user ID
     * @return string|null Masked CPF/RF or null if not found
     */
    public static function get_user_cpf_masked(int $user_id): ?string {
        $cpfs = self::get_user_cpfs_masked($user_id);
        return !empty($cpfs) ? $cpfs[0] : null;
    }

    /**
     * Get all user's CPF/RF values (masked)
     *
     * Returns all distinct CPF/RF found in user's submissions (masked)
     *
     * @since 4.3.0
     * @param int $user_id WordPress user ID
     * @return array Array of masked CPF/RF values
     */
    public static function get_user_cpfs_masked(int $user_id): array {
        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // Get all distinct encrypted CPF/RF values
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $encrypted_cpfs = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT cpf_rf_encrypted FROM {$table}
             WHERE user_id = %d
             AND cpf_rf_encrypted IS NOT NULL
             AND cpf_rf_encrypted != ''",
            $user_id
        ));

        if (empty($encrypted_cpfs)) {
            return array();
        }

        $cpfs_masked = array();

        foreach ($encrypted_cpfs as $cpf_encrypted) {
            try {
                $cpf_plain = \FreeFormCertificate\Core\Encryption::decrypt($cpf_encrypted);
                if (!empty($cpf_plain)) {
                    $masked = self::mask_cpf_rf($cpf_plain);
                    // Avoid duplicates (same CPF could be encrypted differently)
                    if (!in_array($masked, $cpfs_masked, true)) {
                        $cpfs_masked[] = $masked;
                    }
                }
            } catch (\Exception $e) {
                // Use centralized debug system for critical errors
                if (class_exists('\FreeFormCertificate\Core\Debug')) {
                    \FreeFormCertificate\Core\Debug::log_user_manager(
                        'Failed to decrypt CPF/RF',
                        array(
                            'user_id' => $user_id,
                            'error' => $e->getMessage()
                        )
                    );
                }
                continue;
            }
        }

        return $cpfs_masked;
    }

    /**
     * Mask CPF/RF for display
     *
     * CPF (11 digits): 123.456.789-01 → ***.***.**9-01
     * RF (7 digits): 1234567 → ****567
     *
     * @param string $cpf_rf CPF or RF (plain)
     * @return string Masked value
     */
    private static function mask_cpf_rf(string $cpf_rf): string {
        // Remove any formatting
        $clean = preg_replace('/[^0-9]/', '', $cpf_rf);

        if (strlen($clean) === 11) {
            // CPF: Show last 4 digits
            return '***.***.' . substr($clean, 7, 2) . '-' . substr($clean, 9, 2);
        } elseif (strlen($clean) === 7) {
            // RF: Show last 3 digits
            return '****' . substr($clean, 4, 3);
        }

        // Unknown format: mask all but last 3
        return str_repeat('*', strlen($clean) - 3) . substr($clean, -3);
    }

    /**
     * Get all emails used by a user in submissions
     *
     * Returns distinct emails found in user's submissions
     *
     * @param int $user_id WordPress user ID
     * @return array Array of emails
     */
    public static function get_user_emails(int $user_id): array {
        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // Get distinct encrypted emails
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $encrypted_emails = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT email_encrypted FROM {$table}
             WHERE user_id = %d
             AND email_encrypted IS NOT NULL
             AND email_encrypted != ''",
            $user_id
        ));

        if (empty($encrypted_emails)) {
            // Fallback to WordPress user email
            $user = get_user_by('id', $user_id);
            return $user ? array($user->user_email) : array();
        }

        $emails = array();

        foreach ($encrypted_emails as $encrypted) {
            try {
                $email = \FreeFormCertificate\Core\Encryption::decrypt($encrypted);
                if (is_email($email)) {
                    $emails[] = $email;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Remove duplicates and ensure WordPress email is included
        $user = get_user_by('id', $user_id);
        if ($user && is_email($user->user_email)) {
            $emails[] = $user->user_email;
        }

        return array_unique($emails);
    }

    /**
     * Get all distinct names used by a user in submissions
     *
     * Returns distinct names found in user's submissions (from JSON data)
     *
     * @since 4.3.0
     * @param int $user_id WordPress user ID
     * @return array Array of names
     */
    public static function get_user_names(int $user_id): array {
        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // Get all submissions data for this user
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $submissions = $wpdb->get_col($wpdb->prepare(
            "SELECT data FROM {$table}
             WHERE user_id = %d
             AND data IS NOT NULL
             AND data != ''",
            $user_id
        ));

        if (empty($submissions)) {
            // Fallback to WordPress user display name
            $user = get_user_by('id', $user_id);
            return $user ? array($user->display_name) : array();
        }

        $names = array();
        $possible_name_fields = array('nome_completo', 'nome', 'name', 'full_name', 'ffc_nome', 'participante');

        foreach ($submissions as $data_json) {
            $data = json_decode($data_json, true);

            if (!is_array($data)) {
                continue;
            }

            // Search for name in various field names
            foreach ($possible_name_fields as $field) {
                if (!empty($data[$field]) && is_string($data[$field])) {
                    $name = trim($data[$field]);
                    if (!empty($name) && !in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                    break; // Found name in this submission, move to next
                }
            }
        }

        // If no names found in submissions, use WordPress display name
        if (empty($names)) {
            $user = get_user_by('id', $user_id);
            if ($user && !empty($user->display_name)) {
                $names[] = $user->display_name;
            }
        }

        return $names;
    }
}
