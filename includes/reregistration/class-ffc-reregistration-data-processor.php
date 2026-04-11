<?php
declare(strict_types=1);

/**
 * Reregistration Data Processor
 *
 * Handles the data pipeline for reregistration form submissions using the
 * unified dynamic field system. All fields — whether originally "standard"
 * or admin-created "custom" — are read from wp_ffc_custom_fields and
 * processed uniformly.
 *
 * Responsibilities:
 * - Collecting and sanitizing form data from POST ($_POST['fields'])
 * - Validating each field against its definition (type, required, rules)
 * - Encrypting sensitive values (is_sensitive=1) before persistence
 * - Syncing profile_key-mapped values to the user profile on approval
 * - Triggering confirmation email and activity log
 *
 * Submission shape after refactor:
 *   { "fields": { "<field_key>": <value>, ... } }
 *
 * Sensitive values are stored encrypted (AES-256-CBC) inside the JSON and
 * transparently decrypted when read back by the form renderer / PDF /
 * admin review UI.
 *
 * @since 4.13.0  Unified dynamic field system
 * @since 4.12.8  Extracted from ReregistrationFrontend
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\UserDashboard\UserManager;

if (!defined('ABSPATH')) {
    exit;
}

class ReregistrationDataProcessor {

    /**
     * POST root key for the unified dynamic form payload.
     */
    private const POST_ROOT = 'fields';

    /**
     * Sanitize a raw working_hours JSON string into canonical JSON.
     *
     * @param string $raw Raw JSON input.
     * @return string Sanitized JSON.
     */
    public static function sanitize_working_hours(string $raw): string {
        $wh = json_decode($raw, true);
        if (!is_array($wh)) {
            return '[]';
        }
        $sanitized = array();
        foreach ($wh as $entry) {
            if (is_array($entry) && isset($entry['day'])) {
                $sanitized[] = array(
                    'day'    => absint($entry['day']),
                    'entry1' => sanitize_text_field((string) ($entry['entry1'] ?? '')),
                    'exit1'  => sanitize_text_field((string) ($entry['exit1']  ?? '')),
                    'entry2' => sanitize_text_field((string) ($entry['entry2'] ?? '')),
                    'exit2'  => sanitize_text_field((string) ($entry['exit2']  ?? '')),
                );
            }
        }
        return (string) wp_json_encode($sanitized);
    }

    /**
     * Collect and sanitize form data from $_POST['fields'].
     *
     * @param object $rereg   Reregistration object.
     * @param int    $user_id User ID.
     * @return array<string, mixed> Structured data { fields: { key => value } }.
     */
    public static function collect_form_data(object $rereg, int $user_id): array {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce verified in AJAX handler; sanitized per-field below.
        $raw = isset($_POST[self::POST_ROOT]) ? (array) wp_unslash($_POST[self::POST_ROOT]) : array();

        $fields    = self::get_fields_for_reregistration($rereg);
        $collected = array();

        foreach ($fields as $field) {
            $key   = (string) $field->field_key;
            $value = $raw[$key] ?? '';

            $collected[$key] = self::sanitize_field_value($field, $value);
        }

        return array('fields' => $collected);
    }

    /**
     * Sanitize a single field value according to its type.
     *
     * @param object $field Field definition.
     * @param mixed  $raw   Raw input value.
     * @return mixed Sanitized value.
     */
    private static function sanitize_field_value(object $field, $raw) {
        if ($raw === null) {
            return '';
        }

        switch ((string) $field->field_type) {
            case 'working_hours':
                return self::sanitize_working_hours(is_string($raw) ? $raw : (string) wp_json_encode($raw));

            case 'dependent_select':
                $dep = is_string($raw) ? json_decode($raw, true) : $raw;
                if (is_array($dep) && isset($dep['parent'], $dep['child'])) {
                    return (string) wp_json_encode(array(
                        'parent' => sanitize_text_field((string) $dep['parent']),
                        'child'  => sanitize_text_field((string) $dep['child']),
                    ));
                }
                return (string) wp_json_encode(array('parent' => '', 'child' => ''));

            case 'textarea':
                return sanitize_textarea_field(is_scalar($raw) ? (string) $raw : '');

            case 'number':
                if (is_numeric($raw)) {
                    return (string) $raw;
                }
                return '';

            case 'checkbox':
                return ! empty($raw) && $raw !== '0' ? '1' : '0';

            case 'date':
                $str = is_scalar($raw) ? sanitize_text_field((string) $raw) : '';
                // Basic YYYY-MM-DD guard; deeper validation happens later.
                return $str;

            default: // text, select and unknown types
                return sanitize_text_field(is_scalar($raw) ? (string) $raw : '');
        }
    }

    /**
     * Validate submission data against each field definition.
     *
     * @param array<string, mixed> $data    Collected data (from collect_form_data).
     * @param object               $rereg   Reregistration.
     * @param int                  $user_id User ID.
     * @return array<string, string> Errors keyed by input name.
     */
    public static function validate_submission(array $data, object $rereg, int $user_id): array {
        $errors      = array();
        $values      = is_array($data['fields'] ?? null) ? $data['fields'] : array();
        $fields      = self::get_fields_for_reregistration($rereg);
        $divisao_map = ReregistrationFieldOptions::get_divisao_setor_map();

        foreach ($fields as $field) {
            $key       = (string) $field->field_key;
            $value     = $values[$key] ?? '';
            $name      = self::POST_ROOT . '[' . $key . ']';
            $label     = (string) $field->field_label;

            // Required check — use repository's is_empty_value semantics.
            if (!empty($field->is_required) && self::is_empty_value($value)) {
                /* translators: %s: field label */
                $errors[$name] = sprintf(__('%s is required.', 'ffcertificate'), $label);
                continue;
            }

            if (self::is_empty_value($value)) {
                continue; // Skip further validation for empty optional fields.
            }

            // Delegate type-aware validation to the repository helper.
            $check = CustomFieldRepository::validate_field_value($field, $value);
            if (is_wp_error($check)) {
                $errors[$name] = $check->get_error_message();
                continue;
            }

            // Additional cross-field consistency for divisao_setor against
            // the authoritative DRE MP map (covers the case where the
            // admin has changed the field but wants the canonical map).
            if ($field->field_type === 'dependent_select' && $key === 'divisao_setor') {
                $decoded = is_string($value) ? json_decode($value, true) : $value;
                if (is_array($decoded) && !empty($decoded['parent']) && !empty($decoded['child'])) {
                    $parent = (string) $decoded['parent'];
                    $child  = (string) $decoded['child'];
                    if (isset($divisao_map[$parent]) && !in_array($child, $divisao_map[$parent], true)) {
                        $errors[$name] = __('Invalid department for the selected division.', 'ffcertificate');
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Process a validated submission.
     *
     * - Encrypts sensitive fields
     * - Saves to wp_ffc_reregistration_submissions
     * - Syncs profile_key-mapped fields to the user profile
     * - Sends confirmation email + activity log entry
     *
     * @param object               $submission Submission record.
     * @param object               $rereg      Reregistration.
     * @param array<string, mixed> $data       Validated collected data.
     * @param int                  $user_id    User ID.
     * @return void
     */
    public static function process_submission(object $submission, object $rereg, array $data, int $user_id): void {
        $new_status = !empty($rereg->auto_approve) ? 'approved' : 'submitted';

        $fields = self::get_fields_for_reregistration($rereg);
        $values = is_array($data['fields'] ?? null) ? $data['fields'] : array();

        // Build the persistence payload with sensitive fields encrypted.
        $persisted_fields = array();
        foreach ($fields as $field) {
            $key = (string) $field->field_key;
            $val = $values[$key] ?? '';

            if (!empty($field->is_sensitive) && is_string($val) && $val !== '' && class_exists('\FreeFormCertificate\Core\Encryption')) {
                $encrypted = \FreeFormCertificate\Core\Encryption::encrypt($val);
                if ($encrypted !== null) {
                    $persisted_fields[$key] = $encrypted;
                    continue;
                }
            }

            $persisted_fields[$key] = $val;
        }

        $persisted_data = array('fields' => $persisted_fields);

        // Auth code + magic token for the approval link.
        $auth_code   = \FreeFormCertificate\Core\Utils::generate_globally_unique_auth_code();
        $magic_token = bin2hex(random_bytes(32));

        $update_data = array(
            'data'         => $persisted_data,
            'status'       => $new_status,
            'submitted_at' => current_time('mysql'),
            'auth_code'    => $auth_code,
            'magic_token'  => $magic_token,
        );

        if ($new_status === 'approved') {
            $update_data['reviewed_at'] = current_time('mysql');
            $update_data['reviewed_by'] = 0;
            $update_data['notes']       = __('Auto-approved', 'ffcertificate');
        }

        ReregistrationSubmissionRepository::update((int) $submission->id, $update_data);

        // Sync profile-mapped fields back to the user profile. We pass the
        // *plain* (pre-encryption) values to update_extended_profile which
        // will re-encrypt the sensitive ones on its side.
        self::sync_profile($user_id, $fields, $values);

        // Store the remaining (non-profile) values in usermeta for future
        // form pre-population on the user side.
        self::store_user_snapshot($user_id, $fields, $values);

        // Confirmation email.
        ReregistrationEmailHandler::send_confirmation((int) $submission->id);

        // Activity log.
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'reregistration_submitted',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
                array(
                    'reregistration_id' => $rereg->id,
                    'submission_id'     => $submission->id,
                    'status'            => $new_status,
                ),
                $user_id,
                (int) $submission->id
            );
        }
    }

    /**
     * Sync profile-mapped fields to the user profile.
     *
     * @param int            $user_id User ID.
     * @param array<object>  $fields  All active field definitions.
     * @param array<string, mixed> $values field_key => plain value.
     */
    private static function sync_profile(int $user_id, array $fields, array $values): void {
        if (!class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            return;
        }

        $payload        = array();
        $sensitive_keys = array();

        foreach ($fields as $field) {
            if (empty($field->field_profile_key)) {
                continue;
            }
            $pkey  = (string) $field->field_profile_key;
            $value = $values[(string) $field->field_key] ?? '';

            // For dependent_select and working_hours we keep the JSON.
            if (is_array($value)) {
                $value = (string) wp_json_encode($value);
            }

            $payload[$pkey] = $value;

            if (!empty($field->is_sensitive)) {
                $sensitive_keys[] = $pkey;
            }
        }

        if (!empty($payload)) {
            UserManager::update_extended_profile($user_id, $payload, $sensitive_keys);
        }
    }

    /**
     * Persist a snapshot of all non-profile field values under the user
     * meta entry used for future form pre-population.
     *
     * Sensitive values are encrypted before being written to usermeta.
     *
     * @param int            $user_id User ID.
     * @param array<object>  $fields  All active field definitions.
     * @param array<string, mixed> $values field_key => plain value.
     */
    private static function store_user_snapshot(int $user_id, array $fields, array $values): void {
        $snapshot = CustomFieldRepository::get_user_data($user_id);

        foreach ($fields as $field) {
            if (!empty($field->field_profile_key)) {
                continue; // Already synced via update_extended_profile.
            }

            $key      = 'field_' . (int) $field->id;
            $value    = $values[(string) $field->field_key] ?? '';

            if (!empty($field->is_sensitive) && is_string($value) && $value !== '' && class_exists('\FreeFormCertificate\Core\Encryption')) {
                $encrypted = \FreeFormCertificate\Core\Encryption::encrypt($value);
                if ($encrypted !== null) {
                    $snapshot[$key] = $encrypted;
                    continue;
                }
            }

            $snapshot[$key] = $value;
        }

        CustomFieldRepository::save_user_data($user_id, $snapshot);
    }

    /**
     * Get active field definitions for all audiences linked to a reregistration.
     *
     * @param object $rereg Reregistration object.
     * @return array<object>
     */
    private static function get_fields_for_reregistration(object $rereg): array {
        $audience_ids = ReregistrationRepository::get_audience_ids((int) $rereg->id);
        $all          = array();
        $seen         = array();

        foreach ($audience_ids as $aud_id) {
            $fields = CustomFieldRepository::get_by_audience_with_parents((int) $aud_id, true);
            foreach ($fields as $field) {
                $id = (int) $field->id;
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $all[]     = $field;
                }
            }
        }

        return $all;
    }

    /**
     * Robust "is this field empty" check that mirrors the repository
     * implementation.
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
}
