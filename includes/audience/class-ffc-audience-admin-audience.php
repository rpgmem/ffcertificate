<?php
/**
 * Audience Admin - Audience management sub-page
 *
 * @package FreeFormCertificate\Audience
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

/**
 * Handles audience CRUD rendering and actions.
 */
class AudienceAdminAudience {

    /**
     * Menu slug prefix.
     *
     * @var string
     */
    private string $menu_slug;

    /**
     * Constructor.
     *
     * @param string $menu_slug The menu slug prefix.
     */
    public function __construct(string $menu_slug) {
        $this->menu_slug = $menu_slug;
    }

    /**
     * Render audiences page
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
                case 'members':
                    $this->render_members($id);
                    break;
                default:
                    $this->render_list();
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
    private function render_list(): void {
        $audiences = AudienceRepository::get_hierarchical();
        $add_url = admin_url('admin.php?page=' . $this->menu_slug . '-audiences&action=new');

        ?>
        <h1 class="wp-heading-inline"><?php esc_html_e('Audiences', 'ffcertificate'); ?></h1>
        <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'ffcertificate'); ?></a>
        <hr class="wp-header-end">

        <?php settings_errors('ffc_audience'); ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="column-name"><?php esc_html_e('Name', 'ffcertificate'); ?></th>
                    <th scope="col" class="column-color"><?php esc_html_e('Color', 'ffcertificate'); ?></th>
                    <th scope="col" class="column-members"><?php esc_html_e('Members', 'ffcertificate'); ?></th>
                    <th scope="col" class="column-status"><?php esc_html_e('Status', 'ffcertificate'); ?></th>
                    <th scope="col" class="column-actions"><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($audiences)) : ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e('No audiences found.', 'ffcertificate'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($audiences as $audience) : ?>
                        <?php $this->render_row($audience, 0); ?>
                        <?php if (!empty($audience->children)) : ?>
                            <?php foreach ($audience->children as $child) : ?>
                                <?php $this->render_row($child, 1); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Styles in ffc-audience-admin.css -->
        <?php
    }

    /**
     * Render a single audience row
     *
     * @param object $audience Audience object
     * @param int $level Hierarchy level (0 = parent, 1 = child)
     * @return void
     */
    private function render_row(object $audience, int $level): void {
        $member_count = AudienceRepository::get_member_count((int) $audience->id);
        $edit_url = admin_url('admin.php?page=' . $this->menu_slug . '-audiences&action=edit&id=' . $audience->id);
        $members_url = admin_url('admin.php?page=' . $this->menu_slug . '-audiences&action=members&id=' . $audience->id);
        $is_active = ($audience->status === 'active');

        if ($is_active) {
            $deactivate_url = wp_nonce_url(
                admin_url('admin.php?page=' . $this->menu_slug . '-audiences&action=deactivate&id=' . $audience->id),
                'deactivate_audience_' . $audience->id
            );
        } else {
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=' . $this->menu_slug . '-audiences&action=delete&id=' . $audience->id),
                'delete_audience_' . $audience->id
            );
        }

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
                    <?php echo $is_active ? esc_html__('Active', 'ffcertificate') : esc_html__('Inactive', 'ffcertificate'); ?>
                </span>
            </td>
            <td class="column-actions">
                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'ffcertificate'); ?></a> |
                <a href="<?php echo esc_url($members_url); ?>"><?php esc_html_e('Members', 'ffcertificate'); ?></a> |
                <?php if ($is_active) : ?>
                    <a href="<?php echo esc_url($deactivate_url); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e('Are you sure you want to deactivate this audience?', 'ffcertificate'); ?>');">
                        <?php esc_html_e('Deactivate', 'ffcertificate'); ?>
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url($delete_url); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e('Are you sure you want to permanently delete this audience?', 'ffcertificate'); ?>');">
                        <?php esc_html_e('Delete', 'ffcertificate'); ?>
                    </a>
                <?php endif; ?>
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
    private function render_form(int $id): void {
        $audience = null;
        $page_title = __('Add New Audience', 'ffcertificate');

        if ($id > 0) {
            $audience = AudienceRepository::get_by_id($id);
            if (!$audience) {
                wp_die(esc_html__('Audience not found.', 'ffcertificate'));
            }
            $page_title = __('Edit Audience', 'ffcertificate');
        }

        $parents = AudienceRepository::get_parents();
        $back_url = admin_url('admin.php?page=' . $this->menu_slug . '-audiences');

        ?>
        <h1><?php echo esc_html($page_title); ?></h1>
        <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to Audiences', 'ffcertificate'); ?></a>

        <?php settings_errors('ffc_audience'); ?>

        <form method="post" action="" class="ffc-form">
            <?php wp_nonce_field('save_audience', 'ffc_audience_nonce'); ?>
            <input type="hidden" name="audience_id" value="<?php echo esc_attr($id); ?>">
            <input type="hidden" name="ffc_action" value="save_audience">

            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row">
                        <label for="audience_name"><?php esc_html_e('Name', 'ffcertificate'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="text" name="audience_name" id="audience_name" class="regular-text"
                               value="<?php echo esc_attr($audience->name ?? ''); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="audience_color"><?php esc_html_e('Color', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="color" name="audience_color" id="audience_color"
                               value="<?php echo esc_attr($audience->color ?? '#3788d8'); ?>">
                        <p class="description"><?php esc_html_e('Color used for visual identification in calendars.', 'ffcertificate'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="audience_parent"><?php esc_html_e('Parent Audience', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="audience_parent" id="audience_parent">
                            <option value=""><?php esc_html_e('None (top-level audience)', 'ffcertificate'); ?></option>
                            <?php foreach ($parents as $parent) : ?>
                                <?php if ($parent->id !== $id) : // Prevent selecting self as parent ?>
                                    <option value="<?php echo esc_attr($parent->id); ?>" <?php selected($audience->parent_id ?? '', $parent->id); ?>>
                                        <?php echo esc_html($parent->name); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Select a parent to create a sub-group (2-level hierarchy only).', 'ffcertificate'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="audience_status"><?php esc_html_e('Status', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="audience_status" id="audience_status">
                            <option value="active" <?php selected($audience->status ?? 'active', 'active'); ?>>
                                <?php esc_html_e('Active', 'ffcertificate'); ?>
                            </option>
                            <option value="inactive" <?php selected($audience->status ?? '', 'inactive'); ?>>
                                <?php esc_html_e('Inactive', 'ffcertificate'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
            </tbody></table>

            <?php submit_button($id > 0 ? __('Update Audience', 'ffcertificate') : __('Create Audience', 'ffcertificate')); ?>
        </form>
        <?php
    }

    /**
     * Render audience members page
     *
     * @param int $id Audience ID
     * @return void
     */
    private function render_members(int $id): void {
        $audience = AudienceRepository::get_by_id($id);
        if (!$audience) {
            wp_die(esc_html__('Audience not found.', 'ffcertificate'));
        }

        $members = AudienceRepository::get_members((int) $audience->id);
        $back_url = admin_url('admin.php?page=' . $this->menu_slug . '-audiences');

        ?>
        <h1><?php /* translators: %s: audience name */ echo esc_html(sprintf(__('Members of %s', 'ffcertificate'), $audience->name)); ?></h1>
        <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to Audiences', 'ffcertificate'); ?></a>

        <?php settings_errors('ffc_audience'); ?>

        <div class="ffc-members-section">
            <h2><?php esc_html_e('Add Members', 'ffcertificate'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('add_members', 'ffc_add_members_nonce'); ?>
                <input type="hidden" name="audience_id" value="<?php echo esc_attr($id); ?>">
                <input type="hidden" name="ffc_action" value="add_members">

                <p>
                    <label for="user_search"><?php esc_html_e('Search users:', 'ffcertificate'); ?></label>
                    <input type="text" id="user_search" class="regular-text" placeholder="<?php esc_attr_e('Type to search...', 'ffcertificate'); ?>">
                </p>
                <div id="user_results" class="ffc-user-results"></div>
                <input type="hidden" name="user_ids" id="selected_user_ids" value="">
                <div id="selected_users" class="ffc-selected-users"></div>
                <?php submit_button(__('Add Selected Members', 'ffcertificate'), 'primary', 'add_members', false); ?>
            </form>
        </div>

        <div class="ffc-members-section">
            <h2><?php esc_html_e('Current Members', 'ffcertificate'); ?> (<?php echo count($members); ?>)</h2>

            <?php if (empty($members)) : ?>
                <p><?php esc_html_e('No members yet.', 'ffcertificate'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('User', 'ffcertificate'); ?></th>
                            <th><?php esc_html_e('Email', 'ffcertificate'); ?></th>
                            <th class="column-actions"><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $user_id) : ?>
                            <?php $user = get_user_by('id', $user_id); ?>
                            <?php if ($user) : ?>
                                <?php
                                $remove_url = wp_nonce_url(
                                    admin_url('admin.php?page=' . $this->menu_slug . '-audiences&action=members&id=' . $id . '&remove_user=' . $user_id),
                                    'remove_member_' . $user_id
                                );
                                ?>
                                <tr>
                                    <td><?php echo esc_html($user->display_name); ?></td>
                                    <td><?php echo esc_html($user->user_email); ?></td>
                                    <td class="column-actions">
                                        <a href="<?php echo esc_url($remove_url); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e('Remove this member?', 'ffcertificate'); ?>');">
                                            <?php esc_html_e('Remove', 'ffcertificate'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Styles in ffc-audience-admin.css -->
        <!-- Scripts in ffc-audience-admin.js -->
        <?php
    }

    /**
     * Handle audience actions (save, delete, members)
     *
     * @return void
     */
    public function handle_actions(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Show feedback for redirect-based actions
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['message']) && isset($_GET['page']) && $_GET['page'] === $this->menu_slug . '-audiences') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $msg = sanitize_text_field(wp_unslash($_GET['message']));
            $messages = array(
                'created'        => __('Audience created successfully.', 'ffcertificate'),
                'deactivated'    => __('Audience deactivated successfully.', 'ffcertificate'),
                'deleted'        => __('Audience deleted successfully.', 'ffcertificate'),
                'member_removed' => __('Member removed successfully.', 'ffcertificate'),
            );
            if (isset($messages[$msg])) {
                add_settings_error('ffc_audience', 'ffc_message', $messages[$msg], 'success');
            }
        }

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
                add_settings_error('ffc_audience', 'ffc_message', __('Audience updated successfully.', 'ffcertificate'), 'success');
            } else {
                $new_id = AudienceRepository::create($data);
                if ($new_id) {
                    wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-audiences&action=edit&id=' . $new_id . '&message=created'));
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
                /* translators: %d: number of members added */
                add_settings_error('ffc_audience', 'ffc_message', sprintf(__('%d member(s) added successfully.', 'ffcertificate'), $added), 'success');
            }
        }

        // Handle remove member
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['remove_user']) && isset($_GET['id'])) {
            $user_id = absint($_GET['remove_user']);
            $audience_id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'remove_member_' . $user_id)) {
                AudienceRepository::remove_member($audience_id, $user_id);
                wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-audiences&action=members&id=' . $audience_id . '&message=member_removed'));
                exit;
            }
        }

        // Handle deactivate (active items get deactivated instead of deleted)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action']) && $_GET['action'] === 'deactivate' && isset($_GET['id']) && isset($_GET['page']) && $_GET['page'] === $this->menu_slug . '-audiences') {
            $id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'deactivate_audience_' . $id)) {
                AudienceRepository::update($id, array('status' => 'inactive'));
                wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-audiences&message=deactivated'));
                exit;
            }
        }

        // Handle delete (only inactive items can be permanently deleted)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && isset($_GET['page']) && $_GET['page'] === $this->menu_slug . '-audiences') {
            $id = absint($_GET['id']);
            if (wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'delete_audience_' . $id)) {
                $aud = AudienceRepository::get_by_id($id);
                if ($aud && $aud->status !== 'active') {
                    AudienceRepository::delete($id);
                    wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '-audiences&message=deleted'));
                    exit;
                }
            }
        }
    }
}
