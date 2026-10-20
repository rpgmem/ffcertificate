<?php
/**
 * BulkIssuePlanner
 *
 * Pure planning for "Issue in bulk" (#1945): reads a participants file, maps
 * its columns onto a form's fields and decides, row by row, what would be
 * issued and what would be skipped and why. It writes nothing. The confirm
 * step, which does write, is a later sprint and consumes this same plan, so
 * the preview and the issue cannot disagree about a row.
 *
 * @package FreeFormCertificate\Submissions
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

use FreeFormCertificate\Core\CsvReader;
use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\DocumentFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plans a bulk certificate issue from a CSV file and a column mapping.
 */
final class BulkIssuePlanner {

	/**
	 * Identifiers every issued certificate needs. The mapping must name a CSV
	 * column for each of them; an e-mail left blank is allowed, the row is then
	 * issued without an account and reported.
	 */
	public const REQUIRED_KEYS = array( 'name', 'cpf_rf', 'email' );

	public const SKIP_EMPTY_ROW      = 'empty_row';
	public const SKIP_MISSING_NAME   = 'missing_name';
	public const SKIP_MISSING_CPF_RF = 'missing_cpf_rf';
	public const SKIP_INVALID_CPF_RF = 'invalid_cpf_rf';
	public const SKIP_INVALID_EMAIL  = 'invalid_email';
	public const SKIP_DUPLICATE_FILE = 'duplicate_in_file';

	/**
	 * Field definitions the template and the mapping are built from.
	 *
	 * Decorative rows (a heading, an embed) carry no `name` and are not data.
	 *
	 * @param array<int, mixed> $fields Rows of `_ffc_form_fields`.
	 * @return array<int, string> Field names, in form order, without duplicates.
	 */
	public static function template_columns( array $fields ): array {
		$names = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = is_scalar( $field['name'] ?? null ) ? trim( (string) $field['name'] ) : '';

			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Parses a CSV file, detecting the delimiter the way the recruitment import
	 * does (`,` or `;`, a UTF-8 BOM is skipped).
	 *
	 * @param string $content Raw file contents.
	 * @return array{delimiter: string, header: array<int, string>, rows: array<int, array<int, string>>}
	 */
	public static function parse( string $content ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream, not the filesystem.
		$handle = fopen( 'php://temp', 'r+' );

		if ( false === $handle ) {
			return array(
				'delimiter' => ',',
				'header'    => array(),
				'rows'      => array(),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writes the request's bytes into the in-memory stream.
		fwrite( $handle, $content );
		rewind( $handle );

		$reader = new CsvReader( $handle, null, true );
		$header = $reader->header();
		$rows   = $reader->all();
		$delim  = $reader->delimiter;

		$reader->close();

		return array(
			'delimiter' => $delim,
			'header'    => $header,
			'rows'      => $rows,
		);
	}

	/**
	 * Required identifiers that the mapping leaves unmapped or maps to a column
	 * the file does not have. An empty result means the mapping can be planned.
	 *
	 * @param array<string, string> $mapping Identifier => CSV column name.
	 * @param array<int, string>    $header  CSV header row.
	 * @return array<int, string> Problem keys: `unmapped:<key>` or `unknown:<key>`.
	 */
	public static function mapping_problems( array $mapping, array $header ): array {
		$problems = array();

		foreach ( self::REQUIRED_KEYS as $key ) {
			$column = $mapping[ $key ] ?? '';

			if ( '' === $column ) {
				$problems[] = 'unmapped:' . $key;
			} elseif ( ! in_array( $column, $header, true ) ) {
				$problems[] = 'unknown:' . $key;
			}
		}

		return $problems;
	}

	/**
	 * Decides every row. Required identifiers are validated; the other mapped
	 * fields are carried through unchanged for the issue step.
	 *
	 * Result shape:
	 * - `issue`: rows that would be issued, each with `line`, `name`,
	 *   `cpf_rf` (digits only), `email` (may be empty) and `fields`.
	 * - `skipped`: `line` and `reason` (one of the SKIP_* constants).
	 * - `no_email`: lines that would be issued without an account.
	 *
	 * @param array<int, array<int, string>> $rows    Data rows, header already removed.
	 * @param array<string, string>          $mapping Field name => CSV column name.
	 * @param array<int, string>             $header  CSV header row.
	 * @return array{issue: array<int, array<string, mixed>>, skipped: array<int, array{line: int, reason: string}>, no_email: array<int, int>}
	 */
	public static function plan( array $rows, array $mapping, array $header ): array {
		$index = array_flip( $header );
		$plan  = array(
			'issue'    => array(),
			'skipped'  => array(),
			'no_email' => array(),
		);
		$seen  = array();

		foreach ( $rows as $offset => $cells ) {
			// Line 1 is the header, so the first data row is line 2.
			$line = $offset + 2;
			$read = static function ( string $key ) use ( $mapping, $index, $cells ): string {
				$column = $mapping[ $key ] ?? '';

				if ( '' === $column || ! isset( $index[ $column ] ) ) {
					return '';
				}

				return trim( (string) ( $cells[ $index[ $column ] ] ?? '' ) );
			};

			if ( self::is_empty_row( $cells ) ) {
				$plan['skipped'][] = array(
					'line'   => $line,
					'reason' => self::SKIP_EMPTY_ROW,
				);
				continue;
			}

			$name = $read( 'name' );

			if ( '' === $name ) {
				$plan['skipped'][] = array(
					'line'   => $line,
					'reason' => self::SKIP_MISSING_NAME,
				);
				continue;
			}

			$cpf_rf = DataSanitizer::normalize_cpf_rf( $read( 'cpf_rf' ) );

			if ( '' === $cpf_rf ) {
				$plan['skipped'][] = array(
					'line'   => $line,
					'reason' => self::SKIP_MISSING_CPF_RF,
				);
				continue;
			}

			if ( ! self::is_valid_identifier( $cpf_rf ) ) {
				$plan['skipped'][] = array(
					'line'   => $line,
					'reason' => self::SKIP_INVALID_CPF_RF,
				);
				continue;
			}

			$email = trim( $read( 'email' ) );

			if ( '' !== $email && false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$plan['skipped'][] = array(
					'line'   => $line,
					'reason' => self::SKIP_INVALID_EMAIL,
				);
				continue;
			}

			if ( isset( $seen[ $cpf_rf ] ) ) {
				$plan['skipped'][] = array(
					'line'   => $line,
					'reason' => self::SKIP_DUPLICATE_FILE,
				);
				continue;
			}

			$seen[ $cpf_rf ] = true;

			$fields = array();

			foreach ( array_keys( $mapping ) as $key ) {
				if ( ! in_array( $key, self::REQUIRED_KEYS, true ) ) {
					$fields[ $key ] = $read( $key );
				}
			}

			$plan['issue'][] = array(
				'line'   => $line,
				'name'   => $name,
				'cpf_rf' => $cpf_rf,
				'email'  => $email,
				'fields' => $fields,
			);

			if ( '' === $email ) {
				$plan['no_email'][] = $line;
			}
		}

		return $plan;
	}

	/**
	 * A CPF has 11 digits and an RF 7; each is checked against its own check
	 * digits, the same validators the form uses.
	 *
	 * @param string $digits Digits only.
	 * @return bool
	 */
	private static function is_valid_identifier( string $digits ): bool {
		return 'rf' === DataSanitizer::classify_cpf_rf( $digits )
			? DocumentFormatter::validate_rf( $digits )
			: DocumentFormatter::validate_cpf( $digits );
	}

	/**
	 * Whether every cell of a row is blank.
	 *
	 * @param array<int, string> $cells One parsed row.
	 * @return bool True when every cell is blank.
	 */
	private static function is_empty_row( array $cells ): bool {
		foreach ( $cells as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				return false;
			}
		}

		return true;
	}
}
