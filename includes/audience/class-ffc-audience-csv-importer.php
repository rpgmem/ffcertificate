<?php
declare(strict_types=1);

/**
 * Audience CSV Importer
 *
 * Handles CSV import for audience members and audience groups.
 * Supports large file uploads using batch processing.
 *
 * Expected CSV format for members:
 * email,name,audience_id (or audience_name)
 *
 * Expected CSV format for audiences:
 * name,color,parent_name
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceCsvImporter {

    /**
     * Batch size for processing
     */
    private const BATCH_SIZE = 100;

    /**
     * Import members from CSV
     *
     * @param string $file_path Path to CSV file
     * @param int $audience_id Target audience ID (optional, can be in CSV)
     * @param bool $create_users Whether to create users if they don't exist
     * @return array{success: bool, imported: int, skipped: int, errors: array<string>}
     */
    public static function import_members(string $file_path, int $audience_id = 0, bool $create_users = false): array {
        $result = array(
            'success' => false,
            'imported' => 0,
            'skipped' => 0,
            'errors' => array(),
        );

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $result['errors'][] = __('File not found or not readable.', 'ffcertificate');
            return $result;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $result['errors'][] = __('Could not open file.', 'ffcertificate');
            return $result;
        }

        // Read header row
        $header = fgetcsv($handle);
        if (!$header) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            $result['errors'][] = __('Empty file or invalid CSV format.', 'ffcertificate');
            return $result;
        }

        // Normalize header
        $header = array_map('strtolower', array_map('trim', $header));
        $header = array_map(function($col) {
            return preg_replace('/[^a-z0-9_]/', '_', $col);
        }, $header);

        // Find required columns
        $email_col = array_search('email', $header, true);
        $name_col = array_search('name', $header, true);
        $audience_col = array_search('audience_id', $header, true);
        $audience_name_col = array_search('audience_name', $header, true);
        $audience_name_col = $audience_name_col !== false ? $audience_name_col : array_search('audience', $header, true);

        if ($email_col === false) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            $result['errors'][] = __('Required column "email" not found in CSV.', 'ffcertificate');
            return $result;
        }

        // If no audience_id provided and not in CSV, error
        if ($audience_id === 0 && $audience_col === false && $audience_name_col === false) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            $result['errors'][] = __('No audience specified. Provide audience_id parameter or include audience_id/audience_name column in CSV.', 'ffcertificate');
            return $result;
        }

        // Process rows
        $row_num = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $row_num++;

            // Skip empty rows
            if (empty(array_filter($data))) {
                continue;
            }

            $email = isset($data[$email_col]) ? sanitize_email($data[$email_col]) : '';

            if (empty($email) || !is_email($email)) {
                /* translators: %d: row number */
                $result['errors'][] = sprintf(__('Row %d: Invalid email address.', 'ffcertificate'), $row_num);
                $result['skipped']++;
                continue;
            }

            // Determine audience
            $target_audience_id = $audience_id;
            if ($target_audience_id === 0) {
                if ($audience_col !== false && isset($data[$audience_col])) {
                    $target_audience_id = absint($data[$audience_col]);
                } elseif ($audience_name_col !== false && isset($data[$audience_name_col])) {
                    $audience_name = sanitize_text_field($data[$audience_name_col]);
                    $target_audience_id = self::get_audience_id_by_name($audience_name);
                }
            }

            if ($target_audience_id === 0) {
                /* translators: %d: row number */
                $result['errors'][] = sprintf(__('Row %d: Could not determine audience.', 'ffcertificate'), $row_num);
                $result['skipped']++;
                continue;
            }

            // Find or create user
            $user = get_user_by('email', $email);
            if (!$user) {
                if ($create_users) {
                    $name = ($name_col !== false && isset($data[$name_col])) ? sanitize_text_field($data[$name_col]) : '';
                    $user_id = self::create_ffc_user($email, $name);
                    if (is_wp_error($user_id)) {
                        /* translators: %1$d: row number, %2$s: error message */
                        $result['errors'][] = sprintf(__('Row %1$d: Could not create user: %2$s', 'ffcertificate'), $row_num, $user_id->get_error_message());
                        $result['skipped']++;
                        continue;
                    }
                } else {
                    /* translators: %1$d: row number, %2$s: email address */
                    $result['errors'][] = sprintf(__('Row %1$d: User not found: %2$s', 'ffcertificate'), $row_num, $email);
                    $result['skipped']++;
                    continue;
                }
            } else {
                $user_id = $user->ID;
            }

            // Add member to audience
            $added = AudienceRepository::add_member($target_audience_id, $user_id);
            if ($added) {
                $result['imported']++;
            } else {
                // Already a member
                $result['skipped']++;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);
        $result['success'] = true;

        return $result;
    }

    /**
     * Import audiences from CSV
     *
     * @param string $file_path Path to CSV file
     * @return array{success: bool, imported: int, skipped: int, errors: array<string>}
     */
    public static function import_audiences(string $file_path): array {
        $result = array(
            'success' => false,
            'imported' => 0,
            'skipped' => 0,
            'errors' => array(),
        );

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $result['errors'][] = __('File not found or not readable.', 'ffcertificate');
            return $result;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $result['errors'][] = __('Could not open file.', 'ffcertificate');
            return $result;
        }

        // Read header row
        $header = fgetcsv($handle);
        if (!$header) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            $result['errors'][] = __('Empty file or invalid CSV format.', 'ffcertificate');
            return $result;
        }

        // Normalize header
        $header = array_map('strtolower', array_map('trim', $header));

        // Find required columns
        $name_col = array_search('name', $header, true);
        $color_col = array_search('color', $header, true);
        $parent_col = array_search('parent', $header, true);
        if ($parent_col === false) {
            $parent_col = array_search('parent_name', $header, true);
        }

        if ($name_col === false) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            $result['errors'][] = __('Required column "name" not found in CSV.', 'ffcertificate');
            return $result;
        }

        // First pass: create all parent audiences
        $audiences_to_create = array();
        $row_num = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $row_num++;

            if (empty(array_filter($data))) {
                continue;
            }

            $name = isset($data[$name_col]) ? sanitize_text_field($data[$name_col]) : '';
            $color = ($color_col !== false && isset($data[$color_col])) ? sanitize_hex_color($data[$color_col]) : '#3788d8';
            $parent_name = ($parent_col !== false && isset($data[$parent_col])) ? sanitize_text_field($data[$parent_col]) : '';

            if (empty($name)) {
                /* translators: %d: row number */
                $result['errors'][] = sprintf(__('Row %d: Empty name.', 'ffcertificate'), $row_num);
                $result['skipped']++;
                continue;
            }

            $audiences_to_create[] = array(
                'row' => $row_num,
                'name' => $name,
                'color' => $color ?: '#3788d8',
                'parent_name' => $parent_name,
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        // Process parents first (empty parent_name)
        foreach ($audiences_to_create as $audience_data) {
            if (!empty($audience_data['parent_name'])) {
                continue;
            }

            $existing_id = self::get_audience_id_by_name($audience_data['name']);
            if ($existing_id) {
                $result['skipped']++;
                continue;
            }

            $new_id = AudienceRepository::create(array(
                'name' => $audience_data['name'],
                'color' => $audience_data['color'],
                'parent_id' => null,
            ));

            if ($new_id) {
                $result['imported']++;
            } else {
                /* translators: %d: row number */
                $result['errors'][] = sprintf(__('Row %d: Could not create audience.', 'ffcertificate'), $audience_data['row']);
                $result['skipped']++;
            }
        }

        // Process children (with parent_name)
        foreach ($audiences_to_create as $audience_data) {
            if (empty($audience_data['parent_name'])) {
                continue;
            }

            $existing_id = self::get_audience_id_by_name($audience_data['name']);
            if ($existing_id) {
                $result['skipped']++;
                continue;
            }

            $parent_id = self::get_audience_id_by_name($audience_data['parent_name']);
            if (!$parent_id) {
                /* translators: %1$d: row number, %2$s: parent audience name */
                $result['errors'][] = sprintf(__('Row %1$d: Parent audience "%2$s" not found.', 'ffcertificate'), $audience_data['row'], $audience_data['parent_name']);
                $result['skipped']++;
                continue;
            }

            $new_id = AudienceRepository::create(array(
                'name' => $audience_data['name'],
                'color' => $audience_data['color'],
                'parent_id' => $parent_id,
            ));

            if ($new_id) {
                $result['imported']++;
            } else {
                /* translators: %d: row number */
                $result['errors'][] = sprintf(__('Row %d: Could not create audience.', 'ffcertificate'), $audience_data['row']);
                $result['skipped']++;
            }
        }

        $result['success'] = true;

        return $result;
    }

    /**
     * Get audience ID by name
     *
     * @param string $name Audience name
     * @return int Audience ID or 0 if not found
     */
    private static function get_audience_id_by_name(string $name): int {
        global $wpdb;
        $table = AudienceRepository::get_table_name();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from trusted static method.
        $id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE name = %s LIMIT 1", $name)
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $id ? (int) $id : 0;
    }

    /**
     * Create FFC user
     *
     * @param string $email User email
     * @param string $name User name
     * @return int|\WP_Error User ID or error
     */
    private static function create_ffc_user(string $email, string $name = '') {
        // Generate username from email
        $username = sanitize_user(substr($email, 0, strpos($email, '@')), true);

        // Ensure unique username
        $original_username = $username;
        $counter = 1;
        while (username_exists($username)) {
            $username = $original_username . $counter;
            $counter++;
        }

        // Generate password
        $password = wp_generate_password(12, false);

        // Create user
        $user_id = wp_insert_user(array(
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => $name ?: $username,
            'role' => 'ffc_user',
        ));

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Grant default FFC capabilities
        $user = get_user_by('id', $user_id);
        if ($user) {
            $user->add_cap('view_own_certificates', true);
            $user->add_cap('download_own_certificates', true);
            $user->add_cap('view_certificate_history', true);
        }

        // Send welcome email (respects per-context settings, default: disabled for CSV)
        if (class_exists('\FreeFormCertificate\Integrations\EmailHandler')) {
            $email_handler = new \FreeFormCertificate\Integrations\EmailHandler();
            $email_handler->send_wp_user_notification($user_id, 'csv_import');
        }

        return $user_id;
    }

    /**
     * Validate CSV file
     *
     * @param string $file_path Path to CSV file
     * @param string $type Import type ('members' or 'audiences')
     * @return array{valid: bool, rows: int, errors: array<string>}
     */
    public static function validate_csv(string $file_path, string $type = 'members'): array {
        $result = array(
            'valid' => false,
            'rows' => 0,
            'errors' => array(),
        );

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $result['errors'][] = __('File not found or not readable.', 'ffcertificate');
            return $result;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $result['errors'][] = __('Could not open file.', 'ffcertificate');
            return $result;
        }

        // Read header
        $header = fgetcsv($handle);
        if (!$header) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            $result['errors'][] = __('Empty file or invalid CSV format.', 'ffcertificate');
            return $result;
        }

        $header = array_map('strtolower', array_map('trim', $header));

        // Check required columns
        if ($type === 'members') {
            if (!in_array('email', $header, true)) {
                $result['errors'][] = __('Required column "email" not found.', 'ffcertificate');
            }
        } else {
            if (!in_array('name', $header, true)) {
                $result['errors'][] = __('Required column "name" not found.', 'ffcertificate');
            }
        }

        // Count rows
        while (($data = fgetcsv($handle)) !== false) {
            if (!empty(array_filter($data))) {
                $result['rows']++;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        $result['valid'] = empty($result['errors']);

        return $result;
    }

    /**
     * Generate sample CSV content
     *
     * @param string $type Type of CSV ('members' or 'audiences')
     * @return string CSV content
     */
    public static function get_sample_csv(string $type = 'members'): string {
        if ($type === 'audiences') {
            return "name,color,parent\n" .
                   "Group A,#3788d8,\n" .
                   "Group B,#28a745,\n" .
                   "Subgroup A1,#dc3545,Group A\n" .
                   "Subgroup A2,#ffc107,Group A\n" .
                   "Subgroup B1,#17a2b8,Group B\n";
        }

        // Default: members
        return "email,name,audience_name\n" .
               "john@example.com,John Doe,Group A\n" .
               "jane@example.com,Jane Smith,Subgroup A1\n" .
               "bob@example.com,Bob Johnson,Group B\n";
    }
}
