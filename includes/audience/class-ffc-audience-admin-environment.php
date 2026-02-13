<?php
/**
 * Audience Admin Environment sub-page.
 *
 * @package FreeFormCertificate\Audience
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

/**
 * Handles the Environments admin sub-page rendering and actions.
 */
class AudienceAdminEnvironment {

    /**
     * Menu slug used to build admin URLs.
     *
     * @var string
     */
    private string $menu_slug;

    /**
     * Constructor.
     *
     * @param string $menu_slug The parent menu slug.
     */
    public function __construct( string $menu_slug ) {
        $this->menu_slug = $menu_slug;
    }

    /**
     * Render environments page
     *
     * @return void
     */
    public function render_page(): void {
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
                    $this->render_form($id);
                    break;
                default:
                    $this->render_list();
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
    private function render_list(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_schedule = isset($_GET['schedule_id']) ? absint($_GET['schedule_id']) : 0;

        $args = array();
        if ($filter_schedule > 0) {
            $args['schedule_id'] = $filter_schedule;
        }
        $environments = AudienceEnvironmentRepository::get_all($args);
        $schedules = AudienceScheduleRepository::get_all();

        // Build schedule name map for sorting
        $schedule_name_map = array();
        foreach ($schedules as $schedule) {
            $schedule_name_map[(int) $schedule->id] = $schedule->name;
        }

        // Sort by calendar name first, then environment name
        usort($environments, function ($a, $b) use ($schedule_name_map) {
            $cal_a = $schedule_name_map[(int) $a->schedule_id] ?? '';
            $cal_b = $schedule_name_map[(int) $b->schedule_id] ?? '';
            $cmp = strnatcasecmp($cal_a, $cal_b);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strnatcasecmp($a->name, $b->name);
        });
        $add_url = admin_url('admin.php?page=' . $this->menu_slug . '-environments&action=new');

        // Dynamic label: use filtered schedule's label or default
        $env_label = $filter_schedule > 0
            ? AudienceScheduleRepository::get_environment_label($filter_schedule)
            : AudienceScheduleRepository::get_environment_label();

        ?>
        <h1 class="wp-heading-inline"><?php echo esc_html($env_label); ?></h1>
        <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'ffcertificate'); ?></a>
        <hr class="wp-header-end">

        <?php settings_errors('ffc_audience'); ?>

        <!-- Filter form -->
        <form method="get" class="ffc-filter-form">
            <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_slug . '-environments'); ?>">
            <select name="schedule_id">
                <option value=""><?php esc_html_e('All Calendars', 'ffcertificate'); ?></option>
                <?php foreach ($schedules as $schedule) : ?>
                    <option value="<?php echo esc_attr($schedule->id); ?>" <?php selected($filter_schedule, $schedule->id); ?>>
                        <?php echo esc_html($schedule->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter', 'ffcertificate'), 'secondary', 'filter', false); ?>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="column-color" style="width: 50px;"><?php esc_html_e('Color', 'ffcertificate'); ?></th>
                    <th scope="col" class="column-name"><?php esc_html_e('Name', 'ffcertificate'); ?></th>
                    <th scope="col" class="column-calendar"><?php esc_html_e('Calendar', 'ffcertificate'); ?></th>
                    <th scope="col" class="column-status"><?php esc_html_e('Status', 'ffcertificate'); ?></th>
                    <th scope="col" class="column-actions"><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($environments)) : ?>
                    <tr>
                        <td colspan="5">
                            <?php
                            /* translators: %s: environment label (e.g. "Environments", "Rooms") */
                            printf(esc_html__('No %s found.', 'ffcertificate'), esc_html(mb_strtolower($env_label)));
                            ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($environments as $env) : ?>
                        <?php
                        $schedule_name = $schedule_name_map[(int) $env->schedule_id] ?? '—';
                        $edit_url = admin_url('admin.php?page=' . $this->menu_slug . '-environments&action=edit&id=' . $env->id);
                        $is_active = ($env->status === 'active');
                        $env_label_singular = mb_strtolower(AudienceScheduleRepository::get_environment_label(isset($env->schedule_id) ? (int) $env->schedule_id : null, true));

                        if ($is_active) {
                            $deactivate_url = wp_nonce_url(
                                admin_url('admin.php?page=' . $this->menu_slug . '-environments&action=deactivate&id=' . $env->id),
                                'deactivate_environment_' . $env->id
                            );
                        } else {
                            $delete_url = wp_nonce_url(
                                admin_url('admin.php?page=' . $this->menu_slug . '-environments&action=delete&id=' . $env->id),
                                'delete_environment_' . $env->id
                            );
                        }
                        ?>
                        <tr>
                            <td class="column-color" style="text-align: center;">
                                <span style="display: inline-block; width: 20px; height: 20px; border-radius: 50%; background-color: <?php echo esc_attr($env->color ?? '#3788d8'); ?>; border: 1px solid rgba(0,0,0,0.1);"></span>
                            </td>
                            <td class="column-name">
                                <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($env->name); ?></a></strong>
                                <?php if ($env->description) : ?>
                                    <p class="description"><?php echo esc_html(wp_trim_words($env->description, 15)); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="column-calendar">
                                <?php echo esc_html($schedule_name); ?>
                            </td>
                            <td class="column-status">
                                <span class="ffc-status-badge ffc-status-<?php echo esc_attr($env->status); ?>">
                                    <?php echo $is_active ? esc_html__('Active', 'ffcertificate') : esc_html__('Inactive', 'ffcertificate'); ?>
                                </span>
                            </td>
                            <td class="column-actions">
                                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'ffcertificate'); ?></a> |
                                <?php if ($is_active) : ?>
                                    <a href="<?php echo esc_url($deactivate_url); ?>" class="delete-link" onclick="return confirm('<?php
                                        /* translators: %s: environment label (singular) */
                                        printf(esc_attr__('Are you sure you want to deactivate this %s?', 'ffcertificate'), esc_attr($env_label_singular));
                                        ?>');">
                                        <?php esc_html_e('Deactivate', 'ffcertificate'); ?>
                                    </a>
                                <?php else : ?>
                                    <a href="<?php echo esc_url($delete_url); ?>" class="delete-link" onclick="return confirm('<?php
                                        /* translators: %s: environment label (singular) */
                                        printf(esc_attr__('Are you sure you want to permanently delete this %s?', 'ffcertificate'), esc_attr($env_label_singular));
                                        ?>');">
                                        <?php esc_html_e('Delete', 'ffcertificate'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Styles in ffc-audience-admin.css -->
        <?php
    }

    /**
     * Render environment form
     *
     * @param int $id Environment ID (0 for new)
     * @return void
     */
    private function render_form(int $id): void {
        $environment = null;

        // Get the schedule for dynamic label
        $schedule_id = 0;
        if ($id > 0) {
            $environment = AudienceEnvironmentRepository::get_by_id($id);
            if (!$environment) {
                wp_die(esc_html__('Environment not found.', 'ffcertificate'));
            }
            $schedule_id = (int) ($environment->schedule_id ?? 0);
        }

        $env_label_singular = AudienceScheduleRepository::get_environment_label($schedule_id ?: null, true);
        $env_label_plural = AudienceScheduleRepository::get_environment_label($schedule_id ?: null);

        $page_title = $id > 0
            /* translators: %s: environment label (singular, e.g. "Room") */
            ? sprintf(__('Edit %s', 'ffcertificate'), $env_label_singular)
            /* translators: %s: environment label (singular, e.g. "Room") */
            : sprintf(__('Add New %s', 'ffcertificate'), $env_label_singular);

        $schedules = AudienceScheduleRepository::get_all(array('status' => 'active'));
        $back_url = admin_url('admin.php?page=' . $this->menu_slug . '-environments');

        // Parse working hours
        $working_hours = array();
        if ($environment && $environment->working_hours) {
            $working_hours = json_decode($environment->working_hours, true) ?: array();
        }

        ?>
        <h1><?php echo esc_html($page_title); ?></h1>
        <a href="<?php echo esc_url($back_url); ?>">&larr;
            <?php
            /* translators: %s: environment label (plural, e.g. "Environments", "Rooms") */
            printf(esc_html__('Back to %s', 'ffcertificate'), esc_html($env_label_plural));
            ?>
        </a>

        <?php settings_errors('ffc_audience'); ?>

        <form method="post" action="" class="ffc-form">
            <?php wp_nonce_field('save_environment', 'ffc_environment_nonce'); ?>
            <input type="hidden" name="environment_id" value="<?php echo esc_attr($id); ?>">
            <input type="hidden" name="ffc_action" value="save_environment">

            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row">
                        <label for="environment_schedule"><?php esc_html_e('Calendar', 'ffcertificate'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <select name="environment_schedule" id="environment_schedule" required>
                            <option value=""><?php esc_html_e('Select a calendar', 'ffcertificate'); ?></option>
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
                        <label for="environment_name"><?php esc_html_e('Name', 'ffcertificate'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="text" name="environment_name" id="environment_name" class="regular-text"
                               value="<?php echo esc_attr($environment->name ?? ''); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="environment_description"><?php esc_html_e('Description', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <textarea name="environment_description" id="environment_description" rows="3" class="large-text"><?php echo esc_textarea($environment->description ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="environment_color"><?php esc_html_e('Color', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="color" name="environment_color" id="environment_color"
                               value="<?php echo esc_attr($environment->color ?? '#3788d8'); ?>"
                               style="width: 60px; height: 36px; padding: 2px; cursor: pointer;">
                        <span class="description" style="vertical-align: middle; margin-left: 8px;">
                            <?php echo esc_html($environment->color ?? '#3788d8'); ?>
                        </span>
                        <p class="description">
                            <?php esc_html_e('Color used to identify this environment in the calendar.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Working Hours', 'ffcertificate'); ?></th>
                    <td>
                        <div class="ffc-working-hours">
                            <?php
                            $days = array(
                                'mon' => __('Monday', 'ffcertificate'),
                                'tue' => __('Tuesday', 'ffcertificate'),
                                'wed' => __('Wednesday', 'ffcertificate'),
                                'thu' => __('Thursday', 'ffcertificate'),
                                'fri' => __('Friday', 'ffcertificate'),
                                'sat' => __('Saturday', 'ffcertificate'),
                                'sun' => __('Sunday', 'ffcertificate'),
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
                                        <?php esc_html_e('Closed', 'ffcertificate'); ?>
                                    </label>
                                    <input type="time" name="working_hours[<?php echo esc_attr($key); ?>][start]" value="<?php echo esc_attr($start); ?>" <?php echo $closed ? 'disabled' : ''; ?>>
                                    <span><?php esc_html_e('to', 'ffcertificate'); ?></span>
                                    <input type="time" name="working_hours[<?php echo esc_attr($key); ?>][end]" value="<?php echo esc_attr($end); ?>" <?php echo $closed ? 'disabled' : ''; ?>>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="description"><?php esc_html_e('Leave times empty to use default (08:00 - 18:00).', 'ffcertificate'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="environment_status"><?php esc_html_e('Status', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="environment_status" id="environment_status">
                            <option value="active" <?php selected($environment->status ?? 'active', 'active'); ?>>
                                <?php esc_html_e('Active', 'ffcertificate'); ?>
                            </option>
                            <option value="inactive" <?php selected($environment->status ?? '', 'inactive'); ?>>
                                <?php esc_html_e('Inactive', 'ffcertificate'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
            </tbody></table>

            <?php
            submit_button($id > 0
                /* translators: %s: environment label (singular, e.g. "Room") */
                ? sprintf(__('Update %s', 'ffcertificate'), $env_label_singular)
                /* translators: %s: environment label (singular, e.g. "Room") */
                : sprintf(__('Create %s', 'ffcertificate'), $env_label_singular)
            );
            ?>
        </form>

        <!-- Styles in ffc-audience-admin.css -->
        <!-- Scripts in ffc-audience-admin.js -->
        <?php
    }

    /**
     * Handle environment actions (save, delete)
     *
     * @return void
     */
    public function handle_actions(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Show feedback for redirect-based actions
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['message']) && isset($_GET['page']) && $_GET['page'] === $this->menu_slug . '-environments') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $msg = sanitize_text_field(wp_unslash($_GET['message']));
            $label = AudienceScheduleRepository::get_environment_label(0, true);
            $messages = array(
                /* translators: %s: environment label (singular) */
                'created' => sprintf(__('%s created successfully.', 'ffcertificate'), $label),
                /* translators: %s: environment label (singular) */
                'deactivated' => sprintf(__('%s deactivated successfully.', 'ffcertificate'), $label),
                /* translators: %s: environment label (singular) */
                'deleted' => sprintf(__('%s deleted successfully.', 'ffcertificate'), $label),
            );
            if (isset($messages[$msg])) {
                add_settings_error('ffc_audience', 'ffc_message', $messages[$msg], 'success');
            }
        }

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
                foreach (wp_unslash($_POST['working_hours']) as $day => $hours) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    $day = sanitize_key($day);
                    $working_hours[$day] = array(
                        'closed' => isset($hours['closed']) ? true : false,
                        'start' => isset($hours['start']) ? sanitize_text_field($hours['start']) : '08:00',
                        'end' => isset($hours['end']) ? sanitize_text_field($hours['end']) : '18:00',
                    );
                }
            }

            $color = isset($_POST['environment_color']) ? sanitize_hex_color(wp_unslash($_POST['environment_color'])) : '#3788d8';

            $data = array(
                'schedule_id' => isset($_POST['environment_schedule']) ? absint($_POST['environment_schedule']) : 0,
                'name' => isset($_POST['environment_name']) ? sanitize_text_field(wp_unslash($_POST['environment_name'])) : '',
                'color' => $color ?: '#3788d8',
                'description' => isset($_POST['environment_description']) ? sanitize_textarea_field(wp_unslash($_POST['environment_description'])) : '',
                'working_hours' => $working_hours,
                'status' => isset($_POST['environment_status']) ? sanitize_text_field(wp_unslash($_POST['environment_status'])) : 'active',
            );

            if ($id > 0) {
                AudienceEnvironmentRepository::update($id, $data);
                $updated_label = AudienceScheduleRepository::get_environment_label($data['schedule_id'], true);
                /* translators: %s: environment label (singular) */
                add_settings_error('ffc_audience', 'ffc_message', sprintf(__('%s updated successfully.', 'ffcertificate'), $updated_label), 'success');
            } else {
                $new_id = AudienceEnvironmentRepository::create($data);
                if ($new_id) {
                    wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-environments&action=edit&id=' . $new_id . '&message=created'));
                    exit;
                }
            }
        }

        // Handle deactivate (active items get deactivated instead of deleted)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action']) && $_GET['action'] === 'deactivate' && isset($_GET['id']) && isset($_GET['page']) && $_GET['page'] === $this->menu_slug . '-environments') {
            $id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'deactivate_environment_' . $id)) {
                AudienceEnvironmentRepository::update($id, array('status' => 'inactive'));
                wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-environments&message=deactivated'));
                exit;
            }
        }

        // Handle delete (only inactive items can be permanently deleted)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && isset($_GET['page']) && $_GET['page'] === $this->menu_slug . '-environments') {
            $id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'delete_environment_' . $id)) {
                $env = AudienceEnvironmentRepository::get_by_id($id);
                if ($env && $env->status !== 'active') {
                    AudienceEnvironmentRepository::delete($id);
                    wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-environments&message=deleted'));
                    exit;
                }
            }
        }
    }
}
