<?php
declare(strict_types=1);

/**
 * Reregistration Frontend
 *
 * Handles the user-facing reregistration form:
 * - AJAX endpoints for loading, saving draft, and submitting
 * - Server-side validation (CPF, email, phone, regex)
 * - Submission processing (save data, update profiles, auto-approve)
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\UserDashboard\UserManager;

if (!defined('ABSPATH')) {
    exit;
}

class ReregistrationFrontend {

    /**
     * Initialize AJAX hooks.
     *
     * @return void
     */
    public static function init(): void {
        add_action('wp_ajax_ffc_get_reregistration_form', array(__CLASS__, 'ajax_get_form'));
        add_action('wp_ajax_ffc_submit_reregistration', array(__CLASS__, 'ajax_submit'));
        add_action('wp_ajax_ffc_save_reregistration_draft', array(__CLASS__, 'ajax_save_draft'));
        add_action('wp_ajax_ffc_download_ficha', array(__CLASS__, 'ajax_download_ficha'));
    }

    /**
     * AJAX: Get reregistration form HTML.
     *
     * @return void
     */
    public static function ajax_get_form(): void {
        check_ajax_referer('ffc_reregistration_frontend', 'nonce');

        $reregistration_id = isset($_POST['reregistration_id']) ? absint($_POST['reregistration_id']) : 0;
        $user_id = get_current_user_id();

        if (!$reregistration_id || !$user_id) {
            wp_send_json_error(array('message' => __('Invalid request.', 'ffcertificate')));
        }

        $rereg = ReregistrationRepository::get_by_id($reregistration_id);
        if (!$rereg || $rereg->status !== 'active') {
            wp_send_json_error(array('message' => __('Reregistration not found or not active.', 'ffcertificate')));
        }

        $submission = ReregistrationSubmissionRepository::get_by_reregistration_and_user($reregistration_id, $user_id);
        if (!$submission) {
            wp_send_json_error(array('message' => __('No submission found for this user.', 'ffcertificate')));
        }

        if (in_array($submission->status, array('approved', 'expired'), true)) {
            wp_send_json_error(array('message' => __('This reregistration has already been completed or expired.', 'ffcertificate')));
        }

        $html = self::render_form($rereg, $submission, $user_id);
        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX: Submit reregistration.
     *
     * @return void
     */
    public static function ajax_submit(): void {
        check_ajax_referer('ffc_reregistration_frontend', 'nonce');

        $reregistration_id = isset($_POST['reregistration_id']) ? absint($_POST['reregistration_id']) : 0;
        $user_id = get_current_user_id();

        if (!$reregistration_id || !$user_id) {
            wp_send_json_error(array('message' => __('Invalid request.', 'ffcertificate')));
        }

        $rereg = ReregistrationRepository::get_by_id($reregistration_id);
        if (!$rereg || $rereg->status !== 'active') {
            wp_send_json_error(array('message' => __('Reregistration not found or not active.', 'ffcertificate')));
        }

        $submission = ReregistrationSubmissionRepository::get_by_reregistration_and_user($reregistration_id, $user_id);
        if (!$submission) {
            wp_send_json_error(array('message' => __('No submission found.', 'ffcertificate')));
        }

        if (in_array($submission->status, array('approved', 'expired'), true)) {
            wp_send_json_error(array('message' => __('This reregistration has already been completed or expired.', 'ffcertificate')));
        }

        // Collect and validate fields
        $data = self::collect_form_data($rereg, $user_id);
        $errors = self::validate_submission($data, $rereg, $user_id);

        if (!empty($errors)) {
            wp_send_json_error(array('message' => __('Please fix the errors below.', 'ffcertificate'), 'errors' => $errors));
        }

        // Process submission
        self::process_submission($submission, $rereg, $data, $user_id);

        wp_send_json_success(array('message' => __('Reregistration submitted successfully!', 'ffcertificate')));
    }

    /**
     * AJAX: Save draft.
     *
     * @return void
     */
    public static function ajax_save_draft(): void {
        check_ajax_referer('ffc_reregistration_frontend', 'nonce');

        $reregistration_id = isset($_POST['reregistration_id']) ? absint($_POST['reregistration_id']) : 0;
        $user_id = get_current_user_id();

        if (!$reregistration_id || !$user_id) {
            wp_send_json_error(array('message' => __('Invalid request.', 'ffcertificate')));
        }

        $rereg = ReregistrationRepository::get_by_id($reregistration_id);
        if (!$rereg || $rereg->status !== 'active') {
            wp_send_json_error(array('message' => __('Reregistration not active.', 'ffcertificate')));
        }

        $submission = ReregistrationSubmissionRepository::get_by_reregistration_and_user($reregistration_id, $user_id);
        if (!$submission || in_array($submission->status, array('approved', 'expired'), true)) {
            wp_send_json_error(array('message' => __('Cannot save draft.', 'ffcertificate')));
        }

        $data = self::collect_form_data($rereg, $user_id);

        ReregistrationSubmissionRepository::update((int) $submission->id, array(
            'data'   => $data,
            'status' => 'in_progress',
        ));

        wp_send_json_success(array('message' => __('Draft saved.', 'ffcertificate')));
    }

    /**
     * AJAX: Generate ficha PDF data for the current user's submission.
     *
     * @return void
     */
    public static function ajax_download_ficha(): void {
        check_ajax_referer('ffc_reregistration_frontend', 'nonce');

        $submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        $user_id = get_current_user_id();

        if (!$submission_id || !$user_id) {
            wp_send_json_error(array('message' => __('Invalid request.', 'ffcertificate')));
        }

        // Verify this submission belongs to the current user
        $submission = ReregistrationSubmissionRepository::get_by_id($submission_id);
        if (!$submission || (int) $submission->user_id !== $user_id) {
            wp_send_json_error(array('message' => __('Submission not found.', 'ffcertificate')));
        }

        if (!in_array($submission->status, array('submitted', 'approved'), true)) {
            wp_send_json_error(array('message' => __('Ficha not available for this submission.', 'ffcertificate')));
        }

        $ficha_data = FichaGenerator::generate_ficha_data($submission_id);
        if (!$ficha_data) {
            wp_send_json_error(array('message' => __('Could not generate ficha.', 'ffcertificate')));
        }

        wp_send_json_success(array('pdf_data' => $ficha_data));
    }

    /**
     * Render the reregistration form HTML.
     *
     * @param object $rereg      Reregistration object.
     * @param object $submission Submission object.
     * @param int    $user_id    User ID.
     * @return string HTML.
     */
    private static function render_form(object $rereg, object $submission, int $user_id): string {
        $user = get_userdata($user_id);
        $profile = array();
        if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            $profile = UserManager::get_profile($user_id);
        }

        // Get custom fields for this audience (including parent hierarchy)
        $custom_fields = CustomFieldRepository::get_by_audience_with_parents((int) $rereg->audience_id, true);

        // Pre-populate from saved draft data, then from profile/user_meta
        $saved_data = $submission->data ? json_decode($submission->data, true) : array();
        $standard = $saved_data['standard_fields'] ?? array();
        $custom_values = $saved_data['custom_fields'] ?? array();

        // Fallback to profile data if no draft
        if (empty($standard)) {
            $standard = array(
                'display_name'  => $profile['display_name'] ?? $user->display_name,
                'phone'         => $profile['phone'] ?? '',
                'department'    => $profile['department'] ?? '',
                'organization'  => $profile['organization'] ?? '',
            );
        }

        // Fallback to user_meta custom fields if no draft
        if (empty($custom_values)) {
            $user_custom = CustomFieldRepository::get_user_data($user_id);
            foreach ($custom_fields as $cf) {
                $key = 'field_' . $cf->id;
                if (isset($user_custom[$key])) {
                    $custom_values[$key] = $user_custom[$key];
                }
            }
        }

        $end_date = wp_date(get_option('date_format'), strtotime($rereg->end_date));

        ob_start();
        ?>
        <div class="ffc-rereg-form-container" data-reregistration-id="<?php echo esc_attr($rereg->id); ?>">
            <h3><?php echo esc_html($rereg->title); ?></h3>
            <p class="ffc-rereg-deadline">
                <?php
                /* translators: %s: end date */
                echo esc_html(sprintf(__('Deadline: %s', 'ffcertificate'), $end_date));
                ?>
            </p>

            <form id="ffc-rereg-form" novalidate>
                <input type="hidden" name="reregistration_id" value="<?php echo esc_attr($rereg->id); ?>">

                <!-- Standard Fields -->
                <fieldset class="ffc-rereg-fieldset">
                    <legend><?php esc_html_e('Personal Information', 'ffcertificate'); ?></legend>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_name"><?php esc_html_e('Full Name', 'ffcertificate'); ?> <span class="required">*</span></label>
                        <input type="text" id="ffc_rereg_name" name="standard_fields[display_name]"
                               value="<?php echo esc_attr($standard['display_name'] ?? ''); ?>" required>
                    </div>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_email"><?php esc_html_e('Email', 'ffcertificate'); ?></label>
                        <input type="email" id="ffc_rereg_email" value="<?php echo esc_attr($user->user_email); ?>" readonly disabled>
                        <p class="ffc-field-hint"><?php esc_html_e('Email cannot be changed here.', 'ffcertificate'); ?></p>
                    </div>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_phone"><?php esc_html_e('Phone', 'ffcertificate'); ?></label>
                        <input type="tel" id="ffc_rereg_phone" name="standard_fields[phone]"
                               value="<?php echo esc_attr($standard['phone'] ?? ''); ?>"
                               data-mask="phone">
                    </div>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_dept"><?php esc_html_e('Department', 'ffcertificate'); ?></label>
                        <input type="text" id="ffc_rereg_dept" name="standard_fields[department]"
                               value="<?php echo esc_attr($standard['department'] ?? ''); ?>">
                    </div>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_org"><?php esc_html_e('Organization', 'ffcertificate'); ?></label>
                        <input type="text" id="ffc_rereg_org" name="standard_fields[organization]"
                               value="<?php echo esc_attr($standard['organization'] ?? ''); ?>">
                    </div>
                </fieldset>

                <?php if (!empty($custom_fields)) : ?>
                <fieldset class="ffc-rereg-fieldset">
                    <legend><?php esc_html_e('Additional Information', 'ffcertificate'); ?></legend>

                    <?php foreach ($custom_fields as $cf) : ?>
                        <?php
                        $field_key = 'field_' . $cf->id;
                        $field_value = $custom_values[$field_key] ?? '';
                        $field_name = 'custom_fields[' . $field_key . ']';
                        $field_id = 'ffc_cf_' . $cf->id;
                        $is_required = !empty($cf->is_required);
                        $rules = $cf->validation_rules ? json_decode($cf->validation_rules, true) : array();
                        ?>
                        <div class="ffc-rereg-field" data-field-id="<?php echo esc_attr($cf->id); ?>"
                             data-format="<?php echo esc_attr($rules['format'] ?? ''); ?>"
                             data-regex="<?php echo esc_attr($rules['custom_regex'] ?? ''); ?>"
                             data-regex-msg="<?php echo esc_attr($rules['custom_regex_message'] ?? ''); ?>">
                            <label for="<?php echo esc_attr($field_id); ?>">
                                <?php echo esc_html($cf->field_label); ?>
                                <?php if ($is_required) : ?><span class="required">*</span><?php endif; ?>
                            </label>

                            <?php
                            switch ($cf->field_type) {
                                case 'textarea':
                                    printf(
                                        '<textarea id="%s" name="%s" rows="3" %s>%s</textarea>',
                                        esc_attr($field_id),
                                        esc_attr($field_name),
                                        $is_required ? 'required' : '',
                                        esc_textarea($field_value)
                                    );
                                    break;

                                case 'select':
                                    $choices = $cf->choices ? json_decode($cf->choices, true) : array();
                                    printf('<select id="%s" name="%s" %s>', esc_attr($field_id), esc_attr($field_name), $is_required ? 'required' : '');
                                    echo '<option value="">' . esc_html__('Select...', 'ffcertificate') . '</option>';
                                    foreach ($choices as $choice) {
                                        printf(
                                            '<option value="%s" %s>%s</option>',
                                            esc_attr($choice),
                                            selected($field_value, $choice, false),
                                            esc_html($choice)
                                        );
                                    }
                                    echo '</select>';
                                    break;

                                case 'checkbox':
                                    printf(
                                        '<label class="ffc-checkbox-label"><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>',
                                        esc_attr($field_id),
                                        esc_attr($field_name),
                                        checked($field_value, '1', false),
                                        esc_html($cf->field_label)
                                    );
                                    break;

                                case 'number':
                                    printf(
                                        '<input type="number" id="%s" name="%s" value="%s" %s>',
                                        esc_attr($field_id),
                                        esc_attr($field_name),
                                        esc_attr($field_value),
                                        $is_required ? 'required' : ''
                                    );
                                    break;

                                case 'date':
                                    printf(
                                        '<input type="date" id="%s" name="%s" value="%s" %s>',
                                        esc_attr($field_id),
                                        esc_attr($field_name),
                                        esc_attr($field_value),
                                        $is_required ? 'required' : ''
                                    );
                                    break;

                                case 'working_hours':
                                    $wh_data = is_string($field_value) ? json_decode($field_value, true) : $field_value;
                                    if (!is_array($wh_data) || empty($wh_data)) {
                                        $wh_data = array(
                                            array('day' => 1, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'),
                                            array('day' => 2, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'),
                                            array('day' => 3, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'),
                                            array('day' => 4, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'),
                                            array('day' => 5, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'),
                                        );
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
                                    <input type="hidden" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr(wp_json_encode($wh_data)); ?>">
                                    <div class="ffc-working-hours" data-target="<?php echo esc_attr($field_id); ?>">
                                        <table class="ffc-wh-table">
                                            <thead>
                                                <tr>
                                                    <th><?php esc_html_e('Day', 'ffcertificate'); ?></th>
                                                    <th><?php esc_html_e('Entry 1', 'ffcertificate'); ?> <span class="required">*</span></th>
                                                    <th><?php esc_html_e('Exit 1', 'ffcertificate'); ?></th>
                                                    <th><?php esc_html_e('Entry 2', 'ffcertificate'); ?></th>
                                                    <th><?php esc_html_e('Exit 2', 'ffcertificate'); ?> <span class="required">*</span></th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($wh_data as $wh_entry) : ?>
                                                <tr>
                                                    <td>
                                                        <select class="ffc-wh-day">
                                                            <?php foreach ($days_labels as $d_num => $d_name) : ?>
                                                                <option value="<?php echo esc_attr($d_num); ?>" <?php selected($wh_entry['day'], $d_num); ?>><?php echo esc_html($d_name); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td><input type="time" class="ffc-wh-entry1" value="<?php echo esc_attr($wh_entry['entry1'] ?? ''); ?>" required></td>
                                                    <td><input type="time" class="ffc-wh-exit1" value="<?php echo esc_attr($wh_entry['exit1'] ?? ''); ?>"></td>
                                                    <td><input type="time" class="ffc-wh-entry2" value="<?php echo esc_attr($wh_entry['entry2'] ?? ''); ?>"></td>
                                                    <td><input type="time" class="ffc-wh-exit2" value="<?php echo esc_attr($wh_entry['exit2'] ?? ''); ?>" required></td>
                                                    <td><button type="button" class="ffc-wh-remove">&times;</button></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <button type="button" class="button ffc-wh-add">+ <?php esc_html_e('Add Day', 'ffcertificate'); ?></button>
                                    </div>
                                    <?php
                                    break;

                                default: // text
                                    $mask = '';
                                    $format = $rules['format'] ?? '';
                                    if ($format === 'cpf') {
                                        $mask = 'data-mask="cpf"';
                                    } elseif ($format === 'phone') {
                                        $mask = 'data-mask="phone"';
                                    }
                                    printf(
                                        '<input type="text" id="%s" name="%s" value="%s" %s %s>',
                                        esc_attr($field_id),
                                        esc_attr($field_name),
                                        esc_attr($field_value),
                                        $is_required ? 'required' : '',
                                        $mask // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- data attributes are safe
                                    );
                                    break;
                            }

                            if (!empty($cf->help_text)) {
                                printf('<p class="ffc-field-hint">%s</p>', esc_html($cf->help_text));
                            }
                            ?>

                            <span class="ffc-field-error" role="alert"></span>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                <?php endif; ?>

                <div class="ffc-rereg-actions">
                    <button type="button" class="button ffc-rereg-draft-btn"><?php esc_html_e('Save Draft', 'ffcertificate'); ?></button>
                    <button type="submit" class="button button-primary ffc-rereg-submit-btn"><?php esc_html_e('Submit', 'ffcertificate'); ?></button>
                    <button type="button" class="button ffc-rereg-cancel-btn"><?php esc_html_e('Cancel', 'ffcertificate'); ?></button>
                    <span class="ffc-rereg-status"></span>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Collect form data from POST.
     *
     * @param object $rereg   Reregistration object.
     * @param int    $user_id User ID.
     * @return array Structured data.
     */
    private static function collect_form_data(object $rereg, int $user_id): array {
        $standard = array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_standard = isset($_POST['standard_fields']) ? (array) wp_unslash($_POST['standard_fields']) : array();

        $allowed_standard = array('display_name', 'phone', 'department', 'organization');
        foreach ($allowed_standard as $key) {
            $standard[$key] = isset($raw_standard[$key]) ? sanitize_text_field($raw_standard[$key]) : '';
        }

        $custom = array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_custom = isset($_POST['custom_fields']) ? (array) wp_unslash($_POST['custom_fields']) : array();

        $fields = CustomFieldRepository::get_by_audience_with_parents((int) $rereg->audience_id, true);
        foreach ($fields as $cf) {
            $key = 'field_' . $cf->id;
            if (isset($raw_custom[$key])) {
                if ($cf->field_type === 'working_hours') {
                    // Sanitize JSON: decode, validate structure, re-encode
                    $wh = json_decode($raw_custom[$key], true);
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
                        $custom[$key] = wp_json_encode($sanitized);
                    } else {
                        $custom[$key] = '[]';
                    }
                } elseif ($cf->field_type === 'textarea') {
                    $custom[$key] = sanitize_textarea_field($raw_custom[$key]);
                } elseif ($cf->field_type === 'number') {
                    $custom[$key] = is_numeric($raw_custom[$key]) ? $raw_custom[$key] : '';
                } else {
                    $custom[$key] = sanitize_text_field($raw_custom[$key]);
                }
            } else {
                $custom[$key] = '';
            }
        }

        return array(
            'standard_fields' => $standard,
            'custom_fields'   => $custom,
        );
    }

    /**
     * Validate submission data.
     *
     * @param array  $data    Collected data.
     * @param object $rereg   Reregistration.
     * @param int    $user_id User ID.
     * @return array Errors keyed by field name.
     */
    private static function validate_submission(array $data, object $rereg, int $user_id): array {
        $errors = array();

        // Standard: display_name required
        if (empty($data['standard_fields']['display_name'])) {
            $errors['standard_fields[display_name]'] = __('Full name is required.', 'ffcertificate');
        }

        // Phone format validation (if provided)
        $phone = $data['standard_fields']['phone'] ?? '';
        if (!empty($phone) && !\FreeFormCertificate\Core\Utils::validate_phone($phone)) {
            $errors['standard_fields[phone]'] = __('Invalid phone format.', 'ffcertificate');
        }

        // Custom fields validation
        $fields = CustomFieldRepository::get_by_audience_with_parents((int) $rereg->audience_id, true);
        foreach ($fields as $cf) {
            $key = 'field_' . $cf->id;
            $value = $data['custom_fields'][$key] ?? '';
            $name = 'custom_fields[' . $key . ']';

            // Required check
            if (!empty($cf->is_required) && ($value === '' || $value === null)) {
                /* translators: %s: field label */
                $errors[$name] = sprintf(__('%s is required.', 'ffcertificate'), $cf->field_label);
                continue;
            }

            if ($value === '' || $value === null) {
                continue;
            }

            // Format validation
            $rules = $cf->validation_rules ? json_decode($cf->validation_rules, true) : array();
            $format = $rules['format'] ?? '';

            if ($format === 'cpf') {
                if (!\FreeFormCertificate\Core\Utils::validate_cpf($value)) {
                    /* translators: %s: field label */
                    $errors[$name] = sprintf(__('%s is not a valid CPF.', 'ffcertificate'), $cf->field_label);
                }
            } elseif ($format === 'email') {
                if (!is_email($value)) {
                    /* translators: %s: field label */
                    $errors[$name] = sprintf(__('%s is not a valid email.', 'ffcertificate'), $cf->field_label);
                }
            } elseif ($format === 'phone') {
                if (!\FreeFormCertificate\Core\Utils::validate_phone($value)) {
                    /* translators: %s: field label */
                    $errors[$name] = sprintf(__('%s is not a valid phone number.', 'ffcertificate'), $cf->field_label);
                }
            } elseif ($format === 'custom_regex' && !empty($rules['custom_regex'])) {
                $regex = $rules['custom_regex'];
                // Ensure regex has delimiters
                if ($regex[0] !== '/') {
                    $regex = '/' . $regex . '/';
                }
                if (!@preg_match($regex, $value)) {
                    $msg = !empty($rules['custom_regex_message']) ? $rules['custom_regex_message'] : sprintf(__('%s has an invalid format.', 'ffcertificate'), $cf->field_label);
                    $errors[$name] = $msg;
                }
            }
        }

        return $errors;
    }

    /**
     * Process a validated submission.
     *
     * @param object $submission Submission record.
     * @param object $rereg      Reregistration.
     * @param array  $data       Validated data.
     * @param int    $user_id    User ID.
     * @return void
     */
    private static function process_submission(object $submission, object $rereg, array $data, int $user_id): void {
        // Determine final status
        $new_status = !empty($rereg->auto_approve) ? 'approved' : 'submitted';

        // Build update data (single query)
        $update_data = array(
            'data'         => $data,
            'status'       => $new_status,
            'submitted_at' => current_time('mysql'),
        );

        // If auto-approved, include reviewed fields in the same update
        if ($new_status === 'approved') {
            $update_data['reviewed_at'] = current_time('mysql');
            $update_data['reviewed_by'] = 0;
            $update_data['notes']       = __('Auto-approved', 'ffcertificate');
        }

        ReregistrationSubmissionRepository::update((int) $submission->id, $update_data);

        // Update user profile with standard fields
        $standard = $data['standard_fields'];
        if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            UserManager::update_profile($user_id, array(
                'display_name'  => $standard['display_name'],
                'phone'         => $standard['phone'],
                'department'    => $standard['department'],
                'organization'  => $standard['organization'],
            ));
        }

        // Update custom fields user meta
        $custom = $data['custom_fields'];
        if (!empty($custom)) {
            $existing = CustomFieldRepository::get_user_data($user_id);
            $merged = array_merge($existing, $custom);
            CustomFieldRepository::save_user_data($user_id, $merged);
        }

        // Send confirmation email
        ReregistrationEmailHandler::send_confirmation((int) $submission->id);

        // Activity log
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'reregistration_submitted',
                $user_id,
                array(
                    'reregistration_id' => $rereg->id,
                    'submission_id'     => $submission->id,
                    'status'            => $new_status,
                )
            );
        }
    }

    /**
     * Get active reregistrations for a user with submission status.
     *
     * @param int $user_id User ID.
     * @return array Array of reregistration data with submission info.
     */
    public static function get_user_reregistrations(int $user_id): array {
        $active = ReregistrationRepository::get_active_for_user($user_id);
        $result = array();

        foreach ($active as $rereg) {
            $submission = ReregistrationSubmissionRepository::get_by_reregistration_and_user((int) $rereg->id, $user_id);
            $sub_status = $submission ? $submission->status : 'no_submission';

            $result[] = array(
                'id'             => (int) $rereg->id,
                'title'          => $rereg->title,
                'audience_name'  => $rereg->audience_name ?? '',
                'start_date'     => $rereg->start_date,
                'end_date'       => $rereg->end_date,
                'auto_approve'   => !empty($rereg->auto_approve),
                'submission_status' => $sub_status,
                'submission_id'  => $submission ? (int) $submission->id : 0,
                'can_submit'     => in_array($sub_status, array('pending', 'in_progress', 'rejected'), true),
            );
        }

        return $result;
    }
}
