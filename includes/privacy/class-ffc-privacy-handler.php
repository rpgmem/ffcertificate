<?php
declare(strict_types=1);

/**
 * PrivacyHandler
 *
 * Integrates with WordPress Privacy Tools (Tools > Export/Erase Personal Data)
 * for LGPD/GDPR compliance.
 *
 * Registers:
 * - Personal Data Exporters: exports user data from all FFC tables
 * - Personal Data Erasers: anonymizes/deletes user data from all FFC tables
 *
 * @since 4.9.5
 * @package FreeFormCertificate\Privacy
 */

namespace FreeFormCertificate\Privacy;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class PrivacyHandler {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * Items per page for batch processing
     */
    private const ITEMS_PER_PAGE = 50;

    /**
     * Initialize privacy hooks
     *
     * @return void
     */
    public static function init(): void {
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_erasers']);
    }

    /**
     * Register personal data exporters
     *
     * @param array<string, array<string, mixed>> $exporters Existing exporters
     * @return array<string, array<string, mixed>> Modified exporters
     */
    public static function register_exporters(array $exporters): array {
        $exporters['ffcertificate-profile'] = array(
            'exporter_friendly_name' => __('FFC Profile', 'ffcertificate'),
            'callback' => [__CLASS__, 'export_profile'],
        );
        $exporters['ffcertificate-certificates'] = array(
            'exporter_friendly_name' => __('FFC Certificates', 'ffcertificate'),
            'callback' => [__CLASS__, 'export_certificates'],
        );
        $exporters['ffcertificate-appointments'] = array(
            'exporter_friendly_name' => __('FFC Appointments', 'ffcertificate'),
            'callback' => [__CLASS__, 'export_appointments'],
        );
        $exporters['ffcertificate-audience-groups'] = array(
            'exporter_friendly_name' => __('FFC Audience Groups', 'ffcertificate'),
            'callback' => [__CLASS__, 'export_audience_groups'],
        );
        $exporters['ffcertificate-audience-bookings'] = array(
            'exporter_friendly_name' => __('FFC Audience Bookings', 'ffcertificate'),
            'callback' => [__CLASS__, 'export_audience_bookings'],
        );
        $exporters['ffcertificate-usermeta'] = array(
            'exporter_friendly_name' => __('FFC User Settings', 'ffcertificate'),
            'callback' => [__CLASS__, 'export_usermeta'],
        );
        return $exporters;
    }

    /**
     * Register personal data erasers
     *
     * @param array<string, array<string, mixed>> $erasers Existing erasers
     * @return array<string, array<string, mixed>> Modified erasers
     */
    public static function register_erasers(array $erasers): array {
        $erasers['ffcertificate'] = array(
            'eraser_friendly_name' => __('Free Form Certificate', 'ffcertificate'),
            'callback' => [__CLASS__, 'erase_personal_data'],
        );
        return $erasers;
    }

    // ──────────────────────────────────────
    // EXPORTERS
    // ──────────────────────────────────────

    /**
     * Export user profile data
     *
     * @param string $email_address User email
     * @param int $page Page number
     * @return array<string, mixed>
     */
    public static function export_profile(string $email_address, int $page = 1): array {
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return array('data' => array(), 'done' => true);
        }

        // Only export on first page
        if ($page > 1) {
            return array('data' => array(), 'done' => true);
        }

        $export_items = array();
        $data = array();

        $data[] = array('name' => __('Display Name', 'ffcertificate'), 'value' => $user->display_name);
        $data[] = array('name' => __('Email', 'ffcertificate'), 'value' => $user->user_email);

        // Profile table data
        if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            $profile = \FreeFormCertificate\UserDashboard\UserManager::get_profile($user->ID);
            if (!empty($profile['phone'])) {
                $data[] = array('name' => __('Phone', 'ffcertificate'), 'value' => $profile['phone']);
            }
            if (!empty($profile['department'])) {
                $data[] = array('name' => __('Department', 'ffcertificate'), 'value' => $profile['department']);
            }
            if (!empty($profile['organization'])) {
                $data[] = array('name' => __('Organization', 'ffcertificate'), 'value' => $profile['organization']);
            }
        }

        $reg_date = get_user_meta($user->ID, 'ffc_registration_date', true);
        if (!empty($reg_date)) {
            $data[] = array('name' => __('Member Since', 'ffcertificate'), 'value' => $reg_date);
        }

        $export_items[] = array(
            'group_id' => 'ffc-profile',
            'group_label' => __('FFC Profile', 'ffcertificate'),
            'item_id' => 'ffc-profile-' . $user->ID,
            'data' => $data,
        );

        return array('data' => $export_items, 'done' => true);
    }

    /**
     * Export certificates (submissions)
     *
     * @param string $email_address User email
     * @param int $page Page number
     * @return array<string, mixed>
     */
    public static function export_certificates(string $email_address, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return array('data' => array(), 'done' => true);
        }

        $table = $wpdb->prefix . 'ffc_submissions';
        $offset = ($page - 1) * self::ITEMS_PER_PAGE;

        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.form_id, s.submission_date, s.auth_code, s.consent_given,
                    s.email_encrypted, p.post_title AS form_title
             FROM %i s
             LEFT JOIN %i p ON s.form_id = p.ID
             WHERE s.user_id = %d AND s.status != 'trash'
             ORDER BY s.submission_date DESC
             LIMIT %d OFFSET %d",
            $table,
            $wpdb->posts,
            $user->ID,
            self::ITEMS_PER_PAGE,
            $offset
        ), ARRAY_A);

        $export_items = array();

        foreach ($submissions as $sub) {
            $email_display = '';
            if (!empty($sub['email_encrypted']) && class_exists('\FreeFormCertificate\Core\Encryption')) {
                $plain = \FreeFormCertificate\Core\Encryption::decrypt($sub['email_encrypted']);
                $email_display = (is_string($plain) && !empty($plain)) ? $plain : '';
            }

            $auth_code = $sub['auth_code'] ?? '';
            if (strlen($auth_code) === 12) {
                $auth_code = substr($auth_code, 0, 4) . '-' . substr($auth_code, 4, 4) . '-' . substr($auth_code, 8, 4);
            }

            $data = array(
                array('name' => __('Form', 'ffcertificate'), 'value' => $sub['form_title'] ?? __('Unknown', 'ffcertificate')),
                array('name' => __('Submission Date', 'ffcertificate'), 'value' => $sub['submission_date'] ?? ''),
                array('name' => __('Auth Code', 'ffcertificate'), 'value' => $auth_code),
                array('name' => __('Email', 'ffcertificate'), 'value' => $email_display),
                array('name' => __('Consent Given', 'ffcertificate'), 'value' => !empty($sub['consent_given']) ? __('Yes', 'ffcertificate') : __('No', 'ffcertificate')),
            );

            $export_items[] = array(
                'group_id' => 'ffc-certificates',
                'group_label' => __('FFC Certificates', 'ffcertificate'),
                'item_id' => 'ffc-cert-' . $sub['id'],
                'data' => $data,
            );
        }

        $done = count($submissions) < self::ITEMS_PER_PAGE;

        return array('data' => $export_items, 'done' => $done);
    }

    /**
     * Export appointments
     *
     * @param string $email_address User email
     * @param int $page Page number
     * @return array<string, mixed>
     */
    public static function export_appointments(string $email_address, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return array('data' => array(), 'done' => true);
        }

        $table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        if (!self::table_exists($table)) {
            return array('data' => array(), 'done' => true);
        }

        $offset = ($page - 1) * self::ITEMS_PER_PAGE;

        $appointments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.status,
                    a.name, a.email_encrypted, a.phone_encrypted, a.user_notes,
                    p.post_title AS calendar_title
             FROM %i a
             LEFT JOIN %i p ON a.calendar_id = p.ID
             WHERE a.user_id = %d
             ORDER BY a.appointment_date DESC
             LIMIT %d OFFSET %d",
            $table,
            $wpdb->posts,
            $user->ID,
            self::ITEMS_PER_PAGE,
            $offset
        ), ARRAY_A);

        $export_items = array();

        foreach ($appointments as $appt) {
            $email_display = '';
            if (!empty($appt['email_encrypted']) && class_exists('\FreeFormCertificate\Core\Encryption')) {
                $plain = \FreeFormCertificate\Core\Encryption::decrypt($appt['email_encrypted']);
                $email_display = (is_string($plain) && !empty($plain)) ? $plain : '';
            }

            $phone_display = '';
            if (!empty($appt['phone_encrypted']) && class_exists('\FreeFormCertificate\Core\Encryption')) {
                $plain = \FreeFormCertificate\Core\Encryption::decrypt($appt['phone_encrypted']);
                $phone_display = (is_string($plain) && !empty($plain)) ? $plain : '';
            }

            $data = array(
                array('name' => __('Calendar', 'ffcertificate'), 'value' => $appt['calendar_title'] ?? __('Unknown', 'ffcertificate')),
                array('name' => __('Date', 'ffcertificate'), 'value' => $appt['appointment_date'] ?? ''),
                array('name' => __('Time', 'ffcertificate'), 'value' => ($appt['start_time'] ?? '') . ' - ' . ($appt['end_time'] ?? '')),
                array('name' => __('Status', 'ffcertificate'), 'value' => $appt['status'] ?? ''),
                array('name' => __('Name', 'ffcertificate'), 'value' => $appt['name'] ?? ''),
                array('name' => __('Email', 'ffcertificate'), 'value' => $email_display),
                array('name' => __('Phone', 'ffcertificate'), 'value' => $phone_display),
                array('name' => __('Notes', 'ffcertificate'), 'value' => $appt['user_notes'] ?? ''),
            );

            $export_items[] = array(
                'group_id' => 'ffc-appointments',
                'group_label' => __('FFC Appointments', 'ffcertificate'),
                'item_id' => 'ffc-appt-' . $appt['id'],
                'data' => $data,
            );
        }

        $done = count($appointments) < self::ITEMS_PER_PAGE;

        return array('data' => $export_items, 'done' => $done);
    }

    /**
     * Export audience group memberships
     *
     * @param string $email_address User email
     * @param int $page Page number
     * @return array<string, mixed>
     */
    public static function export_audience_groups(string $email_address, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return array('data' => array(), 'done' => true);
        }

        $members_table = $wpdb->prefix . 'ffc_audience_members';
        $audiences_table = $wpdb->prefix . 'ffc_audiences';

        if (!self::table_exists($members_table)) {
            return array('data' => array(), 'done' => true);
        }

        // Small dataset — no pagination needed
        if ($page > 1) {
            return array('data' => array(), 'done' => true);
        }

        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT a.name AS audience_name, a.color, m.created_at AS joined_date
             FROM %i m
             INNER JOIN %i a ON a.id = m.audience_id
             WHERE m.user_id = %d
             ORDER BY a.name ASC",
            $members_table,
            $audiences_table,
            $user->ID
        ), ARRAY_A);

        $export_items = array();

        foreach ($groups as $group) {
            $data = array(
                array('name' => __('Audience Name', 'ffcertificate'), 'value' => $group['audience_name'] ?? ''),
                array('name' => __('Joined Date', 'ffcertificate'), 'value' => $group['joined_date'] ?? ''),
            );

            $export_items[] = array(
                'group_id' => 'ffc-audience-groups',
                'group_label' => __('FFC Audience Groups', 'ffcertificate'),
                'item_id' => 'ffc-group-' . sanitize_title($group['audience_name'] ?? 'unknown'),
                'data' => $data,
            );
        }

        return array('data' => $export_items, 'done' => true);
    }

    /**
     * Export audience bookings linked to user
     *
     * @param string $email_address User email
     * @param int $page Page number
     * @return array<string, mixed>
     */
    public static function export_audience_bookings(string $email_address, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return array('data' => array(), 'done' => true);
        }

        $booking_users_table = $wpdb->prefix . 'ffc_audience_booking_users';
        $bookings_table = $wpdb->prefix . 'ffc_audience_bookings';
        $environments_table = $wpdb->prefix . 'ffc_audience_environments';

        if (!self::table_exists($booking_users_table)) {
            return array('data' => array(), 'done' => true);
        }

        $offset = ($page - 1) * self::ITEMS_PER_PAGE;

        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.id, b.booking_date, b.start_time, b.end_time, b.description,
                    b.status, b.is_all_day, e.name AS environment_name
             FROM %i bu
             INNER JOIN %i b ON b.id = bu.booking_id
             LEFT JOIN %i e ON e.id = b.environment_id
             WHERE bu.user_id = %d
             ORDER BY b.booking_date DESC
             LIMIT %d OFFSET %d",
            $booking_users_table,
            $bookings_table,
            $environments_table,
            $user->ID,
            self::ITEMS_PER_PAGE,
            $offset
        ), ARRAY_A);

        $export_items = array();

        foreach ($bookings as $booking) {
            $time = !empty($booking['is_all_day'])
                ? __('All Day', 'ffcertificate')
                : ($booking['start_time'] ?? '') . ' - ' . ($booking['end_time'] ?? '');

            $data = array(
                array('name' => __('Environment', 'ffcertificate'), 'value' => $booking['environment_name'] ?? ''),
                array('name' => __('Date', 'ffcertificate'), 'value' => $booking['booking_date'] ?? ''),
                array('name' => __('Time', 'ffcertificate'), 'value' => $time),
                array('name' => __('Description', 'ffcertificate'), 'value' => $booking['description'] ?? ''),
                array('name' => __('Status', 'ffcertificate'), 'value' => $booking['status'] ?? ''),
            );

            $export_items[] = array(
                'group_id' => 'ffc-audience-bookings',
                'group_label' => __('FFC Audience Bookings', 'ffcertificate'),
                'item_id' => 'ffc-booking-' . $booking['id'],
                'data' => $data,
            );
        }

        $done = count($bookings) < self::ITEMS_PER_PAGE;

        return array('data' => $export_items, 'done' => $done);
    }

    // ──────────────────────────────────────
    // ERASER
    // ──────────────────────────────────────

    /**
     * Erase personal data across all FFC tables
     *
     * Strategy:
     * - Submissions: SET user_id = NULL, clear email_encrypted, cpf_rf_encrypted
     *   (preserve auth_code, magic_token, cpf_rf_hash for public certificate verification)
     * - Appointments: SET user_id = NULL, clear PII fields
     * - Audience members/booking users/permissions: DELETE
     * - User profiles: DELETE
     * - Activity log: SET user_id = NULL
     *
     * @param string $email_address User email
     * @param int $page Page number
     * @return array<string, mixed>
     */
    public static function erase_personal_data(string $email_address, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email_address);

        if (!$user) {
            return array(
                'items_removed' => false,
                'items_retained' => false,
                'messages' => array(),
                'done' => true,
            );
        }

        $user_id = $user->ID;
        $items_removed = 0;
        $items_retained = 0;
        $messages = array();

        // 1. Submissions: anonymize (preserve certificate verification)
        $submissions_table = $wpdb->prefix . 'ffc_submissions';
        $rows = $wpdb->query($wpdb->prepare(
            "UPDATE %i
             SET user_id = NULL, email_encrypted = NULL,
                 cpf_rf_encrypted = NULL, cpf_encrypted = NULL, rf_encrypted = NULL,
                 cpf_hash = NULL, rf_hash = NULL
             WHERE user_id = %d",
            $submissions_table,
            $user_id
        ));
        if ($rows > 0) {
            $items_removed += $rows;
            $items_retained += $rows; // Certificate records retained (anonymized)
            $messages[] = sprintf(
                /* translators: %d: number of submissions */
                __('%d certificate submissions anonymized (auth codes and verification links preserved).', 'ffcertificate'),
                $rows
            );
        }

        // 2. Appointments: anonymize PII
        $appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        if (self::table_exists($appointments_table)) {
            $rows = $wpdb->query($wpdb->prepare(
                "UPDATE %i
                 SET user_id = NULL, name = NULL, email = NULL, email_encrypted = NULL,
                     email_hash = NULL, phone = NULL, phone_encrypted = NULL,
                     cpf_rf = NULL, cpf_rf_encrypted = NULL,
                     cpf_encrypted = NULL, cpf_hash = NULL,
                     rf_encrypted = NULL, rf_hash = NULL,
                     custom_data = NULL, custom_data_encrypted = NULL,
                     user_notes = NULL, user_ip = NULL, user_ip_encrypted = NULL,
                     user_agent = NULL, consent_ip = NULL
                 WHERE user_id = %d",
                $appointments_table,
                $user_id
            ));
            if ($rows > 0) {
                $items_removed += $rows;
                $messages[] = sprintf(
                    /* translators: %d: number of appointments */
                    __('%d appointments anonymized.', 'ffcertificate'),
                    $rows
                );
            }
        }

        // 3. Audience members: DELETE
        $members_table = $wpdb->prefix . 'ffc_audience_members';
        if (self::table_exists($members_table)) {
            $rows = $wpdb->delete($members_table, array('user_id' => $user_id), array('%d'));
            if ($rows > 0) {
                $items_removed += $rows;
                $messages[] = sprintf(
                    /* translators: %d: number of memberships */
                    __('%d audience memberships removed.', 'ffcertificate'),
                    $rows
                );
            }
        }

        // 4. Audience booking users: DELETE
        $booking_users_table = $wpdb->prefix . 'ffc_audience_booking_users';
        if (self::table_exists($booking_users_table)) {
            $rows = $wpdb->delete($booking_users_table, array('user_id' => $user_id), array('%d'));
            if ($rows > 0) {
                $items_removed += $rows;
            }
        }

        // 5. Audience schedule permissions: DELETE
        $permissions_table = $wpdb->prefix . 'ffc_audience_schedule_permissions';
        if (self::table_exists($permissions_table)) {
            $rows = $wpdb->delete($permissions_table, array('user_id' => $user_id), array('%d'));
            if ($rows > 0) {
                $items_removed += $rows;
            }
        }

        // 6. User profiles: DELETE
        $profiles_table = $wpdb->prefix . 'ffc_user_profiles';
        if (self::table_exists($profiles_table)) {
            $rows = $wpdb->delete($profiles_table, array('user_id' => $user_id), array('%d'));
            if ($rows > 0) {
                $items_removed++;
                $messages[] = __('User profile deleted.', 'ffcertificate');
            }
        }

        // 7. Activity log: SET user_id = NULL
        $activity_table = $wpdb->prefix . 'ffc_activity_log';
        if (self::table_exists($activity_table)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE %i SET user_id = NULL WHERE user_id = %d",
                $activity_table,
                $user_id
            ));
        }

        // 8. ffc_* user meta: DELETE
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $meta_deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE user_id = %d AND meta_key LIKE %s",
            $wpdb->usermeta,
            $user_id,
            'ffc_%'
        ));
        if ($meta_deleted > 0) {
            $items_removed += $meta_deleted;
            $messages[] = sprintf(
                /* translators: %d: number of settings */
                __('%d user settings removed.', 'ffcertificate'),
                $meta_deleted
            );
        }

        // Log the erasure
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'privacy_data_erased',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
                array(
                    'email' => $email_address,
                    'items_removed' => $items_removed,
                    'items_retained' => $items_retained,
                )
            );
        }

        return array(
            'items_removed' => $items_removed > 0,
            'items_retained' => $items_retained > 0,
            'messages' => $messages,
            'done' => true,
        );
    }

    /**
     * Export ffc_* user meta data
     *
     * @since 4.9.9
     * @param string $email_address User email
     * @param int    $page          Page number
     * @return array<string, mixed>
     */
    public static function export_usermeta(string $email_address, int $page = 1): array {
        $user = get_user_by('email', $email_address);
        if (!$user || $page > 1) {
            return array('data' => array(), 'done' => true);
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM %i
             WHERE user_id = %d AND meta_key LIKE %s",
            $wpdb->usermeta,
            $user->ID,
            'ffc_%'
        ), ARRAY_A);

        if (empty($meta_rows)) {
            return array('data' => array(), 'done' => true);
        }

        // Sensitive keys that should not be exported in plain text
        $redact_keys = array('ffc_cpf_rf_hash');

        $data = array();
        foreach ($meta_rows as $row) {
            $value = $row['meta_value'];
            if (in_array($row['meta_key'], $redact_keys, true)) {
                $value = '[hash]';
            }
            $data[] = array(
                'name'  => $row['meta_key'],
                'value' => $value,
            );
        }

        $export_items = array(
            array(
                'group_id'    => 'ffc-usermeta',
                'group_label' => __('FFC User Settings', 'ffcertificate'),
                'item_id'     => 'ffc-usermeta-' . $user->ID,
                'data'        => $data,
            ),
        );

        return array('data' => $export_items, 'done' => true);
    }

    // table_exists() provided by DatabaseHelperTrait
}
