<?php
/**
 * Csv
 *
 * Unified entry point for all CSV reading and writing in the plugin.
 *
 * Before this class, eight exporters and two importers each handled
 * fputcsv/fgetcsv directly. The plugin standardised on `;` as the
 * field delimiter in 6.3.9 (matching the BR/EU spreadsheet default),
 * but the IO contract — BOM placement, quoting, delimiter detection —
 * was still implemented per-call-site. This class consolidates that
 * contract behind two factory methods so every CSV the plugin emits
 * or accepts behaves identically.
 *
 * @package FreeFormCertificate\Core
 * @since 6.4.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Csv.
 *
 * Static facade. Use {@see self::writer()} / {@see self::reader()} /
 * {@see self::reader_from_string()} to obtain the worker objects.
 */
final class Csv {

	/**
	 * Default field delimiter — semicolon.
	 *
	 * Matches the BR/EU spreadsheet convention where `,` is the
	 * locale decimal separator. Standardised across the plugin in
	 * 6.3.9 and codified here.
	 */
	public const DELIMITER_DEFAULT = ';';

	/**
	 * Legacy field delimiter — comma.
	 *
	 * Pre-6.3.9 default. Still recognised on import for backward
	 * compatibility (auto-detected) but no exporter emits it.
	 */
	public const DELIMITER_LEGACY = ',';

	/**
	 * UTF-8 byte order mark, written by every CsvWriter on first row
	 * so Excel recognises the file as UTF-8 instead of guessing
	 * (and producing mojibake on accented characters).
	 */
	public const BOM_UTF8 = "\xEF\xBB\xBF";

	/**
	 * Open a writer.
	 *
	 * Accepts either a file path (the writer takes ownership and
	 * fclose()'s on `close()`) or an already-open writable handle
	 * (caller retains ownership; `close()` is a no-op for the handle).
	 *
	 * @param string|resource $target    File path or open writable handle.
	 * @param string          $delimiter Field delimiter (default: `;`).
	 * @return CsvWriter
	 */
	public static function writer( $target, string $delimiter = self::DELIMITER_DEFAULT ): CsvWriter {
		return new CsvWriter( $target, $delimiter );
	}

	/**
	 * Open a reader on an existing handle.
	 *
	 * The handle MUST be seekable and positioned at byte 0 — the
	 * reader peeks the first line (skipping a BOM if present) to
	 * pick `,` vs `;`, then rewinds so `header()` / `each()` see the
	 * stream from the logical start.
	 *
	 * @param resource    $handle          Open readable handle.
	 * @param string|null $force_delimiter Skip auto-detection and use this delimiter.
	 * @return CsvReader
	 */
	public static function reader( $handle, ?string $force_delimiter = null ): CsvReader {
		return new CsvReader( $handle, $force_delimiter, false );
	}

	/**
	 * Open a reader on raw CSV bytes.
	 *
	 * Convenience wrapper for callers that already hold the entire
	 * file in memory (e.g. recruitment CSV upload arrives via
	 * `file_get_contents`). Internally writes the bytes to a
	 * `php://memory` stream so the streaming reader handles it
	 * uniformly. The reader takes ownership of the synthetic stream.
	 *
	 * @param string      $content         Raw CSV bytes (BOM tolerated).
	 * @param string|null $force_delimiter Skip auto-detection.
	 * @return CsvReader
	 */
	public static function reader_from_string( string $content, ?string $force_delimiter = null ): CsvReader {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream, not the filesystem.
		$handle = fopen( 'php://memory', 'r+' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Csv::reader_from_string: cannot open php://memory' );
		}
		fwrite( $handle, $content );
		rewind( $handle );
		return new CsvReader( $handle, $force_delimiter, true );
	}
}
