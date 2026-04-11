<?php
declare(strict_types=1);

/**
 * Reregistration CSV Exporter
 *
 * Handles CSV export of reregistration submissions.
 *
 * @since 4.12.13  Extracted from ReregistrationAdmin
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

if (!defined('ABSPATH')) {
    exit;
}

class ReregistrationCsvExporter {

    /**
     * Handle CSV export action.
     *
     * Verifies nonce, fetches submission data, and streams a CSV file.
     *
     * @return void
     */
    public static function handle_export(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['action']) || $_GET['action'] !== 'export_csv' || !isset($_GET['id'])) {
            return;
        }

        $id = absint($_GET['id']);
        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'export_reregistration_' . $id)) {
            return;
        }

        $rereg = ReregistrationRepository::get_by_id($id);
        if (!$rereg) {
            return;
        }

        $submissions = ReregistrationSubmissionRepository::get_for_export($id);
        $fields      = self::get_custom_fields_for_reregistration($rereg);

        // Build CSV
        $filename = 'reregistration-' . sanitize_file_name($rereg->title) . '-' . gmdate('Y-m-d') . '.csv';

        // Headers
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $output = fopen('php://output', 'w');
        // BOM for Excel UTF-8
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($output, "\xEF\xBB\xBF");

        // Header row — fixed metadata + all dynamic fields in repository order.
        $headers = array(
            __('User ID', 'ffcertificate'),
            __('Name', 'ffcertificate'),
            __('Email', 'ffcertificate'),
            __('Status', 'ffcertificate'),
            __('Submitted At', 'ffcertificate'),
            __('Reviewed At', 'ffcertificate'),
        );

        foreach ($fields as $f) {
            $headers[] = $f->field_label;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
        fputcsv($output, $headers);

        // Data rows
        foreach ($submissions as $sub) {
            $sub_data = $sub->data ? json_decode($sub->data, true) : array();
            $values   = is_array($sub_data['fields'] ?? null) ? $sub_data['fields'] : array();

            // Decrypt sensitive fields in-place.
            $values = self::decrypt_sensitive($fields, $values);

            $row = array(
                $sub->user_id,
                $sub->user_name ?? '',
                $sub->user_email ?? '',
                $sub->status,
                $sub->submitted_at ?? '',
                $sub->reviewed_at ?? '',
            );

            foreach ($fields as $f) {
                $row[] = self::stringify_value($f, $values[(string) $f->field_key] ?? '');
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
            fputcsv($output, $row);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($output);
        exit;
    }

    /**
     * Get custom fields for all audiences linked to a reregistration.
     *
     * @param object $rereg Reregistration object.
     * @return array<object>
     */
    private static function get_custom_fields_for_reregistration(object $rereg): array {
        $audience_ids = ReregistrationRepository::get_audience_ids((int) $rereg->id);
        $all_fields = array();
        $seen = array();

        foreach ($audience_ids as $aud_id) {
            $fields = CustomFieldRepository::get_by_audience_with_parents((int) $aud_id, true);
            foreach ($fields as $field) {
                if (!isset($seen[(int) $field->id])) {
                    $seen[(int) $field->id] = true;
                    $all_fields[] = $field;
                }
            }
        }

        return $all_fields;
    }

    /**
     * Decrypt sensitive values in place.
     *
     * @param array<int, object>   $fields Field definitions.
     * @param array<string, mixed> $values field_key => value map.
     * @return array<string, mixed> Decrypted map.
     */
    private static function decrypt_sensitive(array $fields, array $values): array {
        if (!class_exists('\FreeFormCertificate\Core\Encryption')) {
            return $values;
        }

        foreach ($fields as $field) {
            if (empty($field->is_sensitive)) {
                continue;
            }
            $key = (string) $field->field_key;
            if (!isset($values[$key]) || $values[$key] === '' || !is_string($values[$key])) {
                continue;
            }
            $plain = \FreeFormCertificate\Core\Encryption::decrypt($values[$key]);
            if ($plain !== null) {
                $values[$key] = $plain;
            }
        }

        return $values;
    }

    /**
     * Convert a stored field value into a CSV-friendly string.
     *
     * @param object $field Field definition.
     * @param mixed  $value Plain value (may already be decrypted).
     * @return string
     */
    private static function stringify_value(object $field, $value): string {
        switch ((string) $field->field_type) {
            case 'checkbox':
                return ($value === '1' || $value === 1 || $value === true)
                    ? __('Yes', 'ffcertificate')
                    : __('No', 'ffcertificate');

            case 'dependent_select':
                $dep = is_string($value) ? json_decode($value, true) : $value;
                if (is_array($dep)) {
                    $parent = (string) ($dep['parent'] ?? '');
                    $child  = (string) ($dep['child']  ?? '');
                    return trim($parent . ' / ' . $child, ' /');
                }
                return '';

            case 'working_hours':
                // Keep raw JSON — users can post-process in Excel if needed.
                if (is_string($value)) {
                    return $value === '[]' ? '' : $value;
                }
                return is_array($value) ? (string) wp_json_encode($value) : '';

            default:
                if (is_array($value)) {
                    return implode(', ', array_map('strval', $value));
                }
                return is_scalar($value) ? (string) $value : '';
        }
    }
}
