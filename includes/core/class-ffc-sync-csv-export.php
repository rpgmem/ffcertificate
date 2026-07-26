<?php
/**
 * SyncCsvExport
 *
 * The synchronous-delivery driver for a {@see SyncSourceInterface}: authorize,
 * then stream the source's header + rows to the browser in one request via the
 * shared {@see CsvStreamer} (→ {@see HttpCsvDownload}). This is the bounded-output
 * counterpart to the timeout-safe {@see BatchedCsvExport}; both let a domain keep
 * only its own specifics behind a source contract while the lifecycle lives here.
 *
 * The streamer is injectable so a test can capture the bytes through a buffered
 * download double instead of touching `php://output`. (Issue #772.)
 *
 * @package FreeFormCertificate\Core
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drives a synchronous CSV export for an injected source.
 */
class SyncCsvExport {

	/**
	 * The streaming orchestrator (defaults to the live HTTP download).
	 *
	 * @var CsvStreamer
	 */
	private CsvStreamer $streamer;

	/**
	 * Constructor.
	 *
	 * @param CsvStreamer|null $streamer Streamer (defaults to a live HTTP CsvStreamer).
	 */
	public function __construct( ?CsvStreamer $streamer = null ) {
		$this->streamer = $streamer ?? new CsvStreamer( new HttpCsvDownload() );
	}

	/**
	 * Authorize, then stream the source to the browser. Exits after streaming
	 * (via the download adapter's `finish()`); `authorize()` terminates the
	 * request itself on denial.
	 *
	 * @param SyncSourceInterface $source Domain source.
	 * @return void
	 */
	public function handle( SyncSourceInterface $source ): void {
		$source->authorize();
		$this->streamer->stream( $source->filename(), $source->header(), $source->rows() );
	}
}
