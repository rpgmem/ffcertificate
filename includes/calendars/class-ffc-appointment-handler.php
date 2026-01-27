<?php
declare(strict_types=1);

/**
 * Appointment Handler
 *
 * Handles appointment booking processing, validation, and business logic.
 * Similar to SubmissionHandler but for calendar appointments.
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\Calendars;

if (!defined('ABSPATH')) exit;

class AppointmentHandler {

    /**
     * Repositories
     */
    private $calendar_repository;
    private $appointment_repository;
    private $blocked_date_repository;

    /**
     * Constructor
     */
    public function __construct() {
        $this->calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
        $this->appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
        $this->blocked_date_repository = new \FreeFormCertificate\Repositories\BlockedDateRepository();

        // AJAX handlers
        add_action('wp_ajax_ffc_book_appointment', array($this, 'ajax_book_appointment'));
        add_action('wp_ajax_nopriv_ffc_book_appointment', array($this, 'ajax_book_appointment'));
        add_action('wp_ajax_ffc_get_available_slots', array($this, 'ajax_get_available_slots'));
        add_action('wp_ajax_nopriv_ffc_get_available_slots', array($this, 'ajax_get_available_slots'));
        add_action('wp_ajax_ffc_cancel_appointment', array($this, 'ajax_cancel_appointment'));
        add_action('wp_ajax_nopriv_ffc_cancel_appointment', array($this, 'ajax_cancel_appointment'));
    }

    /**
     * AJAX: Book appointment
     *
     * @return void
     */
    public function ajax_book_appointment(): void {
        try {
            // Verify nonce
            check_ajax_referer('ffc_calendar_nonce', 'nonce');

            // Validate security fields (honeypot + captcha)
            if (!class_exists('\FreeFormCertificate\Core\Utils')) {
                wp_send_json_error(array(
                    'message' => __('System error: Utils class not loaded.', 'ffc')
                ));
                return;
            }

            $security_check = \FreeFormCertificate\Core\Utils::validate_security_fields($_POST);
            if ($security_check !== true) {
                // Generate new captcha for retry
                $new_captcha = \FreeFormCertificate\Core\Utils::generate_simple_captcha();
                wp_send_json_error(array(
                    'message' => $security_check,
                    'refresh_captcha' => true,
                    'new_label' => $new_captcha['label'],
                    'new_hash' => $new_captcha['hash']
                ));
                return;
            }

            // Get and validate input
            $calendar_id = isset($_POST['calendar_id']) ? absint($_POST['calendar_id']) : 0;
            $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
            $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '';

            if (!$calendar_id || !$date || !$time) {
                wp_send_json_error(array(
                    'message' => __('Missing required fields.', 'ffc')
                ));
                return;
            }

            // Collect appointment data
            $appointment_data = array(
                'calendar_id' => $calendar_id,
                'appointment_date' => $date,
                'start_time' => $time,
                'name' => isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '',
                'email' => isset($_POST['email']) ? sanitize_email($_POST['email']) : '',
                'cpf_rf' => isset($_POST['cpf_rf']) ? sanitize_text_field($_POST['cpf_rf']) : '',
                'user_notes' => isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '',
                'custom_data' => isset($_POST['custom_data']) ? $_POST['custom_data'] : array(),
                'consent_given' => isset($_POST['consent']) ? 1 : 0,
                'consent_text' => isset($_POST['consent_text']) ? sanitize_textarea_field($_POST['consent_text']) : '',
                'user_ip' => \FreeFormCertificate\Core\Utils::get_user_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : ''
            );

            // Add user ID if logged in
            if (is_user_logged_in()) {
                $appointment_data['user_id'] = get_current_user_id();
            }

            // Process appointment
            $result = $this->process_appointment($appointment_data);

            if (is_wp_error($result)) {
                wp_send_json_error(array(
                    'message' => $result->get_error_message()
                ));
                return;
            }

            wp_send_json_success(array(
                'message' => __('Appointment booked successfully!', 'ffc'),
                'appointment_id' => $result['appointment_id'],
                'confirmation_token' => $result['confirmation_token'] ?? null
            ));
        } catch (\Exception $e) {
            error_log('FFC Calendar Appointment Error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => __('An unexpected error occurred. Please try again.', 'ffc'),
                'debug' => WP_DEBUG ? $e->getMessage() : null
            ));
        }
    }

    /**
     * AJAX: Get available slots for a date
     *
     * @return void
     */
    public function ajax_get_available_slots(): void {
        check_ajax_referer('ffc_calendar_nonce', 'nonce');

        $calendar_id = isset($_POST['calendar_id']) ? absint($_POST['calendar_id']) : 0;
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';

        if (!$calendar_id || !$date) {
            wp_send_json_error(array(
                'message' => __('Invalid parameters.', 'ffc')
            ));
            return;
        }

        $slots = $this->get_available_slots($calendar_id, $date);

        if (is_wp_error($slots)) {
            wp_send_json_error(array(
                'message' => $slots->get_error_message()
            ));
            return;
        }

        wp_send_json_success(array(
            'slots' => $slots,
            'date' => $date
        ));
    }

    /**
     * AJAX: Cancel appointment
     *
     * @return void
     */
    public function ajax_cancel_appointment(): void {
        // Verify nonce - accept both calendar nonce and wp_rest nonce (for user dashboard)
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

        $nonce_valid = false;
        if (wp_verify_nonce($nonce, 'ffc_calendar_nonce')) {
            $nonce_valid = true;
        } elseif (wp_verify_nonce($nonce, 'wp_rest')) {
            $nonce_valid = true;
        }

        if (!$nonce_valid) {
            wp_send_json_error(array(
                'message' => __('Security check failed. Please refresh the page and try again.', 'ffc')
            ));
            return;
        }

        $appointment_id = isset($_POST['appointment_id']) ? absint($_POST['appointment_id']) : 0;
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        if (!$appointment_id) {
            wp_send_json_error(array(
                'message' => __('Invalid appointment ID.', 'ffc')
            ));
            return;
        }

        $result = $this->cancel_appointment($appointment_id, $token, $reason);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => __('Appointment cancelled successfully.', 'ffc')
        ));
    }

    /**
     * Process appointment booking
     *
     * @param array $data Appointment data
     * @return array|WP_Error
     */
    public function process_appointment(array $data) {
        // Get calendar
        $calendar = $this->calendar_repository->findById($data['calendar_id']);

        if (!$calendar) {
            return new \WP_Error('invalid_calendar', __('Calendar not found.', 'ffc'));
        }

        // Validate calendar status
        if ($calendar['status'] !== 'active') {
            return new \WP_Error('calendar_inactive', __('This calendar is not accepting bookings.', 'ffc'));
        }

        // Calculate end time based on slot duration
        $start_datetime = $data['appointment_date'] . ' ' . $data['start_time'];
        $end_timestamp = strtotime($start_datetime) + ($calendar['slot_duration'] * 60);
        $data['end_time'] = date('H:i:s', $end_timestamp);

        // Run validations
        $validation = $this->validate_appointment($data, $calendar);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Check LGPD consent
        if (empty($data['consent_given'])) {
            return new \WP_Error('consent_required', __('You must agree to the terms to book an appointment.', 'ffc'));
        }

        $data['consent_date'] = current_time('mysql');
        $data['consent_ip'] = $data['user_ip'];

        // Set initial status
        $data['status'] = $calendar['requires_approval'] ? 'pending' : 'confirmed';

        if ($data['status'] === 'confirmed') {
            $data['approved_at'] = current_time('mysql');
        }

        // Create appointment
        $appointment_id = $this->appointment_repository->createAppointment($data);

        if (!$appointment_id) {
            return new \WP_Error('creation_failed', __('Failed to create appointment. Please try again.', 'ffc'));
        }

        // Log activity
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'appointment_created',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
                array(
                    'appointment_id' => $appointment_id,
                    'calendar_id' => $data['calendar_id'],
                    'date' => $data['appointment_date'],
                    'time' => $data['start_time'],
                    'status' => $data['status'],
                    'user_id' => $data['user_id'] ?? null,
                    'ip' => $data['user_ip']
                ),
                $appointment_id
            );
        }

        // Get appointment for email
        $appointment = $this->appointment_repository->findById($appointment_id);

        // Schedule email notifications
        $this->schedule_email_notifications($appointment, $calendar, 'created');

        // Generate receipt URL
        $receipt_url = '';
        if (class_exists('\FreeFormCertificate\Calendars\AppointmentReceiptHandler')) {
            $receipt_url = \FreeFormCertificate\Calendars\AppointmentReceiptHandler::get_receipt_url(
                $appointment_id,
                $appointment['confirmation_token'] ?? ''
            );
        }

        return array(
            'success' => true,
            'appointment_id' => $appointment_id,
            'confirmation_token' => $appointment['confirmation_token'] ?? null,
            'requires_approval' => $calendar['requires_approval'] == 1,
            'receipt_url' => $receipt_url
        );
    }

    /**
     * Validate appointment booking
     *
     * @param array $data Appointment data
     * @param array $calendar Calendar configuration
     * @return bool|WP_Error
     */
    private function validate_appointment(array $data, array $calendar) {
        // 1. Validate required fields
        if (empty($data['appointment_date']) || empty($data['start_time'])) {
            return new \WP_Error('missing_fields', __('Date and time are required.', 'ffc'));
        }

        // 2. Validate date format
        $date_obj = \DateTime::createFromFormat('Y-m-d', $data['appointment_date']);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $data['appointment_date']) {
            return new \WP_Error('invalid_date', __('Invalid date format.', 'ffc'));
        }

        // 3. Validate time format
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $data['start_time'])) {
            return new \WP_Error('invalid_time', __('Invalid time format.', 'ffc'));
        }

        // 4. Check if date is in the past
        $now = current_time('timestamp');
        $appointment_timestamp = strtotime($data['appointment_date'] . ' ' . $data['start_time']);

        if ($appointment_timestamp < $now) {
            return new \WP_Error('past_date', __('Cannot book appointments in the past.', 'ffc'));
        }

        // 5. Validate advance booking window (minimum)
        if ($calendar['advance_booking_min'] > 0) {
            $min_advance = $now + ($calendar['advance_booking_min'] * 3600);
            if ($appointment_timestamp < $min_advance) {
                return new \WP_Error(
                    'too_soon',
                    sprintf(
                        __('Appointments must be booked at least %d hours in advance.', 'ffc'),
                        $calendar['advance_booking_min']
                    )
                );
            }
        }

        // 6. Validate advance booking window (maximum)
        if ($calendar['advance_booking_max'] > 0) {
            $max_advance = $now + ($calendar['advance_booking_max'] * 86400);
            if ($appointment_timestamp > $max_advance) {
                return new \WP_Error(
                    'too_far',
                    sprintf(
                        __('Appointments cannot be booked more than %d days in advance.', 'ffc'),
                        $calendar['advance_booking_max']
                    )
                );
            }
        }

        // 7. Check if date is blocked
        if ($this->blocked_date_repository->isDateBlocked($data['calendar_id'], $data['appointment_date'], $data['start_time'])) {
            return new \WP_Error('date_blocked', __('This date/time is not available.', 'ffc'));
        }

        // 8. Check working hours
        $is_working_hour = $this->is_within_working_hours($data['appointment_date'], $data['start_time'], $calendar);
        if (!$is_working_hour) {
            return new \WP_Error('outside_hours', __('Selected time is outside working hours.', 'ffc'));
        }

        // 9. Check slot availability
        $is_available = $this->appointment_repository->isSlotAvailable(
            $data['calendar_id'],
            $data['appointment_date'],
            $data['start_time'],
            (int)$calendar['max_appointments_per_slot']
        );

        if (!$is_available) {
            return new \WP_Error('slot_full', __('This time slot is fully booked.', 'ffc'));
        }

        // 10. Check daily limit
        if ($calendar['slots_per_day'] > 0) {
            $daily_count = $this->get_daily_appointment_count($data['calendar_id'], $data['appointment_date']);
            if ($daily_count >= $calendar['slots_per_day']) {
                return new \WP_Error('daily_limit', __('Daily booking limit reached for this date.', 'ffc'));
            }
        }

        // 11. Validate user permissions (if login required)
        if ($calendar['require_login']) {
            if (!is_user_logged_in()) {
                return new \WP_Error('login_required', __('You must be logged in to book this calendar.', 'ffc'));
            }

            // Check allowed roles
            if (!empty($calendar['allowed_roles'])) {
                $allowed_roles = json_decode($calendar['allowed_roles'], true);
                if (is_array($allowed_roles) && !empty($allowed_roles)) {
                    $user = wp_get_current_user();
                    $has_role = array_intersect($user->roles, $allowed_roles);
                    if (empty($has_role)) {
                        return new \WP_Error('insufficient_permissions', __('You do not have permission to book this calendar.', 'ffc'));
                    }
                }
            }
        }

        // 12. Validate email (if not logged in)
        if (!is_user_logged_in() && empty($data['email'])) {
            return new \WP_Error('email_required', __('Email address is required.', 'ffc'));
        }

        // 13. Validate CPF/RF
        if (empty($data['cpf_rf'])) {
            return new \WP_Error('cpf_rf_required', __('CPF/RF is required.', 'ffc'));
        }

        // Validate CPF/RF format
        $cpf_rf_clean = preg_replace('/[^0-9]/', '', $data['cpf_rf']);
        if (strlen($cpf_rf_clean) == 7) {
            // RF validation (7 digits)
            if (!preg_match('/^\d{7}$/', $cpf_rf_clean)) {
                return new \WP_Error('invalid_rf', __('Invalid RF format.', 'ffc'));
            }
        } elseif (strlen($cpf_rf_clean) == 11) {
            // CPF validation
            if (!\FreeFormCertificate\Core\Utils::validate_cpf($cpf_rf_clean)) {
                return new \WP_Error('invalid_cpf', __('Invalid CPF.', 'ffc'));
            }
        } else {
            return new \WP_Error('invalid_cpf_rf', __('CPF/RF must be 7 digits (RF) or 11 digits (CPF).', 'ffc'));
        }

        return true;
    }

    /**
     * Check if time is within working hours
     *
     * @param string $date
     * @param string $time
     * @param array $calendar
     * @return bool
     */
    private function is_within_working_hours(string $date, string $time, array $calendar): bool {
        $day_of_week = (int)date('w', strtotime($date));
        $working_hours = json_decode($calendar['working_hours'], true);

        if (empty($working_hours) || !is_array($working_hours)) {
            return true; // No restrictions
        }

        foreach ($working_hours as $hours) {
            if ((int)$hours['day'] === $day_of_week) {
                // Check if time is within this working hour range
                $time_numeric = strtotime('1970-01-01 ' . $time);
                $start_numeric = strtotime('1970-01-01 ' . $hours['start']);
                $end_numeric = strtotime('1970-01-01 ' . $hours['end']);

                if ($time_numeric >= $start_numeric && $time_numeric < $end_numeric) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get available time slots for a date
     *
     * @param int $calendar_id
     * @param string $date
     * @return array|WP_Error
     */
    public function get_available_slots(int $calendar_id, string $date) {
        // Get calendar
        $calendar = $this->calendar_repository->getWithWorkingHours($calendar_id);

        if (!$calendar) {
            return new \WP_Error('invalid_calendar', __('Calendar not found.', 'ffc'));
        }

        if ($calendar['status'] !== 'active') {
            return new \WP_Error('calendar_inactive', __('Calendar is not active.', 'ffc'));
        }

        // Check if date is blocked
        if ($this->blocked_date_repository->isDateBlocked($calendar_id, $date)) {
            return array(); // No slots available
        }

        // Get day of week
        $day_of_week = (int)date('w', strtotime($date));

        // Get working hours for this day
        $working_hours = $calendar['working_hours'] ?? array();
        $day_hours = array_filter($working_hours, function($hours) use ($day_of_week) {
            return (int)$hours['day'] === $day_of_week;
        });

        if (empty($day_hours)) {
            return array(); // Not a working day
        }

        // Get existing appointments for this date
        $existing_appointments = $this->appointment_repository->getAppointmentsByDate($calendar_id, $date);

        // Build slot list
        $slots = array();
        $slot_duration = (int)$calendar['slot_duration'];
        $slot_interval = (int)$calendar['slot_interval'];
        $max_per_slot = (int)$calendar['max_appointments_per_slot'];

        foreach ($day_hours as $hours) {
            $current_time = strtotime($date . ' ' . $hours['start']);
            $end_time = strtotime($date . ' ' . $hours['end']);

            while ($current_time < $end_time) {
                $slot_time = date('H:i:s', $current_time);

                // Check if slot is not blocked by time-range blocks
                if (!$this->blocked_date_repository->isDateBlocked($calendar_id, $date, $slot_time)) {
                    // Count existing appointments for this slot
                    $count = 0;
                    foreach ($existing_appointments as $apt) {
                        if ($apt['start_time'] === $slot_time) {
                            $count++;
                        }
                    }

                    // Check availability
                    if ($count < $max_per_slot) {
                        $slots[] = array(
                            'time' => $slot_time,
                            'display' => date('H:i', $current_time),
                            'available' => $max_per_slot - $count,
                            'total' => $max_per_slot
                        );
                    }
                }

                // Move to next slot
                $current_time += ($slot_duration + $slot_interval) * 60;
            }
        }

        return $slots;
    }

    /**
     * Get daily appointment count
     *
     * @param int $calendar_id
     * @param string $date
     * @return int
     */
    private function get_daily_appointment_count(int $calendar_id, string $date): int {
        $appointments = $this->appointment_repository->getAppointmentsByDate($calendar_id, $date);
        return count($appointments);
    }

    /**
     * Cancel appointment
     *
     * @param int $appointment_id
     * @param string $token Confirmation token for guest users
     * @param string $reason Cancellation reason
     * @return bool|WP_Error
     */
    public function cancel_appointment(int $appointment_id, string $token = '', string $reason = '') {
        // Get appointment
        $appointment = $this->appointment_repository->findById($appointment_id);

        if (!$appointment) {
            return new \WP_Error('not_found', __('Appointment not found.', 'ffc'));
        }

        // Get calendar
        $calendar = $this->calendar_repository->findById($appointment['calendar_id']);

        if (!$calendar) {
            return new \WP_Error('calendar_not_found', __('Calendar not found.', 'ffc'));
        }

        // Verify ownership (user must own appointment or be admin)
        $can_cancel = false;
        $cancelled_by = null;

        if (current_user_can('manage_options')) {
            // Admin can always cancel
            $can_cancel = true;
            $cancelled_by = get_current_user_id();
        } elseif (is_user_logged_in() && $appointment['user_id'] == get_current_user_id()) {
            // User owns appointment
            $can_cancel = true;
            $cancelled_by = get_current_user_id();
        } elseif (!empty($token) && $appointment['confirmation_token'] === $token) {
            // Guest with valid token
            $can_cancel = true;
        }

        if (!$can_cancel) {
            return new \WP_Error('unauthorized', __('You do not have permission to cancel this appointment.', 'ffc'));
        }

        // Check if calendar allows cancellation (admin always can)
        if (!current_user_can('manage_options') && !$calendar['allow_cancellation']) {
            return new \WP_Error('cancellation_disabled', __('Cancellation is not allowed for this calendar.', 'ffc'));
        }

        // Check cancellation deadline
        if (!current_user_can('manage_options') && $calendar['cancellation_min_hours'] > 0) {
            $appointment_time = strtotime($appointment['appointment_date'] . ' ' . $appointment['start_time']);
            $deadline = $appointment_time - ($calendar['cancellation_min_hours'] * 3600);

            if (current_time('timestamp') > $deadline) {
                return new \WP_Error(
                    'deadline_passed',
                    sprintf(
                        __('Cancellation deadline has passed. Appointments must be cancelled at least %d hours in advance.', 'ffc'),
                        $calendar['cancellation_min_hours']
                    )
                );
            }
        }

        // Check if already cancelled
        if ($appointment['status'] === 'cancelled') {
            return new \WP_Error('already_cancelled', __('This appointment is already cancelled.', 'ffc'));
        }

        // Cancel appointment
        $result = $this->appointment_repository->cancel($appointment_id, $cancelled_by, $reason);

        if ($result === false) {
            return new \WP_Error('cancellation_failed', __('Failed to cancel appointment.', 'ffc'));
        }

        // Log activity
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'appointment_cancelled',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
                array(
                    'appointment_id' => $appointment_id,
                    'calendar_id' => $appointment['calendar_id'],
                    'cancelled_by' => $cancelled_by,
                    'reason' => $reason
                ),
                $appointment_id
            );
        }

        // Send cancellation emails
        $this->schedule_email_notifications($appointment, $calendar, 'cancelled');

        return true;
    }

    /**
     * Schedule email notifications
     *
     * @param array $appointment
     * @param array $calendar
     * @param string $event (created, confirmed, cancelled, reminder)
     * @return void
     */
    private function schedule_email_notifications(array $appointment, array $calendar, string $event): void {
        // Get email config
        $email_config = json_decode($calendar['email_config'], true);

        if (!is_array($email_config)) {
            return;
        }

        // Check if global emails are disabled
        $global_settings = get_option('ffc_settings', array());
        if (!empty($global_settings['disable_all_emails'])) {
            return;
        }

        // Schedule based on event type
        switch ($event) {
            case 'created':
                if (!empty($email_config['send_user_confirmation'])) {
                    do_action('ffc_appointment_created_email', $appointment, $calendar);
                }
                if (!empty($email_config['send_admin_notification'])) {
                    do_action('ffc_appointment_admin_notification', $appointment, $calendar);
                }
                break;

            case 'confirmed':
                if (!empty($email_config['send_approval_notification'])) {
                    do_action('ffc_appointment_confirmed_email', $appointment, $calendar);
                }
                break;

            case 'cancelled':
                if (!empty($email_config['send_cancellation_notification'])) {
                    do_action('ffc_appointment_cancelled_email', $appointment, $calendar);
                }
                break;
        }
    }
}
