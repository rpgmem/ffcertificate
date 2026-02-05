<?php
declare(strict_types=1);

/**
 * Appointment Email Handler
 *
 * Handles email notifications for calendar appointments.
 * Supports: booking confirmation, admin notifications, approval, cancellation, reminders.
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;

class AppointmentEmailHandler {

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into appointment events
        add_action('ffc_self_scheduling_appointment_created_email', array($this, 'send_booking_confirmation'), 10, 2);
        add_action('ffc_self_scheduling_appointment_admin_notification', array($this, 'send_admin_notification'), 10, 2);
        add_action('ffc_self_scheduling_appointment_confirmed_email', array($this, 'send_approval_notification'), 10, 2);
        add_action('ffc_self_scheduling_appointment_cancelled_email', array($this, 'send_cancellation_notification'), 10, 2);
        add_action('ffc_self_scheduling_appointment_reminder_email', array($this, 'send_reminder'), 10, 2);
    }

    /**
     * Check if emails are globally disabled
     *
     * @return bool
     */
    private function are_emails_disabled(): bool {
        $settings = get_option('ffc_settings', array());
        return !empty($settings['disable_all_emails']);
    }

    /**
     * Get decrypted email
     *
     * @param array $appointment
     * @return string
     */
    private function get_appointment_email(array $appointment): string {
        if (!empty($appointment['email_encrypted'])) {
            try {
                if (class_exists('\FreeFormCertificate\Core\Encryption')) {
                    $decrypted = \FreeFormCertificate\Core\Encryption::decrypt($appointment['email_encrypted']);
                    if ($decrypted && is_string($decrypted)) {
                        return $decrypted;
                    }
                }
            } catch (\Exception $e) {
                // Fall through to plain email
            }
        }

        return $appointment['email'] ?? '';
    }

    /**
     * Send booking confirmation to user
     *
     * @param array $appointment Appointment data
     * @param array $calendar Calendar data
     * @return void
     */
    public function send_booking_confirmation(array $appointment, array $calendar): void {
        if ($this->are_emails_disabled()) {
            return;
        }

        $email = $this->get_appointment_email($appointment);
        if (empty($email) || !is_email($email)) {
            return;
        }

        // Email subject
        $subject = sprintf(
            /* translators: %s: calendar title */
            __('Appointment Confirmation: %s', 'wp-ffcertificate'),
            $calendar['title']
        );

        // Format date and time
        $date_formatted = date_i18n(get_option('date_format'), strtotime($appointment['appointment_date']));
        $time_formatted = date_i18n('H:i', strtotime($appointment['start_time']));

        // Status message
        $status_message = $calendar['requires_approval']
            ? __('Your appointment is pending approval. You will receive a confirmation email once it is approved.', 'wp-ffcertificate')
            : __('Your appointment has been confirmed!', 'wp-ffcertificate');

        // Build email HTML
        $body = $this->get_email_template_header();

        $body .= '<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<h2 style="margin: 0 0 20px 0; color: #0073aa; font-size: 24px;">📅 ' . esc_html__('Appointment Booked!', 'wp-ffcertificate') . '</h2>';

        $body .= '<p style="margin: 0 0 15px 0; font-size: 16px;">' . esc_html($status_message) . '</p>';

        // Appointment details box
        $body .= '<div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0;">';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Calendar:', 'wp-ffcertificate') . '</strong> ' . esc_html($calendar['title']) . '</p>';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Date:', 'wp-ffcertificate') . '</strong> ' . esc_html($date_formatted) . '</p>';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Time:', 'wp-ffcertificate') . '</strong> ' . esc_html($time_formatted) . '</p>';
        $body .= '<p style="margin: 0;"><strong>' . esc_html__('Status:', 'wp-ffcertificate') . '</strong> ' . esc_html($this->get_status_label($appointment['status'])) . '</p>';
        $body .= '</div>';

        // User notes if provided
        if (!empty($appointment['user_notes'])) {
            $body .= '<div style="margin: 20px 0;">';
            $body .= '<p style="margin: 0 0 5px 0; font-weight: bold; color: #666;">' . esc_html__('Your Notes:', 'wp-ffcertificate') . '</p>';
            $body .= '<p style="margin: 0; color: #333;">' . esc_html($appointment['user_notes']) . '</p>';
            $body .= '</div>';
        }

        // Receipt/Confirmation link
        if (class_exists('\FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler')) {
            $receipt_url = \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler::get_receipt_url(
                $appointment['id'],
                $appointment['confirmation_token'] ?? ''
            );
            $body .= '<div style="text-align: center; margin: 30px 0;">';
            $body .= '<a href="' . esc_url($receipt_url) . '" style="display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">';
            $body .= '📄 ' . esc_html__('View/Print Receipt', 'wp-ffcertificate');
            $body .= '</a>';
            $body .= '</div>';
        }

        // Cancellation link (if allowed)
        if ($calendar['allow_cancellation']) {
            $cancel_url = $this->get_cancellation_url($appointment);
            $body .= '<div style="text-align: center; margin: 30px 0;">';
            $body .= '<p style="margin: 0 0 10px 0; font-size: 14px; color: #666;">' . esc_html__('Need to cancel?', 'wp-ffcertificate') . '</p>';
            $body .= '<a href="' . esc_url($cancel_url) . '" style="display: inline-block; background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-size: 14px;">';
            $body .= esc_html__('Cancel Appointment', 'wp-ffcertificate');
            $body .= '</a>';
            $body .= '</div>';
        }

        $body .= '</div>';

        $body .= $this->get_email_template_footer();

        // Send email
        wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * Send admin notification
     *
     * @param array $appointment Appointment data
     * @param array $calendar Calendar data
     * @return void
     */
    public function send_admin_notification(array $appointment, array $calendar): void {
        if ($this->are_emails_disabled()) {
            return;
        }

        // Get admin emails from calendar config or default
        $email_config = json_decode($calendar['email_config'], true);
        $admin_emails = !empty($email_config['admin_email'])
            ? array_filter(array_map('trim', explode(',', $email_config['admin_email'])))
            : array(get_option('admin_email'));

        // Email subject
        $subject = sprintf(
            /* translators: %s: calendar title */
            __('New Appointment: %s', 'wp-ffcertificate'),
            $calendar['title']
        );

        // Format date and time
        $date_formatted = date_i18n(get_option('date_format'), strtotime($appointment['appointment_date']));
        $time_formatted = date_i18n('H:i', strtotime($appointment['start_time']));

        // Build email HTML
        $body = '<div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">';
        $body .= '<h3 style="color: #0073aa;">' . __('New Appointment Booking', 'wp-ffcertificate') . '</h3>';
        $body .= '<table border="1" cellpadding="10" style="border-collapse:collapse; width:100%; font-family: sans-serif; border: 1px solid #ddd;">';

        // Appointment details
        $details = array(
            'Calendar' => $calendar['title'],
            'Date' => $date_formatted,
            'Time' => $time_formatted,
            'Status' => $this->get_status_label($appointment['status']),
            'Name' => $appointment['name'] ?? '-',
            'Email' => $this->get_appointment_email($appointment),
            'Phone' => $appointment['phone'] ?? '-',
            'Notes' => $appointment['user_notes'] ?? '-',
        );

        foreach ($details as $label => $value) {
            $body .= '<tr>';
            $body .= '<td style="background:#f9f9f9; width:30%; font-weight: bold; border: 1px solid #ddd;">' . esc_html($label) . '</td>';
            $body .= '<td style="border: 1px solid #ddd;">' . esc_html($value) . '</td>';
            $body .= '</tr>';
        }

        $body .= '</table>';

        // Link to manage appointment
        $manage_url = admin_url('edit.php?post_type=ffc_self_scheduling');
        $body .= '<p style="margin: 20px 0;"><a href="' . esc_url($manage_url) . '" style="background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block;">';
        $body .= esc_html__('Manage Appointments', 'wp-ffcertificate');
        $body .= '</a></p>';

        $body .= '</div>';

        // Send to all admin emails
        foreach ($admin_emails as $admin_email) {
            if (is_email($admin_email)) {
                wp_mail($admin_email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
            }
        }
    }

    /**
     * Send approval notification to user
     *
     * @param array $appointment Appointment data
     * @param array $calendar Calendar data
     * @return void
     */
    public function send_approval_notification(array $appointment, array $calendar): void {
        if ($this->are_emails_disabled()) {
            return;
        }

        $email = $this->get_appointment_email($appointment);
        if (empty($email) || !is_email($email)) {
            return;
        }

        // Email subject
        $subject = sprintf(
            /* translators: %s: calendar title */
            __('Appointment Approved: %s', 'wp-ffcertificate'),
            $calendar['title']
        );

        // Format date and time
        $date_formatted = date_i18n(get_option('date_format'), strtotime($appointment['appointment_date']));
        $time_formatted = date_i18n('H:i', strtotime($appointment['start_time']));

        // Build email HTML
        $body = $this->get_email_template_header();

        $body .= '<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<h2 style="margin: 0 0 20px 0; color: #28a745; font-size: 24px;">✅ ' . esc_html__('Appointment Confirmed!', 'wp-ffcertificate') . '</h2>';

        $body .= '<p style="margin: 0 0 15px 0; font-size: 16px;">' . esc_html__('Your appointment has been approved and confirmed.', 'wp-ffcertificate') . '</p>';

        // Appointment details box
        $body .= '<div style="background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #c3e6cb;">';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Calendar:', 'wp-ffcertificate') . '</strong> ' . esc_html($calendar['title']) . '</p>';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Date:', 'wp-ffcertificate') . '</strong> ' . esc_html($date_formatted) . '</p>';
        $body .= '<p style="margin: 0;"><strong>' . esc_html__('Time:', 'wp-ffcertificate') . '</strong> ' . esc_html($time_formatted) . '</p>';
        $body .= '</div>';

        $body .= '</div>';

        $body .= $this->get_email_template_footer();

        // Send email
        wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * Send cancellation notification to user
     *
     * @param array $appointment Appointment data
     * @param array $calendar Calendar data
     * @return void
     */
    public function send_cancellation_notification(array $appointment, array $calendar): void {
        if ($this->are_emails_disabled()) {
            return;
        }

        $email = $this->get_appointment_email($appointment);
        if (empty($email) || !is_email($email)) {
            return;
        }

        // Email subject
        $subject = sprintf(
            /* translators: %s: calendar title */
            __('Appointment Cancelled: %s', 'wp-ffcertificate'),
            $calendar['title']
        );

        // Format date and time
        $date_formatted = date_i18n(get_option('date_format'), strtotime($appointment['appointment_date']));
        $time_formatted = date_i18n('H:i', strtotime($appointment['start_time']));

        // Build email HTML
        $body = $this->get_email_template_header();

        $body .= '<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<h2 style="margin: 0 0 20px 0; color: #dc3545; font-size: 24px;">❌ ' . esc_html__('Appointment Cancelled', 'wp-ffcertificate') . '</h2>';

        $body .= '<p style="margin: 0 0 15px 0; font-size: 16px;">' . esc_html__('Your appointment has been cancelled.', 'wp-ffcertificate') . '</p>';

        // Appointment details box
        $body .= '<div style="background: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #f5c6cb;">';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Calendar:', 'wp-ffcertificate') . '</strong> ' . esc_html($calendar['title']) . '</p>';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Date:', 'wp-ffcertificate') . '</strong> ' . esc_html($date_formatted) . '</p>';
        $body .= '<p style="margin: 0;"><strong>' . esc_html__('Time:', 'wp-ffcertificate') . '</strong> ' . esc_html($time_formatted) . '</p>';
        $body .= '</div>';

        // Cancellation reason if provided
        if (!empty($appointment['cancellation_reason'])) {
            $body .= '<div style="margin: 20px 0;">';
            $body .= '<p style="margin: 0 0 5px 0; font-weight: bold; color: #666;">' . esc_html__('Cancellation Reason:', 'wp-ffcertificate') . '</p>';
            $body .= '<p style="margin: 0; color: #333;">' . esc_html($appointment['cancellation_reason']) . '</p>';
            $body .= '</div>';
        }

        $body .= '</div>';

        $body .= $this->get_email_template_footer();

        // Send email
        wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * Send appointment reminder
     *
     * @param array $appointment Appointment data
     * @param array $calendar Calendar data
     * @return void
     */
    public function send_reminder(array $appointment, array $calendar): void {
        if ($this->are_emails_disabled()) {
            return;
        }

        $email = $this->get_appointment_email($appointment);
        if (empty($email) || !is_email($email)) {
            return;
        }

        // Email subject
        $subject = sprintf(
            /* translators: %s: calendar title */
            __('Reminder: Appointment Tomorrow - %s', 'wp-ffcertificate'),
            $calendar['title']
        );

        // Format date and time
        $date_formatted = date_i18n(get_option('date_format'), strtotime($appointment['appointment_date']));
        $time_formatted = date_i18n('H:i', strtotime($appointment['start_time']));

        // Build email HTML
        $body = $this->get_email_template_header();

        $body .= '<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<h2 style="margin: 0 0 20px 0; color: #ff9800; font-size: 24px;">⏰ ' . esc_html__('Appointment Reminder', 'wp-ffcertificate') . '</h2>';

        $body .= '<p style="margin: 0 0 15px 0; font-size: 16px;">' . esc_html__('This is a reminder about your upcoming appointment.', 'wp-ffcertificate') . '</p>';

        // Appointment details box
        $body .= '<div style="background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #ffeaa7;">';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Calendar:', 'wp-ffcertificate') . '</strong> ' . esc_html($calendar['title']) . '</p>';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Date:', 'wp-ffcertificate') . '</strong> ' . esc_html($date_formatted) . '</p>';
        $body .= '<p style="margin: 0;"><strong>' . esc_html__('Time:', 'wp-ffcertificate') . '</strong> ' . esc_html($time_formatted) . '</p>';
        $body .= '</div>';

        // Cancellation link (if allowed and not too late)
        if ($calendar['allow_cancellation']) {
            $cancel_url = $this->get_cancellation_url($appointment);
            $body .= '<div style="text-align: center; margin: 20px 0;">';
            $body .= '<p style="margin: 0 0 10px 0; font-size: 14px; color: #666;">' . esc_html__('Need to cancel?', 'wp-ffcertificate') . '</p>';
            $body .= '<a href="' . esc_url($cancel_url) . '" style="display: inline-block; background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-size: 14px;">';
            $body .= esc_html__('Cancel Appointment', 'wp-ffcertificate');
            $body .= '</a>';
            $body .= '</div>';
        }

        $body .= '</div>';

        $body .= $this->get_email_template_footer();

        // Send email
        wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * Get email template header
     *
     * @return string
     */
    private function get_email_template_header(): string {
        return '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 20px;">';
    }

    /**
     * Get email template footer
     *
     * @return string
     */
    private function get_email_template_footer(): string {
        $body = '<div style="background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<p style="margin: 0; font-size: 12px; color: #999; text-align: center;">';
        $body .= esc_html(get_bloginfo('name'));
        $body .= '</p></div>';
        $body .= '</div>';
        return $body;
    }

    /**
     * Get status label
     *
     * @param string $status
     * @return string
     */
    private function get_status_label(string $status): string {
        $labels = array(
            'pending' => __('Pending Approval', 'wp-ffcertificate'),
            'confirmed' => __('Confirmed', 'wp-ffcertificate'),
            'cancelled' => __('Cancelled', 'wp-ffcertificate'),
            'completed' => __('Completed', 'wp-ffcertificate'),
            'no_show' => __('No Show', 'wp-ffcertificate'),
        );

        return $labels[$status] ?? $status;
    }

    /**
     * Get cancellation URL
     *
     * @param array $appointment
     * @return string
     */
    private function get_cancellation_url(array $appointment): string {
        // For now, return a placeholder. You can implement a dedicated cancellation page later
        $dashboard_page_id = get_option('ffc_dashboard_page_id');
        $base_url = $dashboard_page_id ? get_permalink($dashboard_page_id) : home_url('/dashboard');

        return add_query_arg(array(
            'tab' => 'appointments',
            'action' => 'cancel',
            'appointment_id' => $appointment['id'],
        ), $base_url);
    }
}
