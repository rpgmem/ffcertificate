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
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    /**
     * Enqueue working hours component on user profile pages.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public static function enqueue_assets(string $hook): void {
        if ($hook !== 'user-edit.php' && $hook !== 'profile.php') {
            return;
        }

        $s = \FreeFormCertificate\Core\Utils::asset_suffix();

        wp_enqueue_style('ffc-working-hours', FFC_PLUGIN_URL . "assets/css/ffc-working-hours{$s}.css", array(), FFC_VERSION);
        wp_enqueue_script('ffc-working-hours', FFC_PLUGIN_URL . "assets/js/ffc-working-hours{$s}.js", array('jquery'), FFC_VERSION, true);
        wp_localize_script('ffc-working-hours', 'ffcWorkingHours', array(
            'days' => array(
                array('value' => 0, 'label' => __('Sunday', 'ffcertificate')),
                array('value' => 1, 'label' => __('Monday', 'ffcertificate')),
                array('value' => 2, 'label' => __('Tuesday', 'ffcertificate')),
                array('value' => 3, 'label' => __('Wednesday', 'ffcertificate')),
                array('value' => 4, 'label' => __('Thursday', 'ffcertificate')),
                array('value' => 5, 'label' => __('Friday', 'ffcertificate')),
                array('value' => 6, 'label' => __('Saturday', 'ffcertificate')),
            ),
        ));
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

            case 'working_hours':
                $wh_data = is_string($value) ? json_decode($value, true) : $value;
                if (!is_array($wh_data) || empty($wh_data)) {
                    $wh_data = array();
                }
                $days_labels = array(
                    0 => __('Sunday', 'ffcertificate'),
                    1 => __('Monday', 'ffcertificate'),
                    2 => __('Tuesday', 'ffcertificate'),
                    3 => __('Wednesday', 'ffcertificate'),
                    4 => __('Thursday', 'ffcertificate'),
                    5 => __('Friday', 'ffcertificate'),
                    6 => __('Saturday', 'ffcertificate'),
                );
                ?>
                <input type="hidden" name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr(wp_json_encode($wh_data)); ?>">
                <div class="ffc-working-hours" data-target="<?php echo esc_attr($input_name); ?>">
                    <table class="widefat ffc-wh-table" style="max-width:800px">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Day', 'ffcertificate'); ?></th>
                                <th><?php esc_html_e('Entry 1', 'ffcertificate'); ?> <span style="color:#d63638">*</span></th>
                                <th><?php esc_html_e('Exit 1', 'ffcertificate'); ?></th>
                                <th><?php esc_html_e('Entry 2', 'ffcertificate'); ?></th>
                                <th><?php esc_html_e('Exit 2', 'ffcertificate'); ?> <span style="color:#d63638">*</span></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($wh_data as $wh_entry) : ?>
                            <tr>
                                <td>
                                    <select class="ffc-wh-day">
                                        <?php foreach ($days_labels as $d_num => $d_name) : ?>
                                            <option value="<?php echo esc_attr($d_num); ?>" <?php selected($wh_entry['day'] ?? 0, $d_num); ?>><?php echo esc_html($d_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="time" class="ffc-wh-entry1" value="<?php echo esc_attr($wh_entry['entry1'] ?? ''); ?>" required></td>
                                <td><input type="time" class="ffc-wh-exit1" value="<?php echo esc_attr($wh_entry['exit1'] ?? ''); ?>"></td>
                                <td><input type="time" class="ffc-wh-entry2" value="<?php echo esc_attr($wh_entry['entry2'] ?? ''); ?>"></td>
                                <td><input type="time" class="ffc-wh-exit2" value="<?php echo esc_attr($wh_entry['exit2'] ?? ''); ?>" required></td>
                                <td><button type="button" class="button button-small ffc-wh-remove">&times;</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><button type="button" class="button ffc-wh-add">+ <?php esc_html_e('Add Day', 'ffcertificate'); ?></button></p>
                </div>
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
            } elseif ($field->field_type === 'working_hours') {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
                $raw_value = isset($_POST[$input_name]) ? wp_unslash($_POST[$input_name]) : '[]';
                $wh = json_decode($raw_value, true);
                if (is_array($wh)) {
                    $sanitized = array();
                    foreach ($wh as $entry) {
                        if (is_array($entry) && isset($entry['day'], $entry['entry1'], $entry['exit2'])) {
                            $sanitized[] = array(
                                'day'    => absint($entry['day']),
                                'entry1' => sanitize_text_field($entry['entry1']),
                                'exit1'  => sanitize_text_field($entry['exit1'] ?? ''),
                                'entry2' => sanitize_text_field($entry['entry2'] ?? ''),
                                'exit2'  => sanitize_text_field($entry['exit2']),
                            );
                        }
                    }
                    $data[$field_key] = wp_json_encode($sanitized);
                } else {
                    $data[$field_key] = '[]';
                }
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
