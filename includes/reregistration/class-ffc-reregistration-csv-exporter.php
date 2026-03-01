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
        $custom_fields = self::get_custom_fields_for_reregistration($rereg);

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

        // Header row
        $headers = array(
            __('User ID', 'ffcertificate'),
            __('Name', 'ffcertificate'),
            __('Email', 'ffcertificate'),
            __('Status', 'ffcertificate'),
            __('Submitted At', 'ffcertificate'),
            __('Reviewed At', 'ffcertificate'),
            __('Phone', 'ffcertificate'),
            __('Department', 'ffcertificate'),
            __('Organization', 'ffcertificate'),
        );

        // Add custom field headers
        foreach ($custom_fields as $cf) {
            $headers[] = $cf->field_label;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
        fputcsv($output, $headers);

        // Data rows
        foreach ($submissions as $sub) {
            $sub_data = $sub->data ? json_decode($sub->data, true) : array();
            $standard = $sub_data['standard_fields'] ?? array();
            $custom = $sub_data['custom_fields'] ?? array();

            $row = array(
                $sub->user_id,
                $sub->user_name ?? '',
                $sub->user_email ?? '',
                $sub->status,
                $sub->submitted_at ?? '',
                $sub->reviewed_at ?? '',
                $standard['phone'] ?? '',
                $standard['department'] ?? '',
                $standard['organization'] ?? '',
            );

            foreach ($custom_fields as $cf) {
                $row[] = $custom['field_' . $cf->id] ?? '';
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
}
