<?php
declare(strict_types=1);

/**
 * Appointment CSV Exporter
 *
 * Handles CSV export functionality for calendar appointments.
 * Exports appointment data with dynamic columns and filtering.
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Repositories\AppointmentRepository;
use FreeFormCertificate\Repositories\CalendarRepository;

if (!defined('ABSPATH')) exit;


class AppointmentCsvExporter {

    use \FreeFormCertificate\Core\CsvExportTrait;

    /**
     * @var AppointmentRepository
     */
    protected $appointment_repository;

    /**
     * @var CalendarRepository
     */
    protected $calendar_repository;

    /**
     * Constructor
     */
    public function __construct() {
        $this->appointment_repository = new AppointmentRepository();
        $this->calendar_repository = new CalendarRepository();

        // Register export action
        add_action('admin_post_ffc_export_appointments_csv', array($this, 'handle_export_request'));
    }

    /**
     * Get fixed column headers
     *
     * @return array<int, string>
     */
    private function get_fixed_headers(): array {
        return array(
            __('ID', 'ffcertificate'),
            __('Calendar', 'ffcertificate'),
            __('Calendar ID', 'ffcertificate'),
            __('User ID', 'ffcertificate'),
            __('Name', 'ffcertificate'),
            __('Email', 'ffcertificate'),
            __('Phone', 'ffcertificate'),
            __('Appointment Date', 'ffcertificate'),
            __('Start Time', 'ffcertificate'),
            __('End Time', 'ffcertificate'),
            __('Status', 'ffcertificate'),
            __('User Notes', 'ffcertificate'),
            __('Admin Notes', 'ffcertificate'),
            __('Consent Given', 'ffcertificate'),
            __('Consent Date', 'ffcertificate'),
            __('Consent IP', 'ffcertificate'),
            __('Consent Text', 'ffcertificate'),
            __('Created At', 'ffcertificate'),
            __('Updated At', 'ffcertificate'),
            __('Approved At', 'ffcertificate'),
            __('Approved By', 'ffcertificate'),
            __('Cancelled At', 'ffcertificate'),
            __('Cancelled By', 'ffcertificate'),
            __('Cancellation Reason', 'ffcertificate'),
            __('Reminder Sent At', 'ffcertificate'),
            __('User IP', 'ffcertificate'),
            __('User Agent', 'ffcertificate'),
        );
    }

    /**
     * Get all unique custom data keys from appointments.
     * Delegates to CsvExportTrait::extract_dynamic_keys().
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function get_dynamic_columns(array $rows): array {
        return $this->extract_dynamic_keys($rows, 'custom_data', 'custom_data_encrypted');
    }

    /**
     * Get custom data from a row, handling encryption.
     * Delegates to CsvExportTrait::decode_json_field().
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function get_custom_data(array $row): array {
        return $this->decode_json_field($row, 'custom_data', 'custom_data_encrypted');
    }

    /**
     * Generate translatable headers for dynamic columns.
     * Delegates to CsvExportTrait::build_dynamic_headers().
     *
     * @param array<int, string> $dynamic_keys
     * @return array<string, string>
     */
    private function get_dynamic_headers(array $dynamic_keys): array {
        return $this->build_dynamic_headers($dynamic_keys);
    }

    /**
     * Format a single CSV row
     *
     * @param array<string, mixed> $row
     * @param array<int, string> $dynamic_keys
     * @return array<int, string>
     */
    private function format_csv_row(array $row, array $dynamic_keys): array {
        // Get calendar title
        $calendar_title = '';
        if (!empty($row['calendar_id'])) {
            $calendar = $this->calendar_repository->findById((int)$row['calendar_id']);
            $calendar_title = $calendar['title'] ?? __('(Deleted)', 'ffcertificate');
        }

        // Decrypt sensitive fields (encrypted → plain fallback)
        $email   = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'email' );
        $phone   = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'phone' );
        $user_ip = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'user_ip' );

        // Consent given (Yes/No)
        $consent_given = '';
        if (isset($row['consent_given'])) {
            $consent_given = $row['consent_given'] ? __('Yes', 'ffcertificate') : __('No', 'ffcertificate');
        }

        // Get usernames for approval/cancellation
        $approved_by = '';
        if (!empty($row['approved_by'])) {
            $user = get_userdata((int)$row['approved_by']);
            $approved_by = $user ? $user->display_name : 'ID: ' . $row['approved_by'];
        }

        $cancelled_by = '';
        if (!empty($row['cancelled_by'])) {
            $user = get_userdata((int)$row['cancelled_by']);
            $cancelled_by = $user ? $user->display_name : 'ID: ' . $row['cancelled_by'];
        }

        // Status label
        $status_labels = array(
            'pending' => __('Pending', 'ffcertificate'),
            'confirmed' => __('Confirmed', 'ffcertificate'),
            'cancelled' => __('Cancelled', 'ffcertificate'),
            'completed' => __('Completed', 'ffcertificate'),
            'no_show' => __('No Show', 'ffcertificate'),
        );
        $status = $status_labels[$row['status']] ?? $row['status'];

        // Fixed Columns
        $line = array(
            $row['id'],
            $calendar_title,
            $row['calendar_id'] ?? '',
            $row['user_id'] ?? '',
            $row['name'] ?? '',
            $email,
            $phone,
            $row['appointment_date'] ?? '',
            $row['start_time'] ?? '',
            $row['end_time'] ?? '',
            $status,
            $row['user_notes'] ?? '',
            $row['admin_notes'] ?? '',
            $consent_given,
            $row['consent_date'] ?? '',
            $row['consent_ip'] ?? '',
            $row['consent_text'] ?? '',
            $row['created_at'] ?? '',
            $row['updated_at'] ?? '',
            $row['approved_at'] ?? '',
            $approved_by,
            $row['cancelled_at'] ?? '',
            $cancelled_by,
            $row['cancellation_reason'] ?? '',
            $row['reminder_sent_at'] ?? '',
            $user_ip,
            $row['user_agent'] ?? '',
        );

        // Dynamic Columns (custom_data fields)
        $custom_data = $this->get_custom_data($row);

        foreach ($dynamic_keys as $key) {
            $value = $custom_data[$key] ?? '';
            // Flatten arrays/objects to string
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $line[] = $value;
        }

        return $line;
    }

    /**
     * Export appointments to CSV file
     *
     * @param array<int, int>|null $calendar_ids Calendar ID(s) to filter, null for all
     * @param array<int, string> $statuses Status filter
     * @param string|null $start_date Start date filter (Y-m-d)
     * @param string|null $end_date End date filter (Y-m-d)
     * @return void
     */
    public function export_csv($calendar_ids = null, array $statuses = [], ?string $start_date = null, ?string $end_date = null): void {
        // Normalize calendar_ids to array
        if ($calendar_ids !== null && !is_array($calendar_ids)) {
            $calendar_ids = [(int)$calendar_ids];
        }

        \FreeFormCertificate\Core\Utils::debug_log('Appointment CSV export started', array(
            'calendar_ids' => $calendar_ids,
            'statuses' => $statuses,
            'start_date' => $start_date,
            'end_date' => $end_date,
        ));

        // Get appointments based on filters
        $rows = $this->get_appointments_for_export($calendar_ids, $statuses, $start_date, $end_date);

        if (empty($rows)) {
            wp_die(esc_html__('No appointments available for export.', 'ffcertificate'));
        }

        // Generate filename
        if ($calendar_ids && count($calendar_ids) === 1) {
            $calendar = $this->calendar_repository->findById($calendar_ids[0]);
            $calendar_title = $calendar ? $calendar['title'] : 'calendar-' . $calendar_ids[0];
        } elseif ($calendar_ids && count($calendar_ids) > 1) {
            $calendar_title = count($calendar_ids) . '-calendars';
        } else {
            $calendar_title = 'all-calendars';
        }

        $filename = \FreeFormCertificate\Core\Utils::sanitize_filename($calendar_title) . '-appointments-' . gmdate('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename={$filename}");
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // BOM for Excel UTF-8 recognition
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV binary output, not HTML context
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Build headers
        $dynamic_keys = $this->get_dynamic_columns($rows);
        $headers = array_merge(
            $this->get_fixed_headers(),
            $this->get_dynamic_headers($dynamic_keys)
        );

        // Convert all headers to UTF-8
        $headers = array_map(function($header) {
            return mb_convert_encoding($header, 'UTF-8', 'UTF-8');
        }, $headers);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file output, not HTML context
        fputcsv($output, $headers, ';');

        // Write data rows
        foreach ($rows as $row) {
            $csv_row = $this->format_csv_row($row, $dynamic_keys);

            // Convert all row data to UTF-8
            $csv_row = array_map(function(string $value): string {
                return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }, $csv_row);

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file output, not HTML context
            fputcsv($output, $csv_row, ';');
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream for CSV export.
        fclose($output);
        exit;
    }

    /**
     * Get appointments for export with filters
     *
     * @param array<int, int>|null $calendar_ids
     * @param array<int, string> $statuses
     * @param string|null $start_date
     * @param string|null $end_date
     * @return array<int, array<string, mixed>>
     */
    private function get_appointments_for_export($calendar_ids, array $statuses, ?string $start_date, ?string $end_date): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

        $where_clauses = array();
        $where_values = array();

        // Calendar filter
        if ($calendar_ids !== null && !empty($calendar_ids)) {
            $placeholders = implode(',', array_fill(0, count($calendar_ids), '%d'));
            $where_clauses[] = "calendar_id IN ($placeholders)";
            $where_values = array_merge($where_values, $calendar_ids);
        }

        // Status filter
        if (!empty($statuses)) {
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $where_clauses[] = "status IN ($placeholders)";
            $where_values = array_merge($where_values, $statuses);
        }

        // Date range filter
        if ($start_date && $end_date) {
            $where_clauses[] = "appointment_date BETWEEN %s AND %s";
            $where_values[] = $start_date;
            $where_values[] = $end_date;
        } elseif ($start_date) {
            $where_clauses[] = "appointment_date >= %s";
            $where_values[] = $start_date;
        } elseif ($end_date) {
            $where_clauses[] = "appointment_date <= %s";
            $where_values[] = $end_date;
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $sql = "SELECT * FROM %i {$where_sql} ORDER BY appointment_date DESC, start_time DESC";

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, array_merge( array( $table ), $where_values ));
        } else {
            $sql = $wpdb->prepare($sql, $table);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where_sql is built from validated placeholders above; $sql is pre-prepared.
        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Handle export request from admin
     *
     * @return void
     */
    public function handle_export_request(): void {
        try {
            // Security check
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() is an existence check; value sanitized on next line.
            if (!isset($_POST['ffc_export_appointments_csv_action']) ||
                !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_export_appointments_csv_action'])), 'ffc_export_appointments_csv_nonce')) {
                wp_die(esc_html__('Security check failed.', 'ffcertificate'));
            }

            if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
                wp_die(esc_html__('You do not have permission to export appointments.', 'ffcertificate'));
            }

            // Get filters
            $calendar_ids = null;
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() applied to each element; is_array() is a type check only.
            if (!empty($_POST['calendar_ids']) && is_array($_POST['calendar_ids'])) {
                $calendar_ids = array_map('absint', wp_unslash($_POST['calendar_ids']));
            } elseif (!empty($_POST['calendar_id'])) {
                $calendar_ids = [absint( wp_unslash( $_POST['calendar_id'] ) )]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() is the sanitizer.
            }

            $statuses = array();
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() applied after unslash; is_array() is a type check only.
            if (!empty($_POST['statuses']) && is_array($_POST['statuses'])) {
                $statuses = array_map('sanitize_key', wp_unslash($_POST['statuses']));
            }

            $start_date = !empty($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : null;
            $end_date = !empty($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null;

            $this->export_csv($calendar_ids, $statuses, $start_date, $end_date);

        } catch (\Exception $e) {
            \FreeFormCertificate\Core\Utils::debug_log('Appointment CSV export exception', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_die(esc_html__('Error generating CSV: ', 'ffcertificate') . esc_html($e->getMessage()));
        }
    }
}
