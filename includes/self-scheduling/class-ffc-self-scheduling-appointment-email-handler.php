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

    use \FreeFormCertificate\Core\EmailHelperTrait;

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into appointment events
        add_action('ffcertificate_self_scheduling_appointment_created_email', array($this, 'send_booking_confirmation'), 10, 2);
        add_action('ffcertificate_self_scheduling_appointment_admin_notification', array($this, 'send_admin_notification'), 10, 2);
        add_action('ffcertificate_self_scheduling_appointment_confirmed_email', array($this, 'send_approval_notification'), 10, 2);
        add_action('ffcertificate_self_scheduling_appointment_cancelled_email', array($this, 'send_cancellation_notification'), 10, 2);
        add_action('ffcertificate_self_scheduling_appointment_reminder_email', array($this, 'send_reminder'), 10, 2);
    }

    /**
     * Check if emails are globally disabled
     *
     * @return bool
     */
    private function are_emails_disabled(): bool {
        return self::ffc_emails_disabled();
    }

    /**
     * Get decrypted email
     *
     * @param array $appointment
     * @return string
     */
    private function get_appointment_email(array $appointment): string {
        return \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'email' );
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
            __('Appointment Confirmation: %s', 'ffcertificate'),
            $calendar['title']
        );

        // Format date and time
        $date_formatted = date_i18n(get_option('date_format'), strtotime($appointment['appointment_date']));
        $time_formatted = date_i18n('H:i', strtotime($appointment['start_time']));

        // Status message
        $status_message = $calendar['requires_approval']
            ? __('Your appointment is pending approval. You will receive a confirmation email once it is approved.', 'ffcertificate')
            : __('Your appointment has been confirmed!', 'ffcertificate');

        // Build email HTML
        $body = $this->get_email_template_header();

        $body .= '<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<h2 style="margin: 0 0 20px 0; color: #0073aa; font-size: 24px;">📅 ' . esc_html__('Appointment Booked!', 'ffcertificate') . '</h2>';

        $body .= '<p style="margin: 0 0 15px 0; font-size: 16px;">' . esc_html($status_message) . '</p>';

        // Appointment details box
        $body .= '<div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0;">';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Calendar:', 'ffcertificate') . '</strong> ' . esc_html($calendar['title']) . '</p>';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Date:', 'ffcertificate') . '</strong> ' . esc_html($date_formatted) . '</p>';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Time:', 'ffcertificate') . '</strong> ' . esc_html($time_formatted) . '</p>';
        $body .= '<p style="margin: 0;"><strong>' . esc_html__('Status:', 'ffcertificate') . '</strong> ' . esc_html($this->get_status_label($appointment['status'])) . '</p>';
        $body .= '</div>';

        // User notes if provided
        if (!empty($appointment['user_notes'])) {
            $body .= '<div style="margin: 20px 0;">';
            $body .= '<p style="margin: 0 0 5px 0; font-weight: bold; color: #666;">' . esc_html__('Your Notes:', 'ffcertificate') . '</p>';
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
            $body .= '📄 ' . esc_html__('View/Print Receipt', 'ffcertificate');
            $body .= '</a>';
            $body .= '</div>';
        }

        // Cancellation link (if allowed)
        if ($calendar['allow_cancellation']) {
            $cancel_url = $this->get_cancellation_url($appointment);
            $body .= '<div style="text-align: center; margin: 30px 0;">';
            $body .= '<p style="margin: 0 0 10px 0; font-size: 14px; color: #666;">' . esc_html__('Need to cancel?', 'ffcertificate') . '</p>';
            $body .= '<a href="' . esc_url($cancel_url) . '" style="display: inline-block; background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-size: 14px;">';
            $body .= esc_html__('Cancel Appointment', 'ffcertificate');
            $body .= '</a>';
            $body .= '</div>';
        }

        $body .= '</div>';

        $body .= $this->get_email_template_footer();

        // Send email
        $this->send_mail($email, $subject, $body);
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
        $admin_emails = self::ffc_parse_admin_emails($email_config['admin_email'] ?? '');

        // Email subject
        $subject = sprintf(
            /* translators: %s: calendar title */
            __('New Appointment: %s', 'ffcertificate'),
            $calendar['title']
        );

        // Format date and time
        $date_formatted = date_i18n(get_option('date_format'), strtotime($appointment['appointment_date']));
        $time_formatted = date_i18n('H:i', strtotime($appointment['start_time']));

        // Build email HTML
        $body = '<div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">';
        $body .= '<h3 style="color: #0073aa;">' . __('New Appointment Booking', 'ffcertificate') . '</h3>';

        $body .= self::ffc_admin_notification_table(array(
            __('Calendar', 'ffcertificate') => $calendar['title'],
            __('Date', 'ffcertificate')     => $date_formatted,
            __('Time', 'ffcertificate')     => $time_formatted,
            __('Status', 'ffcertificate')   => $this->get_status_label($appointment['status']),
            __('Name', 'ffcertificate')     => $appointment['name'] ?? '-',
            __('Email', 'ffcertificate')    => $this->get_appointment_email($appointment),
            __('Phone', 'ffcertificate')    => $appointment['phone'] ?? '-',
            __('Notes', 'ffcertificate')    => $appointment['user_notes'] ?? '-',
        ));

        // Link to manage appointment
        $manage_url = admin_url('edit.php?post_type=ffc_self_scheduling');
        $body .= '<p style="margin: 20px 0;"><a href="' . esc_url($manage_url) . '" style="background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block;">';
        $body .= esc_html__('Manage Appointments', 'ffcertificate');
        $body .= '</a></p>';

        $body .= '</div>';

        // Send to all admin emails
        foreach ($admin_emails as $admin_email) {
            if (is_email($admin_email)) {
                $this->send_mail($admin_email, $subject, $body);
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
            __('Appointment Approved: %s', 'ffcertificate'),
            $calendar['title']
        );

        // Format date and time
        $date_formatted = date_i18n(get_option('date_format'), strtotime($appointment['appointment_date']));
        $time_formatted = date_i18n('H:i', strtotime($appointment['start_time']));

        // Build email HTML
        $body = $this->get_email_template_header();

        $body .= '<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<h2 style="margin: 0 0 20px 0; color: #28a745; font-size: 24px;">✅ ' . esc_html__('Appointment Confirmed!', 'ffcertificate') . '</h2>';

        $body .= '<p style="margin: 0 0 15px 0; font-size: 16px;">' . esc_html__('Your appointment has been approved and confirmed.', 'ffcertificate') . '</p>';

        // Appointment details box
        $body .= '<div style="background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #c3e6cb;">';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Calendar:', 'ffcertificate') . '</strong> ' . esc_html($calendar['title']) . '</p>';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Date:', 'ffcertificate') . '</strong> ' . esc_html($date_formatted) . '</p>';
        $body .= '<p style="margin: 0;"><strong>' . esc_html__('Time:', 'ffcertificate') . '</strong> ' . esc_html($time_formatted) . '</p>';
        $body .= '</div>';

        // Receipt link
        if (class_exists('\FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler')) {
            $receipt_url = AppointmentReceiptHandler::get_receipt_url(
                $appointment['id'],
                $appointment['confirmation_token'] ?? ''
            );
            $body .= '<div style="text-align: center; margin: 30px 0;">';
            $body .= '<a href="' . esc_url($receipt_url) . '" style="display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">';
            $body .= '📄 ' . esc_html__('View/Print Receipt', 'ffcertificate');
            $body .= '</a>';
            $body .= '</div>';
        }

        $body .= '</div>';

        $body .= $this->get_email_template_footer();

        // Send email
        $this->send_mail($email, $subject, $body);
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
            __('Appointment Cancelled: %s', 'ffcertificate'),
            $calendar['title']
        );

        // Format date and time
        $date_formatted = date_i18n(get_option('date_format'), strtotime($appointment['appointment_date']));
        $time_formatted = date_i18n('H:i', strtotime($appointment['start_time']));

        // Build email HTML
        $body = $this->get_email_template_header();

        $body .= '<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<h2 style="margin: 0 0 20px 0; color: #dc3545; font-size: 24px;">❌ ' . esc_html__('Appointment Cancelled', 'ffcertificate') . '</h2>';

        $body .= '<p style="margin: 0 0 15px 0; font-size: 16px;">' . esc_html__('Your appointment has been cancelled.', 'ffcertificate') . '</p>';

        // Appointment details box
        $body .= '<div style="background: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #f5c6cb;">';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Calendar:', 'ffcertificate') . '</strong> ' . esc_html($calendar['title']) . '</p>';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Date:', 'ffcertificate') . '</strong> ' . esc_html($date_formatted) . '</p>';
        $body .= '<p style="margin: 0;"><strong>' . esc_html__('Time:', 'ffcertificate') . '</strong> ' . esc_html($time_formatted) . '</p>';
        $body .= '</div>';

        // Cancellation reason if provided
        if (!empty($appointment['cancellation_reason'])) {
            $body .= '<div style="margin: 20px 0;">';
            $body .= '<p style="margin: 0 0 5px 0; font-weight: bold; color: #666;">' . esc_html__('Cancellation Reason:', 'ffcertificate') . '</p>';
            $body .= '<p style="margin: 0; color: #333;">' . esc_html($appointment['cancellation_reason']) . '</p>';
            $body .= '</div>';
        }

        $body .= '</div>';

        $body .= $this->get_email_template_footer();

        // Send email
        $this->send_mail($email, $subject, $body);
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
            __('Reminder: Appointment Tomorrow - %s', 'ffcertificate'),
            $calendar['title']
        );

        // Format date and time
        $date_formatted = date_i18n(get_option('date_format'), strtotime($appointment['appointment_date']));
        $time_formatted = date_i18n('H:i', strtotime($appointment['start_time']));

        // Build email HTML
        $body = $this->get_email_template_header();

        $body .= '<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<h2 style="margin: 0 0 20px 0; color: #ff9800; font-size: 24px;">⏰ ' . esc_html__('Appointment Reminder', 'ffcertificate') . '</h2>';

        $body .= '<p style="margin: 0 0 15px 0; font-size: 16px;">' . esc_html__('This is a reminder about your upcoming appointment.', 'ffcertificate') . '</p>';

        // Appointment details box
        $body .= '<div style="background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #ffeaa7;">';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Calendar:', 'ffcertificate') . '</strong> ' . esc_html($calendar['title']) . '</p>';
        $body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Date:', 'ffcertificate') . '</strong> ' . esc_html($date_formatted) . '</p>';
        $body .= '<p style="margin: 0;"><strong>' . esc_html__('Time:', 'ffcertificate') . '</strong> ' . esc_html($time_formatted) . '</p>';
        $body .= '</div>';

        // Cancellation link (if allowed and not too late)
        if ($calendar['allow_cancellation']) {
            $cancel_url = $this->get_cancellation_url($appointment);
            $body .= '<div style="text-align: center; margin: 20px 0;">';
            $body .= '<p style="margin: 0 0 10px 0; font-size: 14px; color: #666;">' . esc_html__('Need to cancel?', 'ffcertificate') . '</p>';
            $body .= '<a href="' . esc_url($cancel_url) . '" style="display: inline-block; background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-size: 14px;">';
            $body .= esc_html__('Cancel Appointment', 'ffcertificate');
            $body .= '</a>';
            $body .= '</div>';
        }

        $body .= '</div>';

        $body .= $this->get_email_template_footer();

        // Send email
        $this->send_mail($email, $subject, $body);
    }

    /**
     * Send email with failure logging.
     *
     * @since 4.6.6
     * @param string $to      Recipient email.
     * @param string $subject Email subject.
     * @param string $body    Email body HTML.
     * @return bool Whether the email was sent.
     */
    private function send_mail( string $to, string $subject, string $body ): bool {
        return self::ffc_send_mail( $to, $subject, $body );
    }

    /**
     * Get email template header
     *
     * @return string
     */
    private function get_email_template_header(): string {
        return self::ffc_email_header();
    }

    /**
     * Get email template footer
     *
     * @return string
     */
    private function get_email_template_footer(): string {
        return self::ffc_email_footer();
    }

    /**
     * Get status label
     *
     * @param string $status
     * @return string
     */
    private function get_status_label(string $status): string {
        $labels = array(
            'pending' => __('Pending Approval', 'ffcertificate'),
            'confirmed' => __('Confirmed', 'ffcertificate'),
            'cancelled' => __('Cancelled', 'ffcertificate'),
            'completed' => __('Completed', 'ffcertificate'),
            'no_show' => __('No Show', 'ffcertificate'),
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
