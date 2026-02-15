<?php
declare(strict_types=1);

/**
 * Reregistration Email Handler
 *
 * Sends invitation, reminder, and confirmation emails for reregistration campaigns.
 * Uses EmailTemplateService for rendering and sending.
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Scheduling\EmailTemplateService;

if (!defined('ABSPATH')) {
    exit;
}

class ReregistrationEmailHandler {

    /**
     * Send invitation emails to all pending members.
     *
     * @param int $reregistration_id Reregistration ID.
     * @return int Number of emails sent.
     */
    public static function send_invitations(int $reregistration_id): int {
        if (self::emails_disabled()) {
            return 0;
        }

        $rereg = ReregistrationRepository::get_by_id($reregistration_id);
        if (!$rereg || empty($rereg->email_invitation_enabled)) {
            return 0;
        }

        $submissions = ReregistrationSubmissionRepository::get_by_reregistration($reregistration_id, array(
            'status' => 'pending',
        ));

        $template = self::load_template('reregistration-invitation');
        if (!$template) {
            return 0;
        }

        $count = 0;
        foreach ($submissions as $sub) {
            if (self::send_to_user((int) $sub->user_id, $rereg, $template)) {
                $count++;
            }
        }

        // Activity log
        self::log('reregistration_invitations_sent', 0, array(
            'reregistration_id' => $reregistration_id,
            'count'             => $count,
        ));

        return $count;
    }

    /**
     * Send reminder emails to pending/in-progress members.
     *
     * @param int        $reregistration_id Reregistration ID.
     * @param array<int> $user_ids          Specific user IDs (empty = all pending).
     * @return int Number of emails sent.
     */
    public static function send_reminders(int $reregistration_id, array $user_ids = array()): int {
        if (self::emails_disabled()) {
            return 0;
        }

        $rereg = ReregistrationRepository::get_by_id($reregistration_id);
        if (!$rereg || empty($rereg->email_reminder_enabled)) {
            return 0;
        }

        $template = self::load_template('reregistration-reminder');
        if (!$template) {
            return 0;
        }

        // If specific user IDs given, filter to those. Otherwise get all pending/in-progress.
        if (!empty($user_ids)) {
            $submissions = array();
            foreach ($user_ids as $uid) {
                $sub = ReregistrationSubmissionRepository::get_by_reregistration_and_user($reregistration_id, (int) $uid);
                if ($sub && in_array($sub->status, array('pending', 'in_progress'), true)) {
                    $submissions[] = $sub;
                }
            }
        } else {
            $pending = ReregistrationSubmissionRepository::get_by_reregistration($reregistration_id, array('status' => 'pending'));
            $in_progress = ReregistrationSubmissionRepository::get_by_reregistration($reregistration_id, array('status' => 'in_progress'));
            $submissions = array_merge($pending, $in_progress);
        }

        $days_left = max(0, (int) ((strtotime($rereg->end_date) - time()) / 86400));

        $count = 0;
        foreach ($submissions as $sub) {
            if (self::send_to_user((int) $sub->user_id, $rereg, $template, array('days_left' => (string) $days_left))) {
                $count++;
            }
        }

        self::log('reregistration_reminders_sent', 0, array(
            'reregistration_id' => $reregistration_id,
            'count'             => $count,
        ));

        return $count;
    }

    /**
     * Send confirmation email to a user after submission.
     *
     * @param int $submission_id Submission ID.
     * @return bool
     */
    public static function send_confirmation(int $submission_id): bool {
        if (self::emails_disabled()) {
            return false;
        }

        $submission = ReregistrationSubmissionRepository::get_by_id($submission_id);
        if (!$submission) {
            return false;
        }

        $rereg = ReregistrationRepository::get_by_id((int) $submission->reregistration_id);
        if (!$rereg || empty($rereg->email_confirmation_enabled)) {
            return false;
        }

        $template = self::load_template('reregistration-confirmation');
        if (!$template) {
            return false;
        }

        $status_label = ReregistrationSubmissionRepository::get_status_label($submission->status);

        return self::send_to_user((int) $submission->user_id, $rereg, $template, array(
            'submission_status' => $status_label,
        ));
    }

    /**
     * Run automated reminders for all active campaigns.
     *
     * Called by the daily cron job. Sends reminders when:
     * - Campaign is active
     * - email_reminder_enabled = 1
     * - Days until end_date <= reminder_days
     *
     * @return int Total emails sent across all campaigns.
     */
    public static function run_automated_reminders(): int {
        if (self::emails_disabled()) {
            return 0;
        }

        global $wpdb;
        $table = ReregistrationRepository::get_table_name();

        // Get active campaigns where reminder is due
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $campaigns = $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE status = 'active'
               AND email_reminder_enabled = 1
               AND DATEDIFF(end_date, CURDATE()) <= reminder_days
               AND DATEDIFF(end_date, CURDATE()) >= 0"
        );

        if (empty($campaigns)) {
            return 0;
        }

        $total = 0;
        foreach ($campaigns as $campaign) {
            $total += self::send_reminders((int) $campaign->id);
        }

        return $total;
    }

    /**
     * Send an email to a specific user.
     *
     * @param int    $user_id     User ID.
     * @param object $rereg       Reregistration object.
     * @param array  $template    Template with 'subject' and 'body' keys.
     * @param array  $extra_vars  Additional template variables.
     * @return bool
     */
    private static function send_to_user(int $user_id, object $rereg, array $template, array $extra_vars = array()): bool {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        $dashboard_page_id = get_option('ffc_dashboard_page_id');
        $dashboard_url = $dashboard_page_id ? get_permalink((int) $dashboard_page_id) : home_url('/dashboard');

        $variables = array_merge(array(
            'user_name'             => $user->display_name,
            'reregistration_title'  => $rereg->title,
            'audience_name'         => $rereg->audience_name ?? '',
            'start_date'            => EmailTemplateService::format_date($rereg->start_date),
            'end_date'              => EmailTemplateService::format_date($rereg->end_date),
            'dashboard_url'         => $dashboard_url,
            'site_name'             => get_bloginfo('name'),
        ), $extra_vars);

        $subject = EmailTemplateService::render_template($template['subject'], $variables);
        $body = EmailTemplateService::render_template($template['body'], $variables);

        return EmailTemplateService::send($user->user_email, $subject, $body);
    }

    /**
     * Load an email template file.
     *
     * @param string $template_name Template name (without path/extension).
     * @return array|null Array with 'subject' and 'body', or null.
     */
    private static function load_template(string $template_name): ?array {
        $file = FFC_PLUGIN_DIR . "templates/emails/{$template_name}.php";
        if (!file_exists($file)) {
            return null;
        }

        $template = include $file;
        if (!is_array($template) || !isset($template['subject'], $template['body'])) {
            return null;
        }

        return $template;
    }

    /**
     * Check if all emails are globally disabled.
     *
     * @return bool
     */
    private static function emails_disabled(): bool {
        $settings = get_option('ffc_settings', array());
        return !empty($settings['disable_all_emails']);
    }

    /**
     * Log an email event.
     *
     * @param string $type    Event type.
     * @param int    $user_id User ID (0 for system events).
     * @param array  $data    Extra data.
     * @return void
     */
    private static function log(string $type, int $user_id, array $data): void {
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log($type, $user_id, $data);
        }
    }
}
