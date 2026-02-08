<?php
declare(strict_types=1);

/**
 * Email Template Service
 *
 * Shared email template rendering for both self-scheduling and audience systems.
 * Provides consistent email layout, date/time formatting, and wp_mail() wrapper.
 *
 * @since 4.6.0
 * @package FreeFormCertificate\Scheduling
 */

namespace FreeFormCertificate\Scheduling;

if (!defined('ABSPATH')) {
    exit;
}

class EmailTemplateService {

    /**
     * Wrap email body in a standard HTML layout.
     *
     * @param string $body Inner HTML content
     * @return string Complete HTML email
     */
    public static function wrap_html(string $body): string {
        $site_name = esc_html(get_bloginfo('name'));

        return "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2271b1; color: #fff; padding: 20px; text-align: center; border-radius: 4px 4px 0 0; }
        .content { background: #fff; padding: 30px; border: 1px solid #e0e0e0; }
        .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 4px 4px; }
        .info-box { background: #f0f6fc; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .info-row { margin: 8px 0; }
        .info-label { font-weight: 600; }
        .cancelled { background: #fef2f2; border-left: 4px solid #dc3545; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h2>{$site_name}</h2>
        </div>
        <div class='content'>
            {$body}
        </div>
        <div class='footer'>
            <p>" . esc_html__('This is an automated notification from', 'ffcertificate') . " {$site_name}</p>
        </div>
    </div>
</body>
</html>";
    }

    /**
     * Send an HTML email with optional attachments.
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (inner HTML — will be wrapped automatically)
     * @param array<string> $attachments File paths to attach
     * @param bool $wrap Whether to wrap body in standard HTML layout (default true)
     * @return bool
     */
    public static function send(
        string $to,
        string $subject,
        string $body,
        array $attachments = array(),
        bool $wrap = true
    ): bool {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        $html = $wrap ? self::wrap_html($body) : $body;

        /**
         * Filters scheduling email data before sending.
         *
         * @since 4.6.4
         * @param array $email_data {
         *     @type string $to      Recipient email.
         *     @type string $subject Email subject.
         *     @type string $body    Email HTML body.
         * }
         */
        $email_data = apply_filters( 'ffcertificate_scheduling_email', [
            'to'      => $to,
            'subject' => $subject,
            'body'    => $html,
        ] );

        return wp_mail( $email_data['to'], $email_data['subject'], $email_data['body'], $headers, $attachments );
    }

    /**
     * Format a date string using WordPress locale settings.
     *
     * @param string $date Date string (Y-m-d or any strtotime-parseable format)
     * @return string Formatted date
     */
    public static function format_date(string $date): string {
        return date_i18n(get_option('date_format'), strtotime($date));
    }

    /**
     * Format a time string using H:i format.
     *
     * @param string $time Time string (H:i:s or H:i)
     * @return string Formatted time
     */
    public static function format_time(string $time): string {
        return date_i18n('H:i', strtotime($time));
    }

    /**
     * Render a template string by replacing {placeholder} variables.
     *
     * @param string $template Template with {variable} placeholders
     * @param array<string, string> $variables Key => value replacements (without braces)
     * @return string Rendered template
     */
    public static function render_template(string $template, array $variables): string {
        $replacements = array();
        foreach ($variables as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Generate an ICS calendar file content.
     *
     * @param array{
     *     uid: string,
     *     summary: string,
     *     description: string,
     *     location: string,
     *     date: string,
     *     start_time: string,
     *     end_time: string,
     *     status?: string
     * } $event Event data
     * @param string $method ICS method (REQUEST or CANCEL)
     * @return string ICS file content
     */
    public static function generate_ics(array $event, string $method = 'REQUEST'): string {
        $site_name = get_bloginfo('name');
        $site_domain = wp_parse_url(home_url(), PHP_URL_HOST);

        $uid = ($event['uid'] ?? 'ffc-event-' . uniqid()) . '@' . $site_domain;

        // Build ICS datetime: YYYYMMDDTHHMMSS
        $date_clean = str_replace('-', '', $event['date']);
        $start_clean = str_replace(':', '', $event['start_time']);
        $end_clean = str_replace(':', '', $event['end_time']);

        $dtstamp = gmdate('Ymd\THis\Z');
        $status = $event['status'] ?? ($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED');
        $sequence = ($method === 'CANCEL') ? '1' : '0';

        $summary = self::escape_ics_text($event['summary']);
        $description = self::escape_ics_text($event['description']);
        $location = self::escape_ics_text($event['location'] ?? '');

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//{$site_name}//FFC Scheduling//PT\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:{$method}\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$dtstamp}\r\n";
        $ics .= "DTSTART:{$date_clean}T{$start_clean}\r\n";
        $ics .= "DTEND:{$date_clean}T{$end_clean}\r\n";
        $ics .= "SUMMARY:{$summary}\r\n";
        $ics .= "DESCRIPTION:{$description}\r\n";
        if ($location) {
            $ics .= "LOCATION:{$location}\r\n";
        }
        $ics .= "STATUS:{$status}\r\n";
        $ics .= "SEQUENCE:{$sequence}\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }

    /**
     * Escape text for ICS format.
     *
     * @param string $text
     * @return string
     */
    private static function escape_ics_text(string $text): string {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace("\r", '', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);

        return $text;
    }
}
