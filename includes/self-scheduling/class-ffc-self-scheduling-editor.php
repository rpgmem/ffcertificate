<?php
declare(strict_types=1);

/**
 * Self-Scheduling Editor
 *
 * Handles the advanced UI for Calendar Builder, including metaboxes and configuration.
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class SelfSchedulingEditor {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_custom_metaboxes'), 20);
        add_action('save_post_ffc_self_scheduling', array($this, 'save_calendar_data'), 10, 3);
        add_action('admin_notices', array($this, 'display_save_errors'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX handler for appointment cleanup
        add_action('wp_ajax_ffc_cleanup_appointments', array($this, 'handle_cleanup_appointments'));
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

        wp_enqueue_script(
            'ffc-calendar-editor',
            FFC_PLUGIN_URL . 'assets/js/calendar-editor.js',
            array('jquery', 'jquery-ui-sortable'),
            FFC_VERSION,
            true
        );

        wp_enqueue_style(
            'ffc-calendar-editor',
            FFC_PLUGIN_URL . 'assets/css/calendar-editor.css',
            array(),
            FFC_VERSION
        );

        wp_localize_script('ffc-calendar-editor', 'ffcSelfSchedulingEditor', array(
            'nonce' => wp_create_nonce('ffc_self_scheduling_editor_nonce'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this?', 'wp-ffcertificate'),
                'addWorkingHour' => __('Add Working Hours', 'wp-ffcertificate'),
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
            __('1. Calendar Configuration', 'wp-ffcertificate'),
            array($this, 'render_box_config'),
            'ffc_self_scheduling',
            'normal',
            'high'
        );

        // Working hours
        add_meta_box(
            'ffc_self_scheduling_box_hours',
            __('2. Working Hours & Availability', 'wp-ffcertificate'),
            array($this, 'render_box_hours'),
            'ffc_self_scheduling',
            'normal',
            'high'
        );

        // Booking rules
        add_meta_box(
            'ffc_self_scheduling_box_rules',
            __('3. Booking Rules & Restrictions', 'wp-ffcertificate'),
            array($this, 'render_box_rules'),
            'ffc_self_scheduling',
            'normal',
            'high'
        );

        // Email notifications
        add_meta_box(
            'ffc_self_scheduling_box_email',
            __('4. Email Notifications', 'wp-ffcertificate'),
            array($this, 'render_box_email'),
            'ffc_self_scheduling',
            'normal',
            'high'
        );

        // Shortcode (sidebar)
        add_meta_box(
            'ffc_self_scheduling_shortcode',
            __('How to Use / Shortcode', 'wp-ffcertificate'),
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
                __('Clean Up Appointments', 'wp-ffcertificate'),
                array($this, 'render_cleanup_metabox'),
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
                <th><label for="calendar_description"><?php esc_html_e('Description', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <textarea id="calendar_description" name="ffc_self_scheduling_config[description]" rows="3" class="large-text"><?php echo esc_textarea($config['description']); ?></textarea>
                    <p class="description"><?php esc_html_e('Brief description of this calendar (optional)', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="slot_duration"><?php esc_html_e('Appointment Duration', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="slot_duration" name="ffc_self_scheduling_config[slot_duration]" value="<?php echo esc_attr($config['slot_duration']); ?>" min="5" max="480" step="5" /> <?php esc_html_e('minutes', 'wp-ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('Duration of each appointment slot', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="slot_interval"><?php esc_html_e('Break Between Appointments', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="slot_interval" name="ffc_self_scheduling_config[slot_interval]" value="<?php echo esc_attr($config['slot_interval']); ?>" min="0" max="120" step="5" /> <?php esc_html_e('minutes', 'wp-ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('Buffer time between appointments (0 = no break)', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="max_appointments_per_slot"><?php esc_html_e('Max Bookings Per Slot', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="max_appointments_per_slot" name="ffc_self_scheduling_config[max_appointments_per_slot]" value="<?php echo esc_attr($config['max_appointments_per_slot']); ?>" min="1" max="100" />
                    <p class="description"><?php esc_html_e('Maximum number of people per time slot (1 = exclusive)', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="slots_per_day"><?php esc_html_e('Daily Booking Limit', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="slots_per_day" name="ffc_self_scheduling_config[slots_per_day]" value="<?php echo esc_attr($config['slots_per_day']); ?>" min="0" max="200" />
                    <p class="description"><?php esc_html_e('Maximum appointments per day (0 = unlimited)', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="calendar_status"><?php esc_html_e('Status', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <select id="calendar_status" name="ffc_self_scheduling_config[status]">
                        <option value="active" <?php selected($config['status'], 'active'); ?>><?php esc_html_e('Active', 'wp-ffcertificate'); ?></option>
                        <option value="inactive" <?php selected($config['status'], 'inactive'); ?>><?php esc_html_e('Inactive', 'wp-ffcertificate'); ?></option>
                        <option value="archived" <?php selected($config['status'], 'archived'); ?>><?php esc_html_e('Archived', 'wp-ffcertificate'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Calendar status (inactive = no new bookings allowed)', 'wp-ffcertificate'); ?></p>
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
            0 => __('Sunday', 'wp-ffcertificate'),
            1 => __('Monday', 'wp-ffcertificate'),
            2 => __('Tuesday', 'wp-ffcertificate'),
            3 => __('Wednesday', 'wp-ffcertificate'),
            4 => __('Thursday', 'wp-ffcertificate'),
            5 => __('Friday', 'wp-ffcertificate'),
            6 => __('Saturday', 'wp-ffcertificate'),
        );

        ?>
        <div id="ffc-working-hours-wrapper">
            <p><?php esc_html_e('Define which days and times appointments can be booked:', 'wp-ffcertificate'); ?></p>

            <table class="widefat ffc-working-hours-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Day', 'wp-ffcertificate'); ?></th>
                        <th><?php esc_html_e('Start Time', 'wp-ffcertificate'); ?></th>
                        <th><?php esc_html_e('End Time', 'wp-ffcertificate'); ?></th>
                        <th><?php esc_html_e('Actions', 'wp-ffcertificate'); ?></th>
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
                                <button type="button" class="button ffc-remove-hour"><?php esc_html_e('Remove', 'wp-ffcertificate'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" class="button" id="ffc-add-working-hour"><?php esc_html_e('+ Add Working Hours', 'wp-ffcertificate'); ?></button>
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
            'require_login' => 0,
            'allowed_roles' => array()
        );

        $config = array_merge($defaults, $config);

        $roles = wp_roles()->get_names();
        ?>
        <table class="form-table">
            <tr>
                <th><label for="advance_booking_min"><?php esc_html_e('Minimum Advance Booking', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="advance_booking_min" name="ffc_self_scheduling_config[advance_booking_min]" value="<?php echo esc_attr($config['advance_booking_min']); ?>" min="0" max="720" /> <?php esc_html_e('hours', 'wp-ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('Minimum time in advance required to book (0 = same day allowed)', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="advance_booking_max"><?php esc_html_e('Maximum Advance Booking', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="advance_booking_max" name="ffc_self_scheduling_config[advance_booking_max]" value="<?php echo esc_attr($config['advance_booking_max']); ?>" min="1" max="365" /> <?php esc_html_e('days', 'wp-ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('How far in advance can users book?', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="allow_cancellation"><?php esc_html_e('Allow User Cancellation', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="allow_cancellation" name="ffc_self_scheduling_config[allow_cancellation]" value="1" <?php checked($config['allow_cancellation'], 1); ?> />
                        <?php esc_html_e('Users can cancel their own appointments', 'wp-ffcertificate'); ?>
                    </label>
                </td>
            </tr>
            <tr class="ffc-cancellation-hours" <?php echo esc_attr( $config['allow_cancellation'] ? '' : 'style="display:none;"' ); ?>>
                <th><label for="cancellation_min_hours"><?php esc_html_e('Cancellation Deadline', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="cancellation_min_hours" name="ffc_self_scheduling_config[cancellation_min_hours]" value="<?php echo esc_attr($config['cancellation_min_hours']); ?>" min="0" max="168" /> <?php esc_html_e('hours before', 'wp-ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('Minimum notice required to cancel (e.g., 24 hours)', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="minimum_interval_between_bookings"><?php esc_html_e('Minimum Interval Between Bookings', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="minimum_interval_between_bookings" name="ffc_self_scheduling_config[minimum_interval_between_bookings]" value="<?php echo esc_attr($config['minimum_interval_between_bookings']); ?>" min="0" max="720" /> <?php esc_html_e('hours', 'wp-ffcertificate'); ?>
                    <p class="description"><?php esc_html_e('Prevent users from booking another appointment within X hours of their last booking (0 = disabled, default: 24 hours)', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="requires_approval"><?php esc_html_e('Require Manual Approval', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="requires_approval" name="ffc_self_scheduling_config[requires_approval]" value="1" <?php checked($config['requires_approval'], 1); ?> />
                        <?php esc_html_e('Admin must manually approve all bookings', 'wp-ffcertificate'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="require_login"><?php esc_html_e('Require User Login', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="require_login" name="ffc_self_scheduling_config[require_login]" value="1" <?php checked($config['require_login'], 1); ?> />
                        <?php esc_html_e('Only logged-in users can book', 'wp-ffcertificate'); ?>
                    </label>
                </td>
            </tr>
            <tr class="ffc-allowed-roles" <?php echo esc_attr( $config['require_login'] ? '' : 'style="display:none;"' ); ?>>
                <th><label><?php esc_html_e('Allowed Roles', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <?php foreach ($roles as $role_key => $role_name): ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="ffc_self_scheduling_config[allowed_roles][]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $config['allowed_roles'])); ?> />
                            <?php echo esc_html($role_name); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php esc_html_e('Leave empty to allow all logged-in users', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
        </table>

        <script>
        jQuery(document).ready(function($) {
            $('#allow_cancellation').on('change', function() {
                $('.ffc-cancellation-hours').toggle(this.checked);
            });
            $('#require_login').on('change', function() {
                $('.ffc-allowed-roles').toggle(this.checked);
            });
        });
        </script>
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
            'user_confirmation_subject' => __('Appointment Confirmation - {{calendar_title}}', 'wp-ffcertificate'),
            'user_confirmation_body' => __("Hello {{user_name}},\n\nYour appointment has been scheduled:\n\nCalendar: {{calendar_title}}\nDate: {{appointment_date}}\nTime: {{appointment_time}}\n\nThank you!", 'wp-ffcertificate'),
        );

        $email_config = array_merge($defaults, $email_config);

        ?>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Notifications', 'wp-ffcertificate'); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_self_scheduling_email_config[send_user_confirmation]" value="1" <?php checked($email_config['send_user_confirmation'], 1); ?> />
                            <?php esc_html_e('Send confirmation email to user', 'wp-ffcertificate'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_self_scheduling_email_config[send_admin_notification]" value="1" <?php checked($email_config['send_admin_notification'], 1); ?> />
                            <?php esc_html_e('Send notification to admin on new booking', 'wp-ffcertificate'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_self_scheduling_email_config[send_approval_notification]" value="1" <?php checked($email_config['send_approval_notification'], 1); ?> />
                            <?php esc_html_e('Send notification when booking is approved', 'wp-ffcertificate'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_self_scheduling_email_config[send_cancellation_notification]" value="1" <?php checked($email_config['send_cancellation_notification'], 1); ?> />
                            <?php esc_html_e('Send notification when booking is cancelled', 'wp-ffcertificate'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_self_scheduling_email_config[send_reminder]" value="1" <?php checked($email_config['send_reminder'], 1); ?> />
                            <?php esc_html_e('Send reminder before appointment', 'wp-ffcertificate'); ?>
                        </label>
                    </fieldset>
                    <p class="description"><?php esc_html_e('Default: All notifications disabled', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="reminder_hours_before"><?php esc_html_e('Reminder Timing', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <input type="number" id="reminder_hours_before" name="ffc_self_scheduling_email_config[reminder_hours_before]" value="<?php echo esc_attr($email_config['reminder_hours_before']); ?>" min="1" max="168" /> <?php esc_html_e('hours before appointment', 'wp-ffcertificate'); ?>
                </td>
            </tr>
            <tr>
                <th><label for="admin_emails"><?php esc_html_e('Admin Email Addresses', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <input type="text" id="admin_emails" name="ffc_self_scheduling_email_config[admin_emails]" value="<?php echo esc_attr($email_config['admin_emails']); ?>" class="large-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" />
                    <p class="description"><?php esc_html_e('Comma-separated email addresses for admin notifications (leave empty to use site admin email)', 'wp-ffcertificate'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="user_confirmation_subject"><?php esc_html_e('Confirmation Email Subject', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <input type="text" id="user_confirmation_subject" name="ffc_self_scheduling_email_config[user_confirmation_subject]" value="<?php echo esc_attr($email_config['user_confirmation_subject']); ?>" class="large-text" />
                </td>
            </tr>
            <tr>
                <th><label for="user_confirmation_body"><?php esc_html_e('Confirmation Email Body', 'wp-ffcertificate'); ?></label></th>
                <td>
                    <textarea id="user_confirmation_body" name="ffc_self_scheduling_email_config[user_confirmation_body]" rows="10" class="large-text"><?php echo esc_textarea($email_config['user_confirmation_body']); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Available variables:', 'wp-ffcertificate'); ?>
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
            <p><strong><?php esc_html_e('Use this shortcode to display the calendar:', 'wp-ffcertificate'); ?></strong></p>

            <?php if ($post->post_status === 'publish'): ?>
                <input type="text" readonly value='[ffc_self_scheduling id="<?php echo esc_attr( $post->ID ); ?>"]' onclick="this.select();" style="width: 100%; padding: 6px; font-family: monospace; background: #f0f0f1;" />

                <p style="margin-top: 15px;"><strong><?php esc_html_e('Preview:', 'wp-ffcertificate'); ?></strong></p>
                <p><a href="<?php echo esc_url( add_query_arg('calendar_preview', $post->ID, home_url('/')) ); ?>" target="_blank" class="button button-secondary"><?php esc_html_e('Preview Calendar', 'wp-ffcertificate'); ?></a></p>
            <?php else: ?>
                <p class="description"><?php esc_html_e('Publish this calendar to generate the shortcode.', 'wp-ffcertificate'); ?></p>
            <?php endif; ?>
        </div>
        <?php
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

        // Save configuration
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() check only; value unslashed below.
        if (isset($_POST['ffc_self_scheduling_config'])) {
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
            $config['require_login'] = isset($config['require_login']) ? 1 : 0;
            $config['status'] = sanitize_text_field($config['status'] ?? 'active');

            // Sanitize allowed roles
            if (isset($config['allowed_roles']) && is_array($config['allowed_roles'])) {
                $config['allowed_roles'] = array_map('sanitize_text_field', $config['allowed_roles']);
            } else {
                $config['allowed_roles'] = array();
            }

            update_post_meta($post_id, '_ffc_self_scheduling_config', $config);
        }

        // Save working hours
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset()/is_array() check only; value unslashed below.
        if (isset($_POST['ffc_self_scheduling_working_hours']) && is_array($_POST['ffc_self_scheduling_working_hours'])) {
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

        // Save email configuration
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() check only; value unslashed below.
        if (isset($_POST['ffc_self_scheduling_email_config'])) {
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

    /**
     * Handle appointment cleanup AJAX request
     *
     * @return void
     */
    public function handle_cleanup_appointments(): void {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ffc_cleanup_appointments_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed', 'wp-ffcertificate')
            ));
            return;
        }

        // Verify permissions
        if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to perform this action', 'wp-ffcertificate')
            ));
            return;
        }

        // Get parameters
        $calendar_id = isset($_POST['calendar_id']) ? absint(wp_unslash($_POST['calendar_id'])) : 0;
        $cleanup_action = isset($_POST['cleanup_action']) ? sanitize_text_field(wp_unslash($_POST['cleanup_action'])) : '';

        if (!$calendar_id || !$cleanup_action) {
            wp_send_json_error(array(
                'message' => __('Invalid parameters', 'wp-ffcertificate')
            ));
            return;
        }

        // Verify calendar exists
        $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
        $calendar = $calendar_repository->findById($calendar_id);

        if (!$calendar) {
            wp_send_json_error(array(
                'message' => __('Calendar not found', 'wp-ffcertificate')
            ));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        $today = current_time('Y-m-d');

        $deleted = 0;

        // Build query based on action
        switch ($cleanup_action) {
            case 'all':
                // Delete all appointments for this calendar
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $deleted = $wpdb->delete($table, ['calendar_id' => $calendar_id], ['%d']);
                $message = sprintf(
                    /* translators: %d: number of deleted appointments */
                    __('Successfully deleted %d appointment(s).', 'wp-ffcertificate'),
                    $deleted
                );
                break;

            case 'old':
                // Delete appointments before today
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$table} WHERE calendar_id = %d AND appointment_date < %s",
                    $calendar_id,
                    $today
                ));
                $message = sprintf(
                    /* translators: %d: number of deleted past appointments */
                    __('Successfully deleted %d past appointment(s).', 'wp-ffcertificate'),
                    $deleted
                );
                break;

            case 'future':
                // Delete appointments today and after
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$table} WHERE calendar_id = %d AND appointment_date >= %s",
                    $calendar_id,
                    $today
                ));
                $message = sprintf(
                    /* translators: %d: number of deleted future appointments */
                    __('Successfully deleted %d future appointment(s).', 'wp-ffcertificate'),
                    $deleted
                );
                break;

            case 'cancelled':
                // Delete only cancelled appointments
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $deleted = $wpdb->delete($table, [
                    'calendar_id' => $calendar_id,
                    'status' => 'cancelled'
                ], ['%d', '%s']);
                $message = sprintf(
                    /* translators: %d: number of deleted cancelled appointments */
                    __('Successfully deleted %d cancelled appointment(s).', 'wp-ffcertificate'),
                    $deleted
                );
                break;

            default:
                wp_send_json_error(array(
                    'message' => __('Invalid cleanup action', 'wp-ffcertificate')
                ));
                return;
        }

        // Log the action
        \FreeFormCertificate\Core\Utils::debug_log('Appointments cleaned up', array(
            'calendar_id' => $calendar_id,
            'calendar_title' => $calendar['title'],
            'action' => $cleanup_action,
            'deleted_count' => $deleted,
            'user_id' => get_current_user_id()
        ));

        wp_send_json_success(array(
            'message' => $message,
            'deleted' => $deleted
        ));
    }

    /**
     * Render cleanup appointments metabox
     *
     * Allows admins to bulk delete appointments based on criteria:
     * - All appointments
     * - Old/past appointments
     * - Future appointments
     * - Cancelled appointments
     *
     * @param object $post
     * @return void
     */
    public function render_cleanup_metabox(object $post): void {
        // Get calendar ID from database
        $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
        $calendar = $calendar_repository->findByPostId($post->ID);

        if (!$calendar) {
            echo '<p>' . esc_html__('Calendar not found in database. Save the calendar first.', 'wp-ffcertificate') . '</p>';
            return;
        }

        $calendar_id = (int) $calendar['id'];
        $appointment_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();

        // Count appointments by category
        $today = current_time('Y-m-d');

        $count_all = $appointment_repo->count(['calendar_id' => $calendar_id]);

        // Count old appointments (before today)
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count_old = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE calendar_id = %d AND appointment_date < %s",
            $calendar_id,
            $today
        ));

        // Count future appointments (today and after)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count_future = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE calendar_id = %d AND appointment_date >= %s",
            $calendar_id,
            $today
        ));

        // Count cancelled appointments
        $count_cancelled = $appointment_repo->count([
            'calendar_id' => $calendar_id,
            'status' => 'cancelled'
        ]);

        wp_nonce_field('ffc_cleanup_appointments_nonce', 'ffc_cleanup_appointments_nonce');
        ?>
        <div class="ffc-cleanup-appointments">
            <p class="description">
                <?php esc_html_e('Permanently delete appointments from this calendar. This action cannot be undone.', 'wp-ffcertificate'); ?>
            </p>

            <div class="ffc-cleanup-stats" style="margin: 15px 0;">
                <table class="widefat" style="border: none;">
                    <tr>
                        <td><strong><?php esc_html_e('Total:', 'wp-ffcertificate'); ?></strong></td>
                        <td><?php echo esc_html($count_all); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Past:', 'wp-ffcertificate'); ?></strong></td>
                        <td><?php echo esc_html($count_old); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Future:', 'wp-ffcertificate'); ?></strong></td>
                        <td><?php echo esc_html($count_future); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Cancelled:', 'wp-ffcertificate'); ?></strong></td>
                        <td><?php echo esc_html($count_cancelled); ?></td>
                    </tr>
                </table>
            </div>

            <?php if ($count_all > 0) : ?>
                <div class="ffc-cleanup-actions">
                    <p><strong><?php esc_html_e('Delete appointments:', 'wp-ffcertificate'); ?></strong></p>

                    <?php if ($count_cancelled > 0) : ?>
                        <button type="button"
                                class="button ffc-cleanup-btn"
                                data-action="cancelled"
                                data-calendar-id="<?php echo esc_attr($calendar_id); ?>"
                                style="width: 100%; margin-bottom: 5px;">
                            🗑️ <?php
                            /* translators: %d: number of cancelled appointments */
                            printf(esc_html__('Cancelled (%d)', 'wp-ffcertificate'), intval($count_cancelled)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printf with esc_html__ and %d integer format ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($count_old > 0) : ?>
                        <button type="button"
                                class="button ffc-cleanup-btn"
                                data-action="old"
                                data-calendar-id="<?php echo esc_attr($calendar_id); ?>"
                                style="width: 100%; margin-bottom: 5px;">
                            📅 <?php
                            /* translators: %d: number of past appointments */
                            printf(esc_html__('Past (%d)', 'wp-ffcertificate'), intval($count_old)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printf with esc_html__ and %d integer format ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($count_future > 0) : ?>
                        <button type="button"
                                class="button ffc-cleanup-btn"
                                data-action="future"
                                data-calendar-id="<?php echo esc_attr($calendar_id); ?>"
                                style="width: 100%; margin-bottom: 5px;">
                            ⏭️ <?php
                            /* translators: %d: number of future appointments */
                            printf(esc_html__('Future (%d)', 'wp-ffcertificate'), intval($count_future)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printf with esc_html__ and %d integer format ?>
                        </button>
                    <?php endif; ?>

                    <button type="button"
                            class="button button-link-delete ffc-cleanup-btn"
                            data-action="all"
                            data-calendar-id="<?php echo esc_attr($calendar_id); ?>"
                            style="width: 100%; margin-top: 10px;">
                        <?php
                        /* translators: %d: total number of appointments */
                        printf(esc_html__('All Appointments (%d)', 'wp-ffcertificate'), intval($count_all)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printf with esc_html__ and %d integer format
                        ?>
                    </button>
                </div>

                <p class="description" style="margin-top: 10px; color: #d63638;">
                    ⚠️ <?php esc_html_e('Warning: This action is permanent and cannot be undone!', 'wp-ffcertificate'); ?>
                </p>
            <?php else : ?>
                <p><?php esc_html_e('No appointments to clean up.', 'wp-ffcertificate'); ?></p>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.ffc-cleanup-btn').on('click', function() {
                const $btn = $(this);
                const action = $btn.data('action');
                const calendarId = $btn.data('calendar-id');

                let confirmMessage = '<?php esc_html_e('Are you sure you want to delete these appointments? This action cannot be undone.', 'wp-ffcertificate'); ?>';

                if (action === 'all') {
                    confirmMessage = '<?php esc_html_e('Are you sure you want to delete ALL appointments? This will permanently remove all appointment data and cannot be undone!', 'wp-ffcertificate'); ?>';
                }

                if (!confirm(confirmMessage)) {
                    return;
                }

                $btn.prop('disabled', true).text('<?php esc_html_e('Deleting...', 'wp-ffcertificate'); ?>');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'ffc_cleanup_appointments',
                        calendar_id: calendarId,
                        cleanup_action: action,
                        nonce: $('#ffc_cleanup_appointments_nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_html_e('Error deleting appointments', 'wp-ffcertificate'); ?>');
                            $btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('<?php esc_html_e('Error communicating with server', 'wp-ffcertificate'); ?>');
                        $btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
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
