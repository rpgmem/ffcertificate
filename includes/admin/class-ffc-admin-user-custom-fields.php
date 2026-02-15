<?php
declare(strict_types=1);

/**
 * Admin User Custom Fields
 *
 * Adds a "Custom Data" section to the WordPress user edit screen showing
 * custom fields from all audiences the user belongs to.
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Admin
 */

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Reregistration\CustomFieldRepository;
use FreeFormCertificate\Audience\AudienceRepository;

if (!defined('ABSPATH')) {
    exit;
}

class AdminUserCustomFields {

    /**
     * Initialize hooks.
     *
     * @return void
     */
    public static function init(): void {
        add_action('show_user_profile', array(__CLASS__, 'render_section'), 30);
        add_action('edit_user_profile', array(__CLASS__, 'render_section'), 30);
        add_action('personal_options_update', array(__CLASS__, 'save_section'));
        add_action('edit_user_profile_update', array(__CLASS__, 'save_section'));
    }

    /**
     * Render the custom fields section on user profile page.
     *
     * @param \WP_User $user User object.
     * @return void
     */
    public static function render_section(\WP_User $user): void {
        $audiences = AudienceRepository::get_user_audiences($user->ID);
        if (empty($audiences)) {
            return;
        }

        $user_data = CustomFieldRepository::get_user_data($user->ID);
        $rendered_field_ids = array();

        ?>
        <h2><?php esc_html_e('FFC Custom Data', 'ffcertificate'); ?></h2>
        <p class="description"><?php esc_html_e('Custom fields from audience memberships. Fields are grouped by audience.', 'ffcertificate'); ?></p>

        <?php wp_nonce_field('ffc_save_user_custom_fields', 'ffc_user_custom_fields_nonce'); ?>

        <?php foreach ($audiences as $audience) : ?>
            <?php
            $fields = CustomFieldRepository::get_by_audience_with_parents((int) $audience->id, true);
            if (empty($fields)) {
                continue;
            }
            $section_id = 'ffc-cf-section-' . $audience->id;
            ?>

            <div class="ffc-cf-section">
                <h3 class="ffc-audience-section-heading ffc-cf-toggle" data-target="<?php echo esc_attr($section_id); ?>" role="button" tabindex="0" aria-expanded="true">
                    <span class="ffc-cf-toggle-icon dashicons dashicons-arrow-down-alt2"></span>
                    <span class="ffc-color-dot" style="background-color: <?php echo esc_attr($audience->color); ?>;"></span>
                    <?php echo esc_html($audience->name); ?>
                    <span class="ffc-cf-field-count"><?php echo esc_html(count($fields)); ?></span>
                </h3>

                <div id="<?php echo esc_attr($section_id); ?>" class="ffc-cf-section-body">
                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php foreach ($fields as $field) : ?>
                                <?php
                                // Avoid rendering same field twice (shared parent)
                                if (isset($rendered_field_ids[(int) $field->id])) {
                                    continue;
                                }
                                $rendered_field_ids[(int) $field->id] = true;

                                $field_key = 'field_' . $field->id;
                                $value = $user_data[$field_key] ?? '';
                                $input_name = 'ffc_cf_' . $field->id;
                                ?>
                                <tr>
                                    <th scope="row">
                                        <label for="<?php echo esc_attr($input_name); ?>">
                                            <?php echo esc_html($field->field_label); ?>
                                            <?php if (!empty($field->is_required)) : ?>
                                                <span class="required">*</span>
                                            <?php endif; ?>
                                        </label>
                                        <?php if ((int) $field->source_audience_id !== (int) $audience->id) : ?>
                                            <br><small class="description">
                                                <?php
                                                /* translators: %s: parent audience name */
                                                echo esc_html(sprintf(__('Inherited from %s', 'ffcertificate'), $field->source_audience_name));
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    </th>
                                    <td>
                                        <?php self::render_field_input($field, $input_name, $value); ?>
                                        <?php
                                        $options = $field->field_options;
                                        if (is_string($options)) {
                                            $options = json_decode($options, true);
                                        }
                                        if (!empty($options['help_text'])) :
                                            ?>
                                            <p class="description"><?php echo esc_html($options['help_text']); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <style>
            .ffc-audience-section-heading {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-top: 20px;
                padding-bottom: 5px;
                border-bottom: 1px solid #c3c4c7;
            }
            .ffc-color-dot {
                display: inline-block;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                flex-shrink: 0;
            }
            .ffc-cf-toggle {
                cursor: pointer;
                user-select: none;
            }
            .ffc-cf-toggle:hover {
                color: #2271b1;
            }
            .ffc-cf-toggle-icon {
                font-size: 16px;
                width: 16px;
                height: 16px;
                transition: transform 0.2s;
            }
            .ffc-cf-toggle.collapsed .ffc-cf-toggle-icon {
                transform: rotate(-90deg);
            }
            .ffc-cf-field-count {
                background: #dcdcde;
                color: #50575e;
                font-size: 11px;
                font-weight: 400;
                padding: 1px 7px;
                border-radius: 10px;
                margin-left: auto;
            }
            .ffc-cf-section-body {
                transition: max-height 0.25s ease;
                overflow: hidden;
            }
            .ffc-cf-section-body.collapsed {
                display: none;
            }
        </style>
        <script>
        (function() {
            document.querySelectorAll('.ffc-cf-toggle').forEach(function(heading) {
                heading.addEventListener('click', function() {
                    var targetId = this.getAttribute('data-target');
                    var body = document.getElementById(targetId);
                    var isCollapsed = this.classList.toggle('collapsed');
                    this.setAttribute('aria-expanded', !isCollapsed);
                    if (body) {
                        body.classList.toggle('collapsed', isCollapsed);
                    }
                });
                heading.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.click();
                    }
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render a single field input based on its type.
     *
     * @param object $field      Field definition.
     * @param string $input_name HTML input name.
     * @param mixed  $value      Current value.
     * @return void
     */
    private static function render_field_input(object $field, string $input_name, $value): void {
        switch ($field->field_type) {
            case 'textarea':
                ?>
                <textarea name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_name); ?>" rows="4" cols="50" class="regular-text"><?php echo esc_textarea((string) $value); ?></textarea>
                <?php
                break;

            case 'select':
                $choices = CustomFieldRepository::get_field_choices($field);
                ?>
                <select name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_name); ?>">
                    <option value=""><?php esc_html_e('&mdash; Select &mdash;', 'ffcertificate'); ?></option>
                    <?php foreach ($choices as $choice) : ?>
                        <option value="<?php echo esc_attr($choice); ?>" <?php selected($value, $choice); ?>>
                            <?php echo esc_html($choice); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php
                break;

            case 'checkbox':
                ?>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_name); ?>" value="1" <?php checked(!empty($value)); ?>>
                    <?php echo esc_html($field->field_label); ?>
                </label>
                <?php
                break;

            case 'number':
                ?>
                <input type="number" name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr((string) $value); ?>" class="regular-text">
                <?php
                break;

            case 'date':
                ?>
                <input type="date" name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr((string) $value); ?>" class="regular-text">
                <?php
                break;

            case 'text':
            default:
                ?>
                <input type="text" name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr((string) $value); ?>" class="regular-text">
                <?php
                break;
        }
    }

    /**
     * Save custom field data from user profile page.
     *
     * @param int $user_id User ID being saved.
     * @return void
     */
    public static function save_section(int $user_id): void {
        // Verify nonce
        if (!isset($_POST['ffc_user_custom_fields_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_user_custom_fields_nonce'])), 'ffc_save_user_custom_fields')) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        // Get all fields for this user
        $fields = CustomFieldRepository::get_all_for_user($user_id, true);
        if (empty($fields)) {
            return;
        }

        $data = array();
        $seen_ids = array();

        foreach ($fields as $field) {
            // Avoid processing same field twice
            if (isset($seen_ids[(int) $field->id])) {
                continue;
            }
            $seen_ids[(int) $field->id] = true;

            $input_name = 'ffc_cf_' . $field->id;
            $field_key = 'field_' . $field->id;

            if ($field->field_type === 'checkbox') {
                $data[$field_key] = isset($_POST[$input_name]) ? 1 : 0;
            } else {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Checked via isset in ternary.
                $raw_value = isset($_POST[$input_name]) ? wp_unslash($_POST[$input_name]) : '';
                $data[$field_key] = $field->field_type === 'textarea'
                    ? sanitize_textarea_field($raw_value)
                    : sanitize_text_field($raw_value);
            }
        }

        if (!empty($data)) {
            CustomFieldRepository::save_user_data($user_id, $data);
        }
    }
}
