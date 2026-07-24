<?php
/**
 * BatchedCsvExport
 *
 * Timeout-safe CSV export engine: a job is started, processed in fixed-size
 * batches across many short HTTP requests (so it never depends on being able to
 * raise `max_execution_time` — impossible on many shared hosts), then the
 * completed file is served and cleaned up. This is the shared lifecycle
 * extracted from the two near-duplicate batched exporters (`Admin\CsvExporter`
 * and `PublicCsvExporter`); all domain specifics live behind an injected
 * {@see BatchedExportSourceInterface}. (Issue #772.)
 *
 * Job flow (each step is one AJAX request driven by the client):
 *  1. {@see self::handle_start()}   — authorize, scan keys/context, count,
 *                                     write header+BOM to a temp file, store the
 *                                     job in a transient, return job_id + total.
 *  2. {@see self::handle_batch()}   — fetch one keyset page, append formatted
 *                                     rows to the temp file, advance the cursor
 *                                     (repeat until empty).
 *  3. {@see self::handle_download()}— stream the finished file, unlink it, drop
 *                                     the transient.
 *
 * The temp file lives under `wp_upload_dir()/ffc-tmp` (guaranteed writable,
 * survives plugin updates, per-site on multisite), guarded by a `.htaccess`
 * deny. Anything left behind by an abandoned job is reclaimed by the daily
 * cleanup sweep on the exporter that owns the cron.
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
 * Drives the batched CSV export job lifecycle for an injected source.
 */
class BatchedCsvExport {

	/**
	 * Rows processed per batch request.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * How long (seconds) a job transient lives before auto-cleanup.
	 */
	public const JOB_TTL = 3600;

	/**
	 * Transient key prefix (`{prefix}{job_id}`).
	 *
	 * @var string
	 */
	private string $transient_prefix;

	/**
	 * Temp filename prefix (`{prefix}{job_id}.csv`).
	 *
	 * @var string
	 */
	private string $file_prefix;

	/**
	 * Constructor.
	 *
	 * @param string $transient_prefix Transient key prefix (e.g. `ffc_csv_export_`).
	 * @param string $file_prefix      Temp filename prefix (e.g. `ffc-export-`).
	 */
	public function __construct( string $transient_prefix, string $file_prefix ) {
		$this->transient_prefix = $transient_prefix;
		$this->file_prefix      = $file_prefix;
	}

	/**
	 * Start a new export job: scan context, count, write the header to a temp
	 * file, persist the job, and return `job_id` + `total` (plus any
	 * source-supplied extras) to the client.
	 *
	 * @param BatchedExportSourceInterface $source Domain source.
	 * @return void
	 */
	public function handle_start( BatchedExportSourceInterface $source ): void {
		$source->authorize_start();

		$filters = $source->sanitize_filters();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit may be disabled on shared hosts; batching is the real safety net.
		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$context = $source->build_context( $filters );
		$total   = $source->count( $filters );

		if ( 0 === $total ) {
			wp_send_json_error( __( 'No records available for export.', 'ffcertificate' ) );
		}

		$filename = $source->filename( $filters, $context );

		$tmp_dir  = $this->ensure_tmp_dir();
		$job_id   = wp_generate_uuid4();
		$tmp_file = $tmp_dir . '/' . $this->file_prefix . $job_id . '.csv';

		try {
			$writer = Csv::writer( $tmp_file );
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( __( 'Cannot create temp file.', 'ffcertificate' ) );
		}
		$writer->row( $source->header( $filters, $context ) );
		$writer->close();

		$job = array_merge(
			array(
				'type'      => $source->type(),
				'filters'   => $filters,
				'context'   => $context,
				'cursor'    => PHP_INT_MAX,
				'processed' => 0,
				'total'     => $total,
				'file'      => $tmp_file,
				'filename'  => $filename,
			),
			$source->job_owner_fields()
		);
		set_transient( $this->transient_prefix . $job_id, $job, self::JOB_TTL );

		wp_send_json_success(
			array_merge(
				array(
					'job_id' => $job_id,
					'total'  => $total,
				),
				$source->extra_start_response( $job_id, $job )
			)
		);
	}

	/**
	 * Process one batch: fetch a keyset page, append formatted rows to the temp
	 * file, advance the cursor. On the empty page, fire the source's completion
	 * hook and report `done`.
	 *
	 * @param BatchedExportSourceInterface $source Domain source.
	 * @return void
	 */
	public function handle_batch( BatchedExportSourceInterface $source ): void {
		$job_id = RequestInput::get_post_string( 'job_id' );
		$job    = get_transient( $this->transient_prefix . $job_id );

		if ( ! is_array( $job ) ) {
			wp_send_json_error( __( 'Export job not found or expired.', 'ffcertificate' ) );
		}

		$source->authorize_batch( $job );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; a short per-batch cap is fine and unavailability is harmless.
		@set_time_limit( 60 );

		$filters = is_array( $job['filters'] ?? null ) ? $job['filters'] : array();
		$context = is_array( $job['context'] ?? null ) ? $job['context'] : array();
		$batch   = $source->fetch_page( $filters, $context, (int) $job['cursor'], self::BATCH_SIZE );

		if ( empty( $batch ) ) {
			$source->on_complete( $job_id, $job );

			wp_send_json_success(
				array(
					'done'      => true,
					'processed' => $job['processed'],
					'total'     => $job['total'],
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming append; CsvWriter borrows this handle and does not own it.
		$fh = fopen( (string) $job['file'], 'a' );
		if ( ! $fh ) {
			wp_send_json_error( __( 'Cannot write to temp file.', 'ffcertificate' ) );
		}

		// The header + BOM are already in the file, so the append writer
		// suppresses its own BOM emission.
		$writer = Csv::writer( $fh, Csv::DELIMITER_DEFAULT, true );
		foreach ( $batch as $row ) {
			$writer->row( $source->format_row( (array) $row, $context ) );
		}
		$writer->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the handle this method opened; CsvWriter does not own borrowed handles.
		fclose( $fh );

		$last_row          = end( $batch );
		$job['cursor']     = $source->cursor_of( (array) $last_row );
		$job['processed'] += count( $batch );
		set_transient( $this->transient_prefix . $job_id, $job, self::JOB_TTL );

		wp_send_json_success(
			array(
				'done'      => false,
				'processed' => $job['processed'],
				'total'     => $job['total'],
			)
		);
	}

	/**
	 * Serve the completed file and clean up.
	 *
	 * @param BatchedExportSourceInterface $source Domain source.
	 * @return void
	 */
	public function handle_download( BatchedExportSourceInterface $source ): void {
		$job_id = RequestInput::get_get_string( 'job_id' );
		$job    = get_transient( $this->transient_prefix . $job_id );

		if ( ! is_array( $job ) ) {
			wp_die( esc_html__( 'Export job not found or expired.', 'ffcertificate' ) );
		}

		$source->authorize_download( $job );

		$file = (string) $job['file'];
		if ( ! file_exists( $file ) ) {
			wp_die( esc_html__( 'Export file not found.', 'ffcertificate' ) );
		}

		// Drop any output buffers so readfile streams cleanly.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.CodeAnalysis.EmptyStatement.DetectedWhile -- body intentionally empty; @ swallows the "no buffer" notice.
		while ( @ob_end_clean() ) {
			/* no-op */
		}

		$safe_filename = str_replace( array( "\r", "\n", '"' ), '', (string) $job['filename'] );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $file );
		delete_transient( $this->transient_prefix . $job_id );

		exit;
	}

	/**
	 * Ensure the temp dir exists and is protected from direct HTTP access.
	 *
	 * @return string Absolute temp dir path (no trailing slash).
	 */
	private function ensure_tmp_dir(): string {
		$upload_dir = wp_upload_dir();
		$tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'ffc-tmp';
		wp_mkdir_p( $tmp_dir );

		$htaccess = $tmp_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		return $tmp_dir;
	}
}
