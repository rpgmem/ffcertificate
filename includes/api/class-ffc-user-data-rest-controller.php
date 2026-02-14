<?php
declare(strict_types=1);

/**
 * User Data REST Controller
 *
 * Handles user-facing REST API endpoints:
 *   GET  /user/certificates      – Current user's certificates
 *   GET  /user/profile           – Current user's profile
 *   PUT  /user/profile           – Update current user's profile
 *   GET  /user/appointments      – Current user's self-scheduling appointments
 *   GET  /user/audience-bookings – Current user's audience bookings
 *
 * @since 4.6.1
 * @package FreeFormCertificate\API
 */

namespace FreeFormCertificate\API;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class UserDataRestController {

    /**
     * API namespace
     */
    private string $namespace;

    /**
     * Constructor
     *
     * @param string $namespace API namespace.
     */
    public function __construct(string $namespace) {
        $this->namespace = $namespace;
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/user/certificates', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_certificates'),
            'permission_callback' => 'is_user_logged_in',
        ));

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

        register_rest_route($this->namespace, '/user/appointments', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_appointments'),
            'permission_callback' => 'is_user_logged_in',
        ));

        register_rest_route($this->namespace, '/user/audience-bookings', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_audience_bookings'),
            'permission_callback' => 'is_user_logged_in',
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

        register_rest_route($this->namespace, '/user/summary', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_summary'),
            'permission_callback' => 'is_user_logged_in',
        ));

        register_rest_route($this->namespace, '/user/joinable-groups', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_joinable_groups'),
            'permission_callback' => 'is_user_logged_in',
        ));

        register_rest_route($this->namespace, '/user/audience-group/join', array(
            'methods' => 'POST',
            'callback' => array($this, 'join_audience_group'),
            'permission_callback' => 'is_user_logged_in',
        ));

        register_rest_route($this->namespace, '/user/audience-group/leave', array(
            'methods' => 'POST',
            'callback' => array($this, 'leave_audience_group'),
            'permission_callback' => 'is_user_logged_in',
        ));
    }

    /**
     * Resolve effective user_id and whether view-as is active
     *
     * When admin uses view-as, capability checks must use the TARGET
     * user's capabilities so the admin sees exactly what the user would see.
     *
     * @since 4.9.7
     * @param \WP_REST_Request $request
     * @return array{user_id: int, is_view_as: bool}
     */
    private function resolve_user_context($request): array {
        $user_id = get_current_user_id();
        $is_view_as = false;

        $view_as_user_id = $request->get_param('viewAsUserId');
        if ($view_as_user_id && current_user_can('manage_options')) {
            $user_id = absint($view_as_user_id);
            $is_view_as = true;
        }

        return array('user_id' => $user_id, 'is_view_as' => $is_view_as);
    }

    /**
     * Check if a capability is granted for the effective user
     *
     * In view-as mode, checks the TARGET user's capabilities.
     * Otherwise, checks the current user's capabilities.
     *
     * @since 4.9.7
     * @param string $capability Capability name
     * @param int $user_id Target user ID
     * @param bool $is_view_as Whether view-as mode is active
     * @return bool
     */
    private function user_has_capability(string $capability, int $user_id, bool $is_view_as): bool {
        if ($is_view_as) {
            return user_can($user_id, $capability);
        }
        return current_user_can('manage_options') || current_user_can($capability);
    }

    /**
     * GET /user/certificates
     *
     * @since 3.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_user_certificates($request) {
        try {
            $user_id = get_current_user_id();

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view certificates', 'ffcertificate'),
                    array('status' => 401)
                );
            }

            // Resolve user context (view-as or self)
            $ctx = $this->resolve_user_context($request);
            $user_id = $ctx['user_id'];

            if (!$this->user_has_capability('view_own_certificates', $user_id, $ctx['is_view_as'])) {
                return new \WP_Error(
                    'capability_denied',
                    __('You do not have permission to view certificates', 'ffcertificate'),
                    array('status' => 403)
                );
            }

            global $wpdb;

            if (!class_exists('\FreeFormCertificate\Core\Utils')) {
                return new \WP_Error('missing_class', 'FFC_Utils class not found', array('status' => 500));
            }

            $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

            $settings = get_option('ffc_settings', array());
            $date_format = $settings['date_format'] ?? 'F j, Y';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $submissions = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, p.post_title as form_title
                 FROM {$table} s
                 LEFT JOIN {$wpdb->posts} p ON s.form_id = p.ID
                 WHERE s.user_id = %d
                 AND s.status != 'trash'
                 ORDER BY s.submission_date DESC",
                $user_id
            ), ARRAY_A);

            // Check per-capability permissions for the target user
            $can_download = $this->user_has_capability('download_own_certificates', $user_id, $ctx['is_view_as']);
            $can_view_history = $this->user_has_capability('view_certificate_history', $user_id, $ctx['is_view_as']);

            $certificates = array();

            foreach ($submissions as $submission) {
                $email_display = '';
                if (!empty($submission['email_encrypted'])) {
                    try {
                        $email_plain = \FreeFormCertificate\Core\Encryption::decrypt($submission['email_encrypted']);
                        $email_display = ($email_plain && is_string($email_plain)) ? \FreeFormCertificate\Core\Utils::mask_email($email_plain) : '';
                    } catch (\Exception $e) {
                        $email_display = __('Error decrypting', 'ffcertificate');
                    }
                } elseif (!empty($submission['email'])) {
                    $email_display = \FreeFormCertificate\Core\Utils::mask_email($submission['email']);
                }

                $verification_page_id = get_option('ffc_verification_page_id');
                $verification_url = $verification_page_id ? get_permalink((int) $verification_page_id) : home_url('/valid');

                $magic_link = '';
                if (!empty($submission['magic_token'])) {
                    $magic_link = add_query_arg('token', $submission['magic_token'], $verification_url);
                }

                $auth_code_formatted = '';
                if (!empty($submission['auth_code'])) {
                    $auth_code_formatted = \FreeFormCertificate\Core\Utils::format_auth_code($submission['auth_code']);
                }

                $date_formatted = '';
                if (!empty($submission['submission_date'])) {
                    $timestamp = strtotime($submission['submission_date']);
                    $date_formatted = ($timestamp !== false) ? date_i18n($date_format, $timestamp) : $submission['submission_date'];
                }

                $certificates[] = array(
                    'id' => (int) ($submission['id'] ?? 0),
                    'form_id' => (int) ($submission['form_id'] ?? 0),
                    'form_title' => $submission['form_title'] ?? __('Unknown Form', 'ffcertificate'),
                    'submission_date' => $date_formatted ?: '',
                    'submission_date_raw' => $submission['submission_date'] ?? '',
                    'consent_given' => !empty($submission['consent_given']),
                    'email' => $email_display,
                    'auth_code' => $auth_code_formatted,
                    'magic_link' => $can_download ? $magic_link : '',
                    'pdf_url' => $can_download ? $magic_link : '',
                );
            }

            // When view_certificate_history is disabled, keep only the most recent per form
            if (!$can_view_history && !empty($certificates)) {
                $seen_forms = array();
                $filtered = array();
                foreach ($certificates as $cert) {
                    if (!isset($seen_forms[$cert['form_id']])) {
                        $seen_forms[$cert['form_id']] = true;
                        $filtered[] = $cert;
                    }
                }
                $certificates = $filtered;
            }

            return rest_ensure_response(array(
                'certificates' => $certificates,
                'total' => count($certificates),
            ));

        } catch (\Exception $e) {
            if (class_exists('\FreeFormCertificate\Core\Utils')) {
                \FreeFormCertificate\Core\Utils::debug_log('get_user_certificates error', array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ));
            }
            return new \WP_Error(
                'get_certificates_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
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
            $user_id = get_current_user_id();

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view profile', 'ffcertificate'),
                    array('status' => 401)
                );
            }

            // Resolve user context (view-as or self)
            $ctx = $this->resolve_user_context($request);
            $user_id = $ctx['user_id'];

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

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $members_table));
            if ($table_exists) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $audience_groups = $wpdb->get_results($wpdb->prepare(
                    "SELECT a.name, a.color
                     FROM {$members_table} m
                     INNER JOIN {$audiences_table} a ON a.id = m.audience_id
                     WHERE m.user_id = %d AND a.status = 'active'
                     ORDER BY a.name ASC",
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
            $user_id = get_current_user_id();

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
     * GET /user/appointments
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_user_appointments($request) {
        try {
            $user_id = get_current_user_id();

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view appointments', 'ffcertificate'),
                    array('status' => 401)
                );
            }

            // Resolve user context (view-as or self)
            $ctx = $this->resolve_user_context($request);
            $user_id = $ctx['user_id'];

            if (!$this->user_has_capability('ffc_view_self_scheduling', $user_id, $ctx['is_view_as'])) {
                return new \WP_Error(
                    'capability_denied',
                    __('You do not have permission to view appointments', 'ffcertificate'),
                    array('status' => 403)
                );
            }

            if (!class_exists('\FreeFormCertificate\Repositories\AppointmentRepository')) {
                return new \WP_Error(
                    'repository_not_found',
                    __('Appointment repository not available', 'ffcertificate'),
                    array('status' => 500)
                );
            }

            if (!class_exists('\FreeFormCertificate\Repositories\CalendarRepository')) {
                return new \WP_Error(
                    'calendar_repository_not_found',
                    __('Calendar repository not available', 'ffcertificate'),
                    array('status' => 500)
                );
            }

            $appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
            $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();

            $appointments = $appointment_repository->findByUserId($user_id);

            if (!is_array($appointments)) {
                $appointments = array();
            }

            $date_format = get_option('date_format', 'F j, Y');

            // Batch load all calendars to avoid N+1 queries
            $calendar_ids = array_unique( array_filter( array_map( function ( $apt ) {
                return (int) ( $apt['calendar_id'] ?? 0 );
            }, $appointments ) ) );
            $calendars_map = ! empty( $calendar_ids ) ? $calendar_repository->findByIds( $calendar_ids ) : [];

            $appointments_formatted = array();

            foreach ($appointments as $appointment) {
                if (!is_array($appointment) || empty($appointment['id'])) {
                    continue;
                }

                $calendar_title = __('Unknown Calendar', 'ffcertificate');
                $calendar = null;
                if (!empty($appointment['calendar_id'])) {
                    $calendar = $calendars_map[ (int) $appointment['calendar_id'] ] ?? null;
                    if ($calendar && isset($calendar['title'])) {
                        $calendar_title = $calendar['title'];
                    }
                }

                $date_formatted = '';
                if (!empty($appointment['appointment_date'])) {
                    $timestamp = strtotime($appointment['appointment_date']);
                    $date_formatted = ($timestamp !== false) ? date_i18n($date_format, $timestamp) : $appointment['appointment_date'];
                }

                $time_formatted = '';
                if (!empty($appointment['start_time'])) {
                    $time_timestamp = strtotime($appointment['start_time']);
                    $time_formatted = ($time_timestamp !== false) ? date_i18n('H:i', $time_timestamp) : $appointment['start_time'];
                }

                $email_display = '';
                if (!empty($appointment['email_encrypted'])) {
                    try {
                        if (class_exists('\FreeFormCertificate\Core\Encryption')) {
                            $email_plain = \FreeFormCertificate\Core\Encryption::decrypt($appointment['email_encrypted']);
                            $email_display = ($email_plain && is_string($email_plain)) ? $email_plain : '';
                        }
                    } catch (\Exception $e) {
                        $email_display = '';
                    }
                } elseif (!empty($appointment['email'])) {
                    $email_display = $appointment['email'];
                }

                $end_time_formatted = '';
                if (!empty($appointment['end_time'])) {
                    $end_timestamp = strtotime($appointment['end_time']);
                    $end_time_formatted = ($end_timestamp !== false) ? date_i18n('H:i', $end_timestamp) : '';
                }

                $status_labels = array(
                    'pending' => __('Pending', 'ffcertificate'),
                    'confirmed' => __('Confirmed', 'ffcertificate'),
                    'cancelled' => __('Cancelled', 'ffcertificate'),
                    'completed' => __('Completed', 'ffcertificate'),
                    'no_show' => __('No Show', 'ffcertificate'),
                );

                $status = $appointment['status'] ?? 'pending';

                $receipt_url = '';
                if ( $status !== 'cancelled' ) {
                    $confirmation_token = $appointment['confirmation_token'] ?? '';
                    if ( ! empty( $confirmation_token ) && class_exists( '\\FreeFormCertificate\\Generators\\MagicLinkHelper' ) ) {
                        $receipt_url = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $confirmation_token );
                    } elseif (class_exists('\FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler')) {
                        $receipt_url = \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler::get_receipt_url(
                            (int) $appointment['id'],
                            $confirmation_token
                        );
                    }
                }

                $can_cancel = false;
                if (in_array($status, ['pending', 'confirmed'])) {
                    $appointment_time = ( new \DateTimeImmutable( $appointment['appointment_date'] . ' ' . ( $appointment['start_time'] ?? '23:59:59' ), wp_timezone() ) )->getTimestamp();
                    $now = time();

                    if ($appointment_time > $now) {
                        if (current_user_can('manage_options')) {
                            $can_cancel = true;
                        } elseif ($calendar && is_array($calendar) && !empty($calendar['allow_cancellation'])) {
                            $can_cancel = true;
                            if (!empty($calendar['cancellation_min_hours']) && $calendar['cancellation_min_hours'] > 0) {
                                $deadline = $appointment_time - ($calendar['cancellation_min_hours'] * 3600);
                                if ($now > $deadline) {
                                    $can_cancel = false;
                                }
                            }
                        }
                    }
                }

                $appointments_formatted[] = array(
                    'id' => (int) $appointment['id'],
                    'calendar_id' => (int) ($appointment['calendar_id'] ?? 0),
                    'calendar_title' => $calendar_title,
                    'appointment_date' => $date_formatted,
                    'appointment_date_raw' => $appointment['appointment_date'] ?? '',
                    'start_time' => $time_formatted,
                    'start_time_raw' => $appointment['start_time'] ?? '',
                    'end_time' => $end_time_formatted,
                    'status' => $status,
                    'status_label' => $status_labels[$status] ?? $status,
                    'name' => $appointment['name'] ?? '',
                    'email' => $email_display,
                    'phone' => $appointment['phone'] ?? '',
                    'user_notes' => $appointment['user_notes'] ?? '',
                    'created_at' => $appointment['created_at'] ?? '',
                    'can_cancel' => $can_cancel,
                    'receipt_url' => $receipt_url,
                );
            }

            return rest_ensure_response(array(
                'appointments' => $appointments_formatted,
                'total' => count($appointments_formatted),
            ));

        } catch (\Exception $e) {
            if (class_exists('\FreeFormCertificate\Core\Utils')) {
                \FreeFormCertificate\Core\Utils::debug_log('get_user_appointments error', array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ));
            }

            return new \WP_Error(
                'get_appointments_error',
                /* translators: %s: error message */
                sprintf(__('Error loading appointments: %s', 'ffcertificate'), $e->getMessage()),
                array('status' => 500)
            );
        }
    }

    /**
     * GET /user/audience-bookings
     *
     * @since 4.5.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_user_audience_bookings($request) {
        try {
            $user_id = get_current_user_id();

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view bookings', 'ffcertificate'),
                    array('status' => 401)
                );
            }

            // Resolve user context (view-as or self)
            $ctx = $this->resolve_user_context($request);
            $user_id = $ctx['user_id'];

            if (!$this->user_has_capability('ffc_view_audience_bookings', $user_id, $ctx['is_view_as'])) {
                return new \WP_Error(
                    'capability_denied',
                    __('You do not have permission to view audience bookings', 'ffcertificate'),
                    array('status' => 403)
                );
            }

            global $wpdb;

            $date_format = get_option('date_format', 'F j, Y');

            $bookings_table = $wpdb->prefix . 'ffc_audience_bookings';
            $users_table = $wpdb->prefix . 'ffc_audience_booking_users';
            $booking_audiences_table = $wpdb->prefix . 'ffc_audience_booking_audiences';
            $members_table = $wpdb->prefix . 'ffc_audience_members';
            $audience_names_table = $wpdb->prefix . 'ffc_audiences';
            $environments_table = $wpdb->prefix . 'ffc_audience_environments';
            $schedules_table = $wpdb->prefix . 'ffc_audience_schedules';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT b.*, e.name as environment_name, s.name as schedule_name
                 FROM {$bookings_table} b
                 LEFT JOIN {$users_table} bu ON b.id = bu.booking_id
                 LEFT JOIN {$booking_audiences_table} ba ON b.id = ba.booking_id
                 LEFT JOIN {$members_table} am ON ba.audience_id = am.audience_id
                 LEFT JOIN {$environments_table} e ON b.environment_id = e.id
                 LEFT JOIN {$schedules_table} s ON e.schedule_id = s.id
                 WHERE (bu.user_id = %d OR am.user_id = %d)
                 ORDER BY b.booking_date DESC, b.start_time DESC",
                $user_id,
                $user_id
            ), ARRAY_A);

            if (!is_array($bookings)) {
                $bookings = array();
            }

            // Batch load audiences for all bookings to avoid N+1 queries
            $audiences_map = [];
            $booking_ids = array_filter( array_map( function ( $b ) {
                return (int) ( $b['id'] ?? 0 );
            }, $bookings ) );

            if ( ! empty( $booking_ids ) ) {
                $safe_ids = array_map( 'absint', $booking_ids );
                $id_list  = implode( ',', $safe_ids );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $all_audiences = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ba.booking_id, a.name, a.color
                     FROM %i ba
                     INNER JOIN %i a ON ba.audience_id = a.id
                     WHERE ba.booking_id IN ({$id_list})",
                    $booking_audiences_table,
                    $audience_names_table
                ), ARRAY_A );

                if ( is_array( $all_audiences ) ) {
                    foreach ( $all_audiences as $aud ) {
                        $audiences_map[ (int) $aud['booking_id'] ][] = [
                            'name'  => $aud['name'],
                            'color' => $aud['color'] ?? '#2271b1',
                        ];
                    }
                }
            }

            $bookings_formatted = array();

            foreach ($bookings as $booking) {
                $date_formatted = '';
                if (!empty($booking['booking_date'])) {
                    $timestamp = strtotime($booking['booking_date']);
                    $date_formatted = ($timestamp !== false) ? date_i18n($date_format, $timestamp) : $booking['booking_date'];
                }

                $time_formatted = '';
                if (!empty($booking['start_time'])) {
                    $time_timestamp = strtotime($booking['start_time']);
                    $time_formatted = ($time_timestamp !== false) ? date_i18n('H:i', $time_timestamp) : $booking['start_time'];
                }

                $end_time_formatted = '';
                if (!empty($booking['end_time'])) {
                    $end_timestamp = strtotime($booking['end_time']);
                    $end_time_formatted = ($end_timestamp !== false) ? date_i18n('H:i', $end_timestamp) : '';
                }

                $status_labels = array(
                    'active' => __('Confirmed', 'ffcertificate'),
                    'cancelled' => __('Cancelled', 'ffcertificate'),
                );

                $status = $booking['status'] ?? 'active';
                $is_past = strtotime($booking['booking_date']) < strtotime('today');

                $bookings_formatted[] = array(
                    'id' => (int) $booking['id'],
                    'environment_id' => (int) ($booking['environment_id'] ?? 0),
                    'environment_name' => $booking['environment_name'] ?? __('Unknown', 'ffcertificate'),
                    'schedule_name' => $booking['schedule_name'] ?? '',
                    'booking_date' => $date_formatted,
                    'booking_date_raw' => $booking['booking_date'] ?? '',
                    'start_time' => $time_formatted,
                    'end_time' => $end_time_formatted,
                    'description' => $booking['description'] ?? '',
                    'status' => $status,
                    'status_label' => $status_labels[$status] ?? $status,
                    'is_past' => $is_past,
                    'audiences' => $audiences_map[ (int) $booking['id'] ] ?? [],
                );
            }

            return rest_ensure_response(array(
                'bookings' => $bookings_formatted,
                'total' => count($bookings_formatted),
            ));

        } catch (\Exception $e) {
            if (class_exists('\FreeFormCertificate\Core\Utils')) {
                \FreeFormCertificate\Core\Utils::debug_log('get_user_audience_bookings error', array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ));
            }

            return new \WP_Error(
                'get_audience_bookings_error',
                /* translators: %s: error message */
                sprintf(__('Error loading audience bookings: %s', 'ffcertificate'), $e->getMessage()),
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
        $user_id = get_current_user_id();

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

        if (empty($current_password) || empty($new_password)) {
            return new \WP_Error('missing_fields', __('All password fields are required', 'ffcertificate'), array('status' => 400));
        }

        if (strlen($new_password) < 8) {
            return new \WP_Error('password_too_short', __('Password must be at least 8 characters', 'ffcertificate'), array('status' => 400));
        }

        $user = get_user_by('id', $user_id);

        if (!wp_check_password($current_password, $user->user_pass, $user_id)) {
            return new \WP_Error('wrong_password', __('Current password is incorrect', 'ffcertificate'), array('status' => 403));
        }

        wp_set_password($new_password, $user_id);

        // Re-authenticate the user so their session isn't destroyed
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);

        // Log password change
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log_password_changed($user_id);
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Password changed successfully!', 'ffcertificate'),
        ));
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
        $user_id = get_current_user_id();

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
    }

    /**
     * GET /user/summary
     *
     * Returns dashboard summary: total certificates, next appointment, upcoming group events.
     *
     * @since 4.9.8
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_user_summary($request) {
        try {
            $ctx = $this->resolve_user_context($request);
            $user_id = $ctx['user_id'];

            global $wpdb;

            $summary = array(
                'total_certificates' => 0,
                'next_appointment' => null,
                'upcoming_group_events' => 0,
            );

            // Count certificates
            if ($this->user_has_capability('view_own_certificates', $user_id, $ctx['is_view_as'])) {
                $table = \FreeFormCertificate\Core\Utils::get_submissions_table();
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $summary['total_certificates'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status != 'trash'",
                    $user_id
                ));
            }

            // Next appointment
            if ($this->user_has_capability('ffc_view_self_scheduling', $user_id, $ctx['is_view_as'])) {
                $apt_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
                $calendars_table = $wpdb->prefix . 'ffc_self_scheduling_calendars';

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $next = $wpdb->get_row($wpdb->prepare(
                    "SELECT a.appointment_date, a.start_time, c.title as calendar_title
                     FROM {$apt_table} a
                     LEFT JOIN {$calendars_table} c ON a.calendar_id = c.id
                     WHERE a.user_id = %d
                       AND a.status IN ('pending', 'confirmed')
                       AND a.appointment_date >= CURDATE()
                     ORDER BY a.appointment_date ASC, a.start_time ASC
                     LIMIT 1",
                    $user_id
                ), ARRAY_A);

                if ($next) {
                    $settings = get_option('ffc_settings', array());
                    $date_format = $settings['date_format'] ?? 'F j, Y';
                    $timestamp = strtotime($next['appointment_date']);
                    $time_formatted = '';
                    if (!empty($next['start_time'])) {
                        $time_ts = strtotime($next['start_time']);
                        $time_formatted = ($time_ts !== false) ? date_i18n('H:i', $time_ts) : '';
                    }

                    $summary['next_appointment'] = array(
                        'date' => ($timestamp !== false) ? date_i18n($date_format, $timestamp) : $next['appointment_date'],
                        'time' => $time_formatted,
                        'title' => $next['calendar_title'] ?? '',
                    );
                }
            }

            // Upcoming group events
            if ($this->user_has_capability('ffc_view_audience_bookings', $user_id, $ctx['is_view_as'])) {
                $bookings_table = $wpdb->prefix . 'ffc_audience_bookings';
                $users_table = $wpdb->prefix . 'ffc_audience_booking_users';
                $booking_audiences_table = $wpdb->prefix . 'ffc_audience_booking_audiences';
                $members_table = $wpdb->prefix . 'ffc_audience_members';

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $bookings_table));
                if ($table_exists) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $summary['upcoming_group_events'] = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(DISTINCT b.id)
                         FROM {$bookings_table} b
                         LEFT JOIN {$users_table} bu ON b.id = bu.booking_id
                         LEFT JOIN {$booking_audiences_table} ba ON b.id = ba.booking_id
                         LEFT JOIN {$members_table} am ON ba.audience_id = am.audience_id
                         WHERE (bu.user_id = %d OR am.user_id = %d)
                           AND b.booking_date >= CURDATE()
                           AND b.status != 'cancelled'",
                        $user_id,
                        $user_id
                    ));
                }
            }

            return rest_ensure_response($summary);

        } catch (\Exception $e) {
            return rest_ensure_response(array(
                'total_certificates' => 0,
                'next_appointment' => null,
                'upcoming_group_events' => 0,
            ));
        }
    }

    /**
     * Maximum number of self-join groups a user can belong to
     */
    private const MAX_SELF_JOIN_GROUPS = 2;

    /**
     * GET /user/joinable-groups
     *
     * Lists audience groups that allow self-join, with the user's current membership status.
     *
     * @since 4.9.9
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_joinable_groups($request) {
        global $wpdb;
        $user_id = get_current_user_id();

        if (!$user_id) {
            return new \WP_Error('not_logged_in', __('You must be logged in', 'ffcertificate'), array('status' => 401));
        }

        $audiences_table = $wpdb->prefix . 'ffc_audiences';
        $members_table = $wpdb->prefix . 'ffc_audience_members';

        // Check tables exist
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $audiences_table))) {
            return rest_ensure_response(array('groups' => array(), 'joined_count' => 0, 'max_groups' => self::MAX_SELF_JOIN_GROUPS));
        }

        // Check if allow_self_join column exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $col_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'allow_self_join'",
            DB_NAME, $audiences_table
        ));
        if (!$col_exists) {
            return rest_ensure_response(array('groups' => array(), 'joined_count' => 0, 'max_groups' => self::MAX_SELF_JOIN_GROUPS));
        }

        // Get all self-joinable groups with membership status
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.name, a.color,
                    CASE WHEN m.id IS NOT NULL THEN 1 ELSE 0 END AS is_member
             FROM {$audiences_table} a
             LEFT JOIN {$members_table} m ON m.audience_id = a.id AND m.user_id = %d
             WHERE a.allow_self_join = 1 AND a.status = 'active'
             ORDER BY a.name ASC",
            $user_id
        ), ARRAY_A);

        // Count how many self-joinable groups the user is currently in
        $joined_count = 0;
        foreach ($groups as &$g) {
            $g['id'] = (int) $g['id'];
            $g['is_member'] = (bool) $g['is_member'];
            if ($g['is_member']) {
                $joined_count++;
            }
        }
        unset($g);

        return rest_ensure_response(array(
            'groups' => $groups,
            'joined_count' => $joined_count,
            'max_groups' => self::MAX_SELF_JOIN_GROUPS,
        ));
    }

    /**
     * POST /user/audience-group/join
     *
     * Join a self-joinable audience group. Max 2 self-join groups per user.
     *
     * @since 4.9.9
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function join_audience_group($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $group_id = absint($request->get_param('group_id'));

        if (!$user_id) {
            return new \WP_Error('not_logged_in', __('You must be logged in', 'ffcertificate'), array('status' => 401));
        }

        if (!$group_id) {
            return new \WP_Error('missing_group', __('Group ID is required', 'ffcertificate'), array('status' => 400));
        }

        $audiences_table = $wpdb->prefix . 'ffc_audiences';
        $members_table = $wpdb->prefix . 'ffc_audience_members';

        // Verify group exists, is active, and allows self-join
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM {$audiences_table} WHERE id = %d AND status = 'active' AND allow_self_join = 1",
            $group_id
        ));

        if (!$group) {
            return new \WP_Error('invalid_group', __('Group not found or does not allow self-join', 'ffcertificate'), array('status' => 404));
        }

        // Check already member
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $already = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$members_table} WHERE audience_id = %d AND user_id = %d",
            $group_id, $user_id
        ));

        if ($already) {
            return new \WP_Error('already_member', __('You are already a member of this group', 'ffcertificate'), array('status' => 409));
        }

        // Count current self-join memberships
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $current_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$members_table} m
             INNER JOIN {$audiences_table} a ON a.id = m.audience_id
             WHERE m.user_id = %d AND a.allow_self_join = 1",
            $user_id
        ));

        if ($current_count >= self::MAX_SELF_JOIN_GROUPS) {
            return new \WP_Error(
                'max_groups_reached',
                /* translators: %d: maximum number of groups */
                sprintf(__('You can join a maximum of %d groups. Leave one first.', 'ffcertificate'), self::MAX_SELF_JOIN_GROUPS),
                array('status' => 422)
            );
        }

        // Join the group
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($members_table, array(
            'audience_id' => $group_id,
            'user_id' => $user_id,
        ), array('%d', '%d'));

        // Grant audience capabilities if needed
        if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            \FreeFormCertificate\UserDashboard\UserManager::grant_audience_capabilities($user_id);
        }

        return rest_ensure_response(array(
            'success' => true,
            /* translators: %s: group name */
            'message' => sprintf(__('You joined "%s"!', 'ffcertificate'), $group->name),
        ));
    }

    /**
     * POST /user/audience-group/leave
     *
     * Leave a self-joinable audience group.
     *
     * @since 4.9.9
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function leave_audience_group($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $group_id = absint($request->get_param('group_id'));

        if (!$user_id) {
            return new \WP_Error('not_logged_in', __('You must be logged in', 'ffcertificate'), array('status' => 401));
        }

        if (!$group_id) {
            return new \WP_Error('missing_group', __('Group ID is required', 'ffcertificate'), array('status' => 400));
        }

        $audiences_table = $wpdb->prefix . 'ffc_audiences';
        $members_table = $wpdb->prefix . 'ffc_audience_members';

        // Verify group allows self-join (can only leave self-joinable groups)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM {$audiences_table} WHERE id = %d AND allow_self_join = 1",
            $group_id
        ));

        if (!$group) {
            return new \WP_Error('invalid_group', __('Group not found or cannot be left by user', 'ffcertificate'), array('status' => 404));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->delete($members_table, array(
            'audience_id' => $group_id,
            'user_id' => $user_id,
        ), array('%d', '%d'));

        if (!$deleted) {
            return new \WP_Error('not_member', __('You are not a member of this group', 'ffcertificate'), array('status' => 404));
        }

        return rest_ensure_response(array(
            'success' => true,
            /* translators: %s: group name */
            'message' => sprintf(__('You left "%s".', 'ffcertificate'), $group->name),
        ));
    }
}
