<?php
declare(strict_types=1);

/**
 * Reregistration Admin
 *
 * Provides the admin interface for managing reregistration campaigns:
 * - List of campaigns with filters
 * - Create/edit campaign form
 * - View submissions per campaign (approve/reject/remind)
 *
 * Action handlers are delegated to focused classes:
 * - ReregistrationSubmissionActions (approve, reject, return, bulk)
 * - ReregistrationCsvExporter (CSV export)
 * - ReregistrationCustomFieldsPage (custom fields submenu)
 *
 * @since 4.11.0
 * @since 4.12.13  Extracted action handlers and custom fields page
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceRepository;

if (!defined('ABSPATH')) {
    exit;
}

class ReregistrationAdmin {

    /**
     * Menu slug.
     */
    public const MENU_SLUG = 'ffc-reregistration';

    /**
     * Required capability.
     */
    private const CAPABILITY = 'ffc_manage_reregistration';

    /**
     * Initialize admin hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action('admin_menu', array($this, 'add_menu'), 30);
        add_action('admin_init', array($this, 'handle_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_ffc_generate_ficha', array($this, 'ajax_generate_ficha'));
    }

    /**
     * Register admin menu pages.
     *
     * Creates a top-level "Reregistration" menu with submenus:
     * - Campaigns (default landing page)
     * - Custom Fields (per-audience field management)
     *
     * @return void
     */
    public function add_menu(): void {
        add_menu_page(
            __('Reregistration', 'ffcertificate'),
            __('Reregistration', 'ffcertificate'),
            self::CAPABILITY,
            self::MENU_SLUG,
            array($this, 'render_page'),
            'dashicons-update-alt',
            27
        );

        // Rename auto-generated first submenu from "Reregistration" to "Campaigns"
        add_submenu_page(
            self::MENU_SLUG,
            __('Campaigns', 'ffcertificate'),
            __('Campaigns', 'ffcertificate'),
            self::CAPABILITY,
            self::MENU_SLUG,
            array($this, 'render_page')
        );

        // Custom Fields submenu
        add_submenu_page(
            self::MENU_SLUG,
            __('Custom Fields', 'ffcertificate'),
            __('Custom Fields', 'ffcertificate'),
            'manage_options',
            'ffc-custom-fields',
            array(ReregistrationCustomFieldsPage::class, 'render')
        );
    }

    /**
     * Enqueue admin assets for reregistration pages.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_assets(string $hook): void {
        if (strpos($hook, self::MENU_SLUG) === false && strpos($hook, 'ffc-custom-fields') === false) {
            return;
        }

        $s = \FreeFormCertificate\Core\Utils::asset_suffix();

        wp_enqueue_style(
            'ffc-reregistration-admin',
            FFC_PLUGIN_URL . "assets/css/ffc-reregistration-admin{$s}.css",
            array(),
            FFC_VERSION
        );

        wp_enqueue_script(
            'ffc-reregistration-admin',
            FFC_PLUGIN_URL . "assets/js/ffc-reregistration-admin{$s}.js",
            array('jquery'),
            FFC_VERSION,
            true
        );

        wp_localize_script('ffc-reregistration-admin', 'ffcReregistrationAdmin', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'adminNonce' => wp_create_nonce('ffc_reregistration_nonce'),
            'fichaNonce' => wp_create_nonce('ffc_generate_ficha'),
            'strings'    => array(
                'confirmDelete'        => __('Are you sure you want to delete this reregistration? This will also delete all submissions.', 'ffcertificate'),
                'confirmApprove'       => __('Approve selected submissions?', 'ffcertificate'),
                'confirmReturnToDraft' => __('Return this submission to draft? The user will be able to edit and resubmit.', 'ffcertificate'),
                'generatingPdf'        => __('Generating PDF...', 'ffcertificate'),
                'errorGenerating'      => __('Error generating ficha.', 'ffcertificate'),
                'ficha'                => __('Record', 'ffcertificate'),
            ),
        ));

        // Enqueue PDF libraries on submissions view
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : '';
        if ($view === 'submissions') {
            wp_enqueue_script('html2canvas', FFC_PLUGIN_URL . 'libs/js/html2canvas.min.js', array(), FFC_HTML2CANVAS_VERSION, true);
            wp_enqueue_script('jspdf', FFC_PLUGIN_URL . 'libs/js/jspdf.umd.min.js', array(), FFC_JSPDF_VERSION, true);
            wp_enqueue_script('ffc-pdf-generator', FFC_PLUGIN_URL . 'assets/js/ffc-pdf-generator.min.js', array('html2canvas', 'jspdf'), FFC_VERSION, true);
        }
    }

    /**
     * Render the current page view.
     *
     * @return void
     */
    public function render_page(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Permission denied.', 'ffcertificate'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        echo '<div class="wrap">';

        switch ($view) {
            case 'new':
            case 'edit':
                $this->render_form($id);
                break;
            case 'submissions':
                $this->render_submissions($id);
                break;
            default:
                $this->render_list();
        }

        echo '</div>';
    }

    // ─────────────────────────────────────────────
    // LIST VIEW
    // ─────────────────────────────────────────────

    /**
     * Render reregistration campaigns list.
     *
     * @return void
     */
    private function render_list(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $audience_filter = isset($_GET['audience_id']) ? absint($_GET['audience_id']) : null;

        $filters = array();
        if ($status_filter) {
            $filters['status'] = $status_filter;
        }
        if ($audience_filter) {
            $filters['audience_id'] = $audience_filter;
        }

        $items = ReregistrationRepository::get_all($filters);
        $audiences = AudienceRepository::get_hierarchical();
        $new_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&view=new');

        ?>
        <h1 class="wp-heading-inline"><?php esc_html_e('Reregistration', 'ffcertificate'); ?></h1>
        <a href="<?php echo esc_url($new_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'ffcertificate'); ?></a>
        <hr class="wp-header-end">

        <?php settings_errors('ffc_reregistration'); ?>

        <!-- Filters -->
        <div class="tablenav top">
            <form method="get" class="ffc-rereg-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
                <select name="status">
                    <option value=""><?php esc_html_e('All Statuses', 'ffcertificate'); ?></option>
                    <?php foreach (ReregistrationRepository::STATUSES as $s) : ?>
                        <option value="<?php echo esc_attr($s); ?>" <?php selected($status_filter, $s); ?>>
                            <?php echo esc_html(ucfirst($s)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="audience_id">
                    <option value=""><?php esc_html_e('All Audiences', 'ffcertificate'); ?></option>
                    <?php $this->render_audience_options($audiences, $audience_filter); ?>
                </select>
                <?php submit_button(__('Filter', 'ffcertificate'), '', '', false); ?>
            </form>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th class="column-title"><?php esc_html_e('Title', 'ffcertificate'); ?></th>
                    <th class="column-audience"><?php esc_html_e('Audience', 'ffcertificate'); ?></th>
                    <th class="column-status"><?php esc_html_e('Status', 'ffcertificate'); ?></th>
                    <th class="column-period"><?php esc_html_e('Period', 'ffcertificate'); ?></th>
                    <th class="column-submissions"><?php esc_html_e('Submissions', 'ffcertificate'); ?></th>
                    <th class="column-auto"><?php esc_html_e('Auto-approve', 'ffcertificate'); ?></th>
                    <th class="column-actions"><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)) : ?>
                    <tr><td colspan="7"><?php esc_html_e('No reregistrations found.', 'ffcertificate'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($items as $item) : ?>
                        <?php $this->render_list_row($item); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render a single list row.
     *
     * @param object $item Reregistration object.
     * @return void
     */
    private function render_list_row(object $item): void {
        $edit_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $item->id);
        $subs_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&view=submissions&id=' . $item->id);
        $delete_url = wp_nonce_url(
            admin_url('admin.php?page=' . self::MENU_SLUG . '&action=delete&id=' . $item->id),
            'delete_reregistration_' . $item->id
        );

        $stats = ReregistrationSubmissionRepository::get_statistics((int) $item->id);
        $start = wp_date(get_option('date_format'), strtotime($item->start_date));
        $end = wp_date(get_option('date_format'), strtotime($item->end_date));

        ?>
        <tr>
            <td class="column-title">
                <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($item->title); ?></a></strong>
            </td>
            <td class="column-audience">
                <?php if (!empty($item->audience_color)) : ?>
                    <span class="ffc-color-dot" style="background:<?php echo esc_attr($item->audience_color); ?>"></span>
                <?php endif; ?>
                <?php echo esc_html($item->audience_name ?? '—'); ?>
            </td>
            <td class="column-status">
                <span class="ffc-status-badge ffc-status-<?php echo esc_attr($item->status); ?>">
                    <?php echo esc_html(ucfirst($item->status)); ?>
                </span>
            </td>
            <td class="column-period"><?php echo esc_html($start . ' — ' . $end); ?></td>
            <td class="column-submissions">
                <a href="<?php echo esc_url($subs_url); ?>">
                    <?php
                    printf(
                        /* translators: 1: approved count 2: total count */
                        esc_html__('%1$d / %2$d', 'ffcertificate'),
                        absint($stats['approved']),
                        absint($stats['total'])
                    );
                    ?>
                </a>
            </td>
            <td class="column-auto">
                <?php echo $item->auto_approve ? '<span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span>' : '<span class="dashicons dashicons-minus" style="color:#a7aaad"></span>'; ?>
            </td>
            <td class="column-actions">
                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'ffcertificate'); ?></a> |
                <a href="<?php echo esc_url($subs_url); ?>"><?php esc_html_e('Submissions', 'ffcertificate'); ?></a> |
                <a href="<?php echo esc_url($delete_url); ?>" class="delete-link"
                   onclick="return confirm(ffcReregistrationAdmin?.strings?.confirmDelete || 'Delete?');">
                    <?php esc_html_e('Delete', 'ffcertificate'); ?>
                </a>
            </td>
        </tr>
        <?php
    }

    // ─────────────────────────────────────────────
    // FORM VIEW (Create / Edit)
    // ─────────────────────────────────────────────

    /**
     * Render create/edit form.
     *
     * @param int $id Reregistration ID (0 for new).
     * @return void
     */
    private function render_form(int $id): void {
        $item = null;
        $title = __('New Reregistration', 'ffcertificate');

        if ($id > 0) {
            $item = ReregistrationRepository::get_by_id($id);
            if (!$item) {
                wp_die(esc_html__('Reregistration not found.', 'ffcertificate'));
            }
            $title = __('Edit Reregistration', 'ffcertificate');
        }

        $audiences = AudienceRepository::get_hierarchical('active');
        $back_url = admin_url('admin.php?page=' . self::MENU_SLUG);

        ?>
        <h1><?php echo esc_html($title); ?></h1>
        <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to Reregistrations', 'ffcertificate'); ?></a>

        <?php settings_errors('ffc_reregistration'); ?>

        <form method="post" action="" class="ffc-form">
            <?php wp_nonce_field('save_reregistration', 'ffc_reregistration_nonce'); ?>
            <input type="hidden" name="reregistration_id" value="<?php echo esc_attr((string) $id); ?>">
            <input type="hidden" name="ffc_action" value="save_reregistration">

            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row"><label for="rereg_title"><?php esc_html_e('Title', 'ffcertificate'); ?> <span class="required">*</span></label></th>
                    <td><input type="text" name="rereg_title" id="rereg_title" class="regular-text" value="<?php echo esc_attr($item->title ?? ''); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rereg_audience"><?php esc_html_e('Audience', 'ffcertificate'); ?> <span class="required">*</span></label></th>
                    <td>
                        <select name="rereg_audience_id" id="rereg_audience" required>
                            <option value=""><?php esc_html_e('Select audience...', 'ffcertificate'); ?></option>
                            <?php $this->render_audience_options($audiences, $item->audience_id ?? ''); ?>
                        </select>
                        <p class="description"><?php esc_html_e('The reregistration will apply to this audience and all its children.', 'ffcertificate'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="rereg_start"><?php esc_html_e('Start Date', 'ffcertificate'); ?> <span class="required">*</span></label></th>
                    <td><input type="datetime-local" name="rereg_start_date" id="rereg_start" value="<?php echo esc_attr($item ? gmdate('Y-m-d\TH:i', strtotime($item->start_date)) : ''); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rereg_end"><?php esc_html_e('End Date', 'ffcertificate'); ?> <span class="required">*</span></label></th>
                    <td><input type="datetime-local" name="rereg_end_date" id="rereg_end" value="<?php echo esc_attr($item ? gmdate('Y-m-d\TH:i', strtotime($item->end_date)) : ''); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rereg_status"><?php esc_html_e('Status', 'ffcertificate'); ?></label></th>
                    <td>
                        <select name="rereg_status" id="rereg_status">
                            <?php foreach (ReregistrationRepository::STATUSES as $s) : ?>
                                <option value="<?php echo esc_attr($s); ?>" <?php selected($item->status ?? 'draft', $s); ?>>
                                    <?php echo esc_html(ucfirst($s)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Approval', 'ffcertificate'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rereg_auto_approve" value="1" <?php checked(!empty($item->auto_approve)); ?>>
                            <?php esc_html_e('Auto-approve submissions (no manual review needed)', 'ffcertificate'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Email Notifications', 'ffcertificate'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="rereg_email_invitation" value="1" <?php checked(!empty($item->email_invitation_enabled)); ?>>
                                <?php esc_html_e('Send invitation email when activated', 'ffcertificate'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="rereg_email_reminder" value="1" <?php checked(!empty($item->email_reminder_enabled)); ?>>
                                <?php esc_html_e('Send reminder email before deadline', 'ffcertificate'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="rereg_email_confirmation" value="1" <?php checked(!empty($item->email_confirmation_enabled)); ?>>
                                <?php esc_html_e('Send confirmation email after submission', 'ffcertificate'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('All email notifications are disabled by default.', 'ffcertificate'); ?></p>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="rereg_reminder_days"><?php esc_html_e('Reminder Days', 'ffcertificate'); ?></label></th>
                    <td>
                        <input type="number" name="rereg_reminder_days" id="rereg_reminder_days" value="<?php echo esc_attr($item->reminder_days ?? '7'); ?>" min="1" max="30" class="small-text">
                        <p class="description"><?php esc_html_e('Send reminder this many days before the end date.', 'ffcertificate'); ?></p>
                    </td>
                </tr>
            </tbody></table>

            <?php
            if ($id > 0) {
                $affected = ReregistrationRepository::get_affected_user_ids((int) $item->audience_id);
                printf(
                    '<p class="description"><strong>%s</strong> %s</p>',
                    esc_html__('Affected users:', 'ffcertificate'),
                    esc_html((string) count($affected))
                );
            }
            ?>

            <?php submit_button($id > 0 ? __('Update Reregistration', 'ffcertificate') : __('Create Reregistration', 'ffcertificate')); ?>
        </form>
        <?php
    }

    // ─────────────────────────────────────────────
    // SUBMISSIONS VIEW
    // ─────────────────────────────────────────────

    /**
     * Render submissions list for a reregistration.
     *
     * @param int $id Reregistration ID.
     * @return void
     */
    private function render_submissions(int $id): void {
        $rereg = ReregistrationRepository::get_by_id($id);
        if (!$rereg) {
            wp_die(esc_html__('Reregistration not found.', 'ffcertificate'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_filter = isset($_GET['sub_status']) ? sanitize_text_field(wp_unslash($_GET['sub_status'])) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : null;

        $filters = array();
        if ($status_filter) {
            $filters['status'] = $status_filter;
        }
        if ($search) {
            $filters['search'] = $search;
        }

        $submissions = ReregistrationSubmissionRepository::get_by_reregistration($id, $filters);
        $stats = ReregistrationSubmissionRepository::get_statistics($id);
        $back_url = admin_url('admin.php?page=' . self::MENU_SLUG);
        $export_url = wp_nonce_url(
            admin_url('admin.php?page=' . self::MENU_SLUG . '&action=export_csv&id=' . $id),
            'export_reregistration_' . $id
        );

        ?>
        <h1>
            <?php
            /* translators: %s: reregistration title */
            echo esc_html(sprintf(__('Submissions: %s', 'ffcertificate'), $rereg->title));
            ?>
        </h1>
        <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to Reregistrations', 'ffcertificate'); ?></a>

        <?php settings_errors('ffc_reregistration'); ?>

        <!-- Stats summary -->
        <div class="ffc-rereg-stats">
            <?php foreach ($stats as $status => $count) : ?>
                <?php if ($status !== 'total') : ?>
                    <span class="ffc-stat-item">
                        <span class="ffc-status-badge ffc-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                        <strong><?php echo esc_html((string) $count); ?></strong>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
            <span class="ffc-stat-item">
                <?php esc_html_e('Total:', 'ffcertificate'); ?> <strong><?php echo esc_html((string) $stats['total']); ?></strong>
            </span>
        </div>

        <!-- Filters & actions -->
        <div class="tablenav top">
            <form method="get" class="ffc-rereg-filters" style="display:inline;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
                <input type="hidden" name="view" value="submissions">
                <input type="hidden" name="id" value="<?php echo esc_attr((string) $id); ?>">
                <select name="sub_status">
                    <option value=""><?php esc_html_e('All Statuses', 'ffcertificate'); ?></option>
                    <?php foreach (ReregistrationSubmissionRepository::STATUSES as $s) : ?>
                        <option value="<?php echo esc_attr($s); ?>" <?php selected($status_filter, $s); ?>><?php echo esc_html(ucfirst($s)); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr($search ?? ''); ?>" placeholder="<?php esc_attr_e('Search name or email...', 'ffcertificate'); ?>">
                <?php submit_button(__('Filter', 'ffcertificate'), '', '', false); ?>
            </form>
            <a href="<?php echo esc_url($export_url); ?>" class="button" style="margin-left:10px;">
                <?php esc_html_e('Export CSV', 'ffcertificate'); ?>
            </a>
        </div>

        <!-- Bulk actions form -->
        <form method="post" id="ffc-submissions-form">
            <?php wp_nonce_field('bulk_submissions_' . $id, 'ffc_bulk_nonce'); ?>
            <input type="hidden" name="ffc_action" value="bulk_submissions">
            <input type="hidden" name="reregistration_id" value="<?php echo esc_attr((string) $id); ?>">

            <div class="tablenav top">
                <select name="bulk_action">
                    <option value=""><?php esc_html_e('Bulk Actions', 'ffcertificate'); ?></option>
                    <option value="approve"><?php esc_html_e('Approve', 'ffcertificate'); ?></option>
                    <option value="return_to_draft"><?php esc_html_e('Return to Draft', 'ffcertificate'); ?></option>
                    <option value="send_reminder"><?php esc_html_e('Send Reminder', 'ffcertificate'); ?></option>
                </select>
                <?php submit_button(__('Apply', 'ffcertificate'), 'action', '', false); ?>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" id="cb-select-all"></td>
                        <th><?php esc_html_e('User', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Email', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Status', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Submitted', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Reviewed', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No submissions found.', 'ffcertificate'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($submissions as $sub) : ?>
                            <?php $this->render_submission_row($sub, $id); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </form>
        <?php
    }

    /**
     * Render a single submission row.
     *
     * @param object $sub         Submission object.
     * @param int    $rereg_id    Reregistration ID.
     * @return void
     */
    private function render_submission_row(object $sub, int $rereg_id): void {
        $approve_url = wp_nonce_url(
            admin_url('admin.php?page=' . self::MENU_SLUG . '&action=approve&sub_id=' . $sub->id . '&id=' . $rereg_id),
            'approve_submission_' . $sub->id
        );
        $reject_url = wp_nonce_url(
            admin_url('admin.php?page=' . self::MENU_SLUG . '&action=reject&sub_id=' . $sub->id . '&id=' . $rereg_id),
            'reject_submission_' . $sub->id
        );
        $draft_url = wp_nonce_url(
            admin_url('admin.php?page=' . self::MENU_SLUG . '&action=return_to_draft&sub_id=' . $sub->id . '&id=' . $rereg_id),
            'return_to_draft_submission_' . $sub->id
        );

        $submitted = $sub->submitted_at ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($sub->submitted_at)) : '—';
        $reviewed = $sub->reviewed_at ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($sub->reviewed_at)) : '—';

        // Statuses that can be sent back to draft for user revision
        $can_return_to_draft = in_array($sub->status, array('submitted', 'approved', 'rejected'), true);

        ?>
        <tr>
            <th class="check-column">
                <input type="checkbox" name="submission_ids[]" value="<?php echo esc_attr($sub->id); ?>">
            </th>
            <td><?php echo esc_html($sub->user_name ?? '—'); ?></td>
            <td><?php echo esc_html($sub->user_email ?? '—'); ?></td>
            <td>
                <span class="ffc-status-badge ffc-status-<?php echo esc_attr($sub->status); ?>">
                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $sub->status))); ?>
                </span>
            </td>
            <td><?php echo esc_html($submitted); ?></td>
            <td><?php echo esc_html($reviewed); ?></td>
            <td>
                <?php if ($sub->status === 'submitted') : ?>
                    <a href="<?php echo esc_url($approve_url); ?>" class="button button-small"><?php esc_html_e('Approve', 'ffcertificate'); ?></a>
                    <a href="<?php echo esc_url($reject_url); ?>" class="button button-small button-link-delete"><?php esc_html_e('Reject', 'ffcertificate'); ?></a>
                <?php elseif ($sub->status === 'pending') : ?>
                    <span class="description"><?php esc_html_e('Awaiting user', 'ffcertificate'); ?></span>
                <?php elseif (!empty($sub->notes)) : ?>
                    <span class="description" title="<?php echo esc_attr($sub->notes); ?>"><?php esc_html_e('See notes', 'ffcertificate'); ?></span>
                <?php else : ?>
                    —
                <?php endif; ?>
                <?php if ($can_return_to_draft) : ?>
                    <a href="<?php echo esc_url($draft_url); ?>" class="button button-small ffc-return-draft-btn" title="<?php esc_attr_e('Return to user for revision', 'ffcertificate'); ?>">
                        <span class="dashicons dashicons-edit" style="vertical-align:middle;font-size:14px"></span>
                        <?php esc_html_e('Return to Draft', 'ffcertificate'); ?>
                    </a>
                <?php endif; ?>
                <?php if (in_array($sub->status, array('submitted', 'approved'), true)) : ?>
                    <button type="button" class="button button-small ffc-ficha-btn" data-submission-id="<?php echo esc_attr($sub->id); ?>">
                        <span class="dashicons dashicons-media-document" style="vertical-align:middle;font-size:14px"></span>
                        <?php esc_html_e('Ficha', 'ffcertificate'); ?>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    // ─────────────────────────────────────────────
    // ACTION HANDLERS
    // ─────────────────────────────────────────────

    /**
     * Handle admin actions (save, delete, approve, reject, bulk, export).
     *
     * Delegates submission workflow actions to ReregistrationSubmissionActions
     * and CSV export to ReregistrationCsvExporter.
     *
     * @return void
     */
    public function handle_actions(): void {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== self::MENU_SLUG) {
            return;
        }

        // Show redirect messages
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['message'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $msg = sanitize_text_field(wp_unslash($_GET['message']));
            $messages = array(
                'created'  => __('Reregistration created successfully.', 'ffcertificate'),
                'updated'  => __('Reregistration updated successfully.', 'ffcertificate'),
                'deleted'  => __('Reregistration deleted successfully.', 'ffcertificate'),
                'approved' => __('Submission approved.', 'ffcertificate'),
                'rejected' => __('Submission rejected.', 'ffcertificate'),
                'returned_to_draft'      => __('Submission returned to draft for revision.', 'ffcertificate'),
                'bulk_approved'          => __('Selected submissions approved.', 'ffcertificate'),
                'bulk_returned_to_draft' => __('Selected submissions returned to draft.', 'ffcertificate'),
                'reminders_sent'         => __('Reminder emails sent.', 'ffcertificate'),
            );
            if (isset($messages[$msg])) {
                add_settings_error('ffc_reregistration', 'ffc_message', $messages[$msg], 'success');
            }
        }

        // Campaign CRUD
        $this->handle_save();
        $this->handle_delete();

        // Submission workflow (delegated)
        ReregistrationSubmissionActions::handle_approve();
        ReregistrationSubmissionActions::handle_reject();
        ReregistrationSubmissionActions::handle_return_to_draft();
        ReregistrationSubmissionActions::handle_bulk();

        // CSV export (delegated)
        ReregistrationCsvExporter::handle_export();
    }

    /**
     * Handle save (create/update) reregistration.
     *
     * @return void
     */
    private function handle_save(): void {
        if (!isset($_POST['ffc_action']) || $_POST['ffc_action'] !== 'save_reregistration') {
            return;
        }
        if (!isset($_POST['ffc_reregistration_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_reregistration_nonce'])), 'save_reregistration')) {
            return;
        }

        $id = isset($_POST['reregistration_id']) ? absint($_POST['reregistration_id']) : 0;
        $prev_status = null;

        if ($id > 0) {
            $existing = ReregistrationRepository::get_by_id($id);
            $prev_status = $existing ? $existing->status : null;
        }

        $data = array(
            'title'                      => isset($_POST['rereg_title']) ? sanitize_text_field(wp_unslash($_POST['rereg_title'])) : '',
            'audience_id'                => isset($_POST['rereg_audience_id']) ? absint($_POST['rereg_audience_id']) : 0,
            'start_date'                 => isset($_POST['rereg_start_date']) ? sanitize_text_field(wp_unslash($_POST['rereg_start_date'])) : '',
            'end_date'                   => isset($_POST['rereg_end_date']) ? sanitize_text_field(wp_unslash($_POST['rereg_end_date'])) : '',
            'auto_approve'               => !empty($_POST['rereg_auto_approve']) ? 1 : 0,
            'email_invitation_enabled'   => !empty($_POST['rereg_email_invitation']) ? 1 : 0,
            'email_reminder_enabled'     => !empty($_POST['rereg_email_reminder']) ? 1 : 0,
            'email_confirmation_enabled' => !empty($_POST['rereg_email_confirmation']) ? 1 : 0,
            'reminder_days'              => isset($_POST['rereg_reminder_days']) ? absint($_POST['rereg_reminder_days']) : 7,
            'status'                     => isset($_POST['rereg_status']) ? sanitize_text_field(wp_unslash($_POST['rereg_status'])) : 'draft',
        );

        if ($id > 0) {
            ReregistrationRepository::update($id, $data);

            // If transitioning to active, create submissions for members and send invitations
            if ($data['status'] === 'active' && $prev_status !== 'active') {
                ReregistrationSubmissionRepository::create_for_audience_members($id, (int) $data['audience_id']);
                ReregistrationEmailHandler::send_invitations($id);
            }

            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $id . '&message=updated'));
            exit;
        } else {
            $new_id = ReregistrationRepository::create($data);
            if ($new_id) {
                // If creating as active, also create submissions and send invitations
                if ($data['status'] === 'active') {
                    ReregistrationSubmissionRepository::create_for_audience_members($new_id, (int) $data['audience_id']);
                    ReregistrationEmailHandler::send_invitations($new_id);
                }

                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $new_id . '&message=created'));
                exit;
            }
        }
    }

    /**
     * Handle delete reregistration.
     *
     * @return void
     */
    private function handle_delete(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['action']) || $_GET['action'] !== 'delete' || !isset($_GET['id'])) {
            return;
        }

        $id = absint($_GET['id']);
        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'delete_reregistration_' . $id)) {
            return;
        }

        ReregistrationRepository::delete($id);
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&message=deleted'));
        exit;
    }

    /**
     * AJAX: Generate ficha PDF data for a submission.
     *
     * @return void
     */
    public function ajax_generate_ficha(): void {
        check_ajax_referer('ffc_generate_ficha', 'nonce');

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
        }

        $submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        if (!$submission_id) {
            wp_send_json_error(array('message' => __('Invalid submission.', 'ffcertificate')));
        }

        $ficha_data = FichaGenerator::generate_ficha_data($submission_id);
        if (!$ficha_data) {
            wp_send_json_error(array('message' => __('Could not generate ficha.', 'ffcertificate')));
        }

        wp_send_json_success(array('pdf_data' => $ficha_data));
    }

    /**
     * Render audience <option> elements with hierarchy (parent → &mdash; child).
     *
     * @param array<int, mixed> $audiences Audience tree (objects with optional ->children).
     * @param int|string $selected  Currently selected audience ID.
     * @return void
     */
    private function render_audience_options(array $audiences, $selected = ''): void {
        foreach ($audiences as $parent) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($parent->id),
                selected($selected, $parent->id, false),
                esc_html($parent->name)
            );
            if (!empty($parent->children)) {
                foreach ($parent->children as $child) {
                    printf(
                        '<option value="%s" %s>&mdash; %s</option>',
                        esc_attr($child->id),
                        selected($selected, $child->id, false),
                        esc_html($child->name)
                    );
                }
            }
        }
    }
}
