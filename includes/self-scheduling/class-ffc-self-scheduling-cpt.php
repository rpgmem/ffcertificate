<?php
declare(strict_types=1);

/**
 * Self-Scheduling CPT
 *
 * Manages the Custom Post Type for calendars.
 * Creates independent menu structure as per requirements:
 * - FFC Calendars (parent menu, independent from FFC Forms)
 *   - All Calendars
 *   - Add New
 *   - Appointments
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class SelfSchedulingCPT {

    /**
     * Calendar repository instance
     *
     * @var \FreeFormCertificate\Repositories\CalendarRepository
     */
    private $calendar_repository;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'register_calendar_cpt'));
        add_filter('post_row_actions', array($this, 'add_duplicate_link'), 10, 2);
        add_action('admin_action_ffc_duplicate_calendar', array($this, 'handle_calendar_duplication'));

        // Hook into post save to create/update calendar record in database
        add_action('save_post_ffc_self_scheduling', array($this, 'sync_calendar_data'), 10, 3);

        // Hook into post delete to clean up calendar records
        add_action('before_delete_post', array($this, 'cleanup_calendar_data'), 10, 2);
    }

    /**
     * Initialize repository
     *
     * @return void
     */
    private function init_repository(): void {
        if (!$this->calendar_repository) {
            $this->calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
        }
    }

    /**
     * Register 'ffc_self_scheduling' Custom Post Type
     *
     * Creates independent menu structure below FFC Forms menu.
     *
     * @return void
     */
    public function register_calendar_cpt(): void {
        $labels = array(
            'name'                  => _x('Personal Calendars', 'Post Type General Name', 'wp-ffcertificate'),
            'singular_name'         => _x('Personal Calendar', 'Post Type Singular Name', 'wp-ffcertificate'),
            'menu_name'             => __('Personal Calendars', 'wp-ffcertificate'),
            'name_admin_bar'        => __('Personal Calendar', 'wp-ffcertificate'),
            'add_new'               => __('New Personal Calendar', 'wp-ffcertificate'),
            'add_new_item'          => __('Add New Personal Calendar', 'wp-ffcertificate'),
            'new_item'              => __('New Personal Calendar', 'wp-ffcertificate'),
            'edit_item'             => __('Edit Personal Calendar', 'wp-ffcertificate'),
            'view_item'             => __('View Personal Calendar', 'wp-ffcertificate'),
            'all_items'             => __('Personal Calendars', 'wp-ffcertificate'),
            'search_items'          => __('Search Personal Calendars', 'wp-ffcertificate'),
            'not_found'             => __('No personal calendars found.', 'wp-ffcertificate'),
            'not_found_in_trash'    => __('No personal calendars found in Trash.', 'wp-ffcertificate'),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'ffc-scheduling', // Unified Scheduling menu
            'query_var'          => true,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'supports'           => array('title'),
            'rewrite'            => array('slug' => 'ffc-calendar'),
        );

        register_post_type('ffc_self_scheduling', $args);
    }

    /**
     * Add duplicate link to calendar row actions
     *
     * @param array $actions
     * @param object $post
     * @return array
     */
    public function add_duplicate_link(array $actions, object $post): array {
        if ($post->post_type !== 'ffc_self_scheduling') {
            return $actions;
        }

        if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=ffc_duplicate_calendar&post=' . $post->ID),
            'ffc_duplicate_calendar_nonce'
        );

        $actions['duplicate'] = '<a href="' . esc_url($url) . '" title="' . esc_attr__('Duplicate this calendar', 'wp-ffcertificate') . '">' . esc_html__('Duplicate', 'wp-ffcertificate') . '</a>';

        return $actions;
    }

    /**
     * Handle calendar duplication
     *
     * @return void
     */
    public function handle_calendar_duplication(): void {
        if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
            \FreeFormCertificate\Core\Utils::debug_log('Unauthorized calendar duplication attempt', array(
                'user_id' => get_current_user_id(),
                'ip' => \FreeFormCertificate\Core\Utils::get_user_ip()
            ));
            wp_die(esc_html__('You do not have permission to duplicate this calendar.', 'wp-ffcertificate'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below via check_admin_referer.
        $post_id = (isset($_GET['post']) ? absint($_GET['post']) : 0);

        check_admin_referer('ffc_duplicate_calendar_nonce');

        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'ffc_self_scheduling') {
            \FreeFormCertificate\Core\Utils::debug_log('Invalid calendar duplication request', array(
                'post_id' => $post_id,
                'user_id' => get_current_user_id()
            ));
            wp_die(esc_html__('Invalid calendar.', 'wp-ffcertificate'));
        }

        $original_title = $post->post_title;
        /* translators: %s: original calendar title */
        $new_title = sprintf(__('%s (Copy)', 'wp-ffcertificate'), $original_title);

        // Create new post
        $new_post_args = array(
            'post_title'  => $new_title,
            'post_status' => 'draft',
            'post_type'   => $post->post_type,
            'post_author' => get_current_user_id(),
        );

        $new_post_id = wp_insert_post($new_post_args);

        if (is_wp_error($new_post_id)) {
            \FreeFormCertificate\Core\Utils::debug_log('Calendar duplication failed', array(
                'error' => $new_post_id->get_error_message(),
                'original_post_id' => $post_id
            ));
            wp_die(esc_html($new_post_id->get_error_message()));
        }

        // Copy all calendar metadata
        $config = get_post_meta($post_id, '_ffc_self_scheduling_config', true);
        $working_hours = get_post_meta($post_id, '_ffc_self_scheduling_working_hours', true);
        $email_config = get_post_meta($post_id, '_ffc_self_scheduling_email_config', true);

        $metadata_copied = array();

        if ($config) {
            update_post_meta($new_post_id, '_ffc_self_scheduling_config', $config);
            $metadata_copied[] = 'config';
        }

        if ($working_hours) {
            update_post_meta($new_post_id, '_ffc_self_scheduling_working_hours', $working_hours);
            $metadata_copied[] = 'working_hours';
        }

        if ($email_config) {
            update_post_meta($new_post_id, '_ffc_self_scheduling_email_config', $email_config);
            $metadata_copied[] = 'email_config';
        }

        \FreeFormCertificate\Core\Utils::debug_log('Calendar duplicated successfully', array(
            'original_post_id' => $post_id,
            'new_post_id' => $new_post_id,
            'metadata_copied' => $metadata_copied,
            'user_id' => get_current_user_id()
        ));

        wp_safe_redirect(admin_url('edit.php?post_type=ffc_self_scheduling'));
        exit;
    }

    /**
     * Sync calendar data to database table
     *
     * When a calendar post is saved, update the calendar record in wp_ffc_self_scheduling_calendars table.
     *
     * @param int $post_id
     * @param object $post
     * @param bool $update
     * @return void
     */
    public function sync_calendar_data(int $post_id, object $post, bool $update): void {
        // Skip autosaves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Only sync published calendars
        if ($post->post_status !== 'publish') {
            return;
        }

        $this->init_repository();

        // Check if calendar record exists
        $existing = $this->calendar_repository->findByPostId($post_id);

        // Get metadata
        $config = get_post_meta($post_id, '_ffc_self_scheduling_config', true);
        $working_hours = get_post_meta($post_id, '_ffc_self_scheduling_working_hours', true);
        $email_config = get_post_meta($post_id, '_ffc_self_scheduling_email_config', true);

        // Parse config into database fields
        $data = $this->parse_calendar_config($config);
        $data['title'] = $post->post_title;
        $data['post_id'] = $post_id;
        $data['working_hours'] = is_array($working_hours) ? json_encode($working_hours) : $working_hours;
        $data['email_config'] = is_array($email_config) ? json_encode($email_config) : $email_config;
        $data['updated_at'] = current_time('mysql');
        $data['updated_by'] = get_current_user_id();

        if ($existing) {
            // Update existing record
            $this->calendar_repository->update((int)$existing['id'], $data);
        } else {
            // Create new record
            $data['created_at'] = current_time('mysql');
            $data['created_by'] = get_current_user_id();
            $this->calendar_repository->createFromPost($post_id, $data);
        }
    }

    /**
     * Parse calendar config into database fields
     *
     * @param array|string $config
     * @return array
     */
    private function parse_calendar_config($config): array {
        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        if (!is_array($config)) {
            $config = array();
        }

        return array(
            'description' => $config['description'] ?? '',
            'slot_duration' => $config['slot_duration'] ?? 30,
            'slot_interval' => $config['slot_interval'] ?? 0,
            'slots_per_day' => $config['slots_per_day'] ?? 0,
            'advance_booking_min' => $config['advance_booking_min'] ?? 0,
            'advance_booking_max' => $config['advance_booking_max'] ?? 30,
            'allow_cancellation' => $config['allow_cancellation'] ?? 1,
            'cancellation_min_hours' => $config['cancellation_min_hours'] ?? 24,
            'minimum_interval_between_bookings' => $config['minimum_interval_between_bookings'] ?? 24,
            'requires_approval' => $config['requires_approval'] ?? 0,
            'max_appointments_per_slot' => $config['max_appointments_per_slot'] ?? 1,
            'require_login' => $config['require_login'] ?? 0,
            'allowed_roles' => isset($config['allowed_roles']) ? json_encode($config['allowed_roles']) : null,
            'status' => $config['status'] ?? 'active'
        );
    }

    /**
     * Cleanup calendar data when post is deleted
     *
     * Deletes the calendar record and optionally cancels all future appointments.
     * The cancellation behavior can be controlled via the 'ffc_self_scheduling_cancel_appointments_on_delete' filter.
     *
     * @param int $post_id
     * @param object $post
     * @return void
     */
    public function cleanup_calendar_data(int $post_id, object $post): void {
        if ($post->post_type !== 'ffc_self_scheduling') {
            return;
        }

        $this->init_repository();

        // Find calendar record
        $calendar = $this->calendar_repository->findByPostId($post_id);

        if ($calendar) {
            $calendar_id = (int)$calendar['id'];
            $calendar_title = $calendar['title'];

            // Check if we should cancel future appointments
            // By default, cancel future appointments to prevent orphaned bookings
            // This can be disabled via filter: add_filter('ffc_self_scheduling_cancel_appointments_on_delete', '__return_false');
            $cancel_appointments = apply_filters('ffc_self_scheduling_cancel_appointments_on_delete', true, $calendar_id, $post_id);

            $cancelled_count = 0;

            if ($cancel_appointments) {
                $cancelled_count = $this->cancel_future_appointments($calendar_id, $calendar_title);
            }

            // Delete calendar record
            $this->calendar_repository->delete($calendar_id);

            \FreeFormCertificate\Core\Utils::debug_log('Calendar data cleaned up', array(
                'post_id' => $post_id,
                'calendar_id' => $calendar_id,
                'calendar_title' => $calendar_title,
                'cancelled_appointments' => $cancelled_count,
                'user_id' => get_current_user_id()
            ));
        }
    }

    /**
     * Cancel all future appointments for a calendar
     *
     * Cancels all pending and confirmed appointments with dates >= today.
     * Sends notification emails to affected users (if email notifications are enabled).
     *
     * @param int $calendar_id Calendar ID
     * @param string $calendar_title Calendar title for notification
     * @return int Number of appointments cancelled
     */
    private function cancel_future_appointments(int $calendar_id, string $calendar_title): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        $today = current_time('Y-m-d');

        // Get all future appointments (pending or confirmed)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $future_appointments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE calendar_id = %d
             AND appointment_date >= %s
             AND status IN ('pending', 'confirmed')
             ORDER BY appointment_date ASC",
            $calendar_id,
            $today
        ), ARRAY_A);

        if (empty($future_appointments)) {
            return 0;
        }

        $cancelled_count = 0;
        $appointment_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();

        foreach ($future_appointments as $appointment) {
            // Cancel the appointment
            $result = $appointment_repo->cancel(
                (int)$appointment['id'],
                get_current_user_id(),
                /* translators: %s: calendar title */
                sprintf(__('Calendar "%s" was deleted', 'wp-ffcertificate'), $calendar_title)
            );

            if ($result) {
                $cancelled_count++;

                // Send cancellation email notification
                $this->send_calendar_deletion_notification($appointment, $calendar_title);
            }
        }

        return $cancelled_count;
    }

    /**
     * Send email notification about appointment cancellation due to calendar deletion
     *
     * @param array $appointment Appointment data
     * @param string $calendar_title Calendar title
     * @return void
     */
    private function send_calendar_deletion_notification(array $appointment, string $calendar_title): void {
        // Check if we should send notifications
        // Can be disabled via filter: add_filter('ffc_self_scheduling_send_deletion_notification', '__return_false');
        $send_notification = apply_filters('ffc_self_scheduling_send_deletion_notification', true, $appointment);

        if (!$send_notification) {
            return;
        }

        // Get recipient email
        $email = '';
        if (!empty($appointment['email'])) {
            $email = $appointment['email'];
        } elseif (!empty($appointment['email_encrypted'])) {
            if (class_exists('\FreeFormCertificate\Core\Encryption')) {
                $email = \FreeFormCertificate\Core\Encryption::decrypt($appointment['email_encrypted']);
            }
        }

        if (empty($email) || !is_email($email)) {
            return;
        }

        // Prepare email
        $subject = sprintf(
            /* translators: %s: site name */
            __('[%s] Appointment Cancelled - Calendar No Longer Available', 'wp-ffcertificate'),
            get_bloginfo('name')
        );

        $date_formatted = date_i18n(get_option('date_format'), strtotime($appointment['appointment_date']));
        $time_formatted = date_i18n(get_option('time_format'), strtotime($appointment['start_time']));

        $message = sprintf(
            /* translators: %1$s, %2$s, %4$s, %5$s, %6$s, %8$s, %10$s, %12$s, %13$s, %14$s, %15$s: line breaks, %3$s: calendar title, %7$s: appointment date, %9$s: appointment time, %11$s: calendar title, %16$s: site name */
            __('Hello,%1$s%2$sWe regret to inform you that your appointment has been cancelled because the calendar "%3$s" is no longer available.%4$s%5$sAppointment Details:%6$s- Date: %7$s%8$s- Time: %9$s%10$s- Calendar: %11$s%12$s%13$sWe apologize for any inconvenience this may cause.%14$s%15$sBest regards,%16$s%17$s', 'wp-ffcertificate'),
            "\n\n",
            "\n",
            $calendar_title,
            "\n\n",
            "\n",
            "\n",
            $date_formatted,
            "\n",
            $time_formatted,
            "\n",
            $calendar_title,
            "\n\n",
            "\n",
            "\n\n",
            "\n",
            "\n",
            get_bloginfo('name')
        );

        // Send email
        wp_mail($email, $subject, $message);

        // Log notification
        if (class_exists('\FreeFormCertificate\Core\Utils')) {
            \FreeFormCertificate\Core\Utils::debug_log('Calendar deletion notification sent', array(
                'appointment_id' => $appointment['id'],
                'email' => $email,
                'calendar_title' => $calendar_title
            ));
        }
    }
}
