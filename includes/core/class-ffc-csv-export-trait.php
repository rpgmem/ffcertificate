<?php
declare(strict_types=1);

/**
 * CSV Export Trait
 *
 * Shared CSV export utilities used across CsvExporter and AppointmentCsvExporter.
 *
 * Eliminates duplicated code for:
 * - Dynamic column extraction from JSON data
 * - Dynamic header generation (snake_case → Title Case)
 * - CSV output (BOM, UTF-8, semicolon separator, HTTP headers)
 * - Encrypted JSON data decryption with fallback
 *
 * @since 4.11.2
 * @package FreeFormCertificate\Core
 */

namespace FreeFormCertificate\Core;

if (!defined('ABSPATH')) {
    exit;
}

trait CsvExportTrait {

    /**
     * Extract all unique keys from a JSON data column across rows.
     *
     * Works with both 'data' (submissions) and 'custom_data' (appointments).
     *
     * @param array  $rows           Array of database rows.
     * @param string $plain_key      Plain-text column name (e.g. 'data', 'custom_data').
     * @param string $encrypted_key  Encrypted column name (e.g. 'data_encrypted', 'custom_data_encrypted').
     * @return array<string> Unique keys from the JSON data.
     */
    protected function extract_dynamic_keys(array $rows, string $plain_key = 'data', string $encrypted_key = 'data_encrypted'): array {
        $all_keys = array();

        foreach ($rows as $row) {
            $decoded = $this->decode_json_field($row, $plain_key, $encrypted_key);
            if (is_array($decoded)) {
                $all_keys = array_merge($all_keys, array_keys($decoded));
            }
        }

        return array_unique($all_keys);
    }

    /**
     * Decrypt and decode a JSON field with encrypted-first fallback.
     *
     * @param array  $row            Database row.
     * @param string $plain_key      Plain-text column name.
     * @param string $encrypted_key  Encrypted column name.
     * @return array Decoded JSON data, or empty array.
     */
    protected function decode_json_field(array $row, string $plain_key = 'data', string $encrypted_key = 'data_encrypted'): array {
        $json = null;

        // Try encrypted first
        if (!empty($row[$encrypted_key])) {
            if (class_exists('\FreeFormCertificate\Core\Encryption')) {
                $json = Encryption::decrypt($row[$encrypted_key]);
            }
        }

        // Fallback to plain text
        if ($json === null && !empty($row[$plain_key])) {
            $json = $row[$plain_key];
        }

        if (empty($json)) {
            return array();
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Generate human-readable headers from snake_case/kebab-case field keys.
     *
     * @param array<string> $keys Field keys.
     * @return array<string> Human-readable headers.
     */
    protected function build_dynamic_headers(array $keys): array {
        return array_map(function (string $key): string {
            return ucwords(str_replace(array('_', '-'), ' ', $key));
        }, $keys);
    }

    /**
     * Extract dynamic column values from a row's JSON data.
     *
     * @param array         $row            Database row.
     * @param array<string> $dynamic_keys   Ordered keys to extract.
     * @param string        $plain_key      Plain-text column name.
     * @param string        $encrypted_key  Encrypted column name.
     * @return array<string> Values in the same order as $dynamic_keys.
     */
    protected function extract_dynamic_values(array $row, array $dynamic_keys, string $plain_key = 'data', string $encrypted_key = 'data_encrypted'): array {
        $data = $this->decode_json_field($row, $plain_key, $encrypted_key);
        $values = array();

        foreach ($dynamic_keys as $key) {
            $value = $data[$key] ?? '';
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * Output a complete CSV file to php://output.
     *
     * Handles BOM, UTF-8 encoding, HTTP headers, and row writing.
     *
     * @param string         $filename  Download filename (e.g. 'export-2024-01-01.csv').
     * @param array<string>  $headers   Column headers.
     * @param array<array>   $rows      Data rows (each is an array of values).
     * @return void Exits after output.
     */
    protected function output_csv(string $filename, array $headers, array $rows): void {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename={$filename}");
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // BOM for Excel UTF-8 recognition
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV binary output, not HTML context
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Convert headers to UTF-8
        $headers = array_map(function ($h) {
            return mb_convert_encoding($h, 'UTF-8', 'UTF-8');
        }, $headers);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file output, not HTML context
        fputcsv($output, $headers, ';');

        foreach ($rows as $row) {
            $row = array_map(function ($v) {
                return is_string($v) ? mb_convert_encoding($v, 'UTF-8', 'UTF-8') : $v;
            }, $row);

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file output, not HTML context
            fputcsv($output, $row, ';');
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream for CSV export.
        fclose($output);
        exit;
    }
}
