<?php
/**
 * CsvWriter
 *
 * Streaming CSV writer used by every exporter in the plugin. See
 * {@see Csv} for the public factory and the contract this class
 * implements.
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
 * CsvWriter.
 *
 * Guarantees:
 *   - UTF-8 BOM written exactly once, immediately before the first row.
 *   - `;` delimiter by default (BR/EU spreadsheet convention).
 *   - RFC 4180 quoting via fputcsv.
 *   - String cells force-converted to UTF-8 (no-op for already-valid
 *     UTF-8) — matches the legacy CsvExportTrait behaviour for the
 *     migration period; can be loosened later if mb_convert_encoding
 *     proves a hot spot.
 *
 * Lifetime: a writer either OWNS its handle (constructed from a path)
 * or BORROWS one (constructed from an existing resource). `close()`
 * fcloses the handle iff owned; calling it on a borrowed handle is a
 * no-op so the caller can keep writing other content.
 */
final class CsvWriter {

	/**
	 * Underlying file handle.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Active field delimiter.
	 *
	 * @var string
	 */
	private string $delimiter;

	/**
	 * True iff this instance opened the handle and is responsible
	 * for fclose()'ing it.
	 *
	 * @var bool
	 */
	private bool $owns_handle;

	/**
	 * Set on the first call to {@see self::row()} so the BOM is
	 * emitted exactly once.
	 *
	 * @var bool
	 */
	private bool $bom_written = false;

	/**
	 * True after {@see self::close()} runs. Subsequent writes throw.
	 *
	 * @var bool
	 */
	private bool $closed = false;

	/**
	 * Constructor.
	 *
	 * @param string|resource $target    Path to open in write mode, or an open writable handle.
	 * @param string          $delimiter Field delimiter (default: `;`).
	 * @param bool            $skip_bom  When true, suppress the BOM emission on the first row. Use when appending to a file that already has its BOM (e.g. batch-export workers picking up after the init writer).
	 *
	 * @throws \InvalidArgumentException When $target is neither a string nor a resource.
	 * @throws \RuntimeException         When opening $target as a file fails.
	 */
	public function __construct( $target, string $delimiter = Csv::DELIMITER_DEFAULT, bool $skip_bom = false ) {
		if ( is_string( $target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- writing CSV to disk is the documented contract.
			$handle = fopen( $target, 'w' );
			if ( false === $handle ) {
				throw new \RuntimeException( esc_html( "CsvWriter: cannot open '{$target}' for writing" ) );
			}
			$this->handle      = $handle;
			$this->owns_handle = true;
		} elseif ( is_resource( $target ) ) {
			$this->handle      = $target;
			$this->owns_handle = false;
		} else {
			throw new \InvalidArgumentException( 'CsvWriter: target must be a path or an open resource' );
		}
		$this->delimiter   = $delimiter;
		$this->bom_written = $skip_bom;
	}

	/**
	 * Write a single row.
	 *
	 * @param array<int|string, mixed> $row Cells. Non-string scalars pass through untouched.
	 *
	 * @throws \LogicException When called after {@see self::close()}.
	 * @return void
	 */
	public function row( array $row ): void {
		if ( $this->closed ) {
			throw new \LogicException( 'CsvWriter: cannot write after close()' );
		}

		if ( ! $this->bom_written ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming the BOM bytes; WP_Filesystem has no streaming equivalent.
			fwrite( $this->handle, Csv::BOM_UTF8 );
			$this->bom_written = true;
		}

		$row = array_map(
			static function ( $cell ) {
				return is_string( $cell )
					? self::neutralize_formula( mb_convert_encoding( $cell, 'UTF-8', 'UTF-8' ) )
					: $cell;
			},
			$row
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- canonical CSV writer; fputcsv is the documented WP-allowed exception for actual CSV output.
		fputcsv( $this->handle, $row, $this->delimiter );
	}

	/**
	 * Neutralize spreadsheet formula injection (CSV injection / DDE).
	 *
	 * Exported cells can contain attacker-supplied form-submission values.
	 * A spreadsheet (Excel / LibreOffice / Google Sheets) evaluates any cell
	 * whose first character is `=`, `+`, `-`, `@`, TAB, or CR as a formula,
	 * so a value like `=HYPERLINK(...)` or `=cmd|...` executes when a
	 * privileged operator opens the export. Prefixing such cells with a
	 * single quote forces the spreadsheet to treat the content as literal
	 * text; the leading quote is a spreadsheet display convention and does
	 * not change the value a CSV parser reads back.
	 *
	 * @param string $cell Cell value (already UTF-8 normalised).
	 * @return string Neutralised cell value.
	 */
	private static function neutralize_formula( string $cell ): string {
		if ( '' === $cell ) {
			return $cell;
		}

		if ( in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $cell;
		}

		return $cell;
	}

	/**
	 * Write many rows in one call.
	 *
	 * Accepts any iterable, so generators / lazy producers can stream
	 * straight through without materialising the full set in memory —
	 * the streaming-export use case driven by issue #144 S5.
	 *
	 * @param iterable<array<int|string, mixed>> $rows Each item is a row array.
	 * @return void
	 */
	public function rows( iterable $rows ): void {
		foreach ( $rows as $row ) {
			$this->row( $row );
		}
	}

	/**
	 * Close the underlying handle iff this writer owns it.
	 *
	 * Idempotent. Safe to call from a finally block.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( $this->closed ) {
			return;
		}
		$this->closed = true;
		if ( $this->owns_handle && is_resource( $this->handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the handle we opened in the constructor.
			fclose( $this->handle );
		}
	}
}
