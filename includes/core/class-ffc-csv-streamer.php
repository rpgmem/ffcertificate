<?php
/**
 * CsvStreamer
 *
 * The **synchronous-delivery adapter** of the unified CSV export contract (#772):
 * `Core\SyncCsvExport` streams a {@see SyncSourceInterface} through this class for
 * bounded outputs, the timeout-safe counterpart being {@see BatchedCsvExport}.
 * See CLAUDE.md §3 "CSV export architecture (one contract, two adapters)".
 *
 * Orchestrates a streamed CSV download: send headers, write the header row, then
 * write each data row from an iterable straight to the output stream, and close.
 * All I/O goes through the injected {@see CsvDownloadInterface}, so this class is
 * pure orchestration and fully unit-testable with a buffered download double —
 * no `header()`/`exit`/`php://output` here.
 *
 * The data rows are an `iterable`, so a caller can pass a generator that pages a
 * query and `yield`s one formatted row at a time; peak memory then stays bounded
 * by a single page regardless of the row count.
 *
 * @package FreeFormCertificate\Core
 * @since   6.16.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams a CSV download through an injected output boundary.
 */
final class CsvStreamer {

	/**
	 * Output boundary (production HTTP adapter, or a buffered test double).
	 *
	 * @var CsvDownloadInterface
	 */
	private CsvDownloadInterface $download;

	/**
	 * Constructor.
	 *
	 * @param CsvDownloadInterface $download Output boundary.
	 */
	public function __construct( CsvDownloadInterface $download ) {
		$this->download = $download;
	}

	/**
	 * Stream a CSV file: headers → header row → each data row → finish.
	 *
	 * @param string                             $filename   Download filename.
	 * @param array<int, string>                 $header_row Column headers.
	 * @param iterable<array<int|string, mixed>> $data_rows  Rows (a generator keeps memory bounded).
	 * @return void
	 */
	public function stream( string $filename, array $header_row, iterable $data_rows ): void {
		$this->download->send_headers( $filename );

		$writer = Csv::writer( $this->download->open_stream() );
		$writer->row( $header_row );
		foreach ( $data_rows as $row ) {
			$writer->row( $row );
		}
		$writer->close();

		$this->download->finish();
	}
}
