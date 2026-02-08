<?php
declare(strict_types=1);

/**
 * Appointment Validator
 *
 * Validates appointment booking data against calendar rules:
 * required fields, date/time format, advance booking window,
 * blocked dates, working hours, slot availability, user permissions.
 *
 * Extracted from AppointmentHandler (M7 refactoring).
 *
 * @since 4.6.8
 * @version 4.6.10 - Added lock-aware validation for concurrent booking safety
 * @package FreeFormCertificate\SelfScheduling
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;

class AppointmentValidator {

    private $appointment_repository;
    private $blocked_date_repository;

    /**
     * Constructor
     *
     * @param \FreeFormCertificate\Repositories\AppointmentRepository $appointment_repository
     * @param \FreeFormCertificate\Repositories\BlockedDateRepository $blocked_date_repository
     */
    public function __construct(
        \FreeFormCertificate\Repositories\AppointmentRepository $appointment_repository,
        \FreeFormCertificate\Repositories\BlockedDateRepository $blocked_date_repository
    ) {
        $this->appointment_repository = $appointment_repository;
        $this->blocked_date_repository = $blocked_date_repository;
    }

    /**
     * Validate appointment booking
     *
     * @param array $data Appointment data
     * @param array $calendar Calendar configuration
     * @param bool $use_lock Use FOR UPDATE locks on capacity queries (requires active transaction)
     * @return true|\WP_Error
     */
    public function validate(array $data, array $calendar, bool $use_lock = false) {
        // 1. Validate required fields
        if (empty($data['appointment_date']) || empty($data['start_time'])) {
            return new \WP_Error('missing_fields', __('Date and time are required.', 'ffcertificate'));
        }

        // 2. Validate date format
        $date_obj = \DateTime::createFromFormat('Y-m-d', $data['appointment_date']);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $data['appointment_date']) {
            return new \WP_Error('invalid_date', __('Invalid date format.', 'ffcertificate'));
        }

        // 3. Validate time format
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $data['start_time'])) {
            return new \WP_Error('invalid_time', __('Invalid time format.', 'ffcertificate'));
        }

        // 4. Check if date is in the past
        $now = time();
        $tz = wp_timezone();
        $appointment_timestamp = ( new \DateTimeImmutable( $data['appointment_date'] . ' ' . $data['start_time'], $tz ) )->getTimestamp();

        if ($appointment_timestamp < $now) {
            return new \WP_Error('past_date', __('Cannot book appointments in the past.', 'ffcertificate'));
        }

        // 5. Validate advance booking window (minimum)
        if ($calendar['advance_booking_min'] > 0) {
            $min_advance = $now + ($calendar['advance_booking_min'] * 3600);
            if ($appointment_timestamp < $min_advance) {
                return new \WP_Error(
                    'too_soon',
                    sprintf(
                        /* translators: %d: minimum number of hours for advance booking */
                        __('Appointments must be booked at least %d hours in advance.', 'ffcertificate'),
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
                        /* translators: %d: maximum number of days for advance booking */
                        __('Appointments cannot be booked more than %d days in advance.', 'ffcertificate'),
                        $calendar['advance_booking_max']
                    )
                );
            }
        }

        // 7. Check global holidays and blocked dates
        if (\FreeFormCertificate\Scheduling\DateBlockingService::is_global_holiday($data['appointment_date'])) {
            return new \WP_Error('date_blocked', __('This date is a holiday.', 'ffcertificate'));
        }
        if ($this->blocked_date_repository->isDateBlocked($data['calendar_id'], $data['appointment_date'], $data['start_time'])) {
            return new \WP_Error('date_blocked', __('This date/time is not available.', 'ffcertificate'));
        }

        // 8. Check working hours
        if (!$this->is_within_working_hours($data['appointment_date'], $data['start_time'], $calendar)) {
            return new \WP_Error('outside_hours', __('Selected time is outside working hours.', 'ffcertificate'));
        }

        // 9. Check slot availability (with row lock inside transaction)
        $is_available = $this->appointment_repository->isSlotAvailable(
            $data['calendar_id'],
            $data['appointment_date'],
            $data['start_time'],
            (int)$calendar['max_appointments_per_slot'],
            $use_lock
        );

        if (!$is_available) {
            return new \WP_Error('slot_full', __('This time slot is fully booked.', 'ffcertificate'));
        }

        // 10. Check daily limit (with row lock inside transaction)
        if ($calendar['slots_per_day'] > 0) {
            $daily_count = $this->get_daily_appointment_count($data['calendar_id'], $data['appointment_date'], $use_lock);
            if ($daily_count >= $calendar['slots_per_day']) {
                return new \WP_Error('daily_limit', __('Daily booking limit reached for this date.', 'ffcertificate'));
            }
        }

        // 11. Check minimum interval between bookings
        if (!empty($calendar['minimum_interval_between_bookings']) && $calendar['minimum_interval_between_bookings'] > 0) {
            $user_identifier = null;

            if (!empty($data['user_id'])) {
                $user_identifier = $data['user_id'];
            } elseif (!empty($data['email'])) {
                $user_identifier = $data['email'];
            } elseif (!empty($data['cpf_rf'])) {
                $user_identifier = $data['cpf_rf'];
            }

            if ($user_identifier) {
                $interval_hours = (int) $calendar['minimum_interval_between_bookings'];
                $interval_check = $this->check_booking_interval($user_identifier, $data['calendar_id'], $interval_hours);

                if (is_wp_error($interval_check)) {
                    return $interval_check;
                }
            }
        }

        // 12. Validate user permissions (capability AND calendar config)
        if (is_user_logged_in()) {
            if (!current_user_can('manage_options')) {
                if (!current_user_can('ffc_book_appointments')) {
                    return new \WP_Error(
                        'capability_denied',
                        __('You do not have permission to book appointments.', 'ffcertificate')
                    );
                }
            }
        }

        // Calendar-specific login requirement
        if ($calendar['require_login']) {
            if (!is_user_logged_in()) {
                return new \WP_Error('login_required', __('You must be logged in to book this calendar.', 'ffcertificate'));
            }

            if (!empty($calendar['allowed_roles'])) {
                $allowed_roles = json_decode($calendar['allowed_roles'], true);
                if (is_array($allowed_roles) && !empty($allowed_roles)) {
                    $user = wp_get_current_user();
                    $has_role = array_intersect($user->roles, $allowed_roles);
                    if (empty($has_role) && !current_user_can('manage_options')) {
                        return new \WP_Error('insufficient_permissions', __('You do not have permission to book this calendar.', 'ffcertificate'));
                    }
                }
            }
        }

        // 13. Validate email (if not logged in)
        if (!is_user_logged_in() && empty($data['email'])) {
            return new \WP_Error('email_required', __('Email address is required.', 'ffcertificate'));
        }

        // 14. Validate CPF/RF
        if (empty($data['cpf_rf'])) {
            return new \WP_Error('cpf_rf_required', __('CPF/RF is required.', 'ffcertificate'));
        }

        $cpf_rf_clean = preg_replace('/[^0-9]/', '', $data['cpf_rf']);
        if (strlen($cpf_rf_clean) == 7) {
            if (!preg_match('/^\d{7}$/', $cpf_rf_clean)) {
                return new \WP_Error('invalid_rf', __('Invalid RF format.', 'ffcertificate'));
            }
        } elseif (strlen($cpf_rf_clean) == 11) {
            if (!\FreeFormCertificate\Core\Utils::validate_cpf($cpf_rf_clean)) {
                return new \WP_Error('invalid_cpf', __('Invalid CPF.', 'ffcertificate'));
            }
        } else {
            return new \WP_Error('invalid_cpf_rf', __('CPF/RF must be 7 digits (RF) or 11 digits (CPF).', 'ffcertificate'));
        }

        return true;
    }

    /**
     * Check minimum interval between bookings for a user
     *
     * @param mixed $user_identifier User ID, email, or CPF/RF
     * @param int $calendar_id Calendar ID
     * @param int $interval_hours Minimum hours between bookings
     * @return true|\WP_Error
     */
    public function check_booking_interval($user_identifier, int $calendar_id, int $interval_hours) {
        $now = time();
        $cutoff_time = $now + ($interval_hours * 3600);

        $recent_appointments = array();

        if (is_int($user_identifier)) {
            $recent_appointments = $this->appointment_repository->findByUserId($user_identifier);
        } else {
            if (filter_var($user_identifier, FILTER_VALIDATE_EMAIL)) {
                $recent_appointments = $this->appointment_repository->findByEmail($user_identifier);
            } else {
                $recent_appointments = $this->appointment_repository->findByCpfRf($user_identifier);
            }
        }

        foreach ($recent_appointments as $appointment) {
            if ($appointment['status'] === 'cancelled') {
                continue;
            }

            if ((int)$appointment['calendar_id'] !== $calendar_id) {
                continue;
            }

            $apt_timestamp = ( new \DateTimeImmutable( $appointment['appointment_date'] . ' ' . $appointment['start_time'], wp_timezone() ) )->getTimestamp();

            if ($apt_timestamp >= $now && $apt_timestamp <= $cutoff_time) {
                $next_available = date_i18n(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    $apt_timestamp + ($interval_hours * 3600)
                );

                return new \WP_Error(
                    'booking_too_soon',
                    sprintf(
                        /* translators: %1$d: number of hours, %2$s: next available date/time */
                        __('You already have an appointment scheduled within the next %1$d hours. You can book again after %2$s.', 'ffcertificate'),
                        $interval_hours,
                        $next_available
                    )
                );
            }
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
    public function is_within_working_hours(string $date, string $time, array $calendar): bool {
        return \FreeFormCertificate\Scheduling\WorkingHoursService::is_within_working_hours(
            $date,
            $time,
            $calendar['working_hours'] ?? ''
        );
    }

    /**
     * Get daily appointment count
     *
     * @param int $calendar_id
     * @param string $date
     * @param bool $use_lock Use FOR UPDATE lock (requires active transaction)
     * @return int
     */
    public function get_daily_appointment_count(int $calendar_id, string $date, bool $use_lock = false): int {
        $appointments = $this->appointment_repository->getAppointmentsByDate($calendar_id, $date, ['confirmed', 'pending'], $use_lock);
        return count($appointments);
    }
}
