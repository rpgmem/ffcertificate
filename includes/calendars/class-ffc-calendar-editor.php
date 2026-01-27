<?php
declare(strict_types=1);

/**
 * Calendar Editor
 *
 * Handles the advanced UI for Calendar Builder, including metaboxes and configuration.
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\Calendars;

if (!defined('ABSPATH')) exit;

class CalendarEditor {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_custom_metaboxes'), 20);
        add_action('save_post_ffc_calendar', array($this, 'save_calendar_data'), 10, 3);
        add_action('admin_notices', array($this, 'display_save_errors'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
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
        if (!$screen || $screen->post_type !== 'ffc_calendar') {
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

        wp_localize_script('ffc-calendar-editor', 'ffcCalendarEditor', array(
            'nonce' => wp_create_nonce('ffc_calendar_editor_nonce'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this?', 'ffc'),
                'addWorkingHour' => __('Add Working Hours', 'ffc'),
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
            'ffc_calendar_box_config',
            __('1. Calendar Configuration', 'ffc'),
            array($this, 'render_box_config'),
            'ffc_calendar',
            'normal',
            'high'
        );

        // Working hours
        add_meta_box(
            'ffc_calendar_box_hours',
            __('2. Working Hours & Availability', 'ffc'),
            array($this, 'render_box_hours'),
            'ffc_calendar',
            'normal',
            'high'
        );

        // Booking rules
        add_meta_box(
            'ffc_calendar_box_rules',
            __('3. Booking Rules & Restrictions', 'ffc'),
            array($this, 'render_box_rules'),
            'ffc_calendar',
            'normal',
            'high'
        );

        // Email notifications
        add_meta_box(
            'ffc_calendar_box_email',
            __('4. Email Notifications', 'ffc'),
            array($this, 'render_box_email'),
            'ffc_calendar',
            'normal',
            'high'
        );

        // Shortcode (sidebar)
        add_meta_box(
            'ffc_calendar_shortcode',
            __('How to Use / Shortcode', 'ffc'),
            array($this, 'render_shortcode_metabox'),
            'ffc_calendar',
            'side',
            'high'
        );
    }

    /**
     * Render calendar configuration metabox
     *
     * @param object $post
     * @return void
     */
    public function render_box_config(object $post): void {
        $config = get_post_meta($post->ID, '_ffc_calendar_config', true);
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

        wp_nonce_field('ffc_calendar_config_nonce', 'ffc_calendar_config_nonce');
        ?>
        <table class="form-table">
            <tr>
                <th><label for="calendar_description"><?php _e('Description', 'ffc'); ?></label></th>
                <td>
                    <textarea id="calendar_description" name="ffc_calendar_config[description]" rows="3" class="large-text"><?php echo esc_textarea($config['description']); ?></textarea>
                    <p class="description"><?php _e('Brief description of this calendar (optional)', 'ffc'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="slot_duration"><?php _e('Appointment Duration', 'ffc'); ?></label></th>
                <td>
                    <input type="number" id="slot_duration" name="ffc_calendar_config[slot_duration]" value="<?php echo esc_attr($config['slot_duration']); ?>" min="5" max="480" step="5" /> <?php _e('minutes', 'ffc'); ?>
                    <p class="description"><?php _e('Duration of each appointment slot', 'ffc'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="slot_interval"><?php _e('Break Between Appointments', 'ffc'); ?></label></th>
                <td>
                    <input type="number" id="slot_interval" name="ffc_calendar_config[slot_interval]" value="<?php echo esc_attr($config['slot_interval']); ?>" min="0" max="120" step="5" /> <?php _e('minutes', 'ffc'); ?>
                    <p class="description"><?php _e('Buffer time between appointments (0 = no break)', 'ffc'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="max_appointments_per_slot"><?php _e('Max Bookings Per Slot', 'ffc'); ?></label></th>
                <td>
                    <input type="number" id="max_appointments_per_slot" name="ffc_calendar_config[max_appointments_per_slot]" value="<?php echo esc_attr($config['max_appointments_per_slot']); ?>" min="1" max="100" />
                    <p class="description"><?php _e('Maximum number of people per time slot (1 = exclusive)', 'ffc'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="slots_per_day"><?php _e('Daily Booking Limit', 'ffc'); ?></label></th>
                <td>
                    <input type="number" id="slots_per_day" name="ffc_calendar_config[slots_per_day]" value="<?php echo esc_attr($config['slots_per_day']); ?>" min="0" max="200" />
                    <p class="description"><?php _e('Maximum appointments per day (0 = unlimited)', 'ffc'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="calendar_status"><?php _e('Status', 'ffc'); ?></label></th>
                <td>
                    <select id="calendar_status" name="ffc_calendar_config[status]">
                        <option value="active" <?php selected($config['status'], 'active'); ?>><?php _e('Active', 'ffc'); ?></option>
                        <option value="inactive" <?php selected($config['status'], 'inactive'); ?>><?php _e('Inactive', 'ffc'); ?></option>
                        <option value="archived" <?php selected($config['status'], 'archived'); ?>><?php _e('Archived', 'ffc'); ?></option>
                    </select>
                    <p class="description"><?php _e('Calendar status (inactive = no new bookings allowed)', 'ffc'); ?></p>
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
        $working_hours = get_post_meta($post->ID, '_ffc_calendar_working_hours', true);
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
            0 => __('Sunday', 'ffc'),
            1 => __('Monday', 'ffc'),
            2 => __('Tuesday', 'ffc'),
            3 => __('Wednesday', 'ffc'),
            4 => __('Thursday', 'ffc'),
            5 => __('Friday', 'ffc'),
            6 => __('Saturday', 'ffc'),
        );

        ?>
        <div id="ffc-working-hours-wrapper">
            <p><?php _e('Define which days and times appointments can be booked:', 'ffc'); ?></p>

            <table class="widefat ffc-working-hours-table">
                <thead>
                    <tr>
                        <th><?php _e('Day', 'ffc'); ?></th>
                        <th><?php _e('Start Time', 'ffc'); ?></th>
                        <th><?php _e('End Time', 'ffc'); ?></th>
                        <th><?php _e('Actions', 'ffc'); ?></th>
                    </tr>
                </thead>
                <tbody id="ffc-working-hours-list">
                    <?php foreach ($working_hours as $index => $hours): ?>
                        <tr>
                            <td>
                                <select name="ffc_calendar_working_hours[<?php echo $index; ?>][day]" required>
                                    <?php foreach ($days_of_week as $day_num => $day_name): ?>
                                        <option value="<?php echo $day_num; ?>" <?php selected($hours['day'], $day_num); ?>><?php echo esc_html($day_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="time" name="ffc_calendar_working_hours[<?php echo $index; ?>][start]" value="<?php echo esc_attr($hours['start']); ?>" required />
                            </td>
                            <td>
                                <input type="time" name="ffc_calendar_working_hours[<?php echo $index; ?>][end]" value="<?php echo esc_attr($hours['end']); ?>" required />
                            </td>
                            <td>
                                <button type="button" class="button ffc-remove-hour"><?php _e('Remove', 'ffc'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" class="button" id="ffc-add-working-hour"><?php _e('+ Add Working Hours', 'ffc'); ?></button>
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
        $config = get_post_meta($post->ID, '_ffc_calendar_config', true);
        if (!is_array($config)) {
            $config = array();
        }

        $defaults = array(
            'advance_booking_min' => 0,
            'advance_booking_max' => 30,
            'allow_cancellation' => 1,
            'cancellation_min_hours' => 24,
            'requires_approval' => 0,
            'require_login' => 0,
            'allowed_roles' => array()
        );

        $config = array_merge($defaults, $config);

        $roles = wp_roles()->get_names();
        ?>
        <table class="form-table">
            <tr>
                <th><label for="advance_booking_min"><?php _e('Minimum Advance Booking', 'ffc'); ?></label></th>
                <td>
                    <input type="number" id="advance_booking_min" name="ffc_calendar_config[advance_booking_min]" value="<?php echo esc_attr($config['advance_booking_min']); ?>" min="0" max="720" /> <?php _e('hours', 'ffc'); ?>
                    <p class="description"><?php _e('Minimum time in advance required to book (0 = same day allowed)', 'ffc'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="advance_booking_max"><?php _e('Maximum Advance Booking', 'ffc'); ?></label></th>
                <td>
                    <input type="number" id="advance_booking_max" name="ffc_calendar_config[advance_booking_max]" value="<?php echo esc_attr($config['advance_booking_max']); ?>" min="1" max="365" /> <?php _e('days', 'ffc'); ?>
                    <p class="description"><?php _e('How far in advance can users book?', 'ffc'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="allow_cancellation"><?php _e('Allow User Cancellation', 'ffc'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="allow_cancellation" name="ffc_calendar_config[allow_cancellation]" value="1" <?php checked($config['allow_cancellation'], 1); ?> />
                        <?php _e('Users can cancel their own appointments', 'ffc'); ?>
                    </label>
                </td>
            </tr>
            <tr class="ffc-cancellation-hours" <?php echo $config['allow_cancellation'] ? '' : 'style="display:none;"'; ?>>
                <th><label for="cancellation_min_hours"><?php _e('Cancellation Deadline', 'ffc'); ?></label></th>
                <td>
                    <input type="number" id="cancellation_min_hours" name="ffc_calendar_config[cancellation_min_hours]" value="<?php echo esc_attr($config['cancellation_min_hours']); ?>" min="0" max="168" /> <?php _e('hours before', 'ffc'); ?>
                    <p class="description"><?php _e('Minimum notice required to cancel (e.g., 24 hours)', 'ffc'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="requires_approval"><?php _e('Require Manual Approval', 'ffc'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="requires_approval" name="ffc_calendar_config[requires_approval]" value="1" <?php checked($config['requires_approval'], 1); ?> />
                        <?php _e('Admin must manually approve all bookings', 'ffc'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="require_login"><?php _e('Require User Login', 'ffc'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="require_login" name="ffc_calendar_config[require_login]" value="1" <?php checked($config['require_login'], 1); ?> />
                        <?php _e('Only logged-in users can book', 'ffc'); ?>
                    </label>
                </td>
            </tr>
            <tr class="ffc-allowed-roles" <?php echo $config['require_login'] ? '' : 'style="display:none;"'; ?>>
                <th><label><?php _e('Allowed Roles', 'ffc'); ?></label></th>
                <td>
                    <?php foreach ($roles as $role_key => $role_name): ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="ffc_calendar_config[allowed_roles][]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $config['allowed_roles'])); ?> />
                            <?php echo esc_html($role_name); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php _e('Leave empty to allow all logged-in users', 'ffc'); ?></p>
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
        $email_config = get_post_meta($post->ID, '_ffc_calendar_email_config', true);
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
            'user_confirmation_subject' => __('Appointment Confirmation - {{calendar_title}}', 'ffc'),
            'user_confirmation_body' => __("Hello {{user_name}},\n\nYour appointment has been scheduled:\n\nCalendar: {{calendar_title}}\nDate: {{appointment_date}}\nTime: {{appointment_time}}\n\nThank you!", 'ffc'),
        );

        $email_config = array_merge($defaults, $email_config);

        ?>
        <table class="form-table">
            <tr>
                <th><?php _e('Notifications', 'ffc'); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_calendar_email_config[send_user_confirmation]" value="1" <?php checked($email_config['send_user_confirmation'], 1); ?> />
                            <?php _e('Send confirmation email to user', 'ffc'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_calendar_email_config[send_admin_notification]" value="1" <?php checked($email_config['send_admin_notification'], 1); ?> />
                            <?php _e('Send notification to admin on new booking', 'ffc'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_calendar_email_config[send_approval_notification]" value="1" <?php checked($email_config['send_approval_notification'], 1); ?> />
                            <?php _e('Send notification when booking is approved', 'ffc'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_calendar_email_config[send_cancellation_notification]" value="1" <?php checked($email_config['send_cancellation_notification'], 1); ?> />
                            <?php _e('Send notification when booking is cancelled', 'ffc'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="ffc_calendar_email_config[send_reminder]" value="1" <?php checked($email_config['send_reminder'], 1); ?> />
                            <?php _e('Send reminder before appointment', 'ffc'); ?>
                        </label>
                    </fieldset>
                    <p class="description"><?php _e('Default: All notifications disabled', 'ffc'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="reminder_hours_before"><?php _e('Reminder Timing', 'ffc'); ?></label></th>
                <td>
                    <input type="number" id="reminder_hours_before" name="ffc_calendar_email_config[reminder_hours_before]" value="<?php echo esc_attr($email_config['reminder_hours_before']); ?>" min="1" max="168" /> <?php _e('hours before appointment', 'ffc'); ?>
                </td>
            </tr>
            <tr>
                <th><label for="admin_emails"><?php _e('Admin Email Addresses', 'ffc'); ?></label></th>
                <td>
                    <input type="text" id="admin_emails" name="ffc_calendar_email_config[admin_emails]" value="<?php echo esc_attr($email_config['admin_emails']); ?>" class="large-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" />
                    <p class="description"><?php _e('Comma-separated email addresses for admin notifications (leave empty to use site admin email)', 'ffc'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="user_confirmation_subject"><?php _e('Confirmation Email Subject', 'ffc'); ?></label></th>
                <td>
                    <input type="text" id="user_confirmation_subject" name="ffc_calendar_email_config[user_confirmation_subject]" value="<?php echo esc_attr($email_config['user_confirmation_subject']); ?>" class="large-text" />
                </td>
            </tr>
            <tr>
                <th><label for="user_confirmation_body"><?php _e('Confirmation Email Body', 'ffc'); ?></label></th>
                <td>
                    <textarea id="user_confirmation_body" name="ffc_calendar_email_config[user_confirmation_body]" rows="10" class="large-text"><?php echo esc_textarea($email_config['user_confirmation_body']); ?></textarea>
                    <p class="description">
                        <?php _e('Available variables:', 'ffc'); ?>
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
            <p><strong><?php _e('Use this shortcode to display the calendar:', 'ffc'); ?></strong></p>

            <?php if ($post->post_status === 'publish'): ?>
                <input type="text" readonly value='[ffc_calendar id="<?php echo $post->ID; ?>"]' onclick="this.select();" style="width: 100%; padding: 6px; font-family: monospace; background: #f0f0f1;" />

                <p style="margin-top: 15px;"><strong><?php _e('Preview:', 'ffc'); ?></strong></p>
                <p><a href="<?php echo add_query_arg('calendar_preview', $post->ID, home_url('/')); ?>" target="_blank" class="button button-secondary"><?php _e('Preview Calendar', 'ffc'); ?></a></p>
            <?php else: ?>
                <p class="description"><?php _e('Publish this calendar to generate the shortcode.', 'ffc'); ?></p>
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

        if (!isset($_POST['ffc_calendar_config_nonce']) || !wp_verify_nonce($_POST['ffc_calendar_config_nonce'], 'ffc_calendar_config_nonce')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save configuration
        if (isset($_POST['ffc_calendar_config'])) {
            $config = $_POST['ffc_calendar_config'];

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
            $config['requires_approval'] = isset($config['requires_approval']) ? 1 : 0;
            $config['require_login'] = isset($config['require_login']) ? 1 : 0;
            $config['status'] = sanitize_text_field($config['status'] ?? 'active');

            // Sanitize allowed roles
            if (isset($config['allowed_roles']) && is_array($config['allowed_roles'])) {
                $config['allowed_roles'] = array_map('sanitize_text_field', $config['allowed_roles']);
            } else {
                $config['allowed_roles'] = array();
            }

            update_post_meta($post_id, '_ffc_calendar_config', $config);
        }

        // Save working hours
        if (isset($_POST['ffc_calendar_working_hours']) && is_array($_POST['ffc_calendar_working_hours'])) {
            $working_hours = array();
            foreach ($_POST['ffc_calendar_working_hours'] as $hours) {
                $working_hours[] = array(
                    'day' => absint($hours['day'] ?? 0),
                    'start' => sanitize_text_field($hours['start'] ?? '09:00'),
                    'end' => sanitize_text_field($hours['end'] ?? '17:00')
                );
            }
            update_post_meta($post_id, '_ffc_calendar_working_hours', $working_hours);
        }

        // Save email configuration
        if (isset($_POST['ffc_calendar_email_config'])) {
            $email_config = $_POST['ffc_calendar_email_config'];

            $email_config['send_user_confirmation'] = isset($email_config['send_user_confirmation']) ? 1 : 0;
            $email_config['send_admin_notification'] = isset($email_config['send_admin_notification']) ? 1 : 0;
            $email_config['send_approval_notification'] = isset($email_config['send_approval_notification']) ? 1 : 0;
            $email_config['send_cancellation_notification'] = isset($email_config['send_cancellation_notification']) ? 1 : 0;
            $email_config['send_reminder'] = isset($email_config['send_reminder']) ? 1 : 0;
            $email_config['reminder_hours_before'] = absint($email_config['reminder_hours_before'] ?? 24);
            $email_config['admin_emails'] = sanitize_text_field($email_config['admin_emails'] ?? '');
            $email_config['user_confirmation_subject'] = sanitize_text_field($email_config['user_confirmation_subject'] ?? '');
            $email_config['user_confirmation_body'] = sanitize_textarea_field($email_config['user_confirmation_body'] ?? '');

            update_post_meta($post_id, '_ffc_calendar_email_config', $email_config);
        }
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
