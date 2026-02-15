<?php
declare(strict_types=1);

/**
 * Email Helper Trait
 *
 * Shared email utilities used across EmailHandler, AppointmentEmailHandler,
 * ReregistrationEmailHandler and AudienceNotificationHandler.
 *
 * Eliminates duplicated code for:
 * - Global email disable check
 * - wp_mail() wrapper with failure logging
 * - Admin email parsing (comma-separated string → array)
 * - Consistent HTML email template header/footer
 *
 * @since 4.11.2
 * @package FreeFormCertificate\Core
 */

namespace FreeFormCertificate\Core;

if (!defined('ABSPATH')) {
    exit;
}

trait EmailHelperTrait {

    /**
     * Check if all emails are globally disabled.
     *
     * @return bool
     */
    protected static function ffc_emails_disabled(): bool {
        $settings = get_option('ffc_settings', array());
        return !empty($settings['disable_all_emails']);
    }

    /**
     * Send an HTML email with failure logging.
     *
     * @param string       $to          Recipient email.
     * @param string       $subject     Email subject.
     * @param string       $body        Email body (HTML).
     * @param array<string> $attachments Optional file paths to attach.
     * @return bool Whether the email was sent.
     */
    protected static function ffc_send_mail(string $to, string $subject, string $body, array $attachments = array()): bool {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sent = wp_mail($to, $subject, $body, $headers, $attachments);

        if (!$sent && class_exists('\FreeFormCertificate\Core\Utils')) {
            Utils::debug_log('Email send failed', array(
                'to'      => $to,
                'subject' => $subject,
            ));
        }

        return $sent;
    }

    /**
     * Parse a comma-separated admin email string into a validated array.
     *
     * @param string $emails_string Comma-separated email addresses.
     * @param string $fallback      Fallback email if string is empty (default: admin_email option).
     * @return array<string> Valid email addresses.
     */
    protected static function ffc_parse_admin_emails(string $emails_string, string $fallback = ''): array {
        if (empty($emails_string)) {
            $fallback_email = $fallback ?: (string) get_option('admin_email');
            return $fallback_email ? array($fallback_email) : array();
        }

        return array_filter(
            array_map('trim', explode(',', $emails_string)),
            'is_email'
        );
    }

    /**
     * Get standard email template header (inline-CSS for email clients).
     *
     * @return string Opening HTML wrapper.
     */
    protected static function ffc_email_header(): string {
        return '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 20px;">';
    }

    /**
     * Get standard email template footer with site name.
     *
     * @return string Closing HTML wrapper.
     */
    protected static function ffc_email_footer(): string {
        $body = '<div style="background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<p style="margin: 0; font-size: 12px; color: #999; text-align: center;">';
        $body .= esc_html(get_bloginfo('name'));
        $body .= '</p></div>';
        $body .= '</div>';
        return $body;
    }

    /**
     * Build an admin notification table from key-value pairs.
     *
     * Matches the format used in EmailHandler::send_admin_notification()
     * and AppointmentEmailHandler::send_admin_notification().
     *
     * @param array<string, string> $details Label => Value pairs.
     * @return string HTML table.
     */
    protected static function ffc_admin_notification_table(array $details): string {
        $body = '<table border="1" cellpadding="10" style="border-collapse:collapse; width:100%; font-family: sans-serif; border: 1px solid #ddd;">';

        foreach ($details as $label => $value) {
            $body .= '<tr>';
            $body .= '<td style="background:#f9f9f9; width:30%; font-weight: bold; border: 1px solid #ddd;">' . esc_html($label) . '</td>';
            $body .= '<td style="border: 1px solid #ddd;">' . esc_html($value) . '</td>';
            $body .= '</tr>';
        }

        $body .= '</table>';
        return $body;
    }
}
