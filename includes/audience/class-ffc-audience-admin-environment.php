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

        $args = array('orderby' => 'name');
        if ($filter_schedule > 0) {
            $args['schedule_id'] = $filter_schedule;
        }
        $environments = AudienceEnvironmentRepository::get_all($args);
        $schedules = AudienceScheduleRepository::get_all();
        $add_url = admin_url('admin.php?page=' . $this->menu_slug . '-environments&action=new');

        ?>
        <h1 class="wp-heading-inline"><?php esc_html_e('Environments', 'wp-ffcertificate'); ?></h1>
        <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'wp-ffcertificate'); ?></a>
        <hr class="wp-header-end">

        <?php settings_errors('ffc_audience'); ?>

        <!-- Filter form -->
        <form method="get" class="ffc-filter-form">
            <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_slug . '-environments'); ?>">
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
                        $edit_url = admin_url('admin.php?page=' . $this->menu_slug . '-environments&action=edit&id=' . $env->id);
                        $delete_url = wp_nonce_url(
                            admin_url('admin.php?page=' . $this->menu_slug . '-environments&action=delete&id=' . $env->id),
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
        $page_title = __('Add New Environment', 'wp-ffcertificate');

        if ($id > 0) {
            $environment = AudienceEnvironmentRepository::get_by_id($id);
            if (!$environment) {
                wp_die(esc_html__('Environment not found.', 'wp-ffcertificate'));
            }
            $page_title = __('Edit Environment', 'wp-ffcertificate');
        }

        $schedules = AudienceScheduleRepository::get_all(array('status' => 'active'));
        $back_url = admin_url('admin.php?page=' . $this->menu_slug . '-environments');

        // Parse working hours
        $working_hours = array();
        if ($environment && $environment->working_hours) {
            $working_hours = json_decode($environment->working_hours, true) ?: array();
        }

        ?>
        <h1><?php echo esc_html($page_title); ?></h1>
        <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to Environments', 'wp-ffcertificate'); ?></a>

        <?php settings_errors('ffc_audience'); ?>

        <form method="post" action="" class="ffc-form">
            <?php wp_nonce_field('save_environment', 'ffc_environment_nonce'); ?>
            <input type="hidden" name="environment_id" value="<?php echo esc_attr($id); ?>">
            <input type="hidden" name="ffc_action" value="save_environment">

            <table class="form-table" role="presentation"><tbody>
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
            </tbody></table>

            <?php submit_button($id > 0 ? __('Update Environment', 'wp-ffcertificate') : __('Create Environment', 'wp-ffcertificate')); ?>
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

            $data = array(
                'schedule_id' => isset($_POST['environment_schedule']) ? absint($_POST['environment_schedule']) : 0,
                'name' => isset($_POST['environment_name']) ? sanitize_text_field(wp_unslash($_POST['environment_name'])) : '',
                'description' => isset($_POST['environment_description']) ? sanitize_textarea_field(wp_unslash($_POST['environment_description'])) : '',
                'working_hours' => $working_hours,
                'status' => isset($_POST['environment_status']) ? sanitize_text_field(wp_unslash($_POST['environment_status'])) : 'active',
            );

            if ($id > 0) {
                AudienceEnvironmentRepository::update($id, $data);
                add_settings_error('ffc_audience', 'ffc_message', __('Environment updated successfully.', 'wp-ffcertificate'), 'success');
            } else {
                $new_id = AudienceEnvironmentRepository::create($data);
                if ($new_id) {
                    wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-environments&action=edit&id=' . $new_id . '&message=created'));
                    exit;
                }
            }
        }

        // Handle delete
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && isset($_GET['page']) && $_GET['page'] === $this->menu_slug . '-environments') {
            $id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'delete_environment_' . $id)) {
                AudienceEnvironmentRepository::delete($id);
                wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-environments&message=deleted'));
                exit;
            }
        }
    }
}
