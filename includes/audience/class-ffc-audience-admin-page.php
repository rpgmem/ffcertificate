<?php
declare(strict_types=1);

/**
 * Audience Admin Page
 *
 * Main admin page handler for audience scheduling system.
 * Provides unified menu under "Agendamentos" (Scheduling) with subpages for:
 * - Schedules (Calendários)
 * - Environments (Ambientes)
 * - Audiences (Públicos)
 * - Bookings (Agendamentos)
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceAdminPage {

    /**
     * Menu slug prefix
     */
    private const MENU_SLUG = 'ffc-scheduling';

    /**
     * Initialize admin page
     *
     * @return void
     */
    public function init(): void {
        add_action('admin_menu', array($this, 'add_admin_menus'), 20);
        add_action('admin_init', array($this, 'handle_form_submissions'));
    }

    /**
     * Add admin menu pages
     *
     * @return void
     */
    public function add_admin_menus(): void {
        // Main menu: Agendamentos (Scheduling)
        add_menu_page(
            __('Scheduling', 'wp-ffcertificate'),
            __('Scheduling', 'wp-ffcertificate'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_dashboard_page'),
            'dashicons-calendar-alt',
            30
        );

        // Submenu: Dashboard (Overview)
        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'wp-ffcertificate'),
            __('Dashboard', 'wp-ffcertificate'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_dashboard_page')
        );

        // Submenu: Calendars (Schedules)
        add_submenu_page(
            self::MENU_SLUG,
            __('Calendars', 'wp-ffcertificate'),
            __('Calendars', 'wp-ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-calendars',
            array($this, 'render_calendars_page')
        );

        // Submenu: Environments
        add_submenu_page(
            self::MENU_SLUG,
            __('Environments', 'wp-ffcertificate'),
            __('Environments', 'wp-ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-environments',
            array($this, 'render_environments_page')
        );

        // Submenu: Audiences
        add_submenu_page(
            self::MENU_SLUG,
            __('Audiences', 'wp-ffcertificate'),
            __('Audiences', 'wp-ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-audiences',
            array($this, 'render_audiences_page')
        );

        // Submenu: Bookings
        add_submenu_page(
            self::MENU_SLUG,
            __('Bookings', 'wp-ffcertificate'),
            __('Bookings', 'wp-ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-bookings',
            array($this, 'render_bookings_page')
        );

        // Submenu: Import
        add_submenu_page(
            self::MENU_SLUG,
            __('Import', 'wp-ffcertificate'),
            __('Import', 'wp-ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-import',
            array($this, 'render_import_page')
        );

        // Submenu: Settings
        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'wp-ffcertificate'),
            __('Settings', 'wp-ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Handle form submissions
     *
     * @return void
     */
    public function handle_form_submissions(): void {
        // Only process on our admin pages
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['page']) || strpos(sanitize_text_field(wp_unslash($_GET['page'])), self::MENU_SLUG) !== 0) {
            return;
        }

        // Handle CSV import
        $this->handle_csv_import();

        // Handle calendar actions
        $this->handle_calendar_actions();

        // Handle environment actions
        $this->handle_environment_actions();

        // Handle audience actions
        $this->handle_audience_actions();
    }

    /**
     * Render dashboard page
     *
     * @return void
     */
    public function render_dashboard_page(): void {
        // Get statistics
        $stats = array(
            'schedules' => AudienceScheduleRepository::count(array('status' => 'active')),
            'environments' => AudienceEnvironmentRepository::count(array('status' => 'active')),
            'audiences' => AudienceRepository::count(array('status' => 'active')),
            'upcoming_bookings' => AudienceBookingRepository::count(array(
                'status' => 'active',
                'start_date' => current_time('Y-m-d'),
            )),
        );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Scheduling Dashboard', 'wp-ffcertificate'); ?></h1>

            <div class="ffc-audience-dashboard">
                <div class="ffc-stats-grid">
                    <div class="ffc-stat-card">
                        <span class="ffc-stat-icon dashicons dashicons-calendar-alt"></span>
                        <div class="ffc-stat-content">
                            <span class="ffc-stat-value"><?php echo esc_html($stats['schedules']); ?></span>
                            <span class="ffc-stat-label"><?php esc_html_e('Active Calendars', 'wp-ffcertificate'); ?></span>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '-calendars')); ?>" class="ffc-stat-link">
                            <?php esc_html_e('Manage', 'wp-ffcertificate'); ?> &rarr;
                        </a>
                    </div>

                    <div class="ffc-stat-card">
                        <span class="ffc-stat-icon dashicons dashicons-building"></span>
                        <div class="ffc-stat-content">
                            <span class="ffc-stat-value"><?php echo esc_html($stats['environments']); ?></span>
                            <span class="ffc-stat-label"><?php esc_html_e('Active Environments', 'wp-ffcertificate'); ?></span>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '-environments')); ?>" class="ffc-stat-link">
                            <?php esc_html_e('Manage', 'wp-ffcertificate'); ?> &rarr;
                        </a>
                    </div>

                    <div class="ffc-stat-card">
                        <span class="ffc-stat-icon dashicons dashicons-groups"></span>
                        <div class="ffc-stat-content">
                            <span class="ffc-stat-value"><?php echo esc_html($stats['audiences']); ?></span>
                            <span class="ffc-stat-label"><?php esc_html_e('Active Audiences', 'wp-ffcertificate'); ?></span>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences')); ?>" class="ffc-stat-link">
                            <?php esc_html_e('Manage', 'wp-ffcertificate'); ?> &rarr;
                        </a>
                    </div>

                    <div class="ffc-stat-card">
                        <span class="ffc-stat-icon dashicons dashicons-clock"></span>
                        <div class="ffc-stat-content">
                            <span class="ffc-stat-value"><?php echo esc_html($stats['upcoming_bookings']); ?></span>
                            <span class="ffc-stat-label"><?php esc_html_e('Upcoming Bookings', 'wp-ffcertificate'); ?></span>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '-bookings')); ?>" class="ffc-stat-link">
                            <?php esc_html_e('View All', 'wp-ffcertificate'); ?> &rarr;
                        </a>
                    </div>
                </div>

                <div class="ffc-quick-actions">
                    <h2><?php esc_html_e('Quick Actions', 'wp-ffcertificate'); ?></h2>
                    <div class="ffc-action-buttons">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '-calendars&action=new')); ?>" class="button button-primary">
                            <?php esc_html_e('Create Calendar', 'wp-ffcertificate'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '-environments&action=new')); ?>" class="button">
                            <?php esc_html_e('Add Environment', 'wp-ffcertificate'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences&action=new')); ?>" class="button">
                            <?php esc_html_e('Create Audience', 'wp-ffcertificate'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .ffc-audience-dashboard { margin-top: 20px; }
            .ffc-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
            .ffc-stat-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; display: flex; flex-direction: column; }
            .ffc-stat-icon { font-size: 32px; color: #2271b1; margin-bottom: 10px; }
            .ffc-stat-content { flex-grow: 1; }
            .ffc-stat-value { font-size: 36px; font-weight: 600; display: block; color: #1d2327; }
            .ffc-stat-label { color: #50575e; font-size: 14px; }
            .ffc-stat-link { margin-top: 15px; color: #2271b1; text-decoration: none; }
            .ffc-stat-link:hover { text-decoration: underline; }
            .ffc-quick-actions { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; }
            .ffc-quick-actions h2 { margin-top: 0; }
            .ffc-action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        </style>
        <?php
    }

    /**
     * Render calendars page
     *
     * @return void
     */
    public function render_calendars_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        ?>
        <div class="wrap">
            <?php
            switch ($action) {
                case 'new':
                case 'edit':
                    $this->render_calendar_form($id);
                    break;
                default:
                    $this->render_calendars_list();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render calendars list
     *
     * @return void
     */
    private function render_calendars_list(): void {
        $schedules = AudienceScheduleRepository::get_all(array('orderby' => 'name'));
        $add_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-calendars&action=new');

        ?>
        <h1 class="wp-heading-inline"><?php esc_html_e('Calendars', 'wp-ffcertificate'); ?></h1>
        <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'wp-ffcertificate'); ?></a>
        <hr class="wp-header-end">

        <?php $this->render_admin_notices(); ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="column-name"><?php esc_html_e('Name', 'wp-ffcertificate'); ?></th>
                    <th scope="col" class="column-visibility"><?php esc_html_e('Visibility', 'wp-ffcertificate'); ?></th>
                    <th scope="col" class="column-environments"><?php esc_html_e('Environments', 'wp-ffcertificate'); ?></th>
                    <th scope="col" class="column-status"><?php esc_html_e('Status', 'wp-ffcertificate'); ?></th>
                    <th scope="col" class="column-actions"><?php esc_html_e('Actions', 'wp-ffcertificate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($schedules)) : ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e('No calendars found.', 'wp-ffcertificate'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($schedules as $schedule) : ?>
                        <?php
                        $env_count = AudienceEnvironmentRepository::count(array('schedule_id' => $schedule->id));
                        $edit_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-calendars&action=edit&id=' . $schedule->id);
                        $delete_url = wp_nonce_url(
                            admin_url('admin.php?page=' . self::MENU_SLUG . '-calendars&action=delete&id=' . $schedule->id),
                            'delete_schedule_' . $schedule->id
                        );
                        ?>
                        <tr>
                            <td class="column-name">
                                <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($schedule->name); ?></a></strong>
                                <?php if ($schedule->description) : ?>
                                    <p class="description"><?php echo esc_html(wp_trim_words($schedule->description, 15)); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="column-visibility">
                                <?php echo $schedule->visibility === 'public' ? esc_html__('Public', 'wp-ffcertificate') : esc_html__('Private', 'wp-ffcertificate'); ?>
                            </td>
                            <td class="column-environments"><?php echo esc_html($env_count); ?></td>
                            <td class="column-status">
                                <span class="ffc-status-badge ffc-status-<?php echo esc_attr($schedule->status); ?>">
                                    <?php echo $schedule->status === 'active' ? esc_html__('Active', 'wp-ffcertificate') : esc_html__('Inactive', 'wp-ffcertificate'); ?>
                                </span>
                            </td>
                            <td class="column-actions">
                                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'wp-ffcertificate'); ?></a> |
                                <a href="<?php echo esc_url($delete_url); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this calendar?', 'wp-ffcertificate'); ?>');">
                                    <?php esc_html_e('Delete', 'wp-ffcertificate'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <style>
            .ffc-status-badge { padding: 3px 8px; border-radius: 3px; font-size: 12px; }
            .ffc-status-active { background: #d1e7dd; color: #0f5132; }
            .ffc-status-inactive { background: #f8d7da; color: #842029; }
            .column-visibility, .column-environments, .column-status { width: 100px; }
            .column-actions { width: 120px; }
            .delete-link { color: #b32d2e; }
        </style>
        <?php
    }

    /**
     * Render calendar form
     *
     * @param int $id Schedule ID (0 for new)
     * @return void
     */
    private function render_calendar_form(int $id): void {
        $schedule = null;
        $page_title = __('Add New Calendar', 'wp-ffcertificate');

        if ($id > 0) {
            $schedule = AudienceScheduleRepository::get_by_id($id);
            if (!$schedule) {
                wp_die(__('Calendar not found.', 'wp-ffcertificate'));
            }
            $page_title = __('Edit Calendar', 'wp-ffcertificate');
        }

        $back_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-calendars');

        ?>
        <h1><?php echo esc_html($page_title); ?></h1>
        <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to Calendars', 'wp-ffcertificate'); ?></a>

        <?php $this->render_admin_notices(); ?>

        <form method="post" action="" class="ffc-form">
            <?php wp_nonce_field('save_schedule', 'ffc_schedule_nonce'); ?>
            <input type="hidden" name="schedule_id" value="<?php echo esc_attr($id); ?>">
            <input type="hidden" name="ffc_action" value="save_schedule">

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="schedule_name"><?php esc_html_e('Name', 'wp-ffcertificate'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="text" name="schedule_name" id="schedule_name" class="regular-text"
                               value="<?php echo esc_attr($schedule->name ?? ''); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="schedule_description"><?php esc_html_e('Description', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <textarea name="schedule_description" id="schedule_description" rows="3" class="large-text"><?php echo esc_textarea($schedule->description ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="schedule_visibility"><?php esc_html_e('Visibility', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="schedule_visibility" id="schedule_visibility">
                            <option value="private" <?php selected($schedule->visibility ?? 'private', 'private'); ?>>
                                <?php esc_html_e('Private (only users with permission)', 'wp-ffcertificate'); ?>
                            </option>
                            <option value="public" <?php selected($schedule->visibility ?? '', 'public'); ?>>
                                <?php esc_html_e('Public (visible to all logged-in users)', 'wp-ffcertificate'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="schedule_future_days"><?php esc_html_e('Future Days Limit', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="schedule_future_days" id="schedule_future_days" class="small-text"
                               value="<?php echo esc_attr($schedule->future_days_limit ?? ''); ?>" min="1" max="365">
                        <p class="description">
                            <?php esc_html_e('Maximum days in advance that non-admin users can book. Leave empty for no limit.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Notifications', 'wp-ffcertificate'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="schedule_notify_booking" value="1"
                                       <?php checked($schedule->notify_on_booking ?? 1, 1); ?>>
                                <?php esc_html_e('Send email on new booking', 'wp-ffcertificate'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="schedule_notify_cancel" value="1"
                                       <?php checked($schedule->notify_on_cancellation ?? 1, 1); ?>>
                                <?php esc_html_e('Send email on cancellation', 'wp-ffcertificate'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="schedule_include_ics" value="1"
                                       <?php checked($schedule->include_ics ?? 0, 1); ?>>
                                <?php esc_html_e('Include .ics calendar file in emails', 'wp-ffcertificate'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="schedule_status"><?php esc_html_e('Status', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="schedule_status" id="schedule_status">
                            <option value="active" <?php selected($schedule->status ?? 'active', 'active'); ?>>
                                <?php esc_html_e('Active', 'wp-ffcertificate'); ?>
                            </option>
                            <option value="inactive" <?php selected($schedule->status ?? '', 'inactive'); ?>>
                                <?php esc_html_e('Inactive', 'wp-ffcertificate'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button($id > 0 ? __('Update Calendar', 'wp-ffcertificate') : __('Create Calendar', 'wp-ffcertificate')); ?>
        </form>

        <style>
            .ffc-form .required { color: #d63638; }
        </style>
        <?php
    }

    /**
     * Render environments page
     *
     * @return void
     */
    public function render_environments_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        ?>
        <div class="wrap">
            <?php
            switch ($action) {
                case 'new':
                case 'edit':
                    $this->render_environment_form($id);
                    break;
                default:
                    $this->render_environments_list();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render environments list
     *
     * @return void
     */
    private function render_environments_list(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_schedule = isset($_GET['schedule_id']) ? absint($_GET['schedule_id']) : 0;

        $args = array('orderby' => 'name');
        if ($filter_schedule > 0) {
            $args['schedule_id'] = $filter_schedule;
        }
        $environments = AudienceEnvironmentRepository::get_all($args);
        $schedules = AudienceScheduleRepository::get_all();
        $add_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-environments&action=new');

        ?>
        <h1 class="wp-heading-inline"><?php esc_html_e('Environments', 'wp-ffcertificate'); ?></h1>
        <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'wp-ffcertificate'); ?></a>
        <hr class="wp-header-end">

        <?php $this->render_admin_notices(); ?>

        <!-- Filter form -->
        <form method="get" class="ffc-filter-form">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG . '-environments'); ?>">
            <select name="schedule_id">
                <option value=""><?php esc_html_e('All Calendars', 'wp-ffcertificate'); ?></option>
                <?php foreach ($schedules as $schedule) : ?>
                    <option value="<?php echo esc_attr($schedule->id); ?>" <?php selected($filter_schedule, $schedule->id); ?>>
                        <?php echo esc_html($schedule->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter', 'wp-ffcertificate'), 'secondary', 'filter', false); ?>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="column-name"><?php esc_html_e('Name', 'wp-ffcertificate'); ?></th>
                    <th scope="col" class="column-calendar"><?php esc_html_e('Calendar', 'wp-ffcertificate'); ?></th>
                    <th scope="col" class="column-status"><?php esc_html_e('Status', 'wp-ffcertificate'); ?></th>
                    <th scope="col" class="column-actions"><?php esc_html_e('Actions', 'wp-ffcertificate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($environments)) : ?>
                    <tr>
                        <td colspan="4"><?php esc_html_e('No environments found.', 'wp-ffcertificate'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($environments as $env) : ?>
                        <?php
                        $schedule = AudienceScheduleRepository::get_by_id((int) $env->schedule_id);
                        $edit_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-environments&action=edit&id=' . $env->id);
                        $delete_url = wp_nonce_url(
                            admin_url('admin.php?page=' . self::MENU_SLUG . '-environments&action=delete&id=' . $env->id),
                            'delete_environment_' . $env->id
                        );
                        ?>
                        <tr>
                            <td class="column-name">
                                <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($env->name); ?></a></strong>
                                <?php if ($env->description) : ?>
                                    <p class="description"><?php echo esc_html(wp_trim_words($env->description, 15)); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="column-calendar">
                                <?php echo $schedule ? esc_html($schedule->name) : '—'; ?>
                            </td>
                            <td class="column-status">
                                <span class="ffc-status-badge ffc-status-<?php echo esc_attr($env->status); ?>">
                                    <?php echo $env->status === 'active' ? esc_html__('Active', 'wp-ffcertificate') : esc_html__('Inactive', 'wp-ffcertificate'); ?>
                                </span>
                            </td>
                            <td class="column-actions">
                                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'wp-ffcertificate'); ?></a> |
                                <a href="<?php echo esc_url($delete_url); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this environment?', 'wp-ffcertificate'); ?>');">
                                    <?php esc_html_e('Delete', 'wp-ffcertificate'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <style>
            .ffc-filter-form { margin: 15px 0; display: flex; gap: 10px; align-items: center; }
            .column-calendar { width: 200px; }
        </style>
        <?php
    }

    /**
     * Render environment form
     *
     * @param int $id Environment ID (0 for new)
     * @return void
     */
    private function render_environment_form(int $id): void {
        $environment = null;
        $page_title = __('Add New Environment', 'wp-ffcertificate');

        if ($id > 0) {
            $environment = AudienceEnvironmentRepository::get_by_id($id);
            if (!$environment) {
                wp_die(__('Environment not found.', 'wp-ffcertificate'));
            }
            $page_title = __('Edit Environment', 'wp-ffcertificate');
        }

        $schedules = AudienceScheduleRepository::get_all(array('status' => 'active'));
        $back_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-environments');

        // Parse working hours
        $working_hours = array();
        if ($environment && $environment->working_hours) {
            $working_hours = json_decode($environment->working_hours, true) ?: array();
        }

        ?>
        <h1><?php echo esc_html($page_title); ?></h1>
        <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to Environments', 'wp-ffcertificate'); ?></a>

        <?php $this->render_admin_notices(); ?>

        <form method="post" action="" class="ffc-form">
            <?php wp_nonce_field('save_environment', 'ffc_environment_nonce'); ?>
            <input type="hidden" name="environment_id" value="<?php echo esc_attr($id); ?>">
            <input type="hidden" name="ffc_action" value="save_environment">

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="environment_schedule"><?php esc_html_e('Calendar', 'wp-ffcertificate'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <select name="environment_schedule" id="environment_schedule" required>
                            <option value=""><?php esc_html_e('Select a calendar', 'wp-ffcertificate'); ?></option>
                            <?php foreach ($schedules as $schedule) : ?>
                                <option value="<?php echo esc_attr($schedule->id); ?>" <?php selected($environment->schedule_id ?? '', $schedule->id); ?>>
                                    <?php echo esc_html($schedule->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="environment_name"><?php esc_html_e('Name', 'wp-ffcertificate'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="text" name="environment_name" id="environment_name" class="regular-text"
                               value="<?php echo esc_attr($environment->name ?? ''); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="environment_description"><?php esc_html_e('Description', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <textarea name="environment_description" id="environment_description" rows="3" class="large-text"><?php echo esc_textarea($environment->description ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Working Hours', 'wp-ffcertificate'); ?></th>
                    <td>
                        <div class="ffc-working-hours">
                            <?php
                            $days = array(
                                'mon' => __('Monday', 'wp-ffcertificate'),
                                'tue' => __('Tuesday', 'wp-ffcertificate'),
                                'wed' => __('Wednesday', 'wp-ffcertificate'),
                                'thu' => __('Thursday', 'wp-ffcertificate'),
                                'fri' => __('Friday', 'wp-ffcertificate'),
                                'sat' => __('Saturday', 'wp-ffcertificate'),
                                'sun' => __('Sunday', 'wp-ffcertificate'),
                            );
                            foreach ($days as $key => $label) :
                                $closed = isset($working_hours[$key]['closed']) && $working_hours[$key]['closed'];
                                $start = $working_hours[$key]['start'] ?? '08:00';
                                $end = $working_hours[$key]['end'] ?? '18:00';
                            ?>
                                <div class="ffc-day-row">
                                    <label class="ffc-day-label"><?php echo esc_html($label); ?></label>
                                    <label>
                                        <input type="checkbox" name="working_hours[<?php echo esc_attr($key); ?>][closed]" value="1" <?php checked($closed); ?>>
                                        <?php esc_html_e('Closed', 'wp-ffcertificate'); ?>
                                    </label>
                                    <input type="time" name="working_hours[<?php echo esc_attr($key); ?>][start]" value="<?php echo esc_attr($start); ?>" <?php echo $closed ? 'disabled' : ''; ?>>
                                    <span><?php esc_html_e('to', 'wp-ffcertificate'); ?></span>
                                    <input type="time" name="working_hours[<?php echo esc_attr($key); ?>][end]" value="<?php echo esc_attr($end); ?>" <?php echo $closed ? 'disabled' : ''; ?>>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="description"><?php esc_html_e('Leave times empty to use default (08:00 - 18:00).', 'wp-ffcertificate'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="environment_status"><?php esc_html_e('Status', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="environment_status" id="environment_status">
                            <option value="active" <?php selected($environment->status ?? 'active', 'active'); ?>>
                                <?php esc_html_e('Active', 'wp-ffcertificate'); ?>
                            </option>
                            <option value="inactive" <?php selected($environment->status ?? '', 'inactive'); ?>>
                                <?php esc_html_e('Inactive', 'wp-ffcertificate'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button($id > 0 ? __('Update Environment', 'wp-ffcertificate') : __('Create Environment', 'wp-ffcertificate')); ?>
        </form>

        <style>
            .ffc-working-hours { background: #f6f7f7; padding: 15px; border-radius: 4px; }
            .ffc-day-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
            .ffc-day-label { width: 100px; font-weight: 600; }
            .ffc-day-row input[type="time"] { width: 120px; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('.ffc-day-row input[type="checkbox"]').on('change', function() {
                var row = $(this).closest('.ffc-day-row');
                var inputs = row.find('input[type="time"]');
                inputs.prop('disabled', $(this).is(':checked'));
            });
        });
        </script>
        <?php
    }

    /**
     * Render audiences page
     *
     * @return void
     */
    public function render_audiences_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        ?>
        <div class="wrap">
            <?php
            switch ($action) {
                case 'new':
                case 'edit':
                    $this->render_audience_form($id);
                    break;
                case 'members':
                    $this->render_audience_members($id);
                    break;
                default:
                    $this->render_audiences_list();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render audiences list
     *
     * @return void
     */
    private function render_audiences_list(): void {
        $audiences = AudienceRepository::get_hierarchical();
        $add_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences&action=new');

        ?>
        <h1 class="wp-heading-inline"><?php esc_html_e('Audiences', 'wp-ffcertificate'); ?></h1>
        <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'wp-ffcertificate'); ?></a>
        <hr class="wp-header-end">

        <?php $this->render_admin_notices(); ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="column-name"><?php esc_html_e('Name', 'wp-ffcertificate'); ?></th>
                    <th scope="col" class="column-color"><?php esc_html_e('Color', 'wp-ffcertificate'); ?></th>
                    <th scope="col" class="column-members"><?php esc_html_e('Members', 'wp-ffcertificate'); ?></th>
                    <th scope="col" class="column-status"><?php esc_html_e('Status', 'wp-ffcertificate'); ?></th>
                    <th scope="col" class="column-actions"><?php esc_html_e('Actions', 'wp-ffcertificate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($audiences)) : ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e('No audiences found.', 'wp-ffcertificate'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($audiences as $audience) : ?>
                        <?php $this->render_audience_row($audience, 0); ?>
                        <?php if (!empty($audience->children)) : ?>
                            <?php foreach ($audience->children as $child) : ?>
                                <?php $this->render_audience_row($child, 1); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <style>
            .column-color { width: 80px; }
            .column-members { width: 80px; }
            .ffc-color-swatch { width: 24px; height: 24px; border-radius: 4px; display: inline-block; border: 1px solid #ccc; }
            .ffc-hierarchy-child { padding-left: 25px; }
            .ffc-hierarchy-child::before { content: "└ "; color: #999; }
        </style>
        <?php
    }

    /**
     * Render a single audience row
     *
     * @param object $audience Audience object
     * @param int $level Hierarchy level (0 = parent, 1 = child)
     * @return void
     */
    private function render_audience_row(object $audience, int $level): void {
        $member_count = AudienceRepository::get_member_count($audience->id);
        $edit_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences&action=edit&id=' . $audience->id);
        $members_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences&action=members&id=' . $audience->id);
        $delete_url = wp_nonce_url(
            admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences&action=delete&id=' . $audience->id),
            'delete_audience_' . $audience->id
        );

        ?>
        <tr>
            <td class="column-name <?php echo $level > 0 ? 'ffc-hierarchy-child' : ''; ?>">
                <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($audience->name); ?></a></strong>
            </td>
            <td class="column-color">
                <span class="ffc-color-swatch" style="background-color: <?php echo esc_attr($audience->color); ?>;"></span>
            </td>
            <td class="column-members">
                <a href="<?php echo esc_url($members_url); ?>"><?php echo esc_html($member_count); ?></a>
            </td>
            <td class="column-status">
                <span class="ffc-status-badge ffc-status-<?php echo esc_attr($audience->status); ?>">
                    <?php echo $audience->status === 'active' ? esc_html__('Active', 'wp-ffcertificate') : esc_html__('Inactive', 'wp-ffcertificate'); ?>
                </span>
            </td>
            <td class="column-actions">
                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'wp-ffcertificate'); ?></a> |
                <a href="<?php echo esc_url($members_url); ?>"><?php esc_html_e('Members', 'wp-ffcertificate'); ?></a> |
                <a href="<?php echo esc_url($delete_url); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this audience?', 'wp-ffcertificate'); ?>');">
                    <?php esc_html_e('Delete', 'wp-ffcertificate'); ?>
                </a>
            </td>
        </tr>
        <?php
    }

    /**
     * Render audience form
     *
     * @param int $id Audience ID (0 for new)
     * @return void
     */
    private function render_audience_form(int $id): void {
        $audience = null;
        $page_title = __('Add New Audience', 'wp-ffcertificate');

        if ($id > 0) {
            $audience = AudienceRepository::get_by_id($id);
            if (!$audience) {
                wp_die(__('Audience not found.', 'wp-ffcertificate'));
            }
            $page_title = __('Edit Audience', 'wp-ffcertificate');
        }

        $parents = AudienceRepository::get_parents();
        $back_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences');

        ?>
        <h1><?php echo esc_html($page_title); ?></h1>
        <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to Audiences', 'wp-ffcertificate'); ?></a>

        <?php $this->render_admin_notices(); ?>

        <form method="post" action="" class="ffc-form">
            <?php wp_nonce_field('save_audience', 'ffc_audience_nonce'); ?>
            <input type="hidden" name="audience_id" value="<?php echo esc_attr($id); ?>">
            <input type="hidden" name="ffc_action" value="save_audience">

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="audience_name"><?php esc_html_e('Name', 'wp-ffcertificate'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="text" name="audience_name" id="audience_name" class="regular-text"
                               value="<?php echo esc_attr($audience->name ?? ''); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="audience_color"><?php esc_html_e('Color', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="color" name="audience_color" id="audience_color"
                               value="<?php echo esc_attr($audience->color ?? '#3788d8'); ?>">
                        <p class="description"><?php esc_html_e('Color used for visual identification in calendars.', 'wp-ffcertificate'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="audience_parent"><?php esc_html_e('Parent Audience', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="audience_parent" id="audience_parent">
                            <option value=""><?php esc_html_e('None (top-level audience)', 'wp-ffcertificate'); ?></option>
                            <?php foreach ($parents as $parent) : ?>
                                <?php if ($parent->id !== $id) : // Prevent selecting self as parent ?>
                                    <option value="<?php echo esc_attr($parent->id); ?>" <?php selected($audience->parent_id ?? '', $parent->id); ?>>
                                        <?php echo esc_html($parent->name); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Select a parent to create a sub-group (2-level hierarchy only).', 'wp-ffcertificate'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="audience_status"><?php esc_html_e('Status', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="audience_status" id="audience_status">
                            <option value="active" <?php selected($audience->status ?? 'active', 'active'); ?>>
                                <?php esc_html_e('Active', 'wp-ffcertificate'); ?>
                            </option>
                            <option value="inactive" <?php selected($audience->status ?? '', 'inactive'); ?>>
                                <?php esc_html_e('Inactive', 'wp-ffcertificate'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button($id > 0 ? __('Update Audience', 'wp-ffcertificate') : __('Create Audience', 'wp-ffcertificate')); ?>
        </form>
        <?php
    }

    /**
     * Render audience members page
     *
     * @param int $id Audience ID
     * @return void
     */
    private function render_audience_members(int $id): void {
        $audience = AudienceRepository::get_by_id($id);
        if (!$audience) {
            wp_die(__('Audience not found.', 'wp-ffcertificate'));
        }

        $members = AudienceRepository::get_members($audience->id);
        $back_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences');

        ?>
        <h1><?php echo esc_html(sprintf(__('Members of %s', 'wp-ffcertificate'), $audience->name)); ?></h1>
        <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to Audiences', 'wp-ffcertificate'); ?></a>

        <?php $this->render_admin_notices(); ?>

        <div class="ffc-members-section">
            <h2><?php esc_html_e('Add Members', 'wp-ffcertificate'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('add_members', 'ffc_add_members_nonce'); ?>
                <input type="hidden" name="audience_id" value="<?php echo esc_attr($id); ?>">
                <input type="hidden" name="ffc_action" value="add_members">

                <p>
                    <label for="user_search"><?php esc_html_e('Search users:', 'wp-ffcertificate'); ?></label>
                    <input type="text" id="user_search" class="regular-text" placeholder="<?php esc_attr_e('Type to search...', 'wp-ffcertificate'); ?>">
                </p>
                <div id="user_results" class="ffc-user-results"></div>
                <input type="hidden" name="user_ids" id="selected_user_ids" value="">
                <div id="selected_users" class="ffc-selected-users"></div>
                <?php submit_button(__('Add Selected Members', 'wp-ffcertificate'), 'primary', 'add_members', false); ?>
            </form>
        </div>

        <div class="ffc-members-section">
            <h2><?php esc_html_e('Current Members', 'wp-ffcertificate'); ?> (<?php echo count($members); ?>)</h2>

            <?php if (empty($members)) : ?>
                <p><?php esc_html_e('No members yet.', 'wp-ffcertificate'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('User', 'wp-ffcertificate'); ?></th>
                            <th><?php esc_html_e('Email', 'wp-ffcertificate'); ?></th>
                            <th class="column-actions"><?php esc_html_e('Actions', 'wp-ffcertificate'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $user_id) : ?>
                            <?php $user = get_user_by('id', $user_id); ?>
                            <?php if ($user) : ?>
                                <?php
                                $remove_url = wp_nonce_url(
                                    admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences&action=members&id=' . $id . '&remove_user=' . $user_id),
                                    'remove_member_' . $user_id
                                );
                                ?>
                                <tr>
                                    <td><?php echo esc_html($user->display_name); ?></td>
                                    <td><?php echo esc_html($user->user_email); ?></td>
                                    <td class="column-actions">
                                        <a href="<?php echo esc_url($remove_url); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e('Remove this member?', 'wp-ffcertificate'); ?>');">
                                            <?php esc_html_e('Remove', 'wp-ffcertificate'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <style>
            .ffc-members-section { background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #c3c4c7; }
            .ffc-user-results { max-height: 200px; overflow-y: auto; border: 1px solid #ddd; display: none; }
            .ffc-user-results.active { display: block; }
            .ffc-user-result { padding: 8px 12px; cursor: pointer; }
            .ffc-user-result:hover { background: #f0f0f1; }
            .ffc-selected-users { margin: 15px 0; }
            .ffc-selected-user { display: inline-block; background: #2271b1; color: #fff; padding: 5px 10px; margin: 3px; border-radius: 3px; }
            .ffc-selected-user .remove { cursor: pointer; margin-left: 8px; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var selectedUsers = {};
            var searchTimeout;

            $('#user_search').on('input', function() {
                clearTimeout(searchTimeout);
                var query = $(this).val();
                if (query.length < 2) {
                    $('#user_results').removeClass('active').empty();
                    return;
                }

                searchTimeout = setTimeout(function() {
                    $.ajax({
                        url: ajaxurl,
                        data: {
                            action: 'ffc_search_users',
                            query: query,
                            nonce: '<?php echo wp_create_nonce('ffc_search_users'); ?>'
                        },
                        success: function(response) {
                            if (response.success && response.data.length > 0) {
                                var html = '';
                                response.data.forEach(function(user) {
                                    if (!selectedUsers[user.id]) {
                                        html += '<div class="ffc-user-result" data-id="' + user.id + '" data-name="' + user.name + '">' + user.name + ' (' + user.email + ')</div>';
                                    }
                                });
                                $('#user_results').html(html).addClass('active');
                            } else {
                                $('#user_results').removeClass('active').empty();
                            }
                        }
                    });
                }, 300);
            });

            $(document).on('click', '.ffc-user-result', function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                selectedUsers[id] = name;
                updateSelectedUsers();
                $('#user_results').removeClass('active').empty();
                $('#user_search').val('');
            });

            $(document).on('click', '.ffc-selected-user .remove', function() {
                var id = $(this).data('id');
                delete selectedUsers[id];
                updateSelectedUsers();
            });

            function updateSelectedUsers() {
                var html = '';
                var ids = [];
                for (var id in selectedUsers) {
                    html += '<span class="ffc-selected-user">' + selectedUsers[id] + '<span class="remove" data-id="' + id + '">&times;</span></span>';
                    ids.push(id);
                }
                $('#selected_users').html(html);
                $('#selected_user_ids').val(ids.join(','));
            }
        });
        </script>
        <?php
    }

    /**
     * Render bookings page
     *
     * @return void
     */
    public function render_bookings_page(): void {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bookings', 'wp-ffcertificate'); ?></h1>
            <p><?php esc_html_e('Booking management will be available in the next phase.', 'wp-ffcertificate'); ?></p>
        </div>
        <?php
    }

    /**
     * Render settings page
     *
     * @return void
     */
    public function render_settings_page(): void {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Scheduling Settings', 'wp-ffcertificate'); ?></h1>
            <p><?php esc_html_e('Settings will be available in a future update.', 'wp-ffcertificate'); ?></p>
        </div>
        <?php
    }

    /**
     * Render import page
     *
     * @return void
     */
    public function render_import_page(): void {
        $audiences = AudienceRepository::get_hierarchical();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Import', 'wp-ffcertificate'); ?></h1>

            <?php $this->render_admin_notices(); ?>

            <div class="ffc-import-sections">
                <!-- Import Members -->
                <div class="ffc-import-section">
                    <h2><?php esc_html_e('Import Members', 'wp-ffcertificate'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Import users as members of audience groups. Users will be created if they do not exist.', 'wp-ffcertificate'); ?>
                    </p>

                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('ffc_import_members', 'ffc_import_members_nonce'); ?>
                        <input type="hidden" name="ffc_action" value="import_members">

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="members_csv"><?php esc_html_e('CSV File', 'wp-ffcertificate'); ?></label>
                                </th>
                                <td>
                                    <input type="file" name="members_csv" id="members_csv" accept=".csv" required>
                                    <p class="description">
                                        <?php esc_html_e('Required columns: email. Optional: name, audience_id or audience_name.', 'wp-ffcertificate'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="import_audience_id"><?php esc_html_e('Target Audience', 'wp-ffcertificate'); ?></label>
                                </th>
                                <td>
                                    <select name="import_audience_id" id="import_audience_id">
                                        <option value=""><?php esc_html_e('Use audience from CSV', 'wp-ffcertificate'); ?></option>
                                        <?php foreach ($audiences as $audience) : ?>
                                            <option value="<?php echo esc_attr($audience->id); ?>">
                                                <?php echo esc_html($audience->name); ?>
                                            </option>
                                            <?php if (!empty($audience->children)) : ?>
                                                <?php foreach ($audience->children as $child) : ?>
                                                    <option value="<?php echo esc_attr($child->id); ?>">
                                                        &nbsp;&nbsp;&nbsp;<?php echo esc_html($child->name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('Select a specific audience or leave empty to use audience_id/audience_name from CSV.', 'wp-ffcertificate'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Options', 'wp-ffcertificate'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="create_users" value="1" checked>
                                        <?php esc_html_e('Create users if they do not exist (with ffc_user role)', 'wp-ffcertificate'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Import Members', 'wp-ffcertificate'), 'primary', 'import_members'); ?>
                    </form>

                    <div class="ffc-sample-csv">
                        <h4><?php esc_html_e('Sample CSV Format', 'wp-ffcertificate'); ?></h4>
                        <pre><?php echo esc_html(AudienceCsvImporter::get_sample_csv('members')); ?></pre>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . self::MENU_SLUG . '-import&download_sample=members'), 'download_sample')); ?>" class="button">
                            <?php esc_html_e('Download Sample', 'wp-ffcertificate'); ?>
                        </a>
                    </div>
                </div>

                <!-- Import Audiences -->
                <div class="ffc-import-section">
                    <h2><?php esc_html_e('Import Audiences', 'wp-ffcertificate'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Import audience groups from a CSV file. Parent groups are created first, then children.', 'wp-ffcertificate'); ?>
                    </p>

                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('ffc_import_audiences', 'ffc_import_audiences_nonce'); ?>
                        <input type="hidden" name="ffc_action" value="import_audiences">

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="audiences_csv"><?php esc_html_e('CSV File', 'wp-ffcertificate'); ?></label>
                                </th>
                                <td>
                                    <input type="file" name="audiences_csv" id="audiences_csv" accept=".csv" required>
                                    <p class="description">
                                        <?php esc_html_e('Required columns: name. Optional: color, parent (parent audience name).', 'wp-ffcertificate'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Import Audiences', 'wp-ffcertificate'), 'primary', 'import_audiences'); ?>
                    </form>

                    <div class="ffc-sample-csv">
                        <h4><?php esc_html_e('Sample CSV Format', 'wp-ffcertificate'); ?></h4>
                        <pre><?php echo esc_html(AudienceCsvImporter::get_sample_csv('audiences')); ?></pre>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . self::MENU_SLUG . '-import&download_sample=audiences'), 'download_sample')); ?>" class="button">
                            <?php esc_html_e('Download Sample', 'wp-ffcertificate'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .ffc-import-sections { display: flex; flex-wrap: wrap; gap: 30px; margin-top: 20px; }
            .ffc-import-section { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; flex: 1; min-width: 400px; }
            .ffc-import-section h2 { margin-top: 0; }
            .ffc-sample-csv { margin-top: 20px; padding-top: 20px; border-top: 1px solid #c3c4c7; }
            .ffc-sample-csv pre { background: #f6f7f7; padding: 15px; overflow-x: auto; font-size: 12px; }
            .ffc-sample-csv h4 { margin-bottom: 10px; }
        </style>
        <?php
    }

    /**
     * Handle CSV import
     *
     * @return void
     */
    private function handle_csv_import(): void {
        // Handle sample download
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['download_sample']) && isset($_GET['_wpnonce'])) {
            $type = sanitize_text_field(wp_unslash($_GET['download_sample']));
            if (wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'download_sample')) {
                $filename = $type === 'audiences' ? 'audiences-sample.csv' : 'members-sample.csv';
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo AudienceCsvImporter::get_sample_csv($type);
                exit;
            }
        }

        // Handle members import
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'import_members') {
            if (!isset($_POST['ffc_import_members_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_import_members_nonce'])), 'ffc_import_members')) {
                return;
            }

            if (!isset($_FILES['members_csv']) || $_FILES['members_csv']['error'] !== UPLOAD_ERR_OK) {
                $this->add_admin_notice('error', __('File upload failed.', 'wp-ffcertificate'));
                return;
            }

            $audience_id = isset($_POST['import_audience_id']) ? absint($_POST['import_audience_id']) : 0;
            $create_users = isset($_POST['create_users']) && $_POST['create_users'] === '1';

            $result = AudienceCsvImporter::import_members(
                $_FILES['members_csv']['tmp_name'],
                $audience_id,
                $create_users
            );

            if ($result['success']) {
                $message = sprintf(
                    __('Import completed. %d imported, %d skipped.', 'wp-ffcertificate'),
                    $result['imported'],
                    $result['skipped']
                );
                if (!empty($result['errors'])) {
                    $message .= ' ' . sprintf(__('%d errors occurred.', 'wp-ffcertificate'), count($result['errors']));
                }
                $this->add_admin_notice('success', $message);

                // Show first 5 errors
                foreach (array_slice($result['errors'], 0, 5) as $error) {
                    $this->add_admin_notice('warning', $error);
                }
            } else {
                $this->add_admin_notice('error', implode(' ', $result['errors']));
            }
        }

        // Handle audiences import
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'import_audiences') {
            if (!isset($_POST['ffc_import_audiences_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_import_audiences_nonce'])), 'ffc_import_audiences')) {
                return;
            }

            if (!isset($_FILES['audiences_csv']) || $_FILES['audiences_csv']['error'] !== UPLOAD_ERR_OK) {
                $this->add_admin_notice('error', __('File upload failed.', 'wp-ffcertificate'));
                return;
            }

            $result = AudienceCsvImporter::import_audiences($_FILES['audiences_csv']['tmp_name']);

            if ($result['success']) {
                $message = sprintf(
                    __('Import completed. %d imported, %d skipped.', 'wp-ffcertificate'),
                    $result['imported'],
                    $result['skipped']
                );
                if (!empty($result['errors'])) {
                    $message .= ' ' . sprintf(__('%d errors occurred.', 'wp-ffcertificate'), count($result['errors']));
                }
                $this->add_admin_notice('success', $message);

                // Show first 5 errors
                foreach (array_slice($result['errors'], 0, 5) as $error) {
                    $this->add_admin_notice('warning', $error);
                }
            } else {
                $this->add_admin_notice('error', implode(' ', $result['errors']));
            }
        }
    }

    /**
     * Handle calendar actions (save, delete)
     *
     * @return void
     */
    private function handle_calendar_actions(): void {
        // Handle save
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'save_schedule') {
            if (!isset($_POST['ffc_schedule_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_schedule_nonce'])), 'save_schedule')) {
                return;
            }

            $id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 0;
            $data = array(
                'name' => isset($_POST['schedule_name']) ? sanitize_text_field(wp_unslash($_POST['schedule_name'])) : '',
                'description' => isset($_POST['schedule_description']) ? sanitize_textarea_field(wp_unslash($_POST['schedule_description'])) : '',
                'visibility' => isset($_POST['schedule_visibility']) ? sanitize_text_field(wp_unslash($_POST['schedule_visibility'])) : 'private',
                'future_days_limit' => isset($_POST['schedule_future_days']) && $_POST['schedule_future_days'] !== '' ? absint($_POST['schedule_future_days']) : null,
                'notify_on_booking' => isset($_POST['schedule_notify_booking']) ? 1 : 0,
                'notify_on_cancellation' => isset($_POST['schedule_notify_cancel']) ? 1 : 0,
                'include_ics' => isset($_POST['schedule_include_ics']) ? 1 : 0,
                'status' => isset($_POST['schedule_status']) ? sanitize_text_field(wp_unslash($_POST['schedule_status'])) : 'active',
            );

            if ($id > 0) {
                AudienceScheduleRepository::update($id, $data);
                $this->add_admin_notice('success', __('Calendar updated successfully.', 'wp-ffcertificate'));
            } else {
                $new_id = AudienceScheduleRepository::create($data);
                if ($new_id) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '-calendars&action=edit&id=' . $new_id . '&message=created'));
                    exit;
                }
            }
        }

        // Handle delete
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            $id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'delete_schedule_' . $id)) {
                AudienceScheduleRepository::delete($id);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '-calendars&message=deleted'));
                exit;
            }
        }
    }

    /**
     * Handle environment actions (save, delete)
     *
     * @return void
     */
    private function handle_environment_actions(): void {
        // Handle save
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'save_environment') {
            if (!isset($_POST['ffc_environment_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_environment_nonce'])), 'save_environment')) {
                return;
            }

            $id = isset($_POST['environment_id']) ? absint($_POST['environment_id']) : 0;

            // Process working hours
            $working_hours = array();
            if (isset($_POST['working_hours']) && is_array($_POST['working_hours'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                foreach ($_POST['working_hours'] as $day => $hours) {
                    $day = sanitize_key($day);
                    $working_hours[$day] = array(
                        'closed' => isset($hours['closed']) ? true : false,
                        'start' => isset($hours['start']) ? sanitize_text_field($hours['start']) : '08:00',
                        'end' => isset($hours['end']) ? sanitize_text_field($hours['end']) : '18:00',
                    );
                }
            }

            $data = array(
                'schedule_id' => isset($_POST['environment_schedule']) ? absint($_POST['environment_schedule']) : 0,
                'name' => isset($_POST['environment_name']) ? sanitize_text_field(wp_unslash($_POST['environment_name'])) : '',
                'description' => isset($_POST['environment_description']) ? sanitize_textarea_field(wp_unslash($_POST['environment_description'])) : '',
                'working_hours' => $working_hours,
                'status' => isset($_POST['environment_status']) ? sanitize_text_field(wp_unslash($_POST['environment_status'])) : 'active',
            );

            if ($id > 0) {
                AudienceEnvironmentRepository::update($id, $data);
                $this->add_admin_notice('success', __('Environment updated successfully.', 'wp-ffcertificate'));
            } else {
                $new_id = AudienceEnvironmentRepository::create($data);
                if ($new_id) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '-environments&action=edit&id=' . $new_id . '&message=created'));
                    exit;
                }
            }
        }

        // Handle delete
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && isset($_GET['page']) && $_GET['page'] === self::MENU_SLUG . '-environments') {
            $id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'delete_environment_' . $id)) {
                AudienceEnvironmentRepository::delete($id);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '-environments&message=deleted'));
                exit;
            }
        }
    }

    /**
     * Handle audience actions (save, delete, members)
     *
     * @return void
     */
    private function handle_audience_actions(): void {
        // Handle save
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'save_audience') {
            if (!isset($_POST['ffc_audience_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_audience_nonce'])), 'save_audience')) {
                return;
            }

            $id = isset($_POST['audience_id']) ? absint($_POST['audience_id']) : 0;
            $data = array(
                'name' => isset($_POST['audience_name']) ? sanitize_text_field(wp_unslash($_POST['audience_name'])) : '',
                'color' => isset($_POST['audience_color']) ? sanitize_hex_color(wp_unslash($_POST['audience_color'])) : '#3788d8',
                'parent_id' => isset($_POST['audience_parent']) && $_POST['audience_parent'] !== '' ? absint($_POST['audience_parent']) : null,
                'status' => isset($_POST['audience_status']) ? sanitize_text_field(wp_unslash($_POST['audience_status'])) : 'active',
            );

            if ($id > 0) {
                AudienceRepository::update($id, $data);
                $this->add_admin_notice('success', __('Audience updated successfully.', 'wp-ffcertificate'));
            } else {
                $new_id = AudienceRepository::create($data);
                if ($new_id) {
                    wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences&action=edit&id=' . $new_id . '&message=created'));
                    exit;
                }
            }
        }

        // Handle add members
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'add_members') {
            if (!isset($_POST['ffc_add_members_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_add_members_nonce'])), 'add_members')) {
                return;
            }

            $audience_id = isset($_POST['audience_id']) ? absint($_POST['audience_id']) : 0;
            $user_ids_string = isset($_POST['user_ids']) ? sanitize_text_field(wp_unslash($_POST['user_ids'])) : '';

            if ($audience_id > 0 && !empty($user_ids_string)) {
                $user_ids = array_map('absint', explode(',', $user_ids_string));
                $added = AudienceRepository::bulk_add_members($audience_id, $user_ids);
                $this->add_admin_notice('success', sprintf(__('%d member(s) added successfully.', 'wp-ffcertificate'), $added));
            }
        }

        // Handle remove member
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['remove_user']) && isset($_GET['id'])) {
            $user_id = absint($_GET['remove_user']);
            $audience_id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'remove_member_' . $user_id)) {
                AudienceRepository::remove_member($audience_id, $user_id);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences&action=members&id=' . $audience_id . '&message=member_removed'));
                exit;
            }
        }

        // Handle delete
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && isset($_GET['page']) && $_GET['page'] === self::MENU_SLUG . '-audiences') {
            $id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'delete_audience_' . $id)) {
                AudienceRepository::delete($id);
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '-audiences&message=deleted'));
                exit;
            }
        }
    }

    /**
     * Add admin notice
     *
     * @param string $type Notice type (success, error, warning, info)
     * @param string $message Notice message
     * @return void
     */
    private function add_admin_notice(string $type, string $message): void {
        add_settings_error('ffc_audience', 'ffc_audience_notice', $message, $type);
    }

    /**
     * Render admin notices
     *
     * @return void
     */
    private function render_admin_notices(): void {
        settings_errors('ffc_audience');

        // Check for URL message parameter
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['message'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $message = sanitize_text_field(wp_unslash($_GET['message']));
            $notice = '';
            switch ($message) {
                case 'created':
                    $notice = __('Item created successfully.', 'wp-ffcertificate');
                    break;
                case 'deleted':
                    $notice = __('Item deleted successfully.', 'wp-ffcertificate');
                    break;
                case 'member_removed':
                    $notice = __('Member removed successfully.', 'wp-ffcertificate');
                    break;
            }
            if ($notice) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
            }
        }
    }
}
