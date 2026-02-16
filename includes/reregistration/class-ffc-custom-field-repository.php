<?php
declare(strict_types=1);

/**
 * Custom Field Repository
 *
 * Handles database operations for audience-specific custom field definitions.
 * Field data for users is stored in wp_usermeta as JSON (key: ffc_custom_fields_data).
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceRepository;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

class CustomFieldRepository {

    /**
     * Supported field types.
     */
    public const FIELD_TYPES = array(
        'text',
        'number',
        'date',
        'select',
        'dependent_select',
        'checkbox',
        'textarea',
        'working_hours',
    );

    /**
     * Built-in validation formats.
     */
    public const VALIDATION_FORMATS = array(
        'cpf',
        'email',
        'phone',
        'custom_regex',
    );

    /**
     * User meta key for storing custom field data.
     */
    public const USER_META_KEY = 'ffc_custom_fields_data';

    /**
     * Get table name.
     *
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_custom_fields';
    }

    /**
     * Get a single field by ID.
     *
     * @param int $field_id Field ID.
     * @return object|null
     */
    public static function get_by_id(int $field_id): ?object {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $field_id)
        );
    }

    /**
     * Get fields for a specific audience.
     *
     * @param int  $audience_id Audience ID.
     * @param bool $active_only Only return active fields.
     * @return array<object>
     */
    public static function get_by_audience(int $audience_id, bool $active_only = true): array {
        global $wpdb;
        $table = self::get_table_name();

        $where = 'WHERE audience_id = %d';
        $values = array($audience_id);

        if ($active_only) {
            $where .= ' AND is_active = 1';
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, id ASC",
                $values
            )
        );
    }

    /**
     * Get fields for an audience including inherited fields from parent audiences.
     *
     * Walks up the hierarchy and collects all fields, ordered by hierarchy level
     * (parent fields first, then child fields), each group sorted by sort_order.
     *
     * @param int  $audience_id Audience ID.
     * @param bool $active_only Only return active fields.
     * @return array<object> Fields with added 'source_audience_id' and 'source_audience_name' properties.
     */
    public static function get_by_audience_with_parents(int $audience_id, bool $active_only = true): array {
        $audience = AudienceRepository::get_by_id($audience_id);
        if (!$audience) {
            return array();
        }

        // Collect audience IDs from bottom to top (child → parent)
        $audience_chain = array();
        $current = $audience;
        while ($current) {
            $audience_chain[] = $current;
            if (!empty($current->parent_id)) {
                $current = AudienceRepository::get_by_id((int) $current->parent_id);
            } else {
                $current = null;
            }
        }

        // Reverse to get top-down order (parent → child)
        $audience_chain = array_reverse($audience_chain);

        $all_fields = array();
        foreach ($audience_chain as $aud) {
            $fields = self::get_by_audience((int) $aud->id, $active_only);
            foreach ($fields as $field) {
                $field->source_audience_id = (int) $aud->id;
                $field->source_audience_name = $aud->name;
                $all_fields[] = $field;
            }
        }

        return $all_fields;
    }

    /**
     * Get all fields for a user based on their audience memberships.
     *
     * @param int  $user_id    User ID.
     * @param bool $active_only Only return active fields.
     * @return array<object> Fields grouped conceptually, with source_audience_* properties.
     */
    public static function get_all_for_user(int $user_id, bool $active_only = true): array {
        $audiences = AudienceRepository::get_user_audiences($user_id);
        if (empty($audiences)) {
            return array();
        }

        $all_fields = array();
        $seen_ids = array();

        foreach ($audiences as $audience) {
            $fields = self::get_by_audience_with_parents((int) $audience->id, $active_only);
            foreach ($fields as $field) {
                // Avoid duplicates when user belongs to sibling audiences sharing a parent
                if (!isset($seen_ids[(int) $field->id])) {
                    $seen_ids[(int) $field->id] = true;
                    $all_fields[] = $field;
                }
            }
        }

        return $all_fields;
    }

    /**
     * Create a custom field.
     *
     * @param array $data Field data.
     * @return int|false Field ID or false on failure.
     */
    public static function create(array $data) {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = array(
            'audience_id'      => 0,
            'field_key'        => '',
            'field_label'      => '',
            'field_type'       => 'text',
            'field_options'    => null,
            'validation_rules' => null,
            'sort_order'       => 0,
            'is_required'      => 0,
            'is_active'        => 1,
        );
        $data = wp_parse_args($data, $defaults);

        // Validate field type
        if (!in_array($data['field_type'], self::FIELD_TYPES, true)) {
            $data['field_type'] = 'text';
        }

        // Auto-generate field_key from label if empty
        if (empty($data['field_key'])) {
            $data['field_key'] = self::generate_field_key($data['field_label']);
        }

        // Ensure field_key uniqueness within audience
        $data['field_key'] = self::ensure_unique_key($data['field_key'], (int) $data['audience_id']);

        $insert_data = array(
            'audience_id'      => (int) $data['audience_id'],
            'field_key'        => sanitize_key($data['field_key']),
            'field_label'      => sanitize_text_field($data['field_label']),
            'field_type'       => $data['field_type'],
            'field_options'    => is_string($data['field_options']) ? $data['field_options'] : wp_json_encode($data['field_options']),
            'validation_rules' => is_string($data['validation_rules']) ? $data['validation_rules'] : wp_json_encode($data['validation_rules']),
            'sort_order'       => (int) $data['sort_order'],
            'is_required'      => (int) $data['is_required'],
            'is_active'        => (int) $data['is_active'],
        );

        $result = $wpdb->insert(
            $table,
            $insert_data,
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update a custom field.
     *
     * @param int   $field_id Field ID.
     * @param array $data     Update data.
     * @return bool
     */
    public static function update(int $field_id, array $data): bool {
        global $wpdb;
        $table = self::get_table_name();

        // Remove non-updatable fields
        unset($data['id'], $data['created_at']);

        if (empty($data)) {
            return false;
        }

        $update_data = array();
        $format = array();

        $field_formats = array(
            'audience_id'      => '%d',
            'field_key'        => '%s',
            'field_label'      => '%s',
            'field_type'       => '%s',
            'field_options'    => '%s',
            'validation_rules' => '%s',
            'sort_order'       => '%d',
            'is_required'      => '%d',
            'is_active'        => '%d',
        );

        foreach ($data as $key => $value) {
            if (!isset($field_formats[$key])) {
                continue;
            }

            // Encode JSON fields
            if (in_array($key, array('field_options', 'validation_rules'), true) && !is_string($value)) {
                $value = wp_json_encode($value);
            }

            // Sanitize text fields
            if (in_array($key, array('field_key', 'field_label', 'field_type'), true)) {
                $value = $key === 'field_key' ? sanitize_key($value) : sanitize_text_field($value);
            }

            // Validate field type
            if ($key === 'field_type' && !in_array($value, self::FIELD_TYPES, true)) {
                $value = 'text';
            }

            $update_data[$key] = $value;
            $format[] = $field_formats[$key];
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $field_id),
            $format,
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Delete a custom field definition.
     *
     * Note: This only removes the field definition. User data in wp_usermeta
     * remains as orphaned keys in the JSON — this is by design so data can
     * be recovered if the field is re-created.
     *
     * @param int $field_id Field ID.
     * @return bool
     */
    public static function delete(int $field_id): bool {
        global $wpdb;
        $table = self::get_table_name();

        $result = $wpdb->delete($table, array('id' => $field_id), array('%d'));

        return $result !== false;
    }

    /**
     * Deactivate a field (hide but preserve data).
     *
     * @param int $field_id Field ID.
     * @return bool
     */
    public static function deactivate(int $field_id): bool {
        return self::update($field_id, array('is_active' => 0));
    }

    /**
     * Reactivate a previously deactivated field.
     *
     * @param int $field_id Field ID.
     * @return bool
     */
    public static function reactivate(int $field_id): bool {
        return self::update($field_id, array('is_active' => 1));
    }

    /**
     * Reorder fields by updating sort_order in batch.
     *
     * @param array<int> $field_ids Ordered array of field IDs.
     * @return bool
     */
    public static function reorder(array $field_ids): bool {
        global $wpdb;
        $table = self::get_table_name();

        foreach ($field_ids as $index => $field_id) {
            $wpdb->update(
                $table,
                array('sort_order' => $index),
                array('id' => (int) $field_id),
                array('%d'),
                array('%d')
            );
        }

        return true;
    }

    /**
     * Get field count for an audience.
     *
     * @param int  $audience_id Audience ID.
     * @param bool $active_only Only count active fields.
     * @return int
     */
    public static function count_by_audience(int $audience_id, bool $active_only = true): int {
        global $wpdb;
        $table = self::get_table_name();

        $where = 'WHERE audience_id = %d';
        $values = array($audience_id);

        if ($active_only) {
            $where .= ' AND is_active = 1';
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where}", $values)
        );
    }

    // ─────────────────────────────────────────────
    // User data helpers (wp_usermeta JSON storage)
    // ─────────────────────────────────────────────

    /**
     * Get custom field data for a user.
     *
     * @param int $user_id User ID.
     * @return array<string, mixed> Associative array of field_id => value.
     */
    public static function get_user_data(int $user_id): array {
        $data = get_user_meta($user_id, self::USER_META_KEY, true);
        if (empty($data) || !is_array($data)) {
            return array();
        }
        return $data;
    }

    /**
     * Save custom field data for a user.
     *
     * Merges with existing data (does not overwrite unrelated fields).
     *
     * @param int   $user_id User ID.
     * @param array $data    Associative array of field_{id} => value.
     * @return bool
     */
    public static function save_user_data(int $user_id, array $data): bool {
        $existing = self::get_user_data($user_id);
        $merged = array_merge($existing, $data);

        return (bool) update_user_meta($user_id, self::USER_META_KEY, $merged);
    }

    /**
     * Get a single field value for a user.
     *
     * @param int $user_id  User ID.
     * @param int $field_id Field ID.
     * @return mixed|null Field value or null if not set.
     */
    public static function get_user_field_value(int $user_id, int $field_id) {
        $data = self::get_user_data($user_id);
        $key = 'field_' . $field_id;
        return $data[$key] ?? null;
    }

    /**
     * Set a single field value for a user.
     *
     * @param int   $user_id  User ID.
     * @param int   $field_id Field ID.
     * @param mixed $value    Field value.
     * @return bool
     */
    public static function set_user_field_value(int $user_id, int $field_id, $value): bool {
        return self::save_user_data($user_id, array('field_' . $field_id => $value));
    }

    // ─────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────

    /**
     * Validate a field value against its definition.
     *
     * @param object $field Field definition object.
     * @param mixed  $value Value to validate.
     * @return true|\WP_Error True if valid, WP_Error with message if invalid.
     */
    public static function validate_field_value(object $field, $value) {
        // Required check
        if (!empty($field->is_required) && self::is_empty_value($value)) {
            return new \WP_Error(
                'field_required',
                /* translators: %s: field label */
                sprintf(__('%s is required.', 'ffcertificate'), $field->field_label)
            );
        }

        // Skip further validation if empty and not required
        if (self::is_empty_value($value)) {
            return true;
        }

        // Type-specific validation
        switch ($field->field_type) {
            case 'number':
                if (!is_numeric($value)) {
                    return new \WP_Error(
                        'field_invalid_number',
                        /* translators: %s: field label */
                        sprintf(__('%s must be a number.', 'ffcertificate'), $field->field_label)
                    );
                }
                break;

            case 'date':
                if (!self::is_valid_date($value)) {
                    return new \WP_Error(
                        'field_invalid_date',
                        /* translators: %s: field label */
                        sprintf(__('%s must be a valid date (YYYY-MM-DD).', 'ffcertificate'), $field->field_label)
                    );
                }
                break;

            case 'select':
                $options = self::get_field_choices($field);
                if (!empty($options) && !in_array($value, $options, true)) {
                    return new \WP_Error(
                        'field_invalid_option',
                        /* translators: %s: field label */
                        sprintf(__('%s has an invalid selection.', 'ffcertificate'), $field->field_label)
                    );
                }
                break;

            case 'dependent_select':
                $dep_result = self::validate_dependent_select($field, $value);
                if (is_wp_error($dep_result)) {
                    return $dep_result;
                }
                break;

            case 'working_hours':
                $wh_result = self::validate_working_hours($field, $value);
                if (is_wp_error($wh_result)) {
                    return $wh_result;
                }
                break;
        }

        // Format validation from validation_rules
        $rules = self::get_validation_rules($field);
        if (!empty($rules)) {
            $format_result = self::validate_format($field, $value, $rules);
            if (is_wp_error($format_result)) {
                return $format_result;
            }
        }

        return true;
    }

    /**
     * Validate value format against validation rules.
     *
     * @param object $field Field definition.
     * @param mixed  $value Value to validate.
     * @param array  $rules Validation rules.
     * @return true|\WP_Error
     */
    private static function validate_format(object $field, $value, array $rules) {
        $str_value = (string) $value;

        // Min/max length
        if (isset($rules['min_length']) && mb_strlen($str_value) < (int) $rules['min_length']) {
            return new \WP_Error(
                'field_too_short',
                /* translators: 1: field label, 2: minimum length */
                sprintf(__('%1$s must be at least %2$d characters.', 'ffcertificate'), $field->field_label, (int) $rules['min_length'])
            );
        }

        if (isset($rules['max_length']) && mb_strlen($str_value) > (int) $rules['max_length']) {
            return new \WP_Error(
                'field_too_long',
                /* translators: 1: field label, 2: maximum length */
                sprintf(__('%1$s must be at most %2$d characters.', 'ffcertificate'), $field->field_label, (int) $rules['max_length'])
            );
        }

        // Built-in format validation
        if (!empty($rules['format'])) {
            switch ($rules['format']) {
                case 'cpf':
                    if (!self::validate_cpf($str_value)) {
                        return new \WP_Error(
                            'field_invalid_cpf',
                            /* translators: %s: field label */
                            sprintf(__('%s must be a valid CPF.', 'ffcertificate'), $field->field_label)
                        );
                    }
                    break;

                case 'email':
                    if (!is_email($str_value)) {
                        return new \WP_Error(
                            'field_invalid_email',
                            /* translators: %s: field label */
                            sprintf(__('%s must be a valid email address.', 'ffcertificate'), $field->field_label)
                        );
                    }
                    break;

                case 'phone':
                    if (!\FreeFormCertificate\Core\Utils::validate_phone($str_value)) {
                        return new \WP_Error(
                            'field_invalid_phone',
                            /* translators: %s: field label */
                            sprintf(__('%s must be a valid phone number.', 'ffcertificate'), $field->field_label)
                        );
                    }
                    break;

                case 'custom_regex':
                    if (!empty($rules['custom_regex'])) {
                        $pattern = '/' . str_replace('/', '\/', $rules['custom_regex']) . '/';
                        if (!preg_match($pattern, $str_value)) {
                            $message = !empty($rules['custom_regex_message'])
                                ? $rules['custom_regex_message']
                                /* translators: %s: field label */
                                : sprintf(__('%s has an invalid format.', 'ffcertificate'), $field->field_label);
                            return new \WP_Error('field_invalid_format', $message);
                        }
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * Validate working_hours JSON value.
     *
     * Each entry: {day, entry1 (required), exit1, entry2, exit2 (required)}.
     * exit1 and entry2 are optional (lunch break).
     *
     * @param object $field Field definition.
     * @param mixed  $value Raw value (JSON string or array).
     * @return true|\WP_Error
     */
    private static function validate_working_hours(object $field, $value) {
        $time_re = '/^\d{2}:\d{2}$/';
        $entries = is_string($value) ? json_decode($value, true) : $value;

        if (!is_array($entries)) {
            return new \WP_Error(
                'field_invalid_working_hours',
                /* translators: %s: field label */
                sprintf(__('%s must be a valid working hours schedule.', 'ffcertificate'), $field->field_label)
            );
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                /* translators: %s: field label */
                return new \WP_Error('field_invalid_working_hours', sprintf(__('%s contains invalid entries.', 'ffcertificate'), $field->field_label));
            }

            $day = $entry['day'] ?? null;
            if ($day === null || !is_numeric($day) || (int) $day < 0 || (int) $day > 6) {
                /* translators: %s: field label */
                return new \WP_Error('field_invalid_working_hours', sprintf(__('%s contains an invalid day.', 'ffcertificate'), $field->field_label));
            }

            // entry1 is required
            $entry1 = $entry['entry1'] ?? null;
            if (!$entry1 || !preg_match($time_re, $entry1)) {
                /* translators: %s: field label */
                return new \WP_Error('field_invalid_working_hours', sprintf(__('%s: Entry 1 is required for each day.', 'ffcertificate'), $field->field_label));
            }

            // exit2 is required
            $exit2 = $entry['exit2'] ?? null;
            if (!$exit2 || !preg_match($time_re, $exit2)) {
                /* translators: %s: field label */
                return new \WP_Error('field_invalid_working_hours', sprintf(__('%s: Exit 2 is required for each day.', 'ffcertificate'), $field->field_label));
            }

            // exit1 and entry2 are optional but must be valid if provided
            $exit1 = $entry['exit1'] ?? '';
            if ($exit1 !== '' && !preg_match($time_re, $exit1)) {
                /* translators: %s: field label */
                return new \WP_Error('field_invalid_working_hours', sprintf(__('%s contains an invalid time.', 'ffcertificate'), $field->field_label));
            }
            $entry2 = $entry['entry2'] ?? '';
            if ($entry2 !== '' && !preg_match($time_re, $entry2)) {
                /* translators: %s: field label */
                return new \WP_Error('field_invalid_working_hours', sprintf(__('%s contains an invalid time.', 'ffcertificate'), $field->field_label));
            }
        }

        return true;
    }

    /**
     * Validate a Brazilian CPF number.
     *
     * @param string $cpf CPF string (with or without formatting).
     * @return bool
     */
    private static function validate_cpf(string $cpf): bool {
        // Use existing utility if available
        if (class_exists('\FreeFormCertificate\Core\Utils') && method_exists('\FreeFormCertificate\Core\Utils', 'validate_cpf')) {
            return \FreeFormCertificate\Core\Utils::validate_cpf($cpf);
        }

        $cpf = preg_replace('/\D/', '', $cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += (int) $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ((int) $cpf[$c] !== $d) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a dependent_select field value.
     *
     * Value should be JSON: {"parent": "DRE - DIAF", "child": "Contabilidade"}.
     * Validates that both selections are valid per field_options groups.
     *
     * @param object $field Field definition.
     * @param mixed  $value Raw value (JSON string or array).
     * @return true|\WP_Error
     */
    private static function validate_dependent_select(object $field, $value) {
        $parsed = is_string($value) ? json_decode($value, true) : $value;

        if (!is_array($parsed) || !isset($parsed['parent'], $parsed['child'])) {
            return new \WP_Error(
                'field_invalid_dependent_select',
                /* translators: %s: field label */
                sprintf(__('%s requires both selections.', 'ffcertificate'), $field->field_label)
            );
        }

        $groups = self::get_dependent_choices($field);
        if (empty($groups)) {
            return true;
        }

        $parent_val = $parsed['parent'];
        $child_val  = $parsed['child'];

        if (!isset($groups[$parent_val])) {
            return new \WP_Error(
                'field_invalid_dependent_select',
                /* translators: %s: field label */
                sprintf(__('%s has an invalid primary selection.', 'ffcertificate'), $field->field_label)
            );
        }

        if (!in_array($child_val, $groups[$parent_val], true)) {
            return new \WP_Error(
                'field_invalid_dependent_select',
                /* translators: %s: field label */
                sprintf(__('%s has an invalid secondary selection.', 'ffcertificate'), $field->field_label)
            );
        }

        return true;
    }

    /**
     * Get grouped choices for a dependent_select field.
     *
     * Expected field_options format:
     *   {"groups": {"Parent Label": ["Child 1", "Child 2"], ...},
     *    "parent_label": "Divisão", "child_label": "Setor"}
     *
     * @param object $field Field definition.
     * @return array<string, array<string>> Parent => [children].
     */
    public static function get_dependent_choices(object $field): array {
        $options = $field->field_options;
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        return $options['groups'] ?? array();
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    /**
     * Generate a field key from a label.
     *
     * @param string $label Field label.
     * @return string
     */
    private static function generate_field_key(string $label): string {
        $key = sanitize_title($label);
        $key = str_replace('-', '_', $key);
        $key = preg_replace('/[^a-z0-9_]/', '', $key);
        return substr($key, 0, 100) ?: 'field';
    }

    /**
     * Ensure a field key is unique within an audience.
     *
     * @param string $key         Desired key.
     * @param int    $audience_id Audience ID.
     * @param int    $exclude_id  Field ID to exclude from check (for updates).
     * @return string Unique key.
     */
    private static function ensure_unique_key(string $key, int $audience_id, int $exclude_id = 0): string {
        global $wpdb;
        $table = self::get_table_name();

        $original_key = $key;
        $counter = 1;

        while (true) {
            $where = 'WHERE audience_id = %d AND field_key = %s';
            $values = array($audience_id, $key);

            if ($exclude_id > 0) {
                $where .= ' AND id != %d';
                $values[] = $exclude_id;
            }

            $exists = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where}", $values)
            );

            if ($exists === 0) {
                break;
            }

            $key = $original_key . '_' . $counter;
            $counter++;
        }

        return $key;
    }

    /**
     * Get choices for a select field.
     *
     * @param object $field Field definition.
     * @return array<string>
     */
    public static function get_field_choices(object $field): array {
        $options = $field->field_options;
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        return $options['choices'] ?? array();
    }

    /**
     * Get validation rules for a field.
     *
     * @param object $field Field definition.
     * @return array
     */
    public static function get_validation_rules(object $field): array {
        $rules = $field->validation_rules;
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }
        return is_array($rules) ? $rules : array();
    }

    /**
     * Check if a value is empty (considering various types).
     *
     * @param mixed $value Value to check.
     * @return bool
     */
    private static function is_empty_value($value): bool {
        if ($value === null || $value === '' || $value === array()) {
            return true;
        }
        if (is_string($value) && (trim($value) === '' || $value === '[]')) {
            return true;
        }
        return false;
    }

    /**
     * Validate a date string (YYYY-MM-DD format).
     *
     * @param string $date Date string.
     * @return bool
     */
    private static function is_valid_date(string $date): bool {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
