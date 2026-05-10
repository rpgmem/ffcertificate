<?php
/**
 * CsvExportTrait
 *
 * JSON-shape helpers used by the submission and appointment CSV
 * exporters to flatten the encrypted/plaintext `data` blob into
 * dynamic columns. Pure data shaping — the bytes-to-stream layer
 * lives in {@see \FreeFormCertificate\Core\CsvWriter} since 6.4.0.
 *
 * Three responsibilities, all about turning a row's JSON column
 * into spreadsheet-friendly columns:
 *   - extract_dynamic_keys: union of all field keys across rows
 *   - decode_json_field: encrypted-first decrypt with plaintext fallback
 *   - build_dynamic_headers: snake_case → Title Case header labels
 *   - extract_dynamic_values: pluck values for one row in a fixed key order
 *
 * @package FreeFormCertificate\Core
 * @since 4.11.2
 * @since 6.4.0 output_csv() removed; CSV IO moved to {@see CsvWriter}.
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CsvExportTrait {

	/**
	 * Extract all unique keys from a JSON data column across rows.
	 *
	 * Works with both 'data' (submissions) and 'custom_data' (appointments).
	 *
	 * @param array<int, array<string, mixed>> $rows           Array of database rows.
	 * @param string                           $plain_key      Plain-text column name (e.g. 'data', 'custom_data').
	 * @param string                           $encrypted_key  Encrypted column name (e.g. 'data_encrypted', 'custom_data_encrypted').
	 * @return array<string> Unique keys from the JSON data.
	 */
	protected function extract_dynamic_keys( array $rows, string $plain_key = 'data', string $encrypted_key = 'data_encrypted' ): array {
		$all_keys = array();

		foreach ( $rows as $row ) {
			$decoded  = $this->decode_json_field( $row, $plain_key, $encrypted_key );
			$all_keys = array_merge( $all_keys, array_keys( $decoded ) );
		}

		return array_unique( $all_keys );
	}

	/**
	 * Decrypt and decode a JSON field with encrypted-first fallback.
	 *
	 * @param array<string, mixed> $row            Database row.
	 * @param string               $plain_key      Plain-text column name.
	 * @param string               $encrypted_key  Encrypted column name.
	 * @return array<string, mixed> Decoded JSON data, or empty array.
	 */
	protected function decode_json_field( array $row, string $plain_key = 'data', string $encrypted_key = 'data_encrypted' ): array {
		$json = null;

		// Try encrypted first.
		if ( ! empty( $row[ $encrypted_key ] ) ) {
			if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
				$json = Encryption::decrypt( $row[ $encrypted_key ] );
			}
		}

		// Fallback to plain text.
		if ( null === $json && ! empty( $row[ $plain_key ] ) ) {
			$json = $row[ $plain_key ];
		}

		if ( empty( $json ) ) {
			return array();
		}

		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Generate human-readable headers from snake_case/kebab-case field keys.
	 *
	 * @param array<string> $keys Field keys.
	 * @return array<string> Human-readable headers.
	 */
	protected function build_dynamic_headers( array $keys ): array {
		return array_map(
			function ( string $key ): string {
				return ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
			},
			$keys
		);
	}

	/**
	 * Extract dynamic column values from a row's JSON data.
	 *
	 * @param array<string, mixed> $row            Database row.
	 * @param array<string>        $dynamic_keys   Ordered keys to extract.
	 * @param string               $plain_key      Plain-text column name.
	 * @param string               $encrypted_key  Encrypted column name.
	 * @return array<string> Values in the same order as $dynamic_keys.
	 */
	protected function extract_dynamic_values( array $row, array $dynamic_keys, string $plain_key = 'data', string $encrypted_key = 'data_encrypted' ): array {
		$data   = $this->decode_json_field( $row, $plain_key, $encrypted_key );
		$values = array();

		foreach ( $dynamic_keys as $key ) {
			$value = $data[ $key ] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			$values[] = $value;
		}

		return $values;
	}
}
