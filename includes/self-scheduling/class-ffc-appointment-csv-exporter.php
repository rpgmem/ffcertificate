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

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

class AppointmentCsvExporter {

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
     * @return array
     */
    private function get_fixed_headers(): array {
        return array(
            __('ID', 'wp-ffcertificate'),
            __('Calendar', 'wp-ffcertificate'),
            __('Calendar ID', 'wp-ffcertificate'),
            __('User ID', 'wp-ffcertificate'),
            __('Name', 'wp-ffcertificate'),
            __('Email', 'wp-ffcertificate'),
            __('Phone', 'wp-ffcertificate'),
            __('Appointment Date', 'wp-ffcertificate'),
            __('Start Time', 'wp-ffcertificate'),
            __('End Time', 'wp-ffcertificate'),
            __('Status', 'wp-ffcertificate'),
            __('User Notes', 'wp-ffcertificate'),
            __('Admin Notes', 'wp-ffcertificate'),
            __('Consent Given', 'wp-ffcertificate'),
            __('Consent Date', 'wp-ffcertificate'),
            __('Consent IP', 'wp-ffcertificate'),
            __('Consent Text', 'wp-ffcertificate'),
            __('Created At', 'wp-ffcertificate'),
            __('Updated At', 'wp-ffcertificate'),
            __('Approved At', 'wp-ffcertificate'),
            __('Approved By', 'wp-ffcertificate'),
            __('Cancelled At', 'wp-ffcertificate'),
            __('Cancelled By', 'wp-ffcertificate'),
            __('Cancellation Reason', 'wp-ffcertificate'),
            __('Reminder Sent At', 'wp-ffcertificate'),
            __('User IP', 'wp-ffcertificate'),
            __('User Agent', 'wp-ffcertificate'),
        );
    }

    /**
     * Get all unique custom data keys from appointments
     *
     * @param array $rows
     * @return array
     */
    private function get_dynamic_columns(array $rows): array {
        $all_keys = array();

        foreach ($rows as $r) {
            $d = $this->get_custom_data($r);
            if (is_array($d)) {
                $all_keys = array_merge($all_keys, array_keys($d));
            }
        }

        return array_unique($all_keys);
    }

    /**
     * Get custom data from a row, handling encryption
     *
     * @param array $row
     * @return array
     */
    private function get_custom_data(array $row): array {
        $json = null;

        // Try encrypted first
        if (!empty($row['custom_data_encrypted'])) {
            try {
                if (class_exists('\FreeFormCertificate\Core\Encryption')) {
                    $json = \FreeFormCertificate\Core\Encryption::decrypt($row['custom_data_encrypted']);
                }
            } catch (\Exception $e) {
                $json = null;
            }
        }

        // Fallback to plain text
        if ($json === null && !empty($row['custom_data'])) {
            $json = $row['custom_data'];
        }

        if (empty($json)) {
            return array();
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Generate translatable headers for dynamic columns
     *
     * @param array $dynamic_keys
     * @return array
     */
    private function get_dynamic_headers(array $dynamic_keys): array {
        $dynamic_headers = array();

        foreach ($dynamic_keys as $key) {
            $label = ucwords(str_replace(array('_', '-'), ' ', $key));
            $dynamic_headers[] = $label;
        }

        return $dynamic_headers;
    }

    /**
     * Format a single CSV row
     *
     * @param array $row
     * @param array $dynamic_keys
     * @return array
     */
    private function format_csv_row(array $row, array $dynamic_keys): array {
        // Get calendar title
        $calendar_title = '';
        if (!empty($row['calendar_id'])) {
            $calendar = $this->calendar_repository->findById((int)$row['calendar_id']);
            $calendar_title = $calendar['title'] ?? __('(Deleted)', 'wp-ffcertificate');
        }

        // Decrypt email
        $email = '';
        if (!empty($row['email_encrypted'])) {
            try {
                $email = \FreeFormCertificate\Core\Encryption::decrypt($row['email_encrypted']);
            } catch (\Exception $e) {
                $email = '';
            }
        } elseif (!empty($row['email'])) {
            $email = $row['email'];
        }

        // Decrypt phone
        $phone = '';
        if (!empty($row['phone_encrypted'])) {
            try {
                $phone = \FreeFormCertificate\Core\Encryption::decrypt($row['phone_encrypted']);
            } catch (\Exception $e) {
                $phone = '';
            }
        } elseif (!empty($row['phone'])) {
            $phone = $row['phone'];
        }

        // Decrypt user IP
        $user_ip = '';
        if (!empty($row['user_ip_encrypted'])) {
            try {
                $user_ip = \FreeFormCertificate\Core\Encryption::decrypt($row['user_ip_encrypted']);
            } catch (\Exception $e) {
                $user_ip = '';
            }
        } elseif (!empty($row['user_ip'])) {
            $user_ip = $row['user_ip'];
        }

        // Consent given (Yes/No)
        $consent_given = '';
        if (isset($row['consent_given'])) {
            $consent_given = $row['consent_given'] ? __('Yes', 'wp-ffcertificate') : __('No', 'wp-ffcertificate');
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
            'pending' => __('Pending', 'wp-ffcertificate'),
            'confirmed' => __('Confirmed', 'wp-ffcertificate'),
            'cancelled' => __('Cancelled', 'wp-ffcertificate'),
            'completed' => __('Completed', 'wp-ffcertificate'),
            'no_show' => __('No Show', 'wp-ffcertificate'),
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
     * @param int|array|null $calendar_ids Calendar ID(s) to filter, null for all
     * @param array $statuses Status filter
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
            wp_die(esc_html__('No appointments available for export.', 'wp-ffcertificate'));
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
            $csv_row = array_map(function($value) {
                if (is_string($value)) {
                    return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }
                return $value;
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
     * @param int|array|null $calendar_ids
     * @param array $statuses
     * @param string|null $start_date
     * @param string|null $end_date
     * @return array
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

        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY appointment_date DESC, start_time DESC";

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
                wp_die(esc_html__('Security check failed.', 'wp-ffcertificate'));
            }

            if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
                wp_die(esc_html__('You do not have permission to export appointments.', 'wp-ffcertificate'));
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
            wp_die(esc_html__('Error generating CSV: ', 'wp-ffcertificate') . esc_html($e->getMessage()));
        }
    }
}
