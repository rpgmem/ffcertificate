<?php
/**
 * HttpCsvDownload
 *
 * Production {@see CsvDownloadInterface} adapter: streams a CSV file download to
 * the live HTTP response. This is deliberately the ONLY place in the CSV export
 * path that touches `header()` / `fopen('php://output')` / `exit`, so those
 * internals stay out of the unit-tested {@see CsvStreamer} and the concrete
 * exporters. It carries no branching logic worth unit-testing; behaviour is
 * covered end-to-end on the testes site.
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
 * HTTP response adapter for a streamed CSV download.
 */
final class HttpCsvDownload implements CsvDownloadInterface {

	/**
	 * The php://output handle, opened lazily on first {@see self::open_stream()}.
	 *
	 * @var resource|null
	 */
	private $stream = null;

	/**
	 * {@inheritDoc}
	 *
	 * @param string $filename Suggested download filename.
	 * @return void
	 */
	public function send_headers( string $filename ): void {
		$safe_filename = str_replace( array( "\r", "\n", '"' ), '', $filename );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return resource
	 */
	public function open_stream() {
		if ( ! is_resource( $this->stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV download to php://output.
			$handle = fopen( 'php://output', 'w' );
			if ( false === $handle ) {
				exit;
			}
			$this->stream = $handle;
		}
		return $this->stream;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function finish(): void {
		if ( is_resource( $this->stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output handle this adapter opened.
			fclose( $this->stream );
		}
		exit;
	}
}
