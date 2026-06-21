<?php
/**
 * Recruitment CSV Parser
 *
 * Pure string→rows layer extracted from {@see RecruitmentCsvImporter} (#563
 * Sprint 6, PR 6a). Holds the header contract (required / optional columns)
 * and the side-effect-free helpers that turn raw CSV bytes into normalised
 * associative rows: delimiter/BOM-aware parsing, positional→named row
 * building, the `pcd`-flag coercion, and the CPF/RF digit normaliser.
 *
 * None of these touch the database or WordPress globals, which is why they
 * live in their own stateless class — they are the safest, most isolated
 * slice of the importer and the easiest to unit-test in isolation.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\Csv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless CSV parsing helpers for recruitment imports.
 *
 * @phpstan-type ParseResult array{ok: bool, rows: list<array<string, mixed>>, errors: list<string>}
 * @phpstan-type NormalisedId array{value: string, too_long: bool}
 */
final class CsvParser {

	/**
	 * Required CSV headers (all in English).
	 */
	public const REQUIRED_HEADERS = array( 'name', 'cpf', 'rf', 'email', 'adjutancy', 'rank', 'score', 'pcd' );

	/**
	 * Optional CSV headers.
	 */
	public const OPTIONAL_HEADERS = array( 'phone', 'time_points', 'hab_emebs' );

	/**
	 * Parse raw CSV bytes into normalised rows.
	 *
	 * Delegates BOM stripping and `,`-vs-`;` delimiter auto-detection to
	 * {@see Csv::reader_from_string()}; the fgetcsv-based reader also handles
	 * quoted multi-line fields correctly. Empty rows (every cell blank after
	 * trimming) are silently skipped. Each surviving row carries a 1-based
	 * `_line` key (the header is logical row 1).
	 *
	 * @param string $content Raw CSV bytes (UTF-8, with or without BOM).
	 * @return array Parse envelope.
	 * @phpstan-return ParseResult
	 */
	public static function parse( string $content ): array {
		if ( '' === trim( $content ) ) {
			return array(
				'ok'     => false,
				'rows'   => array(),
				'errors' => array( 'recruitment_csv_empty' ),
			);
		}

		// Csv::reader_from_string() handles BOM stripping and the
		// `,`-vs-`;` auto-detection (the canonical rule lifted from the
		// previous self::detect_delimiter()). The reader's fgetcsv-based
		// parser also handles quoted multi-line fields correctly, which
		// the previous preg_split('/\r\n|\n|\r/') line-splitter did not —
		// a quoted cell containing a literal newline would have been
		// mis-parsed before.
		$reader  = Csv::reader_from_string( $content );
		$headers = array_map( 'strtolower', array_map( 'trim', $reader->header() ) );

		$missing = array_diff( self::REQUIRED_HEADERS, $headers );
		if ( ! empty( $missing ) ) {
			$reader->close();
			return array(
				'ok'     => false,
				'rows'   => array(),
				'errors' => array( 'recruitment_csv_missing_headers: ' . implode( ',', $missing ) ),
			);
		}

		// Build header → index map (keeps optional headers when present).
		$index_map = array();
		foreach ( $headers as $i => $name ) {
			$index_map[ $name ] = $i;
		}

		$rows        = array();
		$line_number = 1; // 1-based; header was logical row 1.
		$reader->each(
			static function ( array $cells ) use ( &$rows, &$line_number, $index_map ): void {
				++$line_number;

				// Skip rows that are all whitespace after parsing.
				$any_value = false;
				foreach ( $cells as $cell ) {
					if ( '' !== trim( $cell ) ) {
						$any_value = true;
						break;
					}
				}
				if ( ! $any_value ) {
					return;
				}

				$row          = self::build_row( array_values( $cells ), $index_map );
				$row['_line'] = $line_number;
				$rows[]       = $row;
			}
		);
		$reader->close();

		return array(
			'ok'     => true,
			'rows'   => $rows,
			'errors' => array(),
		);
	}

	/**
	 * Build an associative row from positional CSV cells using the header map.
	 *
	 * Missing optional columns are filled with empty strings so downstream
	 * code can treat them uniformly.
	 *
	 * @param array             $cells     Cell values (list<string>).
	 * @phpstan-param list<string> $cells
	 * @param array<string,int> $index_map Header → column index.
	 * @return array<string, string>
	 */
	public static function build_row( array $cells, array $index_map ): array {
		$row = array();
		foreach ( array_merge( self::REQUIRED_HEADERS, self::OPTIONAL_HEADERS ) as $name ) {
			if ( isset( $index_map[ $name ], $cells[ $index_map[ $name ] ] ) ) {
				$row[ $name ] = (string) $cells[ $index_map[ $name ] ];
			} else {
				$row[ $name ] = '';
			}
		}
		return $row;
	}

	/**
	 * Parse the `pcd` column into a boolean.
	 *
	 * Accepts (case-insensitive): true, 1, sim, yes → true. Anything else → false.
	 *
	 * @param mixed $value Raw cell value.
	 * @return bool
	 */
	public static function parse_pcd_flag( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$normalized = strtolower( trim( (string) $value ) );
		return in_array( $normalized, array( 'true', '1', 'sim', 'yes' ), true );
	}

	/**
	 * Normalise a CPF / RF cell value to its canonical digit-only form.
	 *
	 * Strips every non-digit character (so `123.456.789-09` becomes
	 * `12345678909`), then left-pads with `'0'` when the result is shorter
	 * than `$expected_length` (Excel/Sheets exports routinely drop
	 * leading zeros). Returns `too_long => true` when the stripped value
	 * exceeds the canonical width — callers should surface a clear error.
	 *
	 * @param string $raw             The trimmed cell value.
	 * @param int    $expected_length Canonical width (11 for CPF, 7 for RF).
	 * @return array Normalised-id envelope.
	 * @phpstan-return NormalisedId
	 */
	public static function normalise_id( string $raw, int $expected_length ): array {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( ! is_string( $digits ) ) {
			$digits = '';
		}
		if ( '' === $digits ) {
			return array(
				'value'    => '',
				'too_long' => false,
			);
		}
		if ( strlen( $digits ) > $expected_length ) {
			return array(
				'value'    => $digits,
				'too_long' => true,
			);
		}
		if ( strlen( $digits ) < $expected_length ) {
			$digits = str_pad( $digits, $expected_length, '0', STR_PAD_LEFT );
		}
		return array(
			'value'    => $digits,
			'too_long' => false,
		);
	}
}
