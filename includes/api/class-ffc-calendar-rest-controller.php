<?php
declare(strict_types=1);

/**
 * Calendar REST Controller
 *
 * Handles calendar-related REST API endpoints:
 *   GET /calendars            – List active calendars
 *   GET /calendars/{id}       – Get calendar details
 *   GET /calendars/{id}/slots – Get available time slots
 *
 * @since 4.6.1
 * @package FreeFormCertificate\API
 */

namespace FreeFormCertificate\API;

if (!defined('ABSPATH')) exit;

class CalendarRestController {

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
        // GET /calendars - List all active calendars
        register_rest_route($this->namespace, '/calendars', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_calendars'),
            'permission_callback' => '__return_true',
        ));

        // GET /calendars/{id} - Get calendar details
        register_rest_route($this->namespace, '/calendars/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_calendar'),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));

        // GET /calendars/{id}/slots - Get available time slots
        register_rest_route($this->namespace, '/calendars/(?P<id>\d+)/slots', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_calendar_slots'),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
                'date' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return (bool) strtotime($param);
                    },
                ),
            ),
        ));
    }

    /**
     * GET /calendars
     * List all active calendars
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_calendars($request) {
        try {
            if (!class_exists('\FreeFormCertificate\Repositories\CalendarRepository')) {
                return new \WP_Error(
                    'repository_not_found',
                    __('Calendar repository not available', 'ffcertificate'),
                    array('status' => 500)
                );
            }

            $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
            $has_bypass = \FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass();

            // Non-authenticated users only see public calendars
            if (!is_user_logged_in() && !$has_bypass) {
                $calendars = $calendar_repository->getPublicActiveCalendars();
            } else {
                $calendars = $calendar_repository->findAll(
                    array('status' => 'active'),
                    'title',
                    'ASC'
                );
            }

            $calendars_formatted = array();
            foreach ($calendars as $calendar) {
                $calendars_formatted[] = array(
                    'id' => (int) $calendar['id'],
                    'title' => $calendar['title'],
                    'description' => $calendar['description'] ?? '',
                    'requires_approval' => (bool) $calendar['requires_approval'],
                    'visibility' => $calendar['visibility'] ?? 'public',
                    'scheduling_visibility' => $calendar['scheduling_visibility'] ?? 'public',
                    'allow_cancellation' => (bool) $calendar['allow_cancellation'],
                    'slot_duration' => (int) $calendar['slot_duration'],
                    'advance_booking_min' => (int) $calendar['advance_booking_min'],
                    'advance_booking_max' => (int) $calendar['advance_booking_max'],
                );
            }

            return rest_ensure_response(array(
                'calendars' => $calendars_formatted,
                'total' => count($calendars_formatted),
            ));

        } catch (\Exception $e) {
            $this->log_rest_error( 'get_calendars', $e );
            return new \WP_Error(
                'ffc_internal_error',
                __( 'An unexpected error occurred.', 'ffcertificate' ),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * GET /calendars/{id}
     * Get calendar details
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_calendar($request) {
        try {
            $calendar_id = $request->get_param('id');

            if (!class_exists('\FreeFormCertificate\Repositories\CalendarRepository')) {
                return new \WP_Error(
                    'repository_not_found',
                    __('Calendar repository not available', 'ffcertificate'),
                    array('status' => 500)
                );
            }

            $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
            $calendar = $calendar_repository->getWithWorkingHours($calendar_id);

            if (!$calendar) {
                return new \WP_Error(
                    'calendar_not_found',
                    __('Calendar not found', 'ffcertificate'),
                    array('status' => 404)
                );
            }

            if ($calendar['status'] !== 'active') {
                return new \WP_Error(
                    'calendar_inactive',
                    __('Calendar is not active', 'ffcertificate'),
                    array('status' => 403)
                );
            }

            // Check visibility for non-authenticated users
            $visibility = $calendar['visibility'] ?? 'public';
            $has_bypass = \FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass();

            if ($visibility === 'private' && !is_user_logged_in() && !$has_bypass) {
                return new \WP_Error(
                    'calendar_private',
                    __('This calendar requires authentication.', 'ffcertificate'),
                    array('status' => 403)
                );
            }

            return rest_ensure_response(array(
                'id' => (int) $calendar['id'],
                'title' => $calendar['title'],
                'description' => $calendar['description'] ?? '',
                'requires_approval' => (bool) $calendar['requires_approval'],
                'visibility' => $visibility,
                'scheduling_visibility' => $calendar['scheduling_visibility'] ?? 'public',
                'allow_cancellation' => (bool) $calendar['allow_cancellation'],
                'cancellation_min_hours' => (int) $calendar['cancellation_min_hours'],
                'slot_duration' => (int) $calendar['slot_duration'],
                'slot_interval' => (int) $calendar['slot_interval'],
                'max_appointments_per_slot' => (int) $calendar['max_appointments_per_slot'],
                'advance_booking_min' => (int) $calendar['advance_booking_min'],
                'advance_booking_max' => (int) $calendar['advance_booking_max'],
                'working_hours' => $calendar['working_hours'] ?? array(),
            ));

        } catch (\Exception $e) {
            $this->log_rest_error( 'get_calendar', $e );
            return new \WP_Error(
                'ffc_internal_error',
                __( 'An unexpected error occurred.', 'ffcertificate' ),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * GET /calendars/{id}/slots
     * Get available time slots for a date
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_calendar_slots($request) {
        try {
            $calendar_id = $request->get_param('id');
            $date = $request->get_param('date');

            if (!class_exists('\FreeFormCertificate\SelfScheduling\AppointmentHandler')) {
                return new \WP_Error(
                    'handler_not_found',
                    __('Appointment handler not available', 'ffcertificate'),
                    array('status' => 500)
                );
            }

            $appointment_handler = new \FreeFormCertificate\SelfScheduling\AppointmentHandler();
            $slots = $appointment_handler->get_available_slots($calendar_id, $date);

            if (is_wp_error($slots)) {
                return $slots;
            }

            return rest_ensure_response(array(
                'slots' => $slots,
                'date' => $date,
                'calendar_id' => $calendar_id,
            ));

        } catch (\Exception $e) {
            $this->log_rest_error( 'get_calendar_slots', $e );
            return new \WP_Error(
                'ffc_internal_error',
                __( 'An unexpected error occurred.', 'ffcertificate' ),
                array( 'status' => 500 )
            );
        }
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
