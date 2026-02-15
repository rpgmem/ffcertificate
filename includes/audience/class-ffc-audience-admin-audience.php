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
                        <?php $this->render_row_recursive($audience, 0); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Styles in ffc-audience-admin.css -->
        <?php
    }

    /**
     * Render an audience row and its children recursively.
     *
     * @param object $audience Audience object with optional children property.
     * @param int    $level    Hierarchy depth (0 = root, 1 = child, 2+ = grandchild).
     * @return void
     */
    private function render_row_recursive(object $audience, int $level): void {
        $direct_count = AudienceRepository::get_member_count((int) $audience->id);
        $has_children = !empty($audience->children);
        $total_count = $has_children ? AudienceRepository::get_member_count((int) $audience->id, true) : $direct_count;

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

        $indent_class = $level > 0 ? 'ffc-hierarchy-child ffc-hierarchy-level-' . $level : '';

        ?>
        <tr class="<?php echo $level === 0 ? 'ffc-hierarchy-parent' : ''; ?>">
            <td class="column-name <?php echo esc_attr($indent_class); ?>">
                <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($audience->name); ?></a></strong>
            </td>
            <td class="column-color">
                <span class="ffc-color-swatch" style="background-color: <?php echo esc_attr($audience->color); ?>;"></span>
            </td>
            <td class="column-members">
                <a href="<?php echo esc_url($members_url); ?>">
                    <?php echo esc_html($direct_count); ?>
                    <?php if ($has_children && $total_count > $direct_count) : ?>
                        <span class="ffc-member-total" title="<?php esc_attr_e('Including children', 'ffcertificate'); ?>">(<?php echo esc_html($total_count); ?>)</span>
                    <?php endif; ?>
                </a>
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

        // Recursively render children
        if ($has_children) {
            foreach ($audience->children as $child) {
                $this->render_row_recursive($child, $level + 1);
            }
        }
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

        <?php if ($audience && !empty($audience->parent_id)) : ?>
            <?php
            $parent = AudienceRepository::get_by_id((int) $audience->parent_id);
            if ($parent) :
                $parent_edit_url = admin_url('admin.php?page=' . $this->menu_slug . '-audiences&action=edit&id=' . $parent->id);
            ?>
            <div class="ffc-breadcrumb">
                <span class="ffc-color-swatch" style="background-color: <?php echo esc_attr($parent->color); ?>; width:12px; height:12px; display:inline-block; border-radius:50%; vertical-align:middle;"></span>
                <a href="<?php echo esc_url($parent_edit_url); ?>"><?php echo esc_html($parent->name); ?></a>
                <span class="ffc-breadcrumb-sep">&rsaquo;</span>
                <span class="ffc-color-swatch" style="background-color: <?php echo esc_attr($audience->color); ?>; width:12px; height:12px; display:inline-block; border-radius:50%; vertical-align:middle;"></span>
                <strong><?php echo esc_html($audience->name); ?></strong>
            </div>
            <?php endif; ?>
        <?php endif; ?>

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
                <?php
                $is_child = !empty($audience->parent_id);
                $is_self_join = !empty($audience->allow_self_join);
                ?>
                <tr>
                    <th scope="row">
                        <label for="audience_self_join"><?php esc_html_e('Allow Self-Join', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <?php if ($is_child) : ?>
                            <p class="description">
                                <?php if ($is_self_join) : ?>
                                    <span style="color: #00a32a; font-weight: 600;">&check;</span>
                                    <?php esc_html_e('Inherited from parent audience. Users can join this group from their dashboard.', 'ffcertificate'); ?>
                                <?php else : ?>
                                    <?php esc_html_e('This setting is controlled by the parent audience.', 'ffcertificate'); ?>
                                <?php endif; ?>
                            </p>
                            <input type="hidden" name="audience_self_join" value="<?php echo esc_attr($is_self_join ? '1' : '0'); ?>">
                        <?php else : ?>
                            <label>
                                <input type="checkbox" name="audience_self_join" id="audience_self_join" value="1"
                                    <?php checked($is_self_join); ?>>
                                <?php esc_html_e('Users can join/leave child groups from their dashboard', 'ffcertificate'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('When enabled, all child audiences inherit this setting. Users can join up to 2 child groups.', 'ffcertificate'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody></table>

            <?php submit_button($id > 0 ? __('Update Audience', 'ffcertificate') : __('Create Audience', 'ffcertificate')); ?>
        </form>

        <?php if ($id > 0) : ?>
            <?php $this->render_custom_fields_section($id); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Render custom fields management section
     *
     * @param int $audience_id Audience ID.
     * @return void
     */
    private function render_custom_fields_section(int $audience_id): void {
        $fields = \FreeFormCertificate\Reregistration\CustomFieldRepository::get_by_audience($audience_id, false);
        $field_types = \FreeFormCertificate\Reregistration\CustomFieldRepository::FIELD_TYPES;

        ?>
        <hr>
        <h2><?php esc_html_e('Custom Fields', 'ffcertificate'); ?></h2>
        <p class="description"><?php esc_html_e('Define custom fields for members of this audience. Fields are shown during reregistration and on the user profile.', 'ffcertificate'); ?></p>

        <div id="ffc-custom-fields-container" data-audience-id="<?php echo esc_attr($audience_id); ?>">
            <div id="ffc-custom-fields-list" class="ffc-custom-fields-sortable">
                <?php if (!empty($fields)) : ?>
                    <?php foreach ($fields as $field) : ?>
                        <?php $this->render_custom_field_row($field, $field_types); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <p>
                <button type="button" id="ffc-add-custom-field" class="button">
                    <?php esc_html_e('+ Add Field', 'ffcertificate'); ?>
                </button>
                <button type="button" id="ffc-save-custom-fields" class="button button-primary">
                    <?php esc_html_e('Save Fields', 'ffcertificate'); ?>
                </button>
                <span id="ffc-custom-fields-status" class="ffc-save-status"></span>
            </p>
        </div>

        <!-- Template for new field row (used by JS) -->
        <script type="text/html" id="tmpl-ffc-custom-field-row">
            <div class="ffc-custom-field-row" data-field-id="new_{{data.index}}">
                <div class="ffc-field-handle"><span class="dashicons dashicons-menu"></span></div>
                <div class="ffc-field-content">
                    <div class="ffc-field-main-row">
                        <input type="text" class="ffc-field-label regular-text" placeholder="<?php esc_attr_e('Field Label', 'ffcertificate'); ?>" value="">
                        <select class="ffc-field-type">
                            <?php foreach ($field_types as $type) : ?>
                                <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html(ucfirst($type)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="ffc-field-required-label">
                            <input type="checkbox" class="ffc-field-required"> <?php esc_html_e('Required', 'ffcertificate'); ?>
                        </label>
                        <label class="ffc-field-active-label">
                            <input type="checkbox" class="ffc-field-active" checked> <?php esc_html_e('Active', 'ffcertificate'); ?>
                        </label>
                    </div>
                    <div class="ffc-field-details-row">
                        <input type="text" class="ffc-field-key" placeholder="<?php esc_attr_e('field_key (auto)', 'ffcertificate'); ?>" value="">
                        <div class="ffc-field-options-container" style="display:none;">
                            <textarea class="ffc-field-choices" placeholder="<?php esc_attr_e('Options (one per line)', 'ffcertificate'); ?>" rows="3"></textarea>
                        </div>
                        <div class="ffc-field-validation-container">
                            <select class="ffc-field-format">
                                <option value=""><?php esc_html_e('No format validation', 'ffcertificate'); ?></option>
                                <option value="cpf"><?php esc_html_e('CPF', 'ffcertificate'); ?></option>
                                <option value="email"><?php esc_html_e('Email', 'ffcertificate'); ?></option>
                                <option value="phone"><?php esc_html_e('Phone', 'ffcertificate'); ?></option>
                                <option value="custom_regex"><?php esc_html_e('Custom Regex', 'ffcertificate'); ?></option>
                            </select>
                            <input type="text" class="ffc-field-regex" placeholder="<?php esc_attr_e('Regex pattern', 'ffcertificate'); ?>" style="display:none;">
                            <input type="text" class="ffc-field-regex-msg" placeholder="<?php esc_attr_e('Error message for regex', 'ffcertificate'); ?>" style="display:none;">
                        </div>
                        <input type="text" class="ffc-field-help" placeholder="<?php esc_attr_e('Help text (optional)', 'ffcertificate'); ?>">
                    </div>
                </div>
                <div class="ffc-field-actions">
                    <button type="button" class="button button-small ffc-field-toggle-details" title="<?php esc_attr_e('Toggle details', 'ffcertificate'); ?>">
                        <span class="dashicons dashicons-admin-generic"></span>
                    </button>
                    <button type="button" class="button button-small button-link-delete ffc-field-delete" title="<?php esc_attr_e('Remove', 'ffcertificate'); ?>">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            </div>
        </script>
        <?php
    }

    /**
     * Render a single custom field row in the editor.
     *
     * @param object $field      Field object from database.
     * @param array  $field_types Available field types.
     * @return void
     */
    private function render_custom_field_row(object $field, array $field_types): void {
        $options = $field->field_options;
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        $rules = $field->validation_rules;
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }

        $choices_text = '';
        if (!empty($options['choices'])) {
            $choices_text = implode("\n", $options['choices']);
        }
        $format = $rules['format'] ?? '';
        $regex = $rules['custom_regex'] ?? '';
        $regex_msg = $rules['custom_regex_message'] ?? '';
        $help_text = $options['help_text'] ?? '';
        $is_select = ($field->field_type === 'select');
        $is_regex = ($format === 'custom_regex');

        ?>
        <div class="ffc-custom-field-row <?php echo empty($field->is_active) ? 'ffc-field-inactive' : ''; ?>" data-field-id="<?php echo esc_attr($field->id); ?>">
            <div class="ffc-field-handle"><span class="dashicons dashicons-menu"></span></div>
            <div class="ffc-field-content">
                <div class="ffc-field-main-row">
                    <input type="text" class="ffc-field-label regular-text" placeholder="<?php esc_attr_e('Field Label', 'ffcertificate'); ?>" value="<?php echo esc_attr($field->field_label); ?>">
                    <select class="ffc-field-type">
                        <?php foreach ($field_types as $type) : ?>
                            <option value="<?php echo esc_attr($type); ?>" <?php selected($field->field_type, $type); ?>><?php echo esc_html(ucfirst($type)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="ffc-field-required-label">
                        <input type="checkbox" class="ffc-field-required" <?php checked(!empty($field->is_required)); ?>> <?php esc_html_e('Required', 'ffcertificate'); ?>
                    </label>
                    <label class="ffc-field-active-label">
                        <input type="checkbox" class="ffc-field-active" <?php checked(!empty($field->is_active)); ?>> <?php esc_html_e('Active', 'ffcertificate'); ?>
                    </label>
                </div>
                <div class="ffc-field-details-row" style="display:none;">
                    <input type="text" class="ffc-field-key" placeholder="<?php esc_attr_e('field_key', 'ffcertificate'); ?>" value="<?php echo esc_attr($field->field_key); ?>">
                    <div class="ffc-field-options-container" <?php echo $is_select ? '' : 'style="display:none;"'; ?>>
                        <textarea class="ffc-field-choices" placeholder="<?php esc_attr_e('Options (one per line)', 'ffcertificate'); ?>" rows="3"><?php echo esc_textarea($choices_text); ?></textarea>
                    </div>
                    <div class="ffc-field-validation-container">
                        <select class="ffc-field-format">
                            <option value=""><?php esc_html_e('No format validation', 'ffcertificate'); ?></option>
                            <option value="cpf" <?php selected($format, 'cpf'); ?>><?php esc_html_e('CPF', 'ffcertificate'); ?></option>
                            <option value="email" <?php selected($format, 'email'); ?>><?php esc_html_e('Email', 'ffcertificate'); ?></option>
                            <option value="phone" <?php selected($format, 'phone'); ?>><?php esc_html_e('Phone', 'ffcertificate'); ?></option>
                            <option value="custom_regex" <?php selected($format, 'custom_regex'); ?>><?php esc_html_e('Custom Regex', 'ffcertificate'); ?></option>
                        </select>
                        <input type="text" class="ffc-field-regex" placeholder="<?php esc_attr_e('Regex pattern', 'ffcertificate'); ?>" value="<?php echo esc_attr($regex); ?>" <?php echo $is_regex ? '' : 'style="display:none;"'; ?>>
                        <input type="text" class="ffc-field-regex-msg" placeholder="<?php esc_attr_e('Error message for regex', 'ffcertificate'); ?>" value="<?php echo esc_attr($regex_msg); ?>" <?php echo $is_regex ? '' : 'style="display:none;"'; ?>>
                    </div>
                    <input type="text" class="ffc-field-help" placeholder="<?php esc_attr_e('Help text (optional)', 'ffcertificate'); ?>" value="<?php echo esc_attr($help_text); ?>">
                </div>
            </div>
            <div class="ffc-field-actions">
                <button type="button" class="button button-small ffc-field-toggle-details" title="<?php esc_attr_e('Toggle details', 'ffcertificate'); ?>">
                    <span class="dashicons dashicons-admin-generic"></span>
                </button>
                <button type="button" class="button button-small button-link-delete ffc-field-delete" title="<?php esc_attr_e('Remove', 'ffcertificate'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
        </div>
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
                'allow_self_join' => !empty($_POST['audience_self_join']) ? 1 : 0,
            );

            if ($id > 0) {
                AudienceRepository::update($id, $data);

                // Cascade allow_self_join to children if this is a parent
                if (empty($data['parent_id'])) {
                    AudienceRepository::cascade_self_join($id, (int) $data['allow_self_join']);
                }

                add_settings_error('ffc_audience', 'ffc_message', __('Audience updated successfully.', 'ffcertificate'), 'success');
            } else {
                $new_id = AudienceRepository::create($data);
                if ($new_id) {
                    // Cascade to children (if creating a parent from template/import)
                    if (empty($data['parent_id'])) {
                        AudienceRepository::cascade_self_join($new_id, (int) $data['allow_self_join']);
                    }
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
