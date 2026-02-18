<?php
declare(strict_types=1);

/**
 * Self-Scheduling Editor
 *
 * Handles the advanced UI for Calendar Builder, including metaboxes and configuration.
 *
 * v4.12.16: Extracted SelfSchedulingCleanupHandler (AJAX + cleanup metabox)
 *           and SelfSchedulingSaveHandler (save_post handler) for SRP compliance.
 *
 * @since 4.1.0
 * @version 4.12.16
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;

class SelfSchedulingEditor {

    /** @var SelfSchedulingCleanupHandler */
    private $cleanup_handler;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_custom_metaboxes'), 20);
        add_action('admin_notices', array($this, 'display_save_errors'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Delegated handlers
        new SelfSchedulingSaveHandler();
        $this->cleanup_handler = new SelfSchedulingCleanupHandler();
    }

    /**
     * Enqueue scripts and styles for calendar editor
     *
     * @param string $hook
     * @return void
     */
    public function enqueue_scripts(string $hook): void {
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'ffc_self_scheduling') {
            return;
        }

        $s = \FreeFormCertificate\Core\Utils::asset_suffix();

        wp_enqueue_script(
            'ffc-calendar-editor',
            FFC_PLUGIN_URL . "assets/js/ffc-calendar-editor{$s}.js",
            array('jquery', 'jquery-ui-sortable'),
            FFC_VERSION,
            true
        );

        wp_enqueue_style(
            'ffc-calendar-editor',
            FFC_PLUGIN_URL . "assets/css/ffc-calendar-editor{$s}.css",
            array(),
            FFC_VERSION
        );

        wp_localize_script('ffc-calendar-editor', 'ffcSelfSchedulingEditor', array(
            'nonce' => wp_create_nonce('ffc_self_scheduling_editor_nonce'),
            'strings' => array(
                'confirmDelete'     => __('Are you sure you want to delete this?', 'ffcertificate'),
                'addWorkingHour'    => __('Add Working Hours', 'ffcertificate'),
                'confirmCleanup'    => __('Are you sure you want to delete these appointments? This action cannot be undone.', 'ffcertificate'),
                'confirmCleanupAll' => __('Are you sure you want to delete ALL appointments? This will permanently remove all appointment data and cannot be undone!', 'ffcertificate'),
                'deleting'          => __('Deleting...', 'ffcertificate'),
                'errorDeleting'     => __('Error deleting appointments', 'ffcertificate'),
                'errorServer'       => __('Error communicating with server', 'ffcertificate'),
                'schedulingForced'  => __('Forced to Private because Visibility is Private.', 'ffcertificate'),
                'schedulingDesc'    => __('Public: anyone can book. Private: only logged-in users can book.', 'ffcertificate'),
            )
        ));
    }

    /**
     * Add custom metaboxes for calendar editor
     *
     * @return void
     */
    public function add_custom_metaboxes(): void {
        // Main configuration
        add_meta_box(
            'ffc_self_scheduling_box_config',
            __('1. Calendar Configuration', 'ffcertificate'),
            array($this, 'render_box_config'),
            'ffc_self_scheduling',
            'normal',
            'high'
        );

        // Working hours
        add_meta_box(
            'ffc_self_scheduling_box_hours',
            __('2. Working Hours & Availability', 'ffcertificate'),
            array($this, 'render_box_hours'),
            'ffc_self_scheduling',
            'normal',
            'high'
        );

        // Booking rules
        add_meta_box(
            'ffc_self_scheduling_box_rules',
            __('3. Booking Rules & Restrictions', 'ffcertificate'),
            array($this, 'render_box_rules'),
            'ffc_self_scheduling',
            'normal',
            'high'
        );

        // Email notifications
        add_meta_box(
            'ffc_self_scheduling_box_email',
            __('4. Email Notifications', 'ffcertificate'),
            array($this, 'render_box_email'),
            'ffc_self_scheduling',
            'normal',
            'high'
        );

        // Shortcode (sidebar)
        add_meta_box(
            'ffc_self_scheduling_shortcode',
            __('How to Use / Shortcode', 'ffcertificate'),
            array($this, 'render_shortcode_metabox'),
            'ffc_self_scheduling',
            'side',
            'high'
        );

        // Cleanup appointments (sidebar) - Only show for existing calendars
        $post_id = get_the_ID();
        if ($post_id) {
            add_meta_box(
                'ffc_self_scheduling_cleanup',
                __('Clean Up Appointments', 'ffcertificate'),
                array($this->cleanup_handler, 'render_cleanup_metabox'),
                'ffc_self_scheduling',
                'side',
                'default'
            );
        }
    }

    /**
     * Render calendar configuration metabox
     *
     * @param object $post
     * @return void
     */
    public function render_box_config(object $post): void {
        $config = get_post_meta($post->ID, '_ffc_self_scheduling_config', true);
        if (!is_array($config)) {
            $config = array();
        }

        $defaults = array(
            'description' => '',
            'slot_duration' => 30,
            'slot_interval' => 0,
            'slots_per_day' => 0,
            'max_appointments_per_slot' => 1,
            'status' => 'active'
        );

        $config = array_merge($defaults, $config);

        wp_nonce_field('ffc_self_scheduling_config_nonce', 'ffc_self_scheduling_config_nonce');
        ?>
        <table class="form-table">
            <tr>
                <th><label for="calendar_description"><?php esc_html_e('Description', 'ffcertificate'); ?></label></th>
                <td>
                    <textarea id="calendar_description" name="ffc_self_scheduling_config[description]" rows="3" class="large-text"><?php echo esc_textarea($config['description']); ?></textarea>
                    <p class="description"><?php esc_html_e('Brief description of this calendar (optional)', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="slot_duration"><?php esc_html_e('Appointment Duration', 'ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="slot_duration" name="ffc_self_scheduling_config[slot_duration]" value="<?php echo esc_attr($config['slot_duration']); ?>" min="5" max="480" step="5" /> <?php esc_html_e('minutes', 'ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('Duration of each appointment slot', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="slot_interval"><?php esc_html_e('Break Between Appointments', 'ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="slot_interval" name="ffc_self_scheduling_config[slot_interval]" value="<?php echo esc_attr($config['slot_interval']); ?>" min="0" max="120" step="5" /> <?php esc_html_e('minutes', 'ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('Buffer time between appointments (0 = no break)', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="max_appointments_per_slot"><?php esc_html_e('Max Bookings Per Slot', 'ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="max_appointments_per_slot" name="ffc_self_scheduling_config[max_appointments_per_slot]" value="<?php echo esc_attr($config['max_appointments_per_slot']); ?>" min="1" max="100" />
                    <p class="description"><?php esc_html_e('Maximum number of people per time slot (1 = exclusive)', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="slots_per_day"><?php esc_html_e('Daily Booking Limit', 'ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="slots_per_day" name="ffc_self_scheduling_config[slots_per_day]" value="<?php echo esc_attr($config['slots_per_day']); ?>" min="0" max="200" />
                    <p class="description"><?php esc_html_e('Maximum appointments per day (0 = unlimited)', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="calendar_status"><?php esc_html_e('Status', 'ffcertificate'); ?></label></th>
                <td>
                    <select id="calendar_status" name="ffc_self_scheduling_config[status]">
                        <option value="active" <?php selected($config['status'], 'active'); ?>><?php esc_html_e('Active', 'ffcertificate'); ?></option>
                        <option value="inactive" <?php selected($config['status'], 'inactive'); ?>><?php esc_html_e('Inactive', 'ffcertificate'); ?></option>
                        <option value="archived" <?php selected($config['status'], 'archived'); ?>><?php esc_html_e('Archived', 'ffcertificate'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Calendar status (inactive = no new bookings allowed)', 'ffcertificate'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render working hours metabox
     *
     * @param object $post
     * @return void
     */
    public function render_box_hours(object $post): void {
        $working_hours = get_post_meta($post->ID, '_ffc_self_scheduling_working_hours', true);
        if (!is_array($working_hours)) {
            $working_hours = array(
                array('day' => 1, 'start' => '09:00', 'end' => '17:00'), // Monday
                array('day' => 2, 'start' => '09:00', 'end' => '17:00'), // Tuesday
                array('day' => 3, 'start' => '09:00', 'end' => '17:00'), // Wednesday
                array('day' => 4, 'start' => '09:00', 'end' => '17:00'), // Thursday
                array('day' => 5, 'start' => '09:00', 'end' => '17:00'), // Friday
            );
        }

        $days_of_week = array(
            0 => __('Sunday', 'ffcertificate'),
            1 => __('Monday', 'ffcertificate'),
            2 => __('Tuesday', 'ffcertificate'),
            3 => __('Wednesday', 'ffcertificate'),
            4 => __('Thursday', 'ffcertificate'),
            5 => __('Friday', 'ffcertificate'),
            6 => __('Saturday', 'ffcertificate'),
        );

        ?>
        <div id="ffc-working-hours-wrapper">
            <p><?php esc_html_e('Define which days and times appointments can be booked:', 'ffcertificate'); ?></p>

            <table class="widefat ffc-working-hours-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Day', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Start Time', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('End Time', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                    </tr>
                </thead>
                <tbody id="ffc-working-hours-list">
                    <?php foreach ($working_hours as $index => $hours): ?>
                        <tr>
                            <td>
                                <select name="ffc_self_scheduling_working_hours[<?php echo esc_attr( $index ); ?>][day]" required>
                                    <?php foreach ($days_of_week as $day_num => $day_name): ?>
                                        <option value="<?php echo esc_attr( $day_num ); ?>" <?php selected($hours['day'], $day_num); ?>><?php echo esc_html($day_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="time" name="ffc_self_scheduling_working_hours[<?php echo esc_attr( $index ); ?>][start]" value="<?php echo esc_attr($hours['start']); ?>" required />
                            </td>
                            <td>
                                <input type="time" name="ffc_self_scheduling_working_hours[<?php echo esc_attr( $index ); ?>][end]" value="<?php echo esc_attr($hours['end']); ?>" required />
                            </td>
                            <td>
                                <button type="button" class="button ffc-remove-hour"><?php esc_html_e('Remove', 'ffcertificate'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" class="button" id="ffc-add-working-hour"><?php esc_html_e('+ Add Working Hours', 'ffcertificate'); ?></button>
            </p>
        </div>
        <?php
    }

    /**
     * Render booking rules metabox
     *
     * @param object $post
     * @return void
     */
    public function render_box_rules(object $post): void {
        $config = get_post_meta($post->ID, '_ffc_self_scheduling_config', true);
        if (!is_array($config)) {
            $config = array();
        }

        $defaults = array(
            'advance_booking_min' => 0,
            'advance_booking_max' => 30,
            'allow_cancellation' => 1,
            'cancellation_min_hours' => 24,
            'minimum_interval_between_bookings' => 24,
            'requires_approval' => 0,
            'visibility' => 'public',
            'scheduling_visibility' => 'public',
            'restrict_viewing_to_hours' => 0,
            'restrict_booking_to_hours' => 0,
        );

        $config = array_merge($defaults, $config);
        ?>
        <table class="form-table">
            <tr>
                <th><label for="advance_booking_min"><?php esc_html_e('Minimum Advance Booking', 'ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="advance_booking_min" name="ffc_self_scheduling_config[advance_booking_min]" value="<?php echo esc_attr($config['advance_booking_min']); ?>" min="0" max="720" /> <?php esc_html_e('hours', 'ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('Minimum time in advance required to book (0 = same day allowed)', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="advance_booking_max"><?php esc_html_e('Maximum Advance Booking', 'ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="advance_booking_max" name="ffc_self_scheduling_config[advance_booking_max]" value="<?php echo esc_attr($config['advance_booking_max']); ?>" min="1" max="365" /> <?php esc_html_e('days', 'ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('How far in advance can users book?', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="allow_cancellation"><?php esc_html_e('Allow User Cancellation', 'ffcertificate'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="allow_cancellation" name="ffc_self_scheduling_config[allow_cancellation]" value="1" <?php checked($config['allow_cancellation'], 1); ?> />
                        <?php esc_html_e('Users can cancel their own appointments', 'ffcertificate'); ?>
                    </label>
                </td>
            </tr>
            <tr class="ffc-cancellation-hours" <?php echo esc_attr( $config['allow_cancellation'] ? '' : 'style="display:none;"' ); ?>>
                <th><label for="cancellation_min_hours"><?php esc_html_e('Cancellation Deadline', 'ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="cancellation_min_hours" name="ffc_self_scheduling_config[cancellation_min_hours]" value="<?php echo esc_attr($config['cancellation_min_hours']); ?>" min="0" max="168" /> <?php esc_html_e('hours before', 'ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('Minimum notice required to cancel (e.g., 24 hours)', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="minimum_interval_between_bookings"><?php esc_html_e('Minimum Interval Between Bookings', 'ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="minimum_interval_between_bookings" name="ffc_self_scheduling_config[minimum_interval_between_bookings]" value="<?php echo esc_attr($config['minimum_interval_between_bookings']); ?>" min="0" max="720" /> <?php esc_html_e('hours', 'ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('Prevent users from booking another appointment within X hours of their last booking (0 = disabled, default: 24 hours)', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="requires_approval"><?php esc_html_e('Require Manual Approval', 'ffcertificate'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="requires_approval" name="ffc_self_scheduling_config[requires_approval]" value="1" <?php checked($config['requires_approval'], 1); ?> />
                        <?php esc_html_e('Admin must manually approve all bookings', 'ffcertificate'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="ffc_visibility"><?php esc_html_e('Visibility', 'ffcertificate'); ?></label></th>
                <td>
                    <select id="ffc_visibility" name="ffc_self_scheduling_config[visibility]">
                        <option value="public" <?php selected($config['visibility'], 'public'); ?>><?php esc_html_e('Public', 'ffcertificate'); ?></option>
                        <option value="private" <?php selected($config['visibility'], 'private'); ?>><?php esc_html_e('Private', 'ffcertificate'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Public: visible to everyone. Private: only visible to logged-in users.', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ffc_scheduling_visibility"><?php esc_html_e('Scheduling', 'ffcertificate'); ?></label></th>
                <td>
                    <select id="ffc_scheduling_visibility" name="ffc_self_scheduling_config[scheduling_visibility]" <?php echo $config['visibility'] === 'private' ? 'disabled' : ''; ?>>
                        <option value="public" <?php selected($config['scheduling_visibility'], 'public'); ?>><?php esc_html_e('Public', 'ffcertificate'); ?></option>
                        <option value="private" <?php selected($config['scheduling_visibility'], 'private'); ?>><?php esc_html_e('Private', 'ffcertificate'); ?></option>
                    </select>
                    <?php if ($config['visibility'] === 'private'): ?>
                        <input type="hidden" name="ffc_self_scheduling_config[scheduling_visibility]" value="private" />
                    <?php endif; ?>
                    <p class="description" id="ffc-scheduling-desc">
                        <?php if ($config['visibility'] === 'private'): ?>
                            <?php esc_html_e('Forced to Private because Visibility is Private.', 'ffcertificate'); ?>
                        <?php else: ?>
                            <?php esc_html_e('Public: anyone can book. Private: only logged-in users can book.', 'ffcertificate'); ?>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="restrict_viewing_to_hours"><?php esc_html_e('Restrict Viewing to Business Hours', 'ffcertificate'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="restrict_viewing_to_hours" name="ffc_self_scheduling_config[restrict_viewing_to_hours]" value="1" <?php checked($config['restrict_viewing_to_hours'], 1); ?> />
                        <?php esc_html_e('Calendar can only be viewed during working hours', 'ffcertificate'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('When enabled, the calendar will only be visible during the configured working hours. Outside those hours, a message will be displayed instead.', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="restrict_booking_to_hours"><?php esc_html_e('Restrict Booking to Business Hours', 'ffcertificate'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="restrict_booking_to_hours" name="ffc_self_scheduling_config[restrict_booking_to_hours]" value="1" <?php checked($config['restrict_booking_to_hours'], 1); ?> />
                        <?php esc_html_e('Bookings can only be made during working hours', 'ffcertificate'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('When enabled, users can view the calendar at any time but can only make bookings during the configured working hours.', 'ffcertificate'); ?></p>
                </td>
            </tr>
        </table>

        <!-- Toggle logic handled by ffc-calendar-editor.js -->
        <?php
    }

    /**
     * Render email configuration metabox
     *
     * @param object $post
     * @return void
     */
    public function render_box_email(object $post): void {
        $email_config = get_post_meta($post->ID, '_ffc_self_scheduling_email_config', true);
        if (!is_array($email_config)) {
            $email_config = array();
        }

        $defaults = array(
            'send_user_confirmation' => 0,
            'send_admin_notification' => 0,
            'send_approval_notification' => 0,
            'send_cancellation_notification' => 0,
            'send_reminder' => 0,
            'reminder_hours_before' => 24,
            'admin_emails' => '',
            'user_confirmation_subject' => __('Appointment Confirmation - {{calendar_title}}', 'ffcertificate'),
            'user_confirmation_body' => __("Hello {{user_name}},\n\nYour appointment has been scheduled:\n\nCalendar: {{calendar_title}}\nDate: {{appointment_date}}\nTime: {{appointment_time}}\n\nThank you!", 'ffcertificate'),
        );

        $email_config = array_merge($defaults, $email_config);

        ?>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Notifications', 'ffcertificate'); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_self_scheduling_email_config[send_user_confirmation]" value="1" <?php checked($email_config['send_user_confirmation'], 1); ?> />
                            <?php esc_html_e('Send confirmation email to user', 'ffcertificate'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_self_scheduling_email_config[send_admin_notification]" value="1" <?php checked($email_config['send_admin_notification'], 1); ?> />
                            <?php esc_html_e('Send notification to admin on new booking', 'ffcertificate'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_self_scheduling_email_config[send_approval_notification]" value="1" <?php checked($email_config['send_approval_notification'], 1); ?> />
                            <?php esc_html_e('Send notification when booking is approved', 'ffcertificate'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_self_scheduling_email_config[send_cancellation_notification]" value="1" <?php checked($email_config['send_cancellation_notification'], 1); ?> />
                            <?php esc_html_e('Send notification when booking is cancelled', 'ffcertificate'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_self_scheduling_email_config[send_reminder]" value="1" <?php checked($email_config['send_reminder'], 1); ?> />
                            <?php esc_html_e('Send reminder before appointment', 'ffcertificate'); ?>
                        </label>
                    </fieldset>
                    <p class="description"><?php esc_html_e('Default: All notifications disabled', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="reminder_hours_before"><?php esc_html_e('Reminder Timing', 'ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="reminder_hours_before" name="ffc_self_scheduling_email_config[reminder_hours_before]" value="<?php echo esc_attr($email_config['reminder_hours_before']); ?>" min="1" max="168" /> <?php esc_html_e('hours before appointment', 'ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <th><label for="admin_emails"><?php esc_html_e('Admin Email Addresses', 'ffcertificate'); ?></label></th>
                <td>
                    <input type="text" id="admin_emails" name="ffc_self_scheduling_email_config[admin_emails]" value="<?php echo esc_attr($email_config['admin_emails']); ?>" class="large-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" />
                    <p class="description"><?php esc_html_e('Comma-separated email addresses for admin notifications (leave empty to use site admin email)', 'ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="user_confirmation_subject"><?php esc_html_e('Confirmation Email Subject', 'ffcertificate'); ?></label></th>
                <td>
                    <input type="text" id="user_confirmation_subject" name="ffc_self_scheduling_email_config[user_confirmation_subject]" value="<?php echo esc_attr($email_config['user_confirmation_subject']); ?>" class="large-text" />
                </td>
            </tr>
            <tr>
                <th><label for="user_confirmation_body"><?php esc_html_e('Confirmation Email Body', 'ffcertificate'); ?></label></th>
                <td>
                    <textarea id="user_confirmation_body" name="ffc_self_scheduling_email_config[user_confirmation_body]" rows="10" class="large-text"><?php echo esc_textarea($email_config['user_confirmation_body']); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Available variables:', 'ffcertificate'); ?>
                        <code>{{user_name}}</code>,
                        <code>{{user_email}}</code>,
                        <code>{{calendar_title}}</code>,
                        <code>{{appointment_date}}</code>,
                        <code>{{appointment_time}}</code>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render shortcode metabox (sidebar)
     *
     * @param object $post
     * @return void
     */
    public function render_shortcode_metabox(object $post): void {
        ?>
        <div class="ffc-shortcode-box">
            <p><strong><?php esc_html_e('Use this shortcode to display the calendar:', 'ffcertificate'); ?></strong></p>

            <?php if ($post->post_status === 'publish'): ?>
                <input type="text" readonly value='[ffc_self_scheduling id="<?php echo esc_attr( (string) $post->ID ); ?>"]' onclick="this.select();" style="width: 100%; padding: 6px; font-family: monospace; background: #f0f0f1;" />

                <p style="margin-top: 15px;"><strong><?php esc_html_e('Preview:', 'ffcertificate'); ?></strong></p>
                <p><a href="<?php echo esc_url( add_query_arg('calendar_preview', $post->ID, home_url('/')) ); ?>" target="_blank" class="button button-secondary"><?php esc_html_e('Preview Calendar', 'ffcertificate'); ?></a></p>
            <?php else: ?>
                <p class="description"><?php esc_html_e('Publish this calendar to generate the shortcode.', 'ffcertificate'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Display save errors
     *
     * @return void
     */
    public function display_save_errors(): void {
        // Placeholder for error display
        // Can be expanded as needed
    }
}
