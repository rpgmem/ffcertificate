<?php
/**
 * CsvReader
 *
 * Streaming CSV reader with delimiter auto-detection. See {@see Csv}
 * for the public factory and the IO contract this class implements.
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
 * CsvReader.
 *
 * Accepts a seekable resource, peeks the first line, and picks `,` or
 * `;` as the field delimiter based on unquoted occurrence count. Ties
 * resolve to `,` for backward compatibility with files that worked
 * pre-detection (the canonical rule lifted from the recruitment
 * importer's `detect_delimiter()`).
 *
 * Body rows are streamed — `each()` calls fgetcsv per row so that 100k+
 * row imports stay memory-bounded. `all()` is a small-file convenience
 * built on top of `each()`.
 */
final class CsvReader {

	/**
	 * Underlying file handle. Must be seekable.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Detected (or forced) field delimiter for this stream.
	 *
	 * @var string
	 */
	public string $delimiter;

	/**
	 * True iff this instance opened the handle (e.g. via
	 * {@see Csv::reader_from_string()}) and must fclose() it.
	 *
	 * @var bool
	 */
	private bool $owns_handle;

	/**
	 * Cached header row after the first call to {@see self::header()}.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $header = null;

	/**
	 * Constructor.
	 *
	 * Skips a UTF-8 BOM at byte 0 if present, then auto-detects the
	 * delimiter (unless one was forced). After construction the
	 * handle is positioned at the start of the header line so the
	 * first {@see self::header()} call returns it intact.
	 *
	 * @param resource    $handle          Open readable, seekable handle.
	 * @param string|null $force_delimiter Skip detection and use this delimiter.
	 * @param bool        $owns_handle     Whether the reader should fclose on close().
	 *
	 * @throws \InvalidArgumentException When $handle is not a resource.
	 */
	public function __construct( $handle, ?string $force_delimiter = null, bool $owns_handle = false ) {
		if ( ! is_resource( $handle ) ) {
			throw new \InvalidArgumentException( 'CsvReader: handle must be a resource' );
		}
		$this->handle      = $handle;
		$this->owns_handle = $owns_handle;
		$this->skip_bom_at_start();
		$this->delimiter = $force_delimiter ?? $this->detect_delimiter_from_first_line();
	}

	/**
	 * Read the header row. Idempotent — subsequent calls return the
	 * cached value without re-reading the stream.
	 *
	 * @return array<int, string>
	 */
	public function header(): array {
		if ( null !== $this->header ) {
			return $this->header;
		}
		$row          = fgetcsv( $this->handle, 0, $this->delimiter );
		$this->header = is_array( $row ) ? self::coerce_cells( $row ) : array();
		return $this->header;
	}

	/**
	 * Stream every body row (everything after the header) through
	 * the callback. Memory-bounded.
	 *
	 * @param callable(array<int, string>): void $cb Invoked once per row.
	 * @return void
	 */
	public function each( callable $cb ): void {
		if ( null === $this->header ) {
			$this->header();
		}
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- canonical fgetcsv() streaming pattern.
		while ( ( $row = fgetcsv( $this->handle, 0, $this->delimiter ) ) !== false ) {
			$cb( self::coerce_cells( $row ) );
		}
	}

	/**
	 * Read all body rows into an array. Use only when the file is
	 * known to be small (config templates, sub-100-row imports).
	 *
	 * @return list<array<int, string>>
	 */
	public function all(): array {
		$rows = array();
		$this->each(
			static function ( array $row ) use ( &$rows ): void {
				$rows[] = $row;
			}
		);
		return $rows;
	}

	/**
	 * Close the handle iff this reader owns it. Idempotent.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( $this->owns_handle && is_resource( $this->handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the handle we created in Csv::reader_from_string().
			fclose( $this->handle );
			$this->owns_handle = false;
		}
	}

	/**
	 * Coerce fgetcsv output to a list of strings. fgetcsv returns
	 * `null` for missing trailing cells on some PHP versions;
	 * downstream consumers prefer empty strings.
	 *
	 * @param array<int, mixed> $row Raw row from fgetcsv.
	 * @return array<int, string>
	 */
	private static function coerce_cells( array $row ): array {
		return array_map(
			static function ( $cell ): string {
				return is_string( $cell ) ? $cell : '';
			},
			$row
		);
	}

	/**
	 * Consume a UTF-8 BOM at the current handle position if present.
	 * If no BOM, rewinds the three peeked bytes so the next read
	 * sees the actual first byte.
	 *
	 * @return void
	 */
	private function skip_bom_at_start(): void {
		$pos    = ftell( $this->handle );
		$prefix = fread( $this->handle, 3 );
		if ( false === $prefix || 0 !== strncmp( $prefix, Csv::BOM_UTF8, 3 ) ) {
			// Not a BOM — restore position so the body keeps byte 0.
			if ( false !== $pos ) {
				fseek( $this->handle, $pos );
			} else {
				rewind( $this->handle );
			}
		}
		// Else: BOM consumed; leave the handle past it.
	}

	/**
	 * Pick `,` vs `;` by counting unquoted occurrences in the first
	 * line. The canonical rule (semicolon wins iff strictly greater)
	 * was lifted unchanged from the recruitment / audience importers.
	 *
	 * Restores the handle to its pre-peek position so the header
	 * read sees the line intact.
	 *
	 * @return string `,` or `;`.
	 */
	private function detect_delimiter_from_first_line(): string {
		$pos        = ftell( $this->handle );
		$first_line = fgets( $this->handle );
		if ( false !== $pos ) {
			fseek( $this->handle, $pos );
		} else {
			rewind( $this->handle );
		}

		if ( ! is_string( $first_line ) || '' === $first_line ) {
			return Csv::DELIMITER_LEGACY;
		}

		$comma     = 0;
		$semicolon = 0;
		$in_quotes = false;
		$length    = strlen( $first_line );
		for ( $i = 0; $i < $length; $i++ ) {
			$ch = $first_line[ $i ];
			if ( '"' === $ch ) {
				$in_quotes = ! $in_quotes;
				continue;
			}
			if ( $in_quotes ) {
				continue;
			}
			if ( ',' === $ch ) {
				++$comma;
			} elseif ( ';' === $ch ) {
				++$semicolon;
			}
		}
		return $semicolon > $comma ? Csv::DELIMITER_DEFAULT : Csv::DELIMITER_LEGACY;
	}
}
