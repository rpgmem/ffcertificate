<?php
/**
 * CsvExporter
 *
 * Admin certificate-submissions CSV export. Thin façade: it wires the
 * submissions {@see SubmissionsExportSource} to the shared, timeout-safe
 * {@see \FreeFormCertificate\Core\BatchedCsvExport} engine and keeps the daily
 * stale-job cleanup cron. All the export logic (columns, formatting, key scan,
 * cursor query) lives in the source; the job lifecycle (temp file, transient,
 * batching, download) lives in the engine. (Split in issue #772; the AJAX flow
 * itself dates to 5.0.0.)
 *
 * Flow (unchanged, driven by JS):
 *  1. wp_ajax_ffc_csv_export_start    → engine::handle_start
 *  2. wp_ajax_ffc_csv_export_batch    → engine::handle_batch (repeat)
 *  3. wp_ajax_ffc_csv_export_download → engine::handle_download
 *
 * @package FreeFormCertificate\Admin
 * @since   5.0.0  AJAX-driven batched export.
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\BatchedCsvExport;
use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate-submissions CSV exporter (façade over the batched engine).
 */
class CsvExporter {

	/**
	 * Records per AJAX batch request. Kept as an alias of the engine's constant
	 * for backward compatibility (referenced by the recruitment importer's
	 * batch-size note).
	 */
	public const EXPORT_BATCH_SIZE = BatchedCsvExport::BATCH_SIZE;

	/**
	 * Job transient TTL (seconds). Alias of the engine's constant.
	 */
	public const JOB_TTL = BatchedCsvExport::JOB_TTL;

	/**
	 * Submissions source.
	 *
	 * @var SubmissionsExportSource
	 */
	private SubmissionsExportSource $source;

	/**
	 * Batched export engine.
	 *
	 * @var BatchedCsvExport
	 */
	private BatchedCsvExport $engine;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->source = new SubmissionsExportSource( new SubmissionRepository() );
		$this->engine = new BatchedCsvExport( 'ffc_csv_export_', 'ffc-export-' );
	}

	/**
	 * Register AJAX handlers for the three-step export flow.
	 *
	 * @return void
	 */
	public function register_ajax_hooks(): void {
		add_action( 'wp_ajax_ffc_csv_export_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_ffc_csv_export_batch', array( $this, 'ajax_batch' ) );
		add_action( 'wp_ajax_ffc_csv_export_download', array( $this, 'ajax_download' ) );
	}

	/**
	 * AJAX: start a new export job.
	 *
	 * @return void
	 */
	public function ajax_start(): void {
		$this->engine->handle_start( $this->source );
	}

	/**
	 * AJAX: process one batch.
	 *
	 * @return void
	 */
	public function ajax_batch(): void {
		$this->engine->handle_batch( $this->source );
	}

	/**
	 * AJAX: serve the completed file and clean up.
	 *
	 * @return void
	 */
	public function ajax_download(): void {
		$this->engine->handle_download( $this->source );
	}

	/**
	 * Daily cleanup: remove temp CSV files + transient option rows left behind
	 * by exports the user abandoned mid-stream (closed the browser before
	 * clicking the download link, lost network, etc.). Walks both
	 * `_transient_ffc_csv_export_*` (admin) and `_transient_ffc_public_csv_*`
	 * (front-end) prefixes; for each row whose `_transient_timeout_*` is past,
	 * unlinks the temp file referenced in the payload and deletes the option
	 * pair.
	 *
	 * Hooked from {@see Loader::define_admin_hooks()} to
	 * `ffcertificate_daily_cleanup_hook` (existing daily cron).
	 *
	 * @since 6.5.0
	 * @return int Number of stale jobs reclaimed.
	 */
	public static function cleanup_stale_export_jobs(): int {
		global $wpdb;

		$prefixes = array(
			'_transient_timeout_ffc_csv_export_',
			'_transient_timeout_ffc_public_csv_',
		);

		$now       = time();
		$reclaimed = 0;

		foreach ( $prefixes as $timeout_prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $timeout_prefix ) . '%'
				)
			);
			if ( empty( $rows ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				$expires_at = (int) $row->option_value;
				if ( $expires_at > $now ) {
					continue; // Still within TTL — leave it.
				}

				$transient_key = (string) preg_replace( '/^_transient_timeout_/', '', $row->option_name );

				// Read the payload BEFORE deleting so we can unlink the temp
				// file the abandoned job left on disk.
				$job = get_option( '_transient_' . $transient_key );
				if ( is_array( $job ) && ! empty( $job['file'] ) && file_exists( $job['file'] ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $job['file'] );
				}

				delete_transient( $transient_key );
				++$reclaimed;
			}
		}

		return $reclaimed;
	}
}
