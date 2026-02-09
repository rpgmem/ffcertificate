<?php
declare(strict_types=1);

/**
 * Appointment REST Controller
 *
 * Handles appointment-related REST API endpoints:
 *   POST   /calendars/{id}/appointments – Create appointment
 *   GET    /appointments/{id}           – Get appointment details
 *   DELETE /appointments/{id}           – Cancel appointment
 *
 * @since 4.6.1
 * @package FreeFormCertificate\API
 */

namespace FreeFormCertificate\API;

if (!defined('ABSPATH')) exit;

class AppointmentRestController {

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
        // POST /calendars/{id}/appointments - Create appointment
        register_rest_route($this->namespace, '/calendars/(?P<id>\d+)/appointments', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'create_appointment'),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));

        // GET /appointments/{id} - Get appointment details
        register_rest_route($this->namespace, '/appointments/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_appointment'),
            'permission_callback' => array($this, 'check_appointment_access'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));

        // DELETE /appointments/{id} - Cancel appointment
        register_rest_route($this->namespace, '/appointments/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::DELETABLE,
            'callback' => array($this, 'cancel_appointment'),
            'permission_callback' => array($this, 'check_appointment_access'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));
    }

    /**
     * POST /calendars/{id}/appointments
     * Create a new appointment
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function create_appointment($request) {
        try {
            $calendar_id = $request->get_param('id');
            $params = $request->get_json_params();

            if (empty($params)) {
                return new \WP_Error(
                    'no_data',
                    __('No data provided in request body', 'ffcertificate'),
                    array('status' => 400)
                );
            }

            $required_fields = array('date', 'time', 'name', 'email');
            foreach ($required_fields as $field) {
                if (empty($params[$field])) {
                    return new \WP_Error(
                        'missing_field',
                        /* translators: %s: field name */
                        sprintf(__('Missing required field: %s', 'ffcertificate'), $field),
                        array('status' => 400)
                    );
                }
            }

            if (!is_email($params['email'])) {
                return new \WP_Error(
                    'invalid_email',
                    __('Invalid email address', 'ffcertificate'),
                    array('status' => 400)
                );
            }

            // Check scheduling visibility
            $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
            $calendar = $calendar_repository->findById($calendar_id);

            if (!$calendar) {
                return new \WP_Error(
                    'calendar_not_found',
                    __('Calendar not found', 'ffcertificate'),
                    array('status' => 404)
                );
            }

            $has_bypass = \FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass();
            $scheduling_visibility = $calendar['scheduling_visibility'] ?? 'public';

            if ($scheduling_visibility === 'private' && !is_user_logged_in() && !$has_bypass) {
                return new \WP_Error(
                    'scheduling_private',
                    __('This calendar requires authentication to book.', 'ffcertificate'),
                    array('status' => 403)
                );
            }

            // Rate limiting (prevent automated booking abuse)
            if (class_exists('\FreeFormCertificate\Security\RateLimiter')) {
                $ip = \FreeFormCertificate\Core\Utils::get_user_ip();
                $rate_limiter = new \FreeFormCertificate\Security\RateLimiter();

                if (!$rate_limiter->check_limit('ip', $ip)) {
                    return new \WP_Error(
                        'rate_limit_exceeded',
                        __('Too many requests. Please try again later.', 'ffcertificate'),
                        array('status' => 429)
                    );
                }
            }

            $appointment_data = array(
                'calendar_id' => $calendar_id,
                'appointment_date' => sanitize_text_field($params['date']),
                'start_time' => sanitize_text_field($params['time']),
                'name' => sanitize_text_field($params['name']),
                'email' => sanitize_email($params['email']),
                'phone' => isset($params['phone']) ? sanitize_text_field($params['phone']) : '',
                'user_notes' => isset($params['notes']) ? sanitize_textarea_field($params['notes']) : '',
                'custom_data' => isset($params['custom_data']) ? $params['custom_data'] : array(),
                'consent_given' => isset($params['consent']) ? 1 : 0,
                'consent_text' => isset($params['consent_text']) ? sanitize_textarea_field($params['consent_text']) : '',
                'user_ip' => \FreeFormCertificate\Core\Utils::get_user_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : ''
            );

            if (is_user_logged_in()) {
                $appointment_data['user_id'] = get_current_user_id();
            }

            if (!class_exists('\FreeFormCertificate\SelfScheduling\AppointmentHandler')) {
                return new \WP_Error(
                    'handler_not_found',
                    __('Appointment handler not available', 'ffcertificate'),
                    array('status' => 500)
                );
            }

            $appointment_handler = new \FreeFormCertificate\SelfScheduling\AppointmentHandler();
            $result = $appointment_handler->process_appointment($appointment_data);

            if (is_wp_error($result)) {
                return $result;
            }

            return rest_ensure_response(array(
                'success' => true,
                'message' => __('Appointment booked successfully!', 'ffcertificate'),
                'appointment_id' => $result['appointment_id'],
                'requires_approval' => $result['requires_approval'],
            ));

        } catch (\Exception $e) {
            $this->log_rest_error( 'create_appointment', $e );
            return new \WP_Error(
                'ffc_internal_error',
                __( 'An unexpected error occurred.', 'ffcertificate' ),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * GET /appointments/{id}
     * Get appointment details
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_appointment($request) {
        try {
            $appointment_id = $request->get_param('id');

            if (!class_exists('\FreeFormCertificate\Repositories\AppointmentRepository')) {
                return new \WP_Error(
                    'repository_not_found',
                    __('Appointment repository not available', 'ffcertificate'),
                    array('status' => 500)
                );
            }

            $appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
            $appointment = $appointment_repository->findById($appointment_id);

            if (!$appointment) {
                return new \WP_Error(
                    'appointment_not_found',
                    __('Appointment not found', 'ffcertificate'),
                    array('status' => 404)
                );
            }

            $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
            $calendar = $calendar_repository->findById($appointment['calendar_id']);

            $email_display = '';
            if (!empty($appointment['email_encrypted'])) {
                try {
                    $email_plain = \FreeFormCertificate\Core\Encryption::decrypt($appointment['email_encrypted']);
                    $email_display = ($email_plain && is_string($email_plain)) ? $email_plain : '';
                } catch (\Exception $e) {
                    $email_display = '';
                }
            } elseif (!empty($appointment['email'])) {
                $email_display = $appointment['email'];
            }

            return rest_ensure_response(array(
                'id' => (int) $appointment['id'],
                'calendar_id' => (int) $appointment['calendar_id'],
                'calendar_title' => $calendar['title'] ?? '',
                'appointment_date' => $appointment['appointment_date'],
                'start_time' => $appointment['start_time'],
                'end_time' => $appointment['end_time'],
                'status' => $appointment['status'],
                'name' => $appointment['name'],
                'email' => $email_display,
                'phone' => $appointment['phone'] ?? '',
                'user_notes' => $appointment['user_notes'] ?? '',
                'created_at' => $appointment['created_at'],
            ));

        } catch (\Exception $e) {
            $this->log_rest_error( 'get_appointment', $e );
            return new \WP_Error(
                'ffc_internal_error',
                __( 'An unexpected error occurred.', 'ffcertificate' ),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * DELETE /appointments/{id}
     * Cancel an appointment
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function cancel_appointment($request) {
        try {
            $appointment_id = $request->get_param('id');
            $params = $request->get_json_params();
            $reason = isset($params['reason']) ? sanitize_textarea_field($params['reason']) : '';

            if (!class_exists('\FreeFormCertificate\SelfScheduling\AppointmentHandler')) {
                return new \WP_Error(
                    'handler_not_found',
                    __('Appointment handler not available', 'ffcertificate'),
                    array('status' => 500)
                );
            }

            $appointment_handler = new \FreeFormCertificate\SelfScheduling\AppointmentHandler();
            $result = $appointment_handler->cancel_appointment($appointment_id, '', $reason);

            if (is_wp_error($result)) {
                return $result;
            }

            return rest_ensure_response(array(
                'success' => true,
                'message' => __('Appointment cancelled successfully', 'ffcertificate'),
            ));

        } catch (\Exception $e) {
            $this->log_rest_error( 'cancel_appointment', $e );
            return new \WP_Error(
                'ffc_internal_error',
                __( 'An unexpected error occurred.', 'ffcertificate' ),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * Check appointment access permission
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function check_appointment_access($request): bool {
        $appointment_id = $request->get_param('id');

        if (\FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass()) {
            return true;
        }

        if (!is_user_logged_in()) {
            return false;
        }

        if (!class_exists('\FreeFormCertificate\Repositories\AppointmentRepository')) {
            return false;
        }

        $appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
        $appointment = $appointment_repository->findById($appointment_id);

        if (!$appointment) {
            return false;
        }

        return (int) $appointment['user_id'] === get_current_user_id();
    }

    /**
     * Log REST API error without exposing details to clients.
     *
     * @since 4.6.6
     * @param string     $context Action that caused the error.
     * @param \Exception $e       The exception.
     */
    private function log_rest_error( string $context, \Exception $e ): void {
        if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
            \FreeFormCertificate\Core\Utils::debug_log( "REST API error: {$context}", array(
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ) );
        }
    }
}
