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

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * Context constants for capability granting
     */
    public const CONTEXT_CERTIFICATE = 'certificate';
    public const CONTEXT_APPOINTMENT = 'appointment';
    public const CONTEXT_AUDIENCE = 'audience';

    /**
     * Get or create WordPress user based on CPF/RF and email
     *
     * Flow:
     * 1. Check if CPF/RF hash already has user_id in submissions table
     * 2. If yes: return existing user_id (and add context-specific capabilities)
     * 3. If no: check if email exists in WordPress
     * 4. If yes: link to existing user (add role + context-specific capabilities)
     * 5. If no: create new user (with only context-specific capabilities)
     *
     * @param string $cpf_rf_hash Hash of CPF or RF
     * @param string $email Plain email address
     * @param array $submission_data Optional submission data for user creation
     * @param string $context Context for capability granting ('certificate' or 'appointment')
     * @return int|WP_Error User ID or error
     */
    public static function get_or_create_user(string $cpf_rf_hash, string $email, array $submission_data = array(), string $context = self::CONTEXT_CERTIFICATE) {
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
            // CPF/RF already linked → grant context-specific capabilities and return
            self::grant_context_capabilities((int) $existing_user_id, $context);
            self::link_orphaned_records($cpf_rf_hash, (int) $existing_user_id);
            return (int) $existing_user_id;
        }

        // STEP 2: CPF/RF is new → check if email exists in WordPress
        $existing_user = get_user_by('email', $email);

        if ($existing_user) {
            // Email exists → add FFC role + context-specific capabilities
            $user_id = $existing_user->ID;

            // Add ffc_user role (keeps other roles)
            $existing_user->add_role('ffc_user');

            // Grant context-specific capabilities
            self::grant_context_capabilities($user_id, $context);

            // Update display name if empty (username as fallback is not user-friendly)
            if (empty($existing_user->display_name) || $existing_user->display_name === $existing_user->user_login) {
                self::sync_user_metadata($user_id, $submission_data);
            }

            self::link_orphaned_records($cpf_rf_hash, $user_id);
            return $user_id;
        }

        // STEP 3: Email is also new → create new user with context-specific capabilities
        $user_id = self::create_ffc_user($email, $submission_data, $context);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        self::link_orphaned_records($cpf_rf_hash, $user_id);
        return $user_id;
    }

    /**
     * Grant capabilities based on context
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @param string $context Context ('certificate' or 'appointment')
     * @return void
     */
    private static function grant_context_capabilities(int $user_id, string $context): void {
        switch ($context) {
            case self::CONTEXT_CERTIFICATE:
                self::grant_certificate_capabilities($user_id);
                break;
            case self::CONTEXT_APPOINTMENT:
                self::grant_appointment_capabilities($user_id);
                break;
            case self::CONTEXT_AUDIENCE:
                self::grant_audience_capabilities($user_id);
                break;
        }
    }

    /**
     * Link orphaned records (submissions and appointments) to a user
     *
     * When a user is found/created, link any existing submissions or appointments
     * that share the same cpf_rf_hash but have no user_id assigned.
     *
     * @since 4.9.6
     * @param string $cpf_rf_hash Hash of CPF or RF
     * @param int $user_id WordPress user ID
     * @return void
     */
    private static function link_orphaned_records(string $cpf_rf_hash, int $user_id): void {
        global $wpdb;

        // Link orphaned submissions
        $submissions_table = \FreeFormCertificate\Core\Utils::get_submissions_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $linked_submissions = $wpdb->query($wpdb->prepare(
            "UPDATE {$submissions_table} SET user_id = %d WHERE cpf_rf_hash = %s AND user_id IS NULL",
            $user_id,
            $cpf_rf_hash
        ));

        // Link orphaned appointments
        $appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        $linked_appointments = 0;
        if (self::table_exists($appointments_table)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $linked_appointments = $wpdb->query($wpdb->prepare(
                "UPDATE {$appointments_table} SET user_id = %d WHERE cpf_rf_hash = %s AND user_id IS NULL",
                $user_id,
                $cpf_rf_hash
            ));

            // If appointments were linked, grant appointment capabilities
            if ($linked_appointments > 0) {
                self::grant_appointment_capabilities($user_id);
            }
        }

        if ($linked_submissions > 0 || $linked_appointments > 0) {
            if (class_exists('\FreeFormCertificate\Core\Debug')) {
                \FreeFormCertificate\Core\Debug::log_user_manager(
                    'Linked orphaned records',
                    array(
                        'user_id' => $user_id,
                        'submissions_linked' => $linked_submissions,
                        'appointments_linked' => $linked_appointments,
                    )
                );
            }
        }
    }

    /**
     * Create new WordPress user for FFC
     *
     * Creates user with ffc_user role but only grants context-specific capabilities.
     *
     * @param string $email Email address (used as username and email)
     * @param array $submission_data Submission data for user metadata
     * @param string $context Context for capability granting ('certificate' or 'appointment')
     * @return int|WP_Error User ID or error
     */
    private static function create_ffc_user(string $email, array $submission_data = array(), string $context = self::CONTEXT_CERTIFICATE) {
        // Generate strong random password
        $password = wp_generate_password(24, true, true);

        // Generate username from name (not email)
        $username = self::generate_username($email, $submission_data);
        $user_id = wp_create_user($username, $password, $email);

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

        // Set ffc_user role (provides base capabilities from role)
        $user = new \WP_User($user_id);
        $user->set_role('ffc_user');

        // Grant only context-specific capabilities
        // (role has all caps as false, user_meta is the source of truth)
        self::grant_context_capabilities($user_id, $context);

        // Sync user metadata from submission (only on creation)
        self::sync_user_metadata($user_id, $submission_data);

        // Create user profile entry
        self::create_user_profile($user_id);

        // Send welcome email via Email Handler (respects per-context settings)
        if (!class_exists('\FreeFormCertificate\Integrations\EmailHandler')) {
            $email_handler_file = FFC_PLUGIN_DIR . 'includes/integrations/class-ffc-email-handler.php';
            if (file_exists($email_handler_file)) {
                require_once $email_handler_file;
            }
        }

        if (class_exists('\FreeFormCertificate\Integrations\EmailHandler')) {
            $email_context = $context === self::CONTEXT_APPOINTMENT ? 'appointment' : 'submission';
            $email_handler = new \FreeFormCertificate\Integrations\EmailHandler();
            $email_handler->send_wp_user_notification($user_id, $email_context);
        }

        return $user_id;
    }

    /**
     * Reset all FFC capabilities for a user to false
     *
     * Used when creating a new user to remove role-granted capabilities
     * before adding context-specific ones.
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return void
     */
    private static function reset_user_ffc_capabilities(int $user_id): void {
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        // Set all FFC capabilities to false
        foreach (self::get_all_capabilities() as $cap) {
            $user->add_cap($cap, false);
        }
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
     * Generate a unique username for a new FFC user
     *
     * Priority:
     * 1. Slug from name in submission_data (e.g. "joao.silva")
     * 2. Fallback: "ffc_" + random 8-char string
     *
     * Never uses the email as username (privacy concern).
     *
     * @since 4.9.6
     * @param string $email Email (used only as last-resort fallback, not as username)
     * @param array $submission_data Submission data containing name fields
     * @return string Unique username
     */
    public static function generate_username(string $email, array $submission_data = array()): string {
        $possible_names = array('nome_completo', 'nome', 'name', 'full_name', 'ffc_nome');
        $name = '';

        foreach ($possible_names as $field) {
            if (!empty($submission_data[$field]) && is_string($submission_data[$field])) {
                $name = trim($submission_data[$field]);
                break;
            }
        }

        if (!empty($name)) {
            // Generate slug from name: remove accents, lowercase, keep alphanumeric + dots
            $slug = sanitize_user(remove_accents(strtolower($name)), true);
            $slug = preg_replace('/[^a-z0-9._-]/', '', $slug);
            $slug = preg_replace('/[-_.]+/', '.', $slug);
            $slug = trim($slug, '.');

            if (strlen($slug) >= 3) {
                if (!username_exists($slug)) {
                    return $slug;
                }

                // Try with numeric suffix
                for ($i = 2; $i <= 99; $i++) {
                    $candidate = $slug . '.' . $i;
                    if (!username_exists($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        // Fallback: ffc_ + random string
        do {
            $username = 'ffc_' . wp_generate_password(8, false, false);
        } while (username_exists($username));

        return $username;
    }

    /**
     * Create user profile entry in ffc_user_profiles
     *
     * Called once during user creation. Silently skips if table doesn't exist
     * or profile already exists (idempotent).
     *
     * @since 4.9.4
     * @param int $user_id WordPress user ID
     * @return void
     */
    private static function create_user_profile(int $user_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_user_profiles';

        if (!self::table_exists($table)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d",
            $user_id
        ));

        if ($exists) {
            return;
        }

        $user = get_userdata($user_id);
        $display_name = $user ? $user->display_name : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $table,
            array(
                'user_id' => $user_id,
                'display_name' => $display_name,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s')
        );
    }

    /**
     * Get user profile from ffc_user_profiles
     *
     * Falls back to wp_users data if profile doesn't exist.
     *
     * @since 4.9.4
     * @param int $user_id WordPress user ID
     * @return array Profile data
     */
    public static function get_profile(int $user_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_user_profiles';

        if (self::table_exists($table)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $profile = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d",
                $user_id
            ), ARRAY_A);

            if ($profile) {
                return $profile;
            }
        }

        // Fallback to wp_users
        $user = get_userdata($user_id);
        if (!$user) {
            return array();
        }

        return array(
            'user_id' => $user_id,
            'display_name' => $user->display_name,
            'phone' => '',
            'department' => '',
            'organization' => '',
            'notes' => '',
            'preferences' => null,
            'created_at' => $user->user_registered,
            'updated_at' => $user->user_registered,
        );
    }

    /**
     * Update user profile in ffc_user_profiles
     *
     * Creates profile if it doesn't exist (upsert). Keeps wp_users.display_name in sync.
     *
     * @since 4.9.4
     * @param int $user_id WordPress user ID
     * @param array $data Profile fields to update
     * @return bool True on success
     */
    public static function update_profile(int $user_id, array $data): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_user_profiles';

        if (!self::table_exists($table)) {
            return false;
        }

        $allowed = array('display_name', 'phone', 'department', 'organization', 'notes');
        $update_data = array();
        $formats = array();

        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = sanitize_text_field($data[$field]);
                $formats[] = '%s';
            }
        }

        // Handle preferences as JSON
        if (isset($data['preferences']) && is_array($data['preferences'])) {
            $update_data['preferences'] = wp_json_encode($data['preferences']);
            $formats[] = '%s';
        }

        if (empty($update_data)) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d",
            $user_id
        ));

        if ($exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update($table, $update_data, array('user_id' => $user_id), $formats, array('%d'));
        } else {
            $update_data['user_id'] = $user_id;
            $update_data['created_at'] = current_time('mysql');
            $formats[] = '%d';
            $formats[] = '%s';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->insert($table, $update_data, $formats);
        }

        // Keep wp_users.display_name in sync
        if (isset($data['display_name'])) {
            wp_update_user(array(
                'ID' => $user_id,
                'display_name' => sanitize_text_field($data['display_name']),
            ));
        }

        return $result !== false;
    }

    /**
     * All certificate-related capabilities
     */
    public const CERTIFICATE_CAPABILITIES = array(
        'view_own_certificates',
        'download_own_certificates',
        'view_certificate_history',
    );

    /**
     * All appointment-related capabilities
     */
    public const APPOINTMENT_CAPABILITIES = array(
        'ffc_book_appointments',
        'ffc_view_self_scheduling',
        'ffc_cancel_own_appointments',
    );

    /**
     * All audience-related capabilities
     *
     * @since 4.9.3
     */
    public const AUDIENCE_CAPABILITIES = array(
        'ffc_view_audience_bookings',
    );

    /**
     * Admin-level capabilities (not granted by default)
     *
     * @since 4.9.3
     */
    public const ADMIN_CAPABILITIES = array(
        'ffc_scheduling_bypass',
        'ffc_manage_reregistration',
    );

    /**
     * Future capabilities (disabled by default)
     *
     * @since 4.9.3
     */
    public const FUTURE_CAPABILITIES = array(
        'ffc_reregistration',
        'ffc_certificate_update',
    );

    /**
     * Get all FFC capabilities consolidated
     *
     * @since 4.9.3
     * @return array All FFC capability names
     */
    public static function get_all_capabilities(): array {
        return array_merge(
            self::CERTIFICATE_CAPABILITIES,
            self::APPOINTMENT_CAPABILITIES,
            self::AUDIENCE_CAPABILITIES,
            self::ADMIN_CAPABILITIES,
            self::FUTURE_CAPABILITIES
        );
    }

    /**
     * Register ffc_user role on plugin activation
     *
     * @return void
     */
    public static function register_role(): void {
        // Check if role already exists
        $existing_role = get_role('ffc_user');

        if ($existing_role) {
            // Role exists - upgrade it with new capabilities if missing
            self::upgrade_role($existing_role);
            return;
        }

        // Add ffc_user role — all FFC capabilities start as false.
        // Capabilities are granted per-user via user_meta (source of truth).
        $capabilities = array('read' => true);
        foreach (self::get_all_capabilities() as $cap) {
            $capabilities[$cap] = false;
        }

        add_role(
            'ffc_user',
            __('FFC User', 'ffcertificate'),
            $capabilities
        );
    }

    /**
     * Upgrade existing ffc_user role with new capabilities
     *
     * Called when role exists but may be missing newer capabilities.
     *
     * @since 4.4.0
     * @param \WP_Role $role Existing role object
     * @return void
     */
    private static function upgrade_role(\WP_Role $role): void {
        // Add any missing capabilities as false (per-user grants via user_meta)
        foreach (self::get_all_capabilities() as $cap) {
            if (!isset($role->capabilities[$cap])) {
                $role->add_cap($cap, false);
            }
        }
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

    /**
     * Grant certificate capabilities to a user
     *
     * Adds certificate-related capabilities without removing existing capabilities.
     * Used when user submits a certificate form.
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return void
     */
    public static function grant_certificate_capabilities(int $user_id): void {
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        $newly_granted = array();

        // Grant each certificate capability
        foreach (self::CERTIFICATE_CAPABILITIES as $cap) {
            if (!$user->has_cap($cap)) {
                $user->add_cap($cap, true);
                $newly_granted[] = $cap;
            }
        }

        // Log capability grant
        if (class_exists('\FreeFormCertificate\Core\Debug')) {
            \FreeFormCertificate\Core\Debug::log_user_manager(
                'Granted certificate capabilities',
                array(
                    'user_id' => $user_id,
                    'capabilities' => self::CERTIFICATE_CAPABILITIES,
                )
            );
        }

        if (!empty($newly_granted)) {
            self::log_and_notify_capability_grant($user, 'certificate', $newly_granted);
        }
    }

    /**
     * Grant appointment capabilities to a user
     *
     * Adds appointment-related capabilities without removing existing capabilities.
     * Used when user creates an appointment.
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return void
     */
    public static function grant_appointment_capabilities(int $user_id): void {
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        $newly_granted = array();

        // Grant each appointment capability
        foreach (self::APPOINTMENT_CAPABILITIES as $cap) {
            if (!$user->has_cap($cap)) {
                $user->add_cap($cap, true);
                $newly_granted[] = $cap;
            }
        }

        // Log capability grant
        if (class_exists('\FreeFormCertificate\Core\Debug')) {
            \FreeFormCertificate\Core\Debug::log_user_manager(
                'Granted appointment capabilities',
                array(
                    'user_id' => $user_id,
                    'capabilities' => self::APPOINTMENT_CAPABILITIES,
                )
            );
        }

        if (!empty($newly_granted)) {
            self::log_and_notify_capability_grant($user, 'appointment', $newly_granted);
        }
    }

    /**
     * Grant audience capabilities to a user
     *
     * Adds audience-related capabilities without removing existing capabilities.
     * Used when user is added to an audience group.
     *
     * @since 4.9.3
     * @param int $user_id WordPress user ID
     * @return void
     */
    public static function grant_audience_capabilities(int $user_id): void {
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        $newly_granted = array();

        // Grant each audience capability
        foreach (self::AUDIENCE_CAPABILITIES as $cap) {
            if (!$user->has_cap($cap)) {
                $user->add_cap($cap, true);
                $newly_granted[] = $cap;
            }
        }

        // Log capability grant
        if (class_exists('\FreeFormCertificate\Core\Debug')) {
            \FreeFormCertificate\Core\Debug::log_user_manager(
                'Granted audience capabilities',
                array(
                    'user_id' => $user_id,
                    'capabilities' => self::AUDIENCE_CAPABILITIES,
                )
            );
        }

        if (!empty($newly_granted)) {
            self::log_and_notify_capability_grant($user, 'audience', $newly_granted);
        }
    }

    /**
     * Log capability grant to activity log and send email notification
     *
     * @since 4.9.9
     * @param \WP_User $user        User who received capabilities
     * @param string   $context     Context: 'certificate', 'appointment', 'audience'
     * @param array    $capabilities Newly granted capabilities
     * @return void
     */
    private static function log_and_notify_capability_grant(\WP_User $user, string $context, array $capabilities): void {
        // Activity log
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log_capabilities_granted(
                $user->ID,
                $context,
                $capabilities
            );
        }

        // Email notification (check settings)
        $settings = get_option('ffc_settings', array());
        $notify_enabled = !empty($settings['notify_capability_grant']);

        if (!$notify_enabled || empty($user->user_email)) {
            return;
        }

        $site_name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        $dashboard_url = get_permalink(get_option('ffc_dashboard_page_id'));

        $context_labels = array(
            'certificate' => __('Certificates', 'ffcertificate'),
            'appointment' => __('Appointments', 'ffcertificate'),
            'audience'    => __('Audience Groups', 'ffcertificate'),
        );
        $context_label = $context_labels[$context] ?? $context;

        /* translators: %1$s: site name, %2$s: feature name */
        $subject = sprintf(__('[%1$s] Access granted: %2$s', 'ffcertificate'), $site_name, $context_label);

        /* translators: %s: user display name */
        $message  = sprintf(__('Hello %s,', 'ffcertificate'), $user->display_name) . "\n\n";
        /* translators: %1$s: feature name, %2$s: site name */
        $message .= sprintf(__('You now have access to %1$s on %2$s.', 'ffcertificate'), $context_label, $site_name) . "\n\n";

        if ($dashboard_url) {
            /* translators: %s: dashboard URL */
            $message .= sprintf(__('Access your dashboard: %s', 'ffcertificate'), $dashboard_url) . "\n\n";
        }

        $message .= __('This is an automated message.', 'ffcertificate') . "\n";

        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Check if user has any certificate capabilities
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return bool True if user has any certificate capability
     */
    public static function has_certificate_access(int $user_id): bool {
        $user = get_userdata($user_id);

        if (!$user) {
            return false;
        }

        // Admin always has access
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        // Check each certificate capability
        foreach (self::CERTIFICATE_CAPABILITIES as $cap) {
            if ($user->has_cap($cap)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has any appointment capabilities
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return bool True if user has any appointment capability
     */
    public static function has_appointment_access(int $user_id): bool {
        $user = get_userdata($user_id);

        if (!$user) {
            return false;
        }

        // Admin always has access
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        // Check each appointment capability
        foreach (self::APPOINTMENT_CAPABILITIES as $cap) {
            if ($user->has_cap($cap)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all FFC capabilities for a user
     *
     * Returns an array of all FFC-related capabilities and their status.
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return array Associative array of capability => boolean
     */
    public static function get_user_ffc_capabilities(int $user_id): array {
        $user = get_userdata($user_id);

        if (!$user) {
            return array();
        }

        $capabilities = array();

        foreach (self::get_all_capabilities() as $cap) {
            $capabilities[$cap] = $user->has_cap($cap);
        }

        return $capabilities;
    }

    /**
     * Set a specific FFC capability for a user
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @param string $capability Capability name
     * @param bool $grant Whether to grant (true) or revoke (false)
     * @return bool True on success, false on failure
     */
    public static function set_user_capability(int $user_id, string $capability, bool $grant): bool {
        $user = get_userdata($user_id);

        if (!$user) {
            return false;
        }

        // Validate capability is an FFC capability
        $all_ffc_caps = self::get_all_capabilities();

        if (!in_array($capability, $all_ffc_caps, true)) {
            return false;
        }

        if ($grant) {
            $user->add_cap($capability, true);
        } else {
            $user->add_cap($capability, false);
        }

        // Log capability change
        if (class_exists('\FreeFormCertificate\Core\Debug')) {
            \FreeFormCertificate\Core\Debug::log_user_manager(
                'User capability changed',
                array(
                    'user_id' => $user_id,
                    'capability' => $capability,
                    'granted' => $grant,
                )
            );
        }

        return true;
    }
}
