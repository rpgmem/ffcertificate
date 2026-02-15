<?php
declare(strict_types=1);

/**
 * Appointment Handler
 *
 * Core business logic for appointment booking, cancellation, and slot availability.
 * AJAX handling extracted to AppointmentAjaxHandler (v4.6.8).
 * Validation logic extracted to AppointmentValidator (v4.6.8).
 *
 * @since 4.1.0
 * @version 4.6.10 - Transaction-based booking with row-level locking (race condition fix)
 * @version 4.6.8 - Refactored: extracted AJAX + validation into separate classes
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;

class AppointmentHandler {

    use \FreeFormCertificate\Core\EmailHelperTrait;

    /**
     * Repositories
     */
    private $calendar_repository;
    private $appointment_repository;
    private $blocked_date_repository;

    /**
     * Validator
     */
    private AppointmentValidator $validator;

    /**
     * Constructor
     */
    public function __construct() {
        $this->calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
        $this->appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
        $this->blocked_date_repository = new \FreeFormCertificate\Repositories\BlockedDateRepository();

        $this->validator = new AppointmentValidator(
            $this->appointment_repository,
            $this->blocked_date_repository
        );
    }

    /**
     * Repository accessors for AJAX handler
     */
    public function get_calendar_repository(): \FreeFormCertificate\Repositories\CalendarRepository {
        return $this->calendar_repository;
    }

    public function get_appointment_repository(): \FreeFormCertificate\Repositories\AppointmentRepository {
        return $this->appointment_repository;
    }

    public function get_blocked_date_repository(): \FreeFormCertificate\Repositories\BlockedDateRepository {
        return $this->blocked_date_repository;
    }

    /**
     * Process appointment booking
     *
     * @param array $data Appointment data
     * @return array|\WP_Error
     */
    public function process_appointment(array $data) {
        // Get calendar (outside transaction — immutable config)
        $calendar = $this->calendar_repository->findById((int) $data['calendar_id']);

        if (!$calendar) {
            return new \WP_Error('invalid_calendar', __('Calendar not found.', 'ffcertificate'));
        }

        if ($calendar['status'] !== 'active') {
            return new \WP_Error('calendar_inactive', __('This calendar is not accepting bookings.', 'ffcertificate'));
        }

        // Calculate end time based on slot duration
        $start_datetime = $data['appointment_date'] . ' ' . $data['start_time'];
        $end_timestamp = strtotime($start_datetime) + ($calendar['slot_duration'] * 60);
        $data['end_time'] = gmdate('H:i:s', $end_timestamp);

        // Check LGPD consent (outside transaction — no DB needed)
        if (empty($data['consent_given'])) {
            return new \WP_Error('consent_required', __('You must agree to the terms to book an appointment.', 'ffcertificate'));
        }

        $data['consent_date'] = current_time('mysql');
        $data['consent_ip'] = $data['user_ip'];

        // Create or link user if CPF/RF is provided and user is not logged in
        if (!empty($data['cpf_rf']) && empty($data['user_id'])) {
            $this->create_or_link_user($data);
        }

        // Set initial status
        $data['status'] = $calendar['requires_approval'] ? 'pending' : 'confirmed';

        if ($data['status'] === 'confirmed') {
            $data['approved_at'] = current_time('mysql');
        }

        // === BEGIN TRANSACTION: Atomic validate + insert ===
        $this->appointment_repository->begin_transaction();

        try {
            // Validate with row-level locks (FOR UPDATE) to prevent concurrent overbooking
            $validation = $this->validator->validate($data, $calendar, true);
            if (is_wp_error($validation)) {
                $this->appointment_repository->rollback();
                return $validation;
            }

            /**
             * Fires before an appointment is created in the database.
             *
             * @since 4.6.4
             * @param array $data     Appointment data.
             * @param array $calendar Calendar configuration.
             */
            do_action( 'ffcertificate_before_appointment_create', $data, $calendar );

            // Create appointment (inside transaction — protected by locks)
            $appointment_id = $this->appointment_repository->createAppointment($data);

            if (!$appointment_id) {
                $this->appointment_repository->rollback();
                return new \WP_Error('creation_failed', __('Failed to create appointment. Please try again.', 'ffcertificate'));
            }

            $this->appointment_repository->commit();
        } catch (\Exception $e) {
            $this->appointment_repository->rollback();
            return new \WP_Error('booking_error', __('An error occurred while booking. Please try again.', 'ffcertificate'));
        }
        // === END TRANSACTION ===

        /**
         * Fires after an appointment is created.
         *
         * @since 4.6.4
         * @param int   $appointment_id New appointment ID.
         * @param array $data           Appointment data.
         * @param array $calendar       Calendar configuration.
         */
        do_action( 'ffcertificate_after_appointment_create', $appointment_id, $data, $calendar );

        // Get appointment for email (outside transaction — read-only)
        $appointment = $this->appointment_repository->findById($appointment_id);

        // Schedule email notifications
        $this->schedule_email_notifications($appointment, $calendar, 'created');

        // Generate receipt URL (magic link to /valid/ page)
        $receipt_url = '';
        $confirmation_token = $appointment['confirmation_token'] ?? '';
        if ( ! empty( $confirmation_token ) && class_exists( '\\FreeFormCertificate\\Generators\\MagicLinkHelper' ) ) {
            $receipt_url = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $confirmation_token );
        } elseif (class_exists('\FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler')) {
            $receipt_url = \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler::get_receipt_url(
                $appointment_id,
                $confirmation_token
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
     * Get available time slots for a date
     *
     * @param int $calendar_id
     * @param string $date
     * @return array|\WP_Error
     */
    public function get_available_slots(int $calendar_id, string $date) {
        // Get calendar
        $calendar = $this->calendar_repository->getWithWorkingHours($calendar_id);

        if (!$calendar) {
            return new \WP_Error('invalid_calendar', __('Calendar not found.', 'ffcertificate'));
        }

        if ($calendar['status'] !== 'active') {
            return new \WP_Error('calendar_inactive', __('Calendar is not active.', 'ffcertificate'));
        }

        $has_bypass = \FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass();

        // Check global holidays and blocked dates (bypass can see slots on these dates)
        if (!$has_bypass) {
            if (\FreeFormCertificate\Scheduling\DateBlockingService::is_global_holiday($date)) {
                return array(); // Global holiday - no slots
            }
            if ($this->blocked_date_repository->isDateBlocked($calendar_id, $date)) {
                return array(); // No slots available
            }
        }

        // Get day of week
        $day_of_week = (int)gmdate('w', strtotime($date));

        // Get working hours for this day
        $working_hours = $calendar['working_hours'] ?? array();
        $day_hours = array_filter($working_hours, function($hours) use ($day_of_week) {
            return (int)$hours['day'] === $day_of_week;
        });

        if (empty($day_hours)) {
            // Admin bypass: generate default slots (09:00-18:00) for non-working days
            if ($has_bypass) {
                $day_hours = array(array('start' => '09:00', 'end' => '18:00', 'day' => $day_of_week));
            } else {
                return array(); // Not a working day
            }
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
                $slot_time = gmdate('H:i:s', $current_time);

                // Check if slot is not blocked by time-range blocks (bypass skips this check)
                if ($has_bypass || !$this->blocked_date_repository->isDateBlocked($calendar_id, $date, $slot_time)) {
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
                            'display' => gmdate('H:i', $current_time),
                            'available' => $max_per_slot - $count,
                            'total' => $max_per_slot
                        );
                    }
                }

                // Move to next slot
                $current_time += ($slot_duration + $slot_interval) * 60;
            }
        }

        /**
         * Filters available appointment slots for a date.
         *
         * @since 4.6.4
         * @param array  $slots       Array of available slot data.
         * @param int    $calendar_id  Calendar ID.
         * @param string $date         Date string (Y-m-d).
         * @param array  $calendar     Calendar configuration.
         */
        return apply_filters( 'ffcertificate_available_slots', $slots, $calendar_id, $date, $calendar );
    }

    /**
     * Cancel appointment
     *
     * @param int $appointment_id
     * @param string $token Confirmation token for guest users
     * @param string $reason Cancellation reason
     * @return true|\WP_Error
     */
    public function cancel_appointment(int $appointment_id, string $token = '', string $reason = '') {
        $appointment = $this->appointment_repository->findById($appointment_id);

        if (!$appointment) {
            return new \WP_Error('not_found', __('Appointment not found.', 'ffcertificate'));
        }

        $calendar = $this->calendar_repository->findById((int) $appointment['calendar_id']);

        if (!$calendar) {
            return new \WP_Error('calendar_not_found', __('Calendar not found.', 'ffcertificate'));
        }

        // Verify ownership and capability
        $can_cancel = false;
        $cancelled_by = null;

        if (\FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass()) {
            $can_cancel = true;
            $cancelled_by = get_current_user_id();
        } elseif (is_user_logged_in() && $appointment['user_id'] == get_current_user_id()) {
            if (current_user_can('ffc_cancel_own_appointments')) {
                $can_cancel = true;
                $cancelled_by = get_current_user_id();
            } else {
                return new \WP_Error(
                    'capability_denied',
                    __('You do not have permission to cancel appointments.', 'ffcertificate')
                );
            }
        } elseif (!empty($token) && $appointment['confirmation_token'] === $token) {
            $can_cancel = true;
        }

        if (!$can_cancel) {
            return new \WP_Error('unauthorized', __('You do not have permission to cancel this appointment.', 'ffcertificate'));
        }

        // Check if calendar allows cancellation (admin always can)
        if (!\FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass() && !$calendar['allow_cancellation']) {
            return new \WP_Error('cancellation_disabled', __('Cancellation is not allowed for this calendar.', 'ffcertificate'));
        }

        // Check cancellation deadline
        if (!\FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass() && $calendar['cancellation_min_hours'] > 0) {
            $tz = wp_timezone();
            $appointment_time = ( new \DateTimeImmutable( $appointment['appointment_date'] . ' ' . $appointment['start_time'], $tz ) )->getTimestamp();
            $deadline = $appointment_time - ($calendar['cancellation_min_hours'] * 3600);

            if (time() > $deadline) {
                return new \WP_Error(
                    'deadline_passed',
                    sprintf(
                        /* translators: %d: minimum number of hours before appointment for cancellation */
                        __('Cancellation deadline has passed. Appointments must be cancelled at least %d hours in advance.', 'ffcertificate'),
                        $calendar['cancellation_min_hours']
                    )
                );
            }
        }

        // Check if already cancelled
        if ($appointment['status'] === 'cancelled') {
            return new \WP_Error('already_cancelled', __('This appointment is already cancelled.', 'ffcertificate'));
        }

        // Cancel appointment
        $result = $this->appointment_repository->cancel($appointment_id, $cancelled_by, $reason);

        if ($result === false) {
            return new \WP_Error('cancellation_failed', __('Failed to cancel appointment.', 'ffcertificate'));
        }

        /**
         * Fires after an appointment is cancelled.
         *
         * @since 4.6.4
         * @param int    $appointment_id Appointment ID.
         * @param array  $appointment    Original appointment data.
         * @param string $reason         Cancellation reason.
         * @param int|null $cancelled_by User ID who cancelled (null for guest).
         */
        do_action( 'ffcertificate_appointment_cancelled', $appointment_id, $appointment, $reason, $cancelled_by );

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
     */
    private function schedule_email_notifications(array $appointment, array $calendar, string $event): void {
        $email_config = json_decode($calendar['email_config'], true);

        if (!is_array($email_config)) {
            return;
        }

        if (self::ffc_emails_disabled()) {
            return;
        }

        switch ($event) {
            case 'created':
                if (!empty($email_config['send_user_confirmation'])) {
                    do_action('ffcertificate_self_scheduling_appointment_created_email', $appointment, $calendar);
                }
                if (!empty($email_config['send_admin_notification'])) {
                    do_action('ffcertificate_self_scheduling_appointment_admin_notification', $appointment, $calendar);
                }
                break;

            case 'confirmed':
                if (!empty($email_config['send_approval_notification'])) {
                    do_action('ffcertificate_self_scheduling_appointment_confirmed_email', $appointment, $calendar);
                }
                break;

            case 'cancelled':
                if (!empty($email_config['send_cancellation_notification'])) {
                    do_action('ffcertificate_self_scheduling_appointment_cancelled_email', $appointment, $calendar);
                }
                break;
        }
    }

    /**
     * Create or link WordPress user based on CPF/RF and email
     *
     * @param array &$data Appointment data (passed by reference)
     */
    private function create_or_link_user(array &$data): void {
        if (empty($data['cpf_rf']) || empty($data['email'])) {
            return;
        }

        $cpf_rf_clean = preg_replace('/[^0-9]/', '', $data['cpf_rf']);
        if (empty($cpf_rf_clean)) {
            return;
        }

        $cpf_rf_hash = hash('sha256', $cpf_rf_clean);

        if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            try {
                $submission_data = array(
                    'nome_completo' => $data['name'] ?? '',
                    'name' => $data['name'] ?? '',
                );

                $user_id = \FreeFormCertificate\UserDashboard\UserManager::get_or_create_user(
                    $cpf_rf_hash,
                    $data['email'],
                    $submission_data,
                    \FreeFormCertificate\UserDashboard\UserManager::CONTEXT_APPOINTMENT
                );

                if (!is_wp_error($user_id) && $user_id > 0) {
                    $data['user_id'] = $user_id;

                    if (class_exists('\FreeFormCertificate\Core\Utils')) {
                        \FreeFormCertificate\Core\Utils::debug_log('User created/linked for appointment', array(
                            'user_id' => $user_id,
                            'email' => $data['email'],
                            'has_cpf_rf' => !empty($cpf_rf_clean)
                        ));
                    }
                }
            } catch (\Exception $e) {
                if (class_exists('\FreeFormCertificate\Core\Utils')) {
                    \FreeFormCertificate\Core\Utils::debug_log('Failed to create user for appointment', array(
                        'email' => $data['email'],
                        'error' => $e->getMessage()
                    ));
                }
            }
        }
    }
}
