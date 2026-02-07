<?php
declare(strict_types=1);

/**
 * User Data REST Controller
 *
 * Handles user-facing REST API endpoints:
 *   GET /user/certificates      – Current user's certificates
 *   GET /user/profile           – Current user's profile
 *   GET /user/appointments      – Current user's self-scheduling appointments
 *   GET /user/audience-bookings – Current user's audience bookings
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
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_profile'),
            'permission_callback' => 'is_user_logged_in',
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
                    __('You must be logged in to view certificates', 'wp-ffcertificate'),
                    array('status' => 401)
                );
            }

            // Check for admin view-as mode
            $view_as_user_id = $request->get_param('viewAsUserId');
            if ($view_as_user_id && current_user_can('manage_options')) {
                $user_id = absint($view_as_user_id);
            }

            if (!current_user_can('manage_options') && !current_user_can('view_own_certificates')) {
                return new \WP_Error(
                    'capability_denied',
                    __('You do not have permission to view certificates', 'wp-ffcertificate'),
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

            $certificates = array();

            foreach ($submissions as $submission) {
                $email_display = '';
                if (!empty($submission['email_encrypted'])) {
                    try {
                        $email_plain = \FreeFormCertificate\Core\Encryption::decrypt($submission['email_encrypted']);
                        $email_display = ($email_plain && is_string($email_plain)) ? \FreeFormCertificate\Core\Utils::mask_email($email_plain) : '';
                    } catch (\Exception $e) {
                        $email_display = __('Error decrypting', 'wp-ffcertificate');
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
                    'form_title' => $submission['form_title'] ?? __('Unknown Form', 'wp-ffcertificate'),
                    'submission_date' => $date_formatted ?: '',
                    'submission_date_raw' => $submission['submission_date'] ?? '',
                    'consent_given' => !empty($submission['consent_given']),
                    'email' => $email_display,
                    'auth_code' => $auth_code_formatted,
                    'magic_link' => $magic_link,
                    'pdf_url' => $magic_link,
                );
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
                    __('You must be logged in to view profile', 'wp-ffcertificate'),
                    array('status' => 401)
                );
            }

            $view_as_user_id = $request->get_param('viewAsUserId');
            if ($view_as_user_id && current_user_can('manage_options')) {
                $user_id = absint($view_as_user_id);
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
                    __('User not found', 'wp-ffcertificate'),
                    array('status' => 404)
                );
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

            return rest_ensure_response(array(
                'user_id' => $user_id,
                'display_name' => $user->display_name,
                'names' => $names,
                'email' => $user->user_email,
                'emails' => $emails,
                'cpf_masked' => !empty($cpfs_masked) ? $cpfs_masked[0] : __('Not found', 'wp-ffcertificate'),
                'cpfs_masked' => $cpfs_masked,
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
                    __('You must be logged in to view appointments', 'wp-ffcertificate'),
                    array('status' => 401)
                );
            }

            $view_as_user_id = $request->get_param('viewAsUserId');
            if ($view_as_user_id && current_user_can('manage_options')) {
                $user_id = absint($view_as_user_id);
            }

            if (!current_user_can('manage_options') && !current_user_can('ffc_view_self_scheduling')) {
                return new \WP_Error(
                    'capability_denied',
                    __('You do not have permission to view appointments', 'wp-ffcertificate'),
                    array('status' => 403)
                );
            }

            if (!class_exists('\FreeFormCertificate\Repositories\AppointmentRepository')) {
                return new \WP_Error(
                    'repository_not_found',
                    __('Appointment repository not available', 'wp-ffcertificate'),
                    array('status' => 500)
                );
            }

            if (!class_exists('\FreeFormCertificate\Repositories\CalendarRepository')) {
                return new \WP_Error(
                    'calendar_repository_not_found',
                    __('Calendar repository not available', 'wp-ffcertificate'),
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

            $appointments_formatted = array();

            foreach ($appointments as $appointment) {
                if (!is_array($appointment) || empty($appointment['id'])) {
                    continue;
                }

                $calendar_title = __('Unknown Calendar', 'wp-ffcertificate');
                $calendar = null;
                if (!empty($appointment['calendar_id'])) {
                    try {
                        $calendar = $calendar_repository->findById((int)$appointment['calendar_id']);
                        if ($calendar && isset($calendar['title'])) {
                            $calendar_title = $calendar['title'];
                        }
                    } catch (\Exception $e) {
                        // Calendar not found - use default
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
                    'pending' => __('Pending', 'wp-ffcertificate'),
                    'confirmed' => __('Confirmed', 'wp-ffcertificate'),
                    'cancelled' => __('Cancelled', 'wp-ffcertificate'),
                    'completed' => __('Completed', 'wp-ffcertificate'),
                    'no_show' => __('No Show', 'wp-ffcertificate'),
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
                    $appointment_time = strtotime($appointment['appointment_date'] . ' ' . ($appointment['start_time'] ?? '23:59:59'));
                    $now = current_time('timestamp');

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
                sprintf(__('Error loading appointments: %s', 'wp-ffcertificate'), $e->getMessage()),
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
                    __('You must be logged in to view bookings', 'wp-ffcertificate'),
                    array('status' => 401)
                );
            }

            $view_as_user_id = $request->get_param('viewAsUserId');
            if ($view_as_user_id && current_user_can('manage_options')) {
                $user_id = absint($view_as_user_id);
            }

            if (!current_user_can('manage_options') && !current_user_can('ffc_view_audience_bookings')) {
                return new \WP_Error(
                    'capability_denied',
                    __('You do not have permission to view audience bookings', 'wp-ffcertificate'),
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

            $bookings_formatted = array();

            foreach ($bookings as $booking) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $audiences = $wpdb->get_results($wpdb->prepare(
                    "SELECT a.name, a.color
                     FROM {$booking_audiences_table} ba
                     INNER JOIN {$audience_names_table} a ON ba.audience_id = a.id
                     WHERE ba.booking_id = %d",
                    $booking['id']
                ), ARRAY_A);

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
                    'active' => __('Confirmed', 'wp-ffcertificate'),
                    'cancelled' => __('Cancelled', 'wp-ffcertificate'),
                );

                $status = $booking['status'] ?? 'active';
                $is_past = strtotime($booking['booking_date']) < strtotime('today');

                $bookings_formatted[] = array(
                    'id' => (int) $booking['id'],
                    'environment_id' => (int) ($booking['environment_id'] ?? 0),
                    'environment_name' => $booking['environment_name'] ?? __('Unknown', 'wp-ffcertificate'),
                    'schedule_name' => $booking['schedule_name'] ?? '',
                    'booking_date' => $date_formatted,
                    'booking_date_raw' => $booking['booking_date'] ?? '',
                    'start_time' => $time_formatted,
                    'end_time' => $end_time_formatted,
                    'description' => $booking['description'] ?? '',
                    'status' => $status,
                    'status_label' => $status_labels[$status] ?? $status,
                    'is_past' => $is_past,
                    'audiences' => array_map(function($a) {
                        return array(
                            'name' => $a['name'],
                            'color' => $a['color'] ?? '#2271b1',
                        );
                    }, $audiences ?: array()),
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
                sprintf(__('Error loading audience bookings: %s', 'wp-ffcertificate'), $e->getMessage()),
                array('status' => 500)
            );
        }
    }
}
