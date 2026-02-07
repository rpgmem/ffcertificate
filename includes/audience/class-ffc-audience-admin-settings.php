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
            <h1><?php esc_html_e('Scheduling Settings', 'wp-ffcertificate'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-settings&tab=general')); ?>"
                   class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('General', 'wp-ffcertificate'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-settings&tab=self-scheduling')); ?>"
                   class="nav-tab <?php echo $active_tab === 'self-scheduling' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Self-Scheduling', 'wp-ffcertificate'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-settings&tab=audience')); ?>"
                   class="nav-tab <?php echo $active_tab === 'audience' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Audience', 'wp-ffcertificate'); ?>
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
            <h2><?php esc_html_e('General Settings', 'wp-ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('General scheduling settings that apply to both Self-Scheduling and Audience systems.', 'wp-ffcertificate'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'wp-ffcertificate'); ?></th>
                        <td>
                            <p>
                                <strong><?php esc_html_e('Self-Scheduling:', 'wp-ffcertificate'); ?></strong>
                                <?php
                                $calendars_count = wp_count_posts('ffc_self_scheduling');
                                $published = isset($calendars_count->publish) ? $calendars_count->publish : 0;
                                printf(
                                    /* translators: %d: number of published calendars */
                                    esc_html__('%d published calendar(s)', 'wp-ffcertificate'),
                                    (int) $published
                                );
                                ?>
                            </p>
                            <p>
                                <strong><?php esc_html_e('Audience:', 'wp-ffcertificate'); ?></strong>
                                <?php
                                printf(
                                    /* translators: %d: number of active schedules */
                                    esc_html__('%d active schedule(s)', 'wp-ffcertificate'),
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
            <h2><?php esc_html_e('Global Holidays', 'wp-ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Holidays added here will block bookings across all calendars in both scheduling systems. Use per-calendar blocked dates for calendar-specific closures.', 'wp-ffcertificate'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('ffc_global_holiday_action', 'ffc_global_holiday_nonce'); ?>
                <input type="hidden" name="ffc_action" value="add_global_holiday">
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="global_holiday_date"><?php esc_html_e('Date', 'wp-ffcertificate'); ?></label>
                            </th>
                            <td>
                                <input type="date" id="global_holiday_date" name="global_holiday_date" required class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="global_holiday_description"><?php esc_html_e('Description', 'wp-ffcertificate'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="global_holiday_description" name="global_holiday_description"
                                       placeholder="<?php esc_attr_e('e.g. Christmas, Carnival...', 'wp-ffcertificate'); ?>"
                                       class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th></th>
                            <td>
                                <button type="submit" class="button button-primary">
                                    <?php esc_html_e('Add Holiday', 'wp-ffcertificate'); ?>
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
                            <th><?php esc_html_e('Date', 'wp-ffcertificate'); ?></th>
                            <th><?php esc_html_e('Description', 'wp-ffcertificate'); ?></th>
                            <th><?php esc_html_e('Actions', 'wp-ffcertificate'); ?></th>
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
                                       onclick="return confirm('<?php esc_attr_e('Remove this holiday?', 'wp-ffcertificate'); ?>');">
                                        <?php esc_html_e('Delete', 'wp-ffcertificate'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description ffc-mt-15">
                    <?php esc_html_e('No global holidays configured.', 'wp-ffcertificate'); ?>
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
        ?>
        <div class="card">
            <h2><?php esc_html_e('Self-Scheduling Settings', 'wp-ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Settings specific to the personal appointment booking system. Calendar-specific settings (slots, working hours, email templates) are configured on each calendar\'s edit page.', 'wp-ffcertificate'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Manage Calendars', 'wp-ffcertificate'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(admin_url('edit.php?post_type=ffc_self_scheduling')); ?>" class="button">
                                <?php esc_html_e('View All Calendars', 'wp-ffcertificate'); ?>
                            </a>
                            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ffc_self_scheduling')); ?>" class="button">
                                <?php esc_html_e('Add New Calendar', 'wp-ffcertificate'); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Appointments', 'wp-ffcertificate'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=ffc-appointments')); ?>" class="button">
                                <?php esc_html_e('View All Appointments', 'wp-ffcertificate'); ?>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="description">
                <?php esc_html_e('Additional self-scheduling settings will be available in a future update.', 'wp-ffcertificate'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render Audience settings tab
     *
     * @return void
     */
    private function render_audience_tab(): void {
        ?>
        <div class="card">
            <h2><?php esc_html_e('Audience Scheduling Settings', 'wp-ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Settings specific to the audience/group booking system.', 'wp-ffcertificate'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Manage', 'wp-ffcertificate'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-calendars')); ?>" class="button">
                                <?php esc_html_e('Audience Calendars', 'wp-ffcertificate'); ?>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-environments')); ?>" class="button">
                                <?php esc_html_e('Environments', 'wp-ffcertificate'); ?>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-audiences')); ?>" class="button">
                                <?php esc_html_e('Audiences', 'wp-ffcertificate'); ?>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="description">
                <?php esc_html_e('Additional audience settings will be available in a future update.', 'wp-ffcertificate'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Handle global holiday add/delete actions
     *
     * @return void
     */
    public function handle_global_holiday_actions(): void {
        // Add global holiday (POST)
        if (isset($_POST['ffc_action']) && $_POST['ffc_action'] === 'add_global_holiday') {
            if (!isset($_POST['ffc_global_holiday_nonce']) ||
                !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_global_holiday_nonce'])), 'ffc_global_holiday_action')) {
                return;
            }

            if (!current_user_can('manage_options')) {
                return;
            }

            $date = isset($_POST['global_holiday_date']) ? sanitize_text_field(wp_unslash($_POST['global_holiday_date'])) : '';
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
