<?php
declare(strict_types=1);

/**
 * User Profile REST Controller
 *
 * Handles:
 *   GET  /user/profile          – Current user's profile
 *   PUT  /user/profile          – Update current user's profile
 *   POST /user/change-password  – Change password
 *   POST /user/privacy-request  – Create GDPR/LGPD privacy request
 *
 * @since 4.12.7  Extracted from UserDataRestController
 * @package FreeFormCertificate\API
 */

namespace FreeFormCertificate\API;

if (!defined('ABSPATH')) exit;


class UserProfileRestController {

    use UserContextTrait;
    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * API namespace
     */
    private string $namespace;

    public function __construct(string $namespace) {
        $this->namespace = $namespace;
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/user/profile', array(
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array($this, 'get_user_profile'),
                'permission_callback' => 'is_user_logged_in',
            ),
            array(
                'methods' => 'PUT',
                'callback' => array($this, 'update_user_profile'),
                'permission_callback' => 'is_user_logged_in',
            ),
        ));

        register_rest_route($this->namespace, '/user/change-password', array(
            'methods' => 'POST',
            'callback' => array($this, 'change_password'),
            'permission_callback' => 'is_user_logged_in',
        ));

        register_rest_route($this->namespace, '/user/privacy-request', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_privacy_request'),
            'permission_callback' => 'is_user_logged_in',
        ));
    }

    /**
     * GET /user/profile
     *
     * @since 3.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_user_profile($request) {
        try {
            global $wpdb;
            $ctx = $this->resolve_user_context($request);
            $user_id = $ctx['user_id'];

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view profile', 'ffcertificate'),
                    array('status' => 401)
                );
            }

            if (!class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
                $user_manager_file = FFC_PLUGIN_DIR . 'includes/user-dashboard/class-ffc-user-manager.php';
                if (file_exists($user_manager_file)) {
                    require_once $user_manager_file;
                }
            }

            $user = get_user_by('id', $user_id);

            if (!$user) {
                return new \WP_Error(
                    'user_not_found',
                    __('User not found', 'ffcertificate'),
                    array('status' => 404)
                );
            }

            // Load profile from ffc_user_profiles (primary) with wp_users fallback
            $profile = array();
            if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
                $profile = \FreeFormCertificate\UserDashboard\UserManager::get_profile($user_id);
            }

            $cpfs_masked = array();
            if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
                $cpfs_masked = \FreeFormCertificate\UserDashboard\UserManager::get_user_cpfs_masked($user_id);
            }

            $emails = array();
            if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
                $emails = \FreeFormCertificate\UserDashboard\UserManager::get_user_emails($user_id);
            }

            $names = array();
            if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
                $names = \FreeFormCertificate\UserDashboard\UserManager::get_user_names($user_id);
            }

            $member_since = '';
            if (!empty($user->user_registered)) {
                $settings = get_option('ffc_settings', array());
                $date_format = $settings['date_format'] ?? 'F j, Y';
                $timestamp = strtotime($user->user_registered);
                $member_since = ($timestamp !== false) ? date_i18n($date_format, $timestamp) : '';
            }

            $audience_groups = array();
            $audiences_table = $wpdb->prefix . 'ffc_audiences';
            $members_table = $wpdb->prefix . 'ffc_audience_members';

            if (self::table_exists($members_table)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $audience_groups = $wpdb->get_results($wpdb->prepare(
                    "SELECT a.name, a.color
                     FROM %i m
                     INNER JOIN %i a ON a.id = m.audience_id
                     WHERE m.user_id = %d AND a.status = 'active'
                     ORDER BY a.name ASC",
                    $members_table,
                    $audiences_table,
                    $user_id
                ), ARRAY_A);

                if (!is_array($audience_groups)) {
                    $audience_groups = array();
                }
            }

            // Decode preferences JSON
            $preferences = array();
            if (!empty($profile['preferences'])) {
                $decoded = json_decode($profile['preferences'], true);
                if (is_array($decoded)) {
                    $preferences = $decoded;
                }
            }

            return rest_ensure_response(array(
                'user_id' => $user_id,
                'display_name' => !empty($profile['display_name']) ? $profile['display_name'] : $user->display_name,
                'names' => $names,
                'email' => $user->user_email,
                'emails' => $emails,
                'cpf_masked' => !empty($cpfs_masked) ? $cpfs_masked[0] : __('Not found', 'ffcertificate'),
                'cpfs_masked' => $cpfs_masked,
                'phone' => $profile['phone'] ?? '',
                'department' => $profile['department'] ?? '',
                'organization' => $profile['organization'] ?? '',
                'notes' => $profile['notes'] ?? '',
                'preferences' => $preferences,
                'member_since' => $member_since,
                'roles' => $user->roles,
                'audience_groups' => $audience_groups,
            ));

        } catch (\Exception $e) {
            return new \WP_Error(
                'get_profile_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * PUT /user/profile
     *
     * Allows the logged-in user to update their own profile fields:
     * display_name, phone, department, organization, notes.
     *
     * @since 4.9.6
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function update_user_profile($request) {
        try {
            $ctx = $this->resolve_user_context($request);
            $user_id = $ctx['user_id'];

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to update profile', 'ffcertificate'),
                    array('status' => 401)
                );
            }

            if (!class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
                return new \WP_Error(
                    'missing_class',
                    __('UserManager class not found', 'ffcertificate'),
                    array('status' => 500)
                );
            }

            $data = array();
            $allowed_fields = array('display_name', 'phone', 'department', 'organization', 'notes');

            foreach ($allowed_fields as $field) {
                $value = $request->get_param($field);
                if ($value !== null) {
                    $data[$field] = $value;
                }
            }

            // Handle preferences (JSON object)
            $preferences = $request->get_param('preferences');
            if ($preferences !== null && is_array($preferences)) {
                $data['preferences'] = $preferences;
            }

            if (empty($data)) {
                return new \WP_Error(
                    'no_data',
                    __('No profile data provided', 'ffcertificate'),
                    array('status' => 400)
                );
            }

            $result = \FreeFormCertificate\UserDashboard\UserManager::update_profile($user_id, $data);

            if (!$result) {
                return new \WP_Error(
                    'update_failed',
                    __('Failed to update profile', 'ffcertificate'),
                    array('status' => 500)
                );
            }

            // Log profile update
            if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
                \FreeFormCertificate\Core\ActivityLog::log_profile_updated($user_id, array_keys($data));
            }

            // Return updated profile
            return $this->get_user_profile($request);

        } catch (\Exception $e) {
            return new \WP_Error(
                'update_profile_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * POST /user/change-password
     *
     * @since 4.9.8
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function change_password($request) {
        try {
            $ctx = $this->resolve_user_context($request);
            $user_id = $ctx['user_id'];

            if (!$user_id) {
                return new \WP_Error('not_logged_in', __('You must be logged in', 'ffcertificate'), array('status' => 401));
            }

            // Rate limit: 3/hour, 5/day for password changes
            if (class_exists('\FreeFormCertificate\Security\RateLimiter')) {
                $rate_check = \FreeFormCertificate\Security\RateLimiter::check_user_limit($user_id, 'password_change', 3, 5);
                if (!$rate_check['allowed']) {
                    return new \WP_Error('rate_limited', $rate_check['message'], array('status' => 429));
                }
            }

            $current_password = $request->get_param('current_password');
            $new_password = $request->get_param('new_password');

            if (empty($new_password)) {
                return new \WP_Error('missing_fields', __('All password fields are required', 'ffcertificate'), array('status' => 400));
            }

            if (strlen($new_password) < 8) {
                return new \WP_Error('password_too_short', __('Password must be at least 8 characters', 'ffcertificate'), array('status' => 400));
            }

            $user = get_user_by('id', $user_id);

            // Admin in view-as mode can skip current password verification
            if (!$ctx['is_view_as']) {
                if (empty($current_password)) {
                    return new \WP_Error('missing_fields', __('All password fields are required', 'ffcertificate'), array('status' => 400));
                }
                if (!wp_check_password($current_password, $user->user_pass, $user_id)) {
                    return new \WP_Error('wrong_password', __('Current password is incorrect', 'ffcertificate'), array('status' => 403));
                }
            }

            wp_set_password($new_password, $user_id);

            // Re-authenticate: in view-as mode keep the admin session, otherwise re-auth the user
            if (!$ctx['is_view_as']) {
                wp_set_current_user($user_id);
                wp_set_auth_cookie($user_id, true);
            }

            // Log password change
            if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
                \FreeFormCertificate\Core\ActivityLog::log_password_changed($user_id);
            }

            return rest_ensure_response(array(
                'success' => true,
                'message' => __('Password changed successfully!', 'ffcertificate'),
            ));

        } catch (\Exception $e) {
            return new \WP_Error('password_error', __('Error changing password', 'ffcertificate'), array('status' => 500));
        }
    }

    /**
     * POST /user/privacy-request
     *
     * Creates a WordPress privacy request (export or erasure).
     * Erasure requests require admin approval.
     *
     * @since 4.9.8
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function create_privacy_request($request) {
        try {
            $ctx = $this->resolve_user_context($request);
            $user_id = $ctx['user_id'];

            if (!$user_id) {
                return new \WP_Error('not_logged_in', __('You must be logged in', 'ffcertificate'), array('status' => 401));
            }

            // Rate limit: 2/hour, 3/day for privacy requests
            if (class_exists('\FreeFormCertificate\Security\RateLimiter')) {
                $rate_check = \FreeFormCertificate\Security\RateLimiter::check_user_limit($user_id, 'privacy_request', 2, 3);
                if (!$rate_check['allowed']) {
                    return new \WP_Error('rate_limited', $rate_check['message'], array('status' => 429));
                }
            }

            $type = $request->get_param('type');
            if (!in_array($type, array('export_personal_data', 'remove_personal_data'), true)) {
                return new \WP_Error('invalid_type', __('Invalid request type', 'ffcertificate'), array('status' => 400));
            }

            $user = get_user_by('id', $user_id);
            $result = wp_create_user_request($user->user_email, $type);

            if (is_wp_error($result)) {
                return new \WP_Error(
                    'privacy_request_error',
                    $result->get_error_message(),
                    array('status' => 400)
                );
            }

            // Log privacy request
            if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
                \FreeFormCertificate\Core\ActivityLog::log_privacy_request($user_id, $type);
            }

            return rest_ensure_response(array(
                'success' => true,
                'message' => __('Request sent! The administrator will review it.', 'ffcertificate'),
            ));

        } catch (\Exception $e) {
            return new \WP_Error('privacy_error', __('Error processing privacy request', 'ffcertificate'), array('status' => 500));
        }
    }
}
