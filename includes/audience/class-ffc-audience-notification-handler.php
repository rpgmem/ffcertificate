<?php
declare(strict_types=1);

/**
 * Audience Notification Handler
 *
 * Handles email notifications for audience booking system.
 * Sends notifications to affected users when bookings are created or cancelled.
 *
 * Features:
 * - Email to all affected users (via audiences or individual selection)
 * - Email to booking creator
 * - Optional .ics calendar file attachment
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceNotificationHandler {

    /**
     * Initialize notification hooks
     *
     * @return void
     */
    public static function init(): void {
        add_action('ffcertificate_audience_booking_created', array(__CLASS__, 'send_booking_created_notification'));
        add_action('ffcertificate_audience_booking_cancelled', array(__CLASS__, 'send_booking_cancelled_notification'), 10, 2);
    }

    /**
     * Send notification when booking is created
     *
     * @param int $booking_id Booking ID
     * @return void
     */
    public static function send_booking_created_notification(int $booking_id): void {
        $booking = AudienceBookingRepository::get_by_id($booking_id);
        if (!$booking) {
            return;
        }

        $environment = AudienceEnvironmentRepository::get_by_id((int) $booking->environment_id);
        if (!$environment) {
            return;
        }

        $schedule = AudienceScheduleRepository::get_by_id((int) $environment->schedule_id);
        if (!$schedule || !$schedule->notify_on_booking) {
            return;
        }

        // Get all affected users
        $affected_users = AudienceBookingRepository::get_all_affected_users($booking_id);
        if (empty($affected_users)) {
            return;
        }

        // Get booking creator info
        $creator = get_user_by('id', $booking->created_by);
        $creator_name = $creator ? $creator->display_name : __('Unknown', 'ffcertificate');

        // Prepare booking data
        $environment_label = AudienceScheduleRepository::get_environment_label($schedule, true);
        $booking_data = array(
            'booking_id' => $booking_id,
            'environment_name' => $environment->name,
            'environment_label' => $environment_label,
            'schedule_name' => $schedule->name,
            'booking_date' => self::format_date($booking->booking_date),
            'booking_date_raw' => $booking->booking_date,
            'start_time' => self::format_time($booking->start_time),
            'end_time' => self::format_time($booking->end_time),
            'start_time_raw' => $booking->start_time,
            'end_time_raw' => $booking->end_time,
            'description' => $booking->description,
            'creator_name' => $creator_name,
        );

        // Get audiences for the booking
        $audiences = AudienceBookingRepository::get_booking_audiences($booking_id);
        $audience_names = array_map(function($a) { return $a->name; }, $audiences);
        $booking_data['audiences'] = implode(', ', $audience_names);

        // Generate ICS if enabled
        $ics_content = null;
        if ($schedule->include_ics) {
            $ics_content = self::generate_ics($booking_data, 'created');
        }

        // Get email template or use default
        $template = $schedule->email_template_booking ?: self::get_default_booking_template();

        // Send email to each affected user
        foreach ($affected_users as $user_id) {
            $user = get_user_by('id', $user_id);
            if (!$user || !$user->user_email) {
                continue;
            }

            self::send_email(
                $user->user_email,
                self::get_booking_subject($booking_data),
                self::render_template($template, $booking_data, $user),
                $ics_content
            );
        }
    }

    /**
     * Send notification when booking is cancelled
     *
     * @param int $booking_id Booking ID
     * @param string $reason Cancellation reason
     * @return void
     */
    public static function send_booking_cancelled_notification(int $booking_id, string $reason): void {
        $booking = AudienceBookingRepository::get_by_id($booking_id);
        if (!$booking) {
            return;
        }

        $environment = AudienceEnvironmentRepository::get_by_id((int) $booking->environment_id);
        if (!$environment) {
            return;
        }

        $schedule = AudienceScheduleRepository::get_by_id((int) $environment->schedule_id);
        if (!$schedule || !$schedule->notify_on_cancellation) {
            return;
        }

        // Get all affected users (from booking_users table)
        global $wpdb;
        $booking_users_table = $wpdb->prefix . 'ffc_audience_booking_users';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix.
        $affected_users = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$booking_users_table} WHERE booking_id = %d",
            $booking_id
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if (empty($affected_users)) {
            return;
        }

        // Get cancelled by info
        $cancelled_by = get_user_by('id', $booking->cancelled_by);
        $cancelled_by_name = $cancelled_by ? $cancelled_by->display_name : __('Unknown', 'ffcertificate');

        // Prepare booking data
        $environment_label = AudienceScheduleRepository::get_environment_label($schedule, true);
        $booking_data = array(
            'booking_id' => $booking_id,
            'environment_name' => $environment->name,
            'environment_label' => $environment_label,
            'schedule_name' => $schedule->name,
            'booking_date' => self::format_date($booking->booking_date),
            'booking_date_raw' => $booking->booking_date,
            'start_time' => self::format_time($booking->start_time),
            'end_time' => self::format_time($booking->end_time),
            'start_time_raw' => $booking->start_time,
            'end_time_raw' => $booking->end_time,
            'description' => $booking->description,
            'cancelled_by_name' => $cancelled_by_name,
            'cancellation_reason' => $reason,
        );

        // Get audiences for the booking
        $audiences = AudienceBookingRepository::get_booking_audiences($booking_id);
        $audience_names = array_map(function($a) { return $a->name; }, $audiences);
        $booking_data['audiences'] = implode(', ', $audience_names);

        // Generate ICS cancellation if enabled
        $ics_content = null;
        if ($schedule->include_ics) {
            $ics_content = self::generate_ics($booking_data, 'cancelled');
        }

        // Get email template or use default
        $template = $schedule->email_template_cancellation ?: self::get_default_cancellation_template();

        // Send email to each affected user
        foreach ($affected_users as $user_id) {
            $user = get_user_by('id', (int) $user_id);
            if (!$user || !$user->user_email) {
                continue;
            }

            self::send_email(
                $user->user_email,
                self::get_cancellation_subject($booking_data),
                self::render_template($template, $booking_data, $user),
                $ics_content
            );
        }
    }

    /**
     * Send email with optional ICS attachment
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param string|null $ics_content ICS file content
     * @return bool
     */
    private static function send_email(string $to, string $subject, string $body, ?string $ics_content = null): bool {
        $attachments = array();
        $temp_files = array();

        // Create temporary ICS file if content provided
        if ($ics_content) {
            $upload_dir = wp_upload_dir();
            $ics_file = $upload_dir['basedir'] . '/ffc-temp-' . wp_generate_password(12, false) . '.ics';

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            if (file_put_contents($ics_file, $ics_content)) {
                $attachments[] = $ics_file;
                $temp_files[] = $ics_file;
            }
        }

        // Delegate to shared email service
        $result = \FreeFormCertificate\Scheduling\EmailTemplateService::send($to, $subject, $body, $attachments);

        // Clean up temporary ICS file
        foreach ($temp_files as $file) {
            if (file_exists($file)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink($file);
            }
        }

        return $result;
    }

    /**
     * Generate ICS calendar file content
     *
     * @param array<string, mixed> $booking_data Booking data
     * @param string $action Action type (created, cancelled)
     * @return string ICS content
     */
    private static function generate_ics(array $booking_data, string $action): string {
        $summary = $booking_data['description'];
        if ($action === 'cancelled') {
            $summary = '[' . __('CANCELLED', 'ffcertificate') . '] ' . $summary;
        }

        $method = ($action === 'cancelled') ? 'CANCEL' : 'REQUEST';

        return \FreeFormCertificate\Scheduling\EmailTemplateService::generate_ics(
            array(
                'uid' => 'ffc-booking-' . $booking_data['booking_id'],
                'summary' => $summary,
                'description' => $booking_data['description'],
                'location' => $booking_data['environment_name'],
                'date' => $booking_data['booking_date_raw'],
                'start_time' => $booking_data['start_time_raw'],
                'end_time' => $booking_data['end_time_raw'],
            ),
            $method
        );
    }

    // escape_ics_text() removed — already available in EmailTemplateService (v4.11.2)

    /**
     * Render email template with variables
     *
     * @param string $template Template content
     * @param array<string, mixed> $booking_data Booking data
     * @param \WP_User $user Recipient user
     * @return string Rendered template
     */
    private static function render_template(string $template, array $booking_data, \WP_User $user): string {
        $replacements = array(
            '{user_name}' => $user->display_name,
            '{user_email}' => $user->user_email,
            '{environment_name}' => $booking_data['environment_name'],
            '{environment_label}' => $booking_data['environment_label'] ?? __('Environment', 'ffcertificate'),
            '{schedule_name}' => $booking_data['schedule_name'],
            '{booking_date}' => $booking_data['booking_date'],
            '{start_time}' => $booking_data['start_time'],
            '{end_time}' => $booking_data['end_time'],
            '{description}' => $booking_data['description'],
            '{audiences}' => $booking_data['audiences'] ?? '',
            '{creator_name}' => $booking_data['creator_name'] ?? '',
            '{cancelled_by_name}' => $booking_data['cancelled_by_name'] ?? '',
            '{cancellation_reason}' => $booking_data['cancellation_reason'] ?? '',
            '{site_name}' => get_bloginfo('name'),
            '{site_url}' => home_url(),
        );

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Wrap email body in HTML structure.
     * Delegates to EmailTemplateService::wrap_html().
     */
    private static function wrap_email_html(string $body): string {
        return \FreeFormCertificate\Scheduling\EmailTemplateService::wrap_html($body);
    }

    /**
     * Get default booking created email template
     *
     * @return string Template HTML
     */
    private static function get_default_booking_template(): string {
        return "
<h3>" . __('New Scheduled Activity', 'ffcertificate') . "</h3>

<p>" . __('Hello {user_name},', 'ffcertificate') . "</p>

<p>" . __('You have been included in a new scheduled activity:', 'ffcertificate') . "</p>

<div class='info-box'>
    <div class='info-row'><span class='info-label'>" . __('Calendar:', 'ffcertificate') . "</span> {schedule_name}</div>
    <div class='info-row'><span class='info-label'>{environment_label}:</span> {environment_name}</div>
    <div class='info-row'><span class='info-label'>" . __('Date:', 'ffcertificate') . "</span> {booking_date}</div>
    <div class='info-row'><span class='info-label'>" . __('Time:', 'ffcertificate') . "</span> {start_time} - {end_time}</div>
    <div class='info-row'><span class='info-label'>" . __('Description:', 'ffcertificate') . "</span> {description}</div>
    <div class='info-row'><span class='info-label'>" . __('Audiences:', 'ffcertificate') . "</span> {audiences}</div>
    <div class='info-row'><span class='info-label'>" . __('Scheduled by:', 'ffcertificate') . "</span> {creator_name}</div>
</div>

<p>" . __('Please add this event to your calendar.', 'ffcertificate') . "</p>

<p>" . __('Best regards,', 'ffcertificate') . "<br>{site_name}</p>";
    }

    /**
     * Get default booking cancelled email template
     *
     * @return string Template HTML
     */
    private static function get_default_cancellation_template(): string {
        return "
<h3>" . __('Activity Cancelled', 'ffcertificate') . "</h3>

<p>" . __('Hello {user_name},', 'ffcertificate') . "</p>

<p>" . __('A scheduled activity you were included in has been cancelled:', 'ffcertificate') . "</p>

<div class='info-box cancelled'>
    <div class='info-row'><span class='info-label'>" . __('Calendar:', 'ffcertificate') . "</span> {schedule_name}</div>
    <div class='info-row'><span class='info-label'>{environment_label}:</span> {environment_name}</div>
    <div class='info-row'><span class='info-label'>" . __('Date:', 'ffcertificate') . "</span> {booking_date}</div>
    <div class='info-row'><span class='info-label'>" . __('Time:', 'ffcertificate') . "</span> {start_time} - {end_time}</div>
    <div class='info-row'><span class='info-label'>" . __('Description:', 'ffcertificate') . "</span> {description}</div>
    <div class='info-row'><span class='info-label'>" . __('Cancelled by:', 'ffcertificate') . "</span> {cancelled_by_name}</div>
    <div class='info-row'><span class='info-label'>" . __('Reason:', 'ffcertificate') . "</span> {cancellation_reason}</div>
</div>

<p>" . __('Please remove this event from your calendar.', 'ffcertificate') . "</p>

<p>" . __('Best regards,', 'ffcertificate') . "<br>{site_name}</p>";
    }

    /**
     * Get booking created email subject
     *
     * @param array<string, mixed> $booking_data Booking data
     * @return string Email subject
     */
    private static function get_booking_subject(array $booking_data): string {
        return sprintf(
            /* translators: 1: Environment name, 2: Date */
            __('[%1$s] New Scheduled Activity - %2$s', 'ffcertificate'),
            $booking_data['environment_name'],
            $booking_data['booking_date']
        );
    }

    /**
     * Get booking cancelled email subject
     *
     * @param array<string, mixed> $booking_data Booking data
     * @return string Email subject
     */
    private static function get_cancellation_subject(array $booking_data): string {
        return sprintf(
            /* translators: 1: Environment name, 2: Date */
            __('[%1$s] Activity Cancelled - %2$s', 'ffcertificate'),
            $booking_data['environment_name'],
            $booking_data['booking_date']
        );
    }

    /**
     * Format date for display.
     * Delegates to EmailTemplateService::format_date().
     */
    private static function format_date(string $date): string {
        return \FreeFormCertificate\Scheduling\EmailTemplateService::format_date($date);
    }

    /**
     * Format time for display.
     * Delegates to EmailTemplateService::format_time().
     */
    private static function format_time(string $time): string {
        return \FreeFormCertificate\Scheduling\EmailTemplateService::format_time($time);
    }
}
