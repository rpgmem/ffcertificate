<?php
declare(strict_types=1);

/**
 * Audience Admin Settings
 *
 * Handles the settings page and global holiday management for the
 * unified scheduling system.
 *
 * @since 4.6.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceAdminSettings {

    /**
     * Menu slug prefix
     *
     * @var string
     */
    private string $menu_slug;

    /**
     * Constructor
     *
     * @param string $menu_slug Menu slug prefix.
     */
    public function __construct(string $menu_slug) {
        $this->menu_slug = $menu_slug;
    }

    /**
     * Render settings page
     *
     * @return void
     */
    public function render_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general';

        ?>
        <div class="wrap ffc-settings-wrap">
            <h1><?php esc_html_e('Scheduling Settings', 'ffcertificate'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-settings&tab=general')); ?>"
                   class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('General', 'ffcertificate'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-settings&tab=self-scheduling')); ?>"
                   class="nav-tab <?php echo $active_tab === 'self-scheduling' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Self-Scheduling', 'ffcertificate'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-settings&tab=audience')); ?>"
                   class="nav-tab <?php echo $active_tab === 'audience' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Audience', 'ffcertificate'); ?>
                </a>
            </h2>

            <div class="ffc-tab-content">
                <?php
                switch ($active_tab) {
                    case 'self-scheduling':
                        $this->render_self_scheduling_tab();
                        break;
                    case 'audience':
                        $this->render_audience_tab();
                        break;
                    default:
                        $this->render_general_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render General settings tab
     *
     * @return void
     */
    private function render_general_tab(): void {
        $holidays = get_option('ffc_global_holidays', array());
        // Sort by date ascending
        usort($holidays, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        ?>
        <div class="card">
            <h2><?php esc_html_e('General Settings', 'ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('General scheduling settings that apply to both Self-Scheduling and Audience systems.', 'ffcertificate'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'ffcertificate'); ?></th>
                        <td>
                            <p>
                                <strong><?php esc_html_e('Self-Scheduling:', 'ffcertificate'); ?></strong>
                                <?php
                                $calendars_count = wp_count_posts('ffc_self_scheduling');
                                $published = isset($calendars_count->publish) ? $calendars_count->publish : 0;
                                printf(
                                    /* translators: %d: number of published calendars */
                                    esc_html__('%d published calendar(s)', 'ffcertificate'),
                                    (int) $published
                                );
                                ?>
                            </p>
                            <p>
                                <strong><?php esc_html_e('Audience:', 'ffcertificate'); ?></strong>
                                <?php
                                printf(
                                    /* translators: %d: number of active schedules */
                                    esc_html__('%d active schedule(s)', 'ffcertificate'),
                                    absint(AudienceScheduleRepository::count(array('status' => 'active')))
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Global Holidays -->
        <div class="card">
            <h2><?php esc_html_e('Global Holidays', 'ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Holidays added here will block bookings across all calendars in both scheduling systems. Use per-calendar blocked dates for calendar-specific closures.', 'ffcertificate'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('ffc_global_holiday_action', 'ffc_global_holiday_nonce'); ?>
                <input type="hidden" name="ffc_action" value="add_global_holiday">
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="global_holiday_date"><?php esc_html_e('Date', 'ffcertificate'); ?></label>
                            </th>
                            <td>
                                <input type="date" id="global_holiday_date" name="global_holiday_date" required class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="global_holiday_description"><?php esc_html_e('Description', 'ffcertificate'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="global_holiday_description" name="global_holiday_description"
                                       placeholder="<?php esc_attr_e('e.g. Christmas, Carnival...', 'ffcertificate'); ?>"
                                       class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th></th>
                            <td>
                                <button type="submit" class="button button-primary">
                                    <?php esc_html_e('Add Holiday', 'ffcertificate'); ?>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </form>

            <?php if (!empty($holidays)) : ?>
                <table class="widefat striped ffc-mt-15">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'ffcertificate'); ?></th>
                            <th><?php esc_html_e('Description', 'ffcertificate'); ?></th>
                            <th><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($holidays as $index => $holiday) : ?>
                            <tr>
                                <td>
                                    <?php
                                    $timestamp = strtotime($holiday['date']);
                                    echo esc_html($timestamp ? date_i18n(get_option('date_format', 'F j, Y'), $timestamp) : $holiday['date']);
                                    ?>
                                </td>
                                <td><?php echo esc_html($holiday['description'] ?? ''); ?></td>
                                <td>
                                    <?php
                                    $delete_url = wp_nonce_url(
                                        admin_url('admin.php?page=' . $this->menu_slug . '-settings&tab=general&ffc_action=delete_global_holiday&holiday_index=' . $index),
                                        'delete_global_holiday_' . $index,
                                        'ffc_global_holiday_nonce'
                                    );
                                    ?>
                                    <a href="<?php echo esc_url($delete_url); ?>"
                                       class="button button-small button-link-delete"
                                       onclick="return confirm('<?php esc_attr_e('Remove this holiday?', 'ffcertificate'); ?>');">
                                        <?php esc_html_e('Delete', 'ffcertificate'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description ffc-mt-15">
                    <?php esc_html_e('No global holidays configured.', 'ffcertificate'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Self-Scheduling settings tab
     *
     * @return void
     */
    private function render_self_scheduling_tab(): void {
        // Get current settings
        $display_mode = get_option('ffc_ss_private_display_mode', 'show_message');
        /* translators: %login_url% is replaced with the WordPress login page URL */
        $visibility_message = get_option('ffc_ss_visibility_message', __('To view this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate'));
        /* translators: %login_url% is replaced with the WordPress login page URL */
        $scheduling_message = get_option('ffc_ss_scheduling_message', __('To book on this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate'));
        /* translators: %hours% is replaced with today's working hours range */
        $bh_viewing_message = get_option('ffc_ss_business_hours_viewing_message', __('This calendar is available for viewing only during business hours (%hours%).', 'ffcertificate'));
        /* translators: %hours% is replaced with today's working hours range */
        $bh_booking_message = get_option('ffc_ss_business_hours_booking_message', __('Booking is available only during business hours (%hours%).', 'ffcertificate'));

        ?>
        <div class="card">
            <h2><?php esc_html_e('Self-Scheduling Settings', 'ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Settings specific to the personal appointment booking system. Calendar-specific settings (slots, working hours, email templates) are configured on each calendar\'s edit page.', 'ffcertificate'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Manage Calendars', 'ffcertificate'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(admin_url('edit.php?post_type=ffc_self_scheduling')); ?>" class="button">
                                <?php esc_html_e('View All Calendars', 'ffcertificate'); ?>
                            </a>
                            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ffc_self_scheduling')); ?>" class="button">
                                <?php esc_html_e('Add New Calendar', 'ffcertificate'); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Appointments', 'ffcertificate'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=ffc-appointments')); ?>" class="button">
                                <?php esc_html_e('View All Appointments', 'ffcertificate'); ?>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Visibility Settings -->
        <form method="post" action="">
            <?php wp_nonce_field('ffc_ss_visibility_settings', 'ffc_ss_visibility_nonce'); ?>
            <input type="hidden" name="ffc_action" value="save_ss_visibility_settings">

            <div class="card">
                <h2><?php esc_html_e('Visibility Settings', 'ffcertificate'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Configure how private calendars are displayed to non-logged-in visitors.', 'ffcertificate'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="ffc_ss_private_display_mode"><?php esc_html_e('Private Calendar Display', 'ffcertificate'); ?></label>
                            </th>
                            <td>
                                <select name="ffc_ss_private_display_mode" id="ffc_ss_private_display_mode">
                                    <option value="show_message" <?php selected($display_mode, 'show_message'); ?>>
                                        <?php esc_html_e('Show message', 'ffcertificate'); ?>
                                    </option>
                                    <option value="show_title_message" <?php selected($display_mode, 'show_title_message'); ?>>
                                        <?php esc_html_e('Show calendar title + message', 'ffcertificate'); ?>
                                    </option>
                                    <option value="hide" <?php selected($display_mode, 'hide'); ?>>
                                        <?php esc_html_e('Hide completely', 'ffcertificate'); ?>
                                    </option>
                                </select>
                                <p class="description"><?php esc_html_e('What to show when a private calendar is accessed by a non-logged-in user.', 'ffcertificate'); ?></p>
                            </td>
                        </tr>
                        <tr class="ffc-ss-message-row" <?php echo $display_mode === 'hide' ? 'style="display:none;"' : ''; ?>>
                            <th scope="row">
                                <label for="ffc_ss_visibility_message"><?php esc_html_e('Visibility Message', 'ffcertificate'); ?></label>
                            </th>
                            <td>
                                <textarea name="ffc_ss_visibility_message" id="ffc_ss_visibility_message" rows="3" class="large-text"><?php echo esc_textarea($visibility_message); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Shown when the calendar is private and user is not logged in. Use %login_url% for the login link.', 'ffcertificate'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr class="ffc-ss-message-row" <?php echo $display_mode === 'hide' ? 'style="display:none;"' : ''; ?>>
                            <th scope="row">
                                <label for="ffc_ss_scheduling_message"><?php esc_html_e('Scheduling Message', 'ffcertificate'); ?></label>
                            </th>
                            <td>
                                <textarea name="ffc_ss_scheduling_message" id="ffc_ss_scheduling_message" rows="3" class="large-text"><?php echo esc_textarea($scheduling_message); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Shown when the calendar is public but scheduling is private and user is not logged in. Use %login_url% for the login link.', 'ffcertificate'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save Settings', 'ffcertificate')); ?>
            </div>
        </form>

        <!-- Business Hours Restriction Messages -->
        <form method="post" action="">
            <?php wp_nonce_field('ffc_ss_business_hours_settings', 'ffc_ss_business_hours_nonce'); ?>
            <input type="hidden" name="ffc_action" value="save_ss_business_hours_settings">

            <div class="card">
                <h2><?php esc_html_e('Business Hours Restriction Messages', 'ffcertificate'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Messages shown when a calendar has business hours restrictions enabled (configured per calendar).', 'ffcertificate'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="ffc_ss_business_hours_viewing_message"><?php esc_html_e('Viewing Restriction Message', 'ffcertificate'); ?></label>
                            </th>
                            <td>
                                <textarea name="ffc_ss_business_hours_viewing_message" id="ffc_ss_business_hours_viewing_message" rows="3" class="large-text"><?php echo esc_textarea($bh_viewing_message); ?></textarea>
                                <p class="description">
                                    <?php
                                    /* translators: %hours% is a placeholder token the admin can use in the message */
                                    esc_html_e('Shown when the calendar cannot be viewed outside business hours. Use %hours% for today\'s working hours.', 'ffcertificate'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ffc_ss_business_hours_booking_message"><?php esc_html_e('Booking Restriction Message', 'ffcertificate'); ?></label>
                            </th>
                            <td>
                                <textarea name="ffc_ss_business_hours_booking_message" id="ffc_ss_business_hours_booking_message" rows="3" class="large-text"><?php echo esc_textarea($bh_booking_message); ?></textarea>
                                <p class="description">
                                    <?php
                                    /* translators: %hours% is a placeholder token the admin can use in the message */
                                    esc_html_e('Shown when booking is not allowed outside business hours (calendar is still visible). Use %hours% for today\'s working hours.', 'ffcertificate'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save Settings', 'ffcertificate')); ?>
            </div>
        </form>
        <?php
    }

    /**
     * Render Audience settings tab
     *
     * @return void
     */
    private function render_audience_tab(): void {
        // Get current settings
        $display_mode = get_option('ffc_aud_private_display_mode', 'show_message');
        $visibility_message = get_option('ffc_aud_visibility_message', __('To view this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate'));
        $scheduling_message = get_option('ffc_aud_scheduling_message', __('To book on this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate'));
        $multiple_audiences_color = get_option('ffc_aud_multiple_audiences_color', '');

        ?>
        <div class="card">
            <h2><?php esc_html_e('Audience Scheduling Settings', 'ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Settings specific to the audience/group booking system.', 'ffcertificate'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Manage', 'ffcertificate'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-calendars')); ?>" class="button">
                                <?php esc_html_e('Audience Calendars', 'ffcertificate'); ?>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-environments')); ?>" class="button">
                                <?php esc_html_e('Environments', 'ffcertificate'); ?>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-audiences')); ?>" class="button">
                                <?php esc_html_e('Audiences', 'ffcertificate'); ?>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Visibility Settings -->
        <form method="post" action="">
            <?php wp_nonce_field('ffc_aud_visibility_settings', 'ffc_aud_visibility_nonce'); ?>
            <input type="hidden" name="ffc_action" value="save_aud_visibility_settings">

            <div class="card">
                <h2><?php esc_html_e('Visibility Settings', 'ffcertificate'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Configure how private audience calendars are displayed to non-logged-in visitors. Note: Scheduling is always restricted to authorized members.', 'ffcertificate'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="ffc_aud_private_display_mode"><?php esc_html_e('Private Calendar Display', 'ffcertificate'); ?></label>
                            </th>
                            <td>
                                <select name="ffc_aud_private_display_mode" id="ffc_aud_private_display_mode">
                                    <option value="show_message" <?php selected($display_mode, 'show_message'); ?>>
                                        <?php esc_html_e('Show message', 'ffcertificate'); ?>
                                    </option>
                                    <option value="show_title_message" <?php selected($display_mode, 'show_title_message'); ?>>
                                        <?php esc_html_e('Show calendar title + message', 'ffcertificate'); ?>
                                    </option>
                                    <option value="hide" <?php selected($display_mode, 'hide'); ?>>
                                        <?php esc_html_e('Hide completely', 'ffcertificate'); ?>
                                    </option>
                                </select>
                                <p class="description"><?php esc_html_e('What to show when a private calendar is accessed by a non-logged-in user.', 'ffcertificate'); ?></p>
                            </td>
                        </tr>
                        <tr class="ffc-aud-message-row" <?php echo $display_mode === 'hide' ? 'style="display:none;"' : ''; ?>>
                            <th scope="row">
                                <label for="ffc_aud_visibility_message"><?php esc_html_e('Visibility Message', 'ffcertificate'); ?></label>
                            </th>
                            <td>
                                <textarea name="ffc_aud_visibility_message" id="ffc_aud_visibility_message" rows="3" class="large-text"><?php echo esc_textarea($visibility_message); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Shown when the calendar is private and user is not logged in. Use %login_url% for the login link.', 'ffcertificate'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr class="ffc-aud-message-row" <?php echo $display_mode === 'hide' ? 'style="display:none;"' : ''; ?>>
                            <th scope="row">
                                <label for="ffc_aud_scheduling_message"><?php esc_html_e('Scheduling Message', 'ffcertificate'); ?></label>
                            </th>
                            <td>
                                <textarea name="ffc_aud_scheduling_message" id="ffc_aud_scheduling_message" rows="3" class="large-text"><?php echo esc_textarea($scheduling_message); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Shown when the calendar is public but user is not logged in and tries to book. Use %login_url% for the login link.', 'ffcertificate'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ffc_aud_multiple_audiences_color"><?php esc_html_e('"Multiple Audiences" Badge Color', 'ffcertificate'); ?></label>
                            </th>
                            <td>
                                <input type="color" name="ffc_aud_multiple_audiences_color" id="ffc_aud_multiple_audiences_color"
                                       value="<?php echo esc_attr($multiple_audiences_color ?: '#666666'); ?>"
                                       style="width: 50px; height: 30px; padding: 0; border: 1px solid #ccc; cursor: pointer;">
                                <span style="margin-left: 8px; color: #666;"><?php echo esc_html($multiple_audiences_color ?: '#666666'); ?></span>
                                <p class="description">
                                    <?php esc_html_e('Color for the "Multiple audiences" badge shown in the event list when an event has more than 2 audiences.', 'ffcertificate'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save Settings', 'ffcertificate')); ?>
            </div>
        </form>
        <?php
    }

    /**
     * Handle visibility settings save actions
     *
     * @since 4.7.0
     * @return void
     */
    public function handle_visibility_settings(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save Self-Scheduling visibility settings
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified inside this block.
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'save_ss_visibility_settings') {
            if (!isset($_POST['ffc_ss_visibility_nonce']) ||
                !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_ss_visibility_nonce'])), 'ffc_ss_visibility_settings')) {
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
            $display_mode = isset($_POST['ffc_ss_private_display_mode'])
                ? sanitize_text_field(wp_unslash($_POST['ffc_ss_private_display_mode'])) : 'show_message';
            if (!in_array($display_mode, ['show_message', 'show_title_message', 'hide'], true)) {
                $display_mode = 'show_message';
            }

            update_option('ffc_ss_private_display_mode', $display_mode);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; wp_kses_post() sanitises.
            update_option('ffc_ss_visibility_message', wp_kses_post(wp_unslash($_POST['ffc_ss_visibility_message'] ?? '')));
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; wp_kses_post() sanitises.
            update_option('ffc_ss_scheduling_message', wp_kses_post(wp_unslash($_POST['ffc_ss_scheduling_message'] ?? '')));

            add_settings_error('ffc_audience', 'ffc_message', __('Self-scheduling visibility settings saved.', 'ffcertificate'), 'success');
        }

        // Save Self-Scheduling business hours restriction messages
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified inside this block.
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'save_ss_business_hours_settings') {
            if (!isset($_POST['ffc_ss_business_hours_nonce']) ||
                !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_ss_business_hours_nonce'])), 'ffc_ss_business_hours_settings')) {
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; wp_kses_post() sanitises.
            update_option('ffc_ss_business_hours_viewing_message', wp_kses_post(wp_unslash($_POST['ffc_ss_business_hours_viewing_message'] ?? '')));
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; wp_kses_post() sanitises.
            update_option('ffc_ss_business_hours_booking_message', wp_kses_post(wp_unslash($_POST['ffc_ss_business_hours_booking_message'] ?? '')));

            add_settings_error('ffc_audience', 'ffc_message', __('Business hours restriction messages saved.', 'ffcertificate'), 'success');
        }

        // Save Audience visibility settings
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified inside this block.
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'save_aud_visibility_settings') {
            if (!isset($_POST['ffc_aud_visibility_nonce']) ||
                !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_aud_visibility_nonce'])), 'ffc_aud_visibility_settings')) {
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
            $display_mode = isset($_POST['ffc_aud_private_display_mode'])
                ? sanitize_text_field(wp_unslash($_POST['ffc_aud_private_display_mode'])) : 'show_message';
            if (!in_array($display_mode, ['show_message', 'show_title_message', 'hide'], true)) {
                $display_mode = 'show_message';
            }

            update_option('ffc_aud_private_display_mode', $display_mode);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; wp_kses_post() sanitises.
            update_option('ffc_aud_visibility_message', wp_kses_post(wp_unslash($_POST['ffc_aud_visibility_message'] ?? '')));
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; wp_kses_post() sanitises.
            update_option('ffc_aud_scheduling_message', wp_kses_post(wp_unslash($_POST['ffc_aud_scheduling_message'] ?? '')));

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
            $ma_color = isset($_POST['ffc_aud_multiple_audiences_color'])
                ? sanitize_hex_color(wp_unslash($_POST['ffc_aud_multiple_audiences_color'])) : '';
            update_option('ffc_aud_multiple_audiences_color', $ma_color ?: '');

            add_settings_error('ffc_audience', 'ffc_message', __('Audience visibility settings saved.', 'ffcertificate'), 'success');
        }
    }

    /**
     * Handle global holiday add/delete actions
     *
     * @return void
     */
    public function handle_global_holiday_actions(): void {
        // Add global holiday (POST)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified inside this block.
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'add_global_holiday') {
            if (!isset($_POST['ffc_global_holiday_nonce']) ||
                !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_global_holiday_nonce'])), 'ffc_global_holiday_action')) {
                return;
            }

            if (!current_user_can('manage_options')) {
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
            $date = isset($_POST['global_holiday_date']) ? sanitize_text_field(wp_unslash($_POST['global_holiday_date'])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
            $description = isset($_POST['global_holiday_description']) ? sanitize_text_field(wp_unslash($_POST['global_holiday_description'])) : '';

            if (!empty($date)) {
                $holidays = get_option('ffc_global_holidays', array());

                // Avoid duplicates
                $exists = false;
                foreach ($holidays as $h) {
                    if ($h['date'] === $date) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $holidays[] = array(
                        'date' => $date,
                        'description' => $description,
                    );
                    update_option('ffc_global_holidays', $holidays);
                }
            }

            wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-settings&tab=general&message=holiday_added'));
            exit;
        }

        // Delete global holiday (GET)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['ffc_action']) && $_GET['ffc_action'] === 'delete_global_holiday') {
            $index = isset($_GET['holiday_index']) ? absint($_GET['holiday_index']) : -1;

            if (!isset($_GET['ffc_global_holiday_nonce']) ||
                !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['ffc_global_holiday_nonce'])), 'delete_global_holiday_' . $index)) {
                return;
            }

            if (!current_user_can('manage_options')) {
                return;
            }

            $holidays = get_option('ffc_global_holidays', array());
            if (isset($holidays[$index])) {
                array_splice($holidays, $index, 1);
                update_option('ffc_global_holidays', $holidays);
            }

            wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-settings&tab=general&message=holiday_deleted'));
            exit;
        }
    }
}
