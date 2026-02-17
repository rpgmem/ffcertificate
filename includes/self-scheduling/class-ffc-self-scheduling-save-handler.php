<?php
declare(strict_types=1);

/**
 * Self-Scheduling Editor — Save Handler
 *
 * Extracted from SelfSchedulingEditor (Sprint 15 refactoring).
 * Handles saving calendar configuration, working hours, and email
 * settings when the admin saves a ffc_self_scheduling post.
 *
 * @since 4.12.16
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;

class SelfSchedulingSaveHandler {

    /**
     * Register save hook
     */
    public function __construct() {
        add_action('save_post_ffc_self_scheduling', array($this, 'save_calendar_data'), 10, 3);
    }

    /**
     * Save calendar data
     *
     * @param int $post_id
     * @param object $post
     * @param bool $update
     * @return void
     */
    public function save_calendar_data(int $post_id, object $post, bool $update): void {
        // Security checks
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['ffc_self_scheduling_config_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_self_scheduling_config_nonce'])), 'ffc_self_scheduling_config_nonce')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $this->save_config($post_id);
        $this->save_working_hours($post_id);
        $this->save_email_config($post_id);
    }

    /**
     * Save calendar configuration
     */
    private function save_config(int $post_id): void {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() check only; value unslashed below.
        if (!isset($_POST['ffc_self_scheduling_config'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized individually below.
        $config = wp_unslash($_POST['ffc_self_scheduling_config']);

        // Sanitize
        $config['description'] = sanitize_textarea_field($config['description'] ?? '');
        $config['slot_duration'] = absint($config['slot_duration'] ?? 30);
        $config['slot_interval'] = absint($config['slot_interval'] ?? 0);
        $config['slots_per_day'] = absint($config['slots_per_day'] ?? 0);
        $config['max_appointments_per_slot'] = absint($config['max_appointments_per_slot'] ?? 1);
        $config['advance_booking_min'] = absint($config['advance_booking_min'] ?? 0);
        $config['advance_booking_max'] = absint($config['advance_booking_max'] ?? 30);
        $config['allow_cancellation'] = isset($config['allow_cancellation']) ? 1 : 0;
        $config['cancellation_min_hours'] = absint($config['cancellation_min_hours'] ?? 24);
        $config['minimum_interval_between_bookings'] = absint($config['minimum_interval_between_bookings'] ?? 24);
        $config['requires_approval'] = isset($config['requires_approval']) ? 1 : 0;
        $config['status'] = sanitize_text_field($config['status'] ?? 'active');

        // Visibility controls
        $config['visibility'] = in_array(($config['visibility'] ?? ''), ['public', 'private'], true) ? $config['visibility'] : 'public';
        $config['scheduling_visibility'] = in_array(($config['scheduling_visibility'] ?? ''), ['public', 'private'], true) ? $config['scheduling_visibility'] : 'public';

        // If visibility is private, scheduling must also be private
        if ($config['visibility'] === 'private') {
            $config['scheduling_visibility'] = 'private';
        }

        // Business hours restriction toggles
        $config['restrict_viewing_to_hours'] = isset($config['restrict_viewing_to_hours']) ? 1 : 0;
        $config['restrict_booking_to_hours'] = isset($config['restrict_booking_to_hours']) ? 1 : 0;

        update_post_meta($post_id, '_ffc_self_scheduling_config', $config);
    }

    /**
     * Save working hours
     */
    private function save_working_hours(int $post_id): void {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset()/is_array() check only; value unslashed below.
        if (!isset($_POST['ffc_self_scheduling_working_hours']) || !is_array($_POST['ffc_self_scheduling_working_hours'])) {
            return;
        }

        $working_hours = array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized individually below.
        foreach (wp_unslash($_POST['ffc_self_scheduling_working_hours']) as $hours) {
            $working_hours[] = array(
                'day' => absint($hours['day'] ?? 0),
                'start' => sanitize_text_field($hours['start'] ?? '09:00'),
                'end' => sanitize_text_field($hours['end'] ?? '17:00')
            );
        }
        update_post_meta($post_id, '_ffc_self_scheduling_working_hours', $working_hours);
    }

    /**
     * Save email configuration
     */
    private function save_email_config(int $post_id): void {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() check only; value unslashed below.
        if (!isset($_POST['ffc_self_scheduling_email_config'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized individually below.
        $email_config = wp_unslash($_POST['ffc_self_scheduling_email_config']);

        $email_config['send_user_confirmation'] = isset($email_config['send_user_confirmation']) ? 1 : 0;
        $email_config['send_admin_notification'] = isset($email_config['send_admin_notification']) ? 1 : 0;
        $email_config['send_approval_notification'] = isset($email_config['send_approval_notification']) ? 1 : 0;
        $email_config['send_cancellation_notification'] = isset($email_config['send_cancellation_notification']) ? 1 : 0;
        $email_config['send_reminder'] = isset($email_config['send_reminder']) ? 1 : 0;
        $email_config['reminder_hours_before'] = absint($email_config['reminder_hours_before'] ?? 24);
        $email_config['admin_emails'] = sanitize_text_field($email_config['admin_emails'] ?? '');
        $email_config['user_confirmation_subject'] = sanitize_text_field($email_config['user_confirmation_subject'] ?? '');
        $email_config['user_confirmation_body'] = sanitize_textarea_field($email_config['user_confirmation_body'] ?? '');

        update_post_meta($post_id, '_ffc_self_scheduling_email_config', $email_config);
    }
}
