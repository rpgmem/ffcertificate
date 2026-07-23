<?php
/**
 * CsvDownloadInterface
 *
 * The injectable output boundary for a streamed CSV download. It owns the three
 * acts that are otherwise untestable when they live inside a shared exporter:
 * sending the HTTP download headers, providing the writable byte stream, and
 * terminating the response.
 *
 * Keeping these behind an interface lets {@see \FreeFormCertificate\Core\CsvStreamer}
 * and every exporter that uses it stay pure and unit-testable — the production
 * adapter ({@see \FreeFormCertificate\Core\HttpCsvDownload}) is the single place
 * that calls `header()` / `fopen('php://output')` / `exit`, and a buffered test
 * double captures the bytes instead. (This is the seam the reverted
 * `CsvDownloadTrait` lacked — it inlined those internals into shared code and
 * then tried to stub them per-namespace, which the test harness can't do.)
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
 * Output boundary for a streamed CSV download.
 */
interface CsvDownloadInterface {

	/**
	 * Send the HTTP headers that turn the response into a CSV file download.
	 *
	 * @param string $filename Suggested download filename.
	 * @return void
	 */
	public function send_headers( string $filename ): void;

	/**
	 * Return an open, writable stream to write the CSV bytes into.
	 *
	 * Called once per download; implementations return the same handle on
	 * repeat calls.
	 *
	 * @return resource
	 */
	public function open_stream();

	/**
	 * Flush/close the stream and terminate the response.
	 *
	 * @return void
	 */
	public function finish(): void;
}
