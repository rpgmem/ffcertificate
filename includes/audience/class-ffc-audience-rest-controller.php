<?php
declare(strict_types=1);

/**
 * Audience REST Controller
 *
 * Handles REST API endpoints for audience booking system.
 *
 * Endpoints:
 * - GET  /ffc/v1/audience/bookings       - Get bookings for date range
 * - POST /ffc/v1/audience/bookings       - Create a new booking
 * - DELETE /ffc/v1/audience/bookings/{id} - Cancel a booking
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceRestController {

    /**
     * Namespace for REST routes
     */
    private const NAMESPACE = 'ffc/v1';

    /**
     * Resource base
     */
    private const REST_BASE = 'audience';

    /**
     * Register REST routes
     *
     * @return void
     */
    public function register_routes(): void {
        // Get bookings
        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/bookings', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_bookings'),
            'permission_callback' => array($this, 'check_read_permission'),
            'args' => array(
                'schedule_id' => array(
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ),
                'environment_id' => array(
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ),
                'start_date' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'end_date' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Create booking
        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/bookings', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'create_booking'),
            'permission_callback' => array($this, 'check_write_permission'),
            'args' => array(
                'environment_id' => array(
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ),
                'booking_date' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'start_time' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'end_time' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'booking_type' => array(
                    'type' => 'string',
                    'required' => true,
                    'enum' => array('audience', 'individual'),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'description' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'audience_ids' => array(
                    'type' => 'array',
                    'items' => array('type' => 'integer'),
                    'default' => array(),
                ),
                'user_ids' => array(
                    'type' => 'array',
                    'items' => array('type' => 'integer'),
                    'default' => array(),
                ),
            ),
        ));

        // Cancel booking
        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/bookings/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::DELETABLE,
            'callback' => array($this, 'cancel_booking'),
            'permission_callback' => array($this, 'check_cancel_permission'),
            'args' => array(
                'id' => array(
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ),
                'reason' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
            ),
        ));

        // Check conflicts
        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/conflicts', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'check_conflicts'),
            'permission_callback' => array($this, 'check_read_permission'),
            'args' => array(
                'environment_id' => array(
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ),
                'booking_date' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'start_time' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'end_time' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'audience_ids' => array(
                    'type' => 'array',
                    'items' => array('type' => 'integer'),
                    'default' => array(),
                ),
                'user_ids' => array(
                    'type' => 'array',
                    'items' => array('type' => 'integer'),
                    'default' => array(),
                ),
            ),
        ));
    }

    /**
     * Check read permission
     *
     * @return bool
     */
    public function check_read_permission(): bool {
        return is_user_logged_in();
    }

    /**
     * Check write permission
     *
     * @return bool
     */
    public function check_write_permission(): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        // Admin can always write
        if (current_user_can('manage_options')) {
            return true;
        }

        // Check if user has booking permission on any schedule
        // This is a simplified check - actual permission is verified per booking
        return true;
    }

    /**
     * Check cancel permission
     *
     * @param \WP_REST_Request $request Request object
     * @return bool
     */
    public function check_cancel_permission(\WP_REST_Request $request): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        // Admin can cancel anything
        if (current_user_can('manage_options')) {
            return true;
        }

        $booking_id = $request->get_param('id');
        $booking = AudienceBookingRepository::get_by_id($booking_id);

        if (!$booking) {
            return false;
        }

        // Creator can cancel their own booking
        if ((int) $booking->created_by === get_current_user_id()) {
            return true;
        }

        // Check if user has cancel_others permission on this schedule
        $environment = AudienceEnvironmentRepository::get_by_id((int) $booking->environment_id);
        if ($environment) {
            return AudienceScheduleRepository::user_can_cancel_others((int) $environment->schedule_id, get_current_user_id());
        }

        return false;
    }

    /**
     * Get bookings
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function get_bookings(\WP_REST_Request $request): \WP_REST_Response {
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        $schedule_id = $request->get_param('schedule_id');
        $environment_id = $request->get_param('environment_id');

        // Build query args
        $args = array(
            'start_date' => $start_date,
            'end_date' => $end_date,
            'status' => null, // Get all statuses
        );

        if ($schedule_id) {
            $args['schedule_id'] = $schedule_id;
        }

        if ($environment_id) {
            $args['environment_id'] = $environment_id;
        }

        // Get bookings
        $bookings = AudienceBookingRepository::get_all($args);

        // Get user info for each booking
        $user_id = get_current_user_id();
        $is_admin = current_user_can('manage_options');

        // Transform bookings for response
        $bookings_data = array_map(function($booking) use ($user_id, $is_admin) {
            $audiences = AudienceBookingRepository::get_booking_audiences((int) $booking->id);

            return array(
                'id' => $booking->id,
                'environment_id' => $booking->environment_id,
                'environment_name' => $booking->environment_name ?? '',
                'booking_date' => $booking->booking_date,
                'start_time' => $booking->start_time,
                'end_time' => $booking->end_time,
                'booking_type' => $booking->booking_type,
                'description' => $booking->description,
                'status' => $booking->status,
                'created_by' => (int) $booking->created_by,
                'can_cancel' => $is_admin || (int) $booking->created_by === $user_id,
                'audiences' => array_map(function($a) {
                    return array(
                        'id' => $a->id,
                        'name' => $a->name,
                        'color' => $a->color,
                    );
                }, $audiences),
            );
        }, $bookings);

        // Get schedule-specific holidays for the date range
        $holidays = array();
        if ($schedule_id) {
            $holidays = AudienceEnvironmentRepository::get_holidays((int) $schedule_id, $start_date, $end_date);
        }

        // Merge global holidays into the response
        $global_holidays = \FreeFormCertificate\Scheduling\DateBlockingService::get_global_holidays($start_date, $end_date);
        $holidays_formatted = array_map(function($h) {
            return array(
                'holiday_date' => $h->holiday_date,
                'description' => $h->description,
            );
        }, $holidays);

        foreach ($global_holidays as $gh) {
            $holidays_formatted[] = array(
                'holiday_date' => $gh['date'],
                'description' => $gh['description'] ?? __('Holiday', 'wp-ffcertificate'),
            );
        }

        // Get closed weekdays from environment working hours
        $closed_weekdays = array();
        if ($environment_id) {
            $working_hours = AudienceEnvironmentRepository::get_working_hours((int) $environment_id);
            if ($working_hours) {
                $day_map = array('sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6);
                foreach ($working_hours as $day => $hours) {
                    if (isset($hours['closed']) && $hours['closed']) {
                        $closed_weekdays[] = $day_map[$day] ?? -1;
                    }
                }
            }
        }

        return new \WP_REST_Response(array(
            'success' => true,
            'bookings' => $bookings_data,
            'holidays' => $holidays_formatted,
            'closed_weekdays' => $closed_weekdays,
        ), 200);
    }

    /**
     * Create booking
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function create_booking(\WP_REST_Request $request): \WP_REST_Response {
        $environment_id = $request->get_param('environment_id');
        $booking_date = $request->get_param('booking_date');
        $start_time = $request->get_param('start_time');
        $end_time = $request->get_param('end_time');
        $booking_type = $request->get_param('booking_type');
        $description = $request->get_param('description');
        $audience_ids = $request->get_param('audience_ids') ?: array();
        $user_ids = $request->get_param('user_ids') ?: array();

        // Validate environment exists
        $environment = AudienceEnvironmentRepository::get_by_id($environment_id);
        if (!$environment) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid environment.', 'wp-ffcertificate'),
            ), 400);
        }

        // Check user permission on this schedule
        $user_id = get_current_user_id();
        if (!current_user_can('manage_options') && !AudienceScheduleRepository::user_can_book((int) $environment->schedule_id, $user_id)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('You do not have permission to book on this calendar.', 'wp-ffcertificate'),
            ), 403);
        }

        // Validate date is not in the past
        if ($booking_date < current_time('Y-m-d')) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Cannot book dates in the past.', 'wp-ffcertificate'),
            ), 400);
        }

        // Validate time range
        if ($start_time >= $end_time) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('End time must be after start time.', 'wp-ffcertificate'),
            ), 400);
        }

        // Validate description length
        $desc_length = mb_strlen($description);
        if ($desc_length < 15 || $desc_length > 300) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Description must be between 15 and 300 characters.', 'wp-ffcertificate'),
            ), 400);
        }

        // Validate booking type has required data
        if ($booking_type === 'audience' && empty($audience_ids)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('At least one audience must be selected.', 'wp-ffcertificate'),
            ), 400);
        }

        if ($booking_type === 'individual' && empty($user_ids)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('At least one user must be selected.', 'wp-ffcertificate'),
            ), 400);
        }

        // Check for future days limit
        $schedule = AudienceScheduleRepository::get_by_id((int) $environment->schedule_id);
        if ($schedule && $schedule->future_days_limit && !current_user_can('manage_options')) {
            $max_date = gmdate('Y-m-d', strtotime('+' . $schedule->future_days_limit . ' days'));
            if ($booking_date > $max_date) {
                return new \WP_REST_Response(array(
                    'success' => false,
                    'message' => sprintf(
                        /* translators: %d: maximum number of days allowed for advance booking */
                    __('Cannot book more than %d days in advance.', 'wp-ffcertificate'),
                        $schedule->future_days_limit
                    ),
                ), 400);
            }
        }

        // Check environment is open on this date/time
        if (!AudienceEnvironmentRepository::is_open($environment_id, $booking_date, $start_time)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('The environment is closed at this time.', 'wp-ffcertificate'),
            ), 400);
        }

        // Check for time slot conflicts (environment double-booking)
        $conflicts = AudienceBookingRepository::get_conflicts($environment_id, $booking_date, $start_time, $end_time);
        if (!empty($conflicts)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('This time slot is already booked for this environment.', 'wp-ffcertificate'),
            ), 400);
        }

        // Create the booking
        $booking_id = AudienceBookingRepository::create(array(
            'environment_id' => $environment_id,
            'booking_date' => $booking_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'booking_type' => $booking_type,
            'description' => $description,
            'audience_ids' => $audience_ids,
            'user_ids' => $user_ids,
        ));

        if (!$booking_id) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Failed to create booking.', 'wp-ffcertificate'),
            ), 500);
        }

        // Trigger notification hook
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffc_ is the plugin prefix.
        do_action('ffc_audience_booking_created', $booking_id);

        return new \WP_REST_Response(array(
            'success' => true,
            'booking_id' => $booking_id,
            'message' => __('Booking created successfully.', 'wp-ffcertificate'),
        ), 201);
    }

    /**
     * Cancel booking
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function cancel_booking(\WP_REST_Request $request): \WP_REST_Response {
        $booking_id = $request->get_param('id');
        $reason = $request->get_param('reason');

        // Validate reason
        if (empty($reason) || mb_strlen($reason) < 5) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Cancellation reason is required.', 'wp-ffcertificate'),
            ), 400);
        }

        $booking = AudienceBookingRepository::get_by_id($booking_id);
        if (!$booking) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Booking not found.', 'wp-ffcertificate'),
            ), 404);
        }

        if ($booking->status === 'cancelled') {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Booking is already cancelled.', 'wp-ffcertificate'),
            ), 400);
        }

        // Cancel the booking
        $result = AudienceBookingRepository::cancel($booking_id, $reason);

        if (!$result) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Failed to cancel booking.', 'wp-ffcertificate'),
            ), 500);
        }

        // Trigger notification hook
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffc_ is the plugin prefix.
        do_action('ffc_audience_booking_cancelled', $booking_id, $reason);

        return new \WP_REST_Response(array(
            'success' => true,
            'message' => __('Booking cancelled successfully.', 'wp-ffcertificate'),
        ), 200);
    }

    /**
     * Check conflicts
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function check_conflicts(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $environment_id = (int) $request->get_param('environment_id');
            $booking_date = sanitize_text_field($request->get_param('booking_date'));
            $start_time = sanitize_text_field($request->get_param('start_time'));
            $end_time = sanitize_text_field($request->get_param('end_time'));
            $audience_ids = array_map('intval', (array) ($request->get_param('audience_ids') ?: array()));
            $user_ids = array_map('intval', (array) ($request->get_param('user_ids') ?: array()));

            // Validate required parameters
            if (!$environment_id || !$booking_date || !$start_time || !$end_time) {
                return new \WP_REST_Response(array(
                    'success' => false,
                    'message' => __('Missing required parameters.', 'wp-ffcertificate'),
                ), 400);
            }

            $response_data = array(
                'type' => 'none',
                'bookings' => array(),
                'affected_users' => array(),
                'audience_same_day' => array(),
            );

            $is_hard_conflict = false;

            // 1. Check environment time slot conflicts (hard conflict)
            $env_conflicts = AudienceBookingRepository::get_conflicts($environment_id, $booking_date, $start_time, $end_time);
            if (!empty($env_conflicts)) {
                $response_data['type'] = 'environment';
                $response_data['message'] = __('Time slot already booked for this environment.', 'wp-ffcertificate');
                $response_data['bookings'] = array_map(function($b) {
                    return array(
                        'id' => $b->id,
                        'start_time' => $b->start_time,
                        'end_time' => $b->end_time,
                    );
                }, $env_conflicts);
                $is_hard_conflict = true;
            }

            // 2. Check same audience group on same day (hard conflict)
            if (!$is_hard_conflict && !empty($audience_ids)) {
                $same_day = AudienceBookingRepository::get_audience_same_day_bookings($booking_date, $audience_ids);
                if (!empty($same_day)) {
                    $response_data['type'] = 'audience_same_day';
                    $response_data['audience_same_day'] = array_map(function($b) {
                        return array(
                            'id' => (int) $b->id,
                            'start_time' => $b->start_time,
                            'end_time' => $b->end_time,
                            'description' => $b->description ?? '',
                            'audience_name' => $b->audience_name,
                        );
                    }, $same_day);
                    $is_hard_conflict = true;
                }
            }

            // 3. Check user conflicts — members with overlapping bookings (soft conflict)
            // Only check if no hard conflict detected
            if (!$is_hard_conflict) {
                $user_conflicts = AudienceBookingRepository::get_user_conflicts(
                    $booking_date,
                    $start_time,
                    $end_time,
                    $audience_ids,
                    $user_ids
                );

                if (!empty($user_conflicts['bookings'])) {
                    $response_data['type'] = 'user';
                    $response_data['bookings'] = array_map(function($b) {
                        return array(
                            'id' => $b->id,
                            'start_time' => $b->start_time,
                            'end_time' => $b->end_time,
                            'description' => $b->description,
                        );
                    }, $user_conflicts['bookings']);
                    $response_data['affected_users'] = $user_conflicts['affected_users'];
                }
            }

            return new \WP_REST_Response(array(
                'success' => true,
                'conflicts' => $response_data,
            ), 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Error checking conflicts.', 'wp-ffcertificate') . ' ' . $e->getMessage(),
            ), 500);
        }
    }
}
