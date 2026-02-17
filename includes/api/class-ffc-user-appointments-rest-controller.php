<?php
declare(strict_types=1);

/**
 * User Appointments REST Controller
 *
 * Handles:
 *   GET /user/appointments – Current user's self-scheduling appointments
 *
 * @since 4.12.7  Extracted from UserDataRestController
 * @package FreeFormCertificate\API
 */

namespace FreeFormCertificate\API;

if (!defined('ABSPATH')) exit;

class UserAppointmentsRestController {

    use UserContextTrait;

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
        register_rest_route($this->namespace, '/user/appointments', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_appointments'),
            'permission_callback' => 'is_user_logged_in',
        ));
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
            $ctx = $this->resolve_user_context($request);
            $user_id = $ctx['user_id'];

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view appointments', 'ffcertificate'),
                    array('status' => 401)
                );
            }

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

                $email_display = \FreeFormCertificate\Core\Encryption::decrypt_field($appointment, 'email');

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
}
