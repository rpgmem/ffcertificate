<?php
declare(strict_types=1);

/**
 * Appointment AJAX Handler
 *
 * Handles all AJAX endpoints for the appointment booking frontend:
 *   - Book appointment
 *   - Get available slots
 *   - Cancel appointment
 *   - Get monthly booking counts
 *
 * Extracted from AppointmentHandler (M7 refactoring).
 *
 * @since 4.6.8
 * @package FreeFormCertificate\SelfScheduling
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;

class AppointmentAjaxHandler {

    use \FreeFormCertificate\Core\AjaxTrait;

    private AppointmentHandler $handler;

    /**
     * Constructor - registers AJAX hooks
     *
     * @param AppointmentHandler $handler Appointment business logic handler.
     */
    public function __construct(AppointmentHandler $handler) {
        $this->handler = $handler;

        add_action('wp_ajax_ffc_book_appointment', array($this, 'ajax_book_appointment'));
        add_action('wp_ajax_nopriv_ffc_book_appointment', array($this, 'ajax_book_appointment'));
        add_action('wp_ajax_ffc_get_available_slots', array($this, 'ajax_get_available_slots'));
        add_action('wp_ajax_nopriv_ffc_get_available_slots', array($this, 'ajax_get_available_slots'));
        add_action('wp_ajax_ffc_cancel_appointment', array($this, 'ajax_cancel_appointment'));
        add_action('wp_ajax_nopriv_ffc_cancel_appointment', array($this, 'ajax_cancel_appointment'));
        add_action('wp_ajax_ffc_get_month_bookings', array($this, 'ajax_get_month_bookings'));
        add_action('wp_ajax_nopriv_ffc_get_month_bookings', array($this, 'ajax_get_month_bookings'));
    }

    /**
     * AJAX: Book appointment
     */
    public function ajax_book_appointment(): void {
        try {
            check_ajax_referer('ffc_self_scheduling_nonce', 'nonce');

            if (!class_exists('\FreeFormCertificate\Core\Utils')) {
                wp_send_json_error(array(
                    'message' => __('System error: Utils class not loaded.', 'ffcertificate')
                ));
            }

            $security_check = \FreeFormCertificate\Core\Utils::validate_security_fields($_POST);
            if ($security_check !== true) {
                $new_captcha = \FreeFormCertificate\Core\Utils::generate_simple_captcha();
                wp_send_json_error(array(
                    'message' => $security_check,
                    'refresh_captcha' => true,
                    'new_label' => $new_captcha['label'],
                    'new_hash' => $new_captcha['hash']
                ));
            }

            $calendar_id = $this->get_post_int('calendar_id');
            $date = $this->get_post_param('date');
            $time = $this->get_post_param('time');

            if (!$calendar_id || !$date || !$time) {
                wp_send_json_error(array(
                    'message' => __('Missing required fields.', 'ffcertificate')
                ));
            }

            $appointment_data = array(
                'calendar_id' => $calendar_id,
                'appointment_date' => $date,
                'start_time' => $time,
                'name' => $this->get_post_param('name'),
                'email' => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
                'cpf_rf' => $this->get_post_param('cpf_rf'),
                'user_notes' => isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '',
                'custom_data' => $this->get_post_array('custom_data'),
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() used as boolean only.
                'consent_given' => isset($_POST['consent']) ? 1 : 0,
                'consent_text' => isset($_POST['consent_text']) ? sanitize_textarea_field(wp_unslash($_POST['consent_text'])) : '',
                'user_ip' => \FreeFormCertificate\Core\Utils::get_user_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : ''
            );

            if (is_user_logged_in()) {
                $appointment_data['user_id'] = get_current_user_id();
            }

            $result = $this->handler->process_appointment($appointment_data);

            if (is_wp_error($result)) {
                wp_send_json_error(array(
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ));
            }

            // Generate receipt PDF data for auto-download (only for confirmed appointments)
            $pdf_data = null;
            $appointment = null;
            $requires_approval = $result['requires_approval'] ?? false;
            try {
                $appointment = $this->handler->get_appointment_repository()->findById($result['appointment_id']);
                $calendar = $this->handler->get_calendar_repository()->findById($calendar_id);
                if ( $appointment && $calendar && ! $requires_approval ) {
                    $pdf_generator = new \FreeFormCertificate\Generators\PdfGenerator();
                    $pdf_data = $pdf_generator->generate_appointment_pdf_data( $appointment, $calendar );
                }
            } catch ( \Exception $e ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions
                error_log( 'FFC Appointment PDF Error: ' . $e->getMessage() );
            }

            $response = array(
                'message' => $requires_approval
                    ? __('Appointment booked successfully! Awaiting admin approval.', 'ffcertificate')
                    : __('Appointment booked successfully!', 'ffcertificate'),
                'appointment_id' => $result['appointment_id'],
                'confirmation_token' => $result['confirmation_token'] ?? null,
                'validation_code' => $appointment && ! empty( $appointment['validation_code'] )
                    ? \FreeFormCertificate\Core\Utils::format_auth_code( $appointment['validation_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_APPOINTMENT )
                    : null,
                'receipt_url' => $requires_approval ? '' : ( $result['receipt_url'] ?? '' ),
                'requires_approval' => $requires_approval,
            );

            if ( $pdf_data ) {
                $response['pdf_data'] = $pdf_data;
            }

            wp_send_json_success( $response );
        } catch (\Exception $e) {
            if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'Appointment AJAX error', array(
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ) );
            }
            wp_send_json_error(array(
                'code'    => 'ffc_internal_error',
                'message' => __('An unexpected error occurred. Please try again.', 'ffcertificate'),
            ));
        }
    }

    /**
     * AJAX: Get available slots for a date
     */
    public function ajax_get_available_slots(): void {
        check_ajax_referer('ffc_self_scheduling_nonce', 'nonce');

        $calendar_id = $this->get_post_int('calendar_id');
        $date = $this->get_post_param('date');

        if (!$calendar_id || !$date) {
            wp_send_json_error(array(
                'message' => __('Invalid parameters.', 'ffcertificate')
            ));
        }

        $slots = $this->handler->get_available_slots($calendar_id, $date);

        if (is_wp_error($slots)) {
            wp_send_json_error(array(
                'message' => $slots->get_error_message()
            ));
        }

        wp_send_json_success(array(
            'slots' => $slots,
            'date' => $date
        ));
    }

    /**
     * AJAX: Cancel appointment
     */
    public function ajax_cancel_appointment(): void {
        try {
            check_ajax_referer('ffc_self_scheduling_nonce', 'nonce');

            $appointment_id = $this->get_post_int('appointment_id');
            $token = $this->get_post_param('token');
            $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_ajax_nonce() above.

            if (!$appointment_id) {
                wp_send_json_error(array(
                    'message' => __('Invalid appointment ID.', 'ffcertificate')
                ));
            }

            $result = $this->handler->cancel_appointment($appointment_id, $token, $reason);

            if (is_wp_error($result)) {
                wp_send_json_error(array(
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ));
            }

            wp_send_json_success(array(
                'message' => __('Appointment cancelled successfully.', 'ffcertificate')
            ));

        } catch (\Throwable $e) {
            if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'Cancellation AJAX error', array(
                    'message' => $e->getMessage(),
                ) );
            }
            wp_send_json_error(array(
                'code'    => 'ffc_internal_error',
                'message' => __('An unexpected error occurred.', 'ffcertificate'),
            ));
        }
    }

    /**
     * AJAX: Get monthly booking counts
     */
    public function ajax_get_month_bookings(): void {
        try {
            check_ajax_referer('ffc_self_scheduling_nonce', 'nonce');

            $calendar_id = $this->get_post_int('calendar_id');
            $year = $this->get_post_int('year') ?: (int) gmdate('Y');
            $month = $this->get_post_int('month') ?: (int) gmdate('n');

            if (!$calendar_id) {
                wp_send_json_error(array('message' => __('Invalid calendar.', 'ffcertificate')));
            }

            $start_date = sprintf('%04d-%02d-01', $year, $month);
            $end_date = gmdate('Y-m-t', strtotime($start_date));

            $appointment_repo = $this->handler->get_appointment_repository();
            $blocked_repo = $this->handler->get_blocked_date_repository();

            $counts = $appointment_repo->getBookingCountsByDateRange($calendar_id, $start_date, $end_date);

            // Get holidays for the month (global + calendar-specific blocked dates)
            $holidays = array();

            $global_holidays = \FreeFormCertificate\Scheduling\DateBlockingService::get_global_holidays($start_date, $end_date);
            foreach ($global_holidays as $gh) {
                $holidays[$gh['date']] = $gh['description'] ?: __('Holiday', 'ffcertificate');
            }

            $blocked = $blocked_repo->getBlockedDatesInRange($calendar_id, $start_date, $end_date);
            foreach ($blocked as $block) {
                if (isset($block['block_type']) && $block['block_type'] === 'full_day') {
                    $block_start = $block['start_date'];
                    $block_end = $block['end_date'] ?? $block['start_date'];
                    $current = $block_start;
                    while ($current <= $block_end) {
                        if (!isset($holidays[$current])) {
                        $holidays[$current] = $block['reason'] ?: __('Closed', 'ffcertificate');
                        }
                        $current = gmdate('Y-m-d', strtotime($current . ' +1 day'));
                    }
                }
            }

            wp_send_json_success(array(
                'counts' => $counts,
                'holidays' => $holidays,
            ));

        } catch (\Throwable $e) {
            if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'Admin appointments AJAX error', array(
                    'message' => $e->getMessage(),
                ) );
            }
            wp_send_json_error(array(
                'code'    => 'ffc_internal_error',
                'message' => __('An unexpected error occurred.', 'ffcertificate'),
            ));
        }
    }
}
