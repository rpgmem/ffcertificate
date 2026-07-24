<?php
/**
 * CsvExporter
 *
 * Admin certificate-submissions CSV export wiring. Since the batched-export
 * consolidation (#772) it no longer owns any endpoints or job logic: it just
 * registers the submissions {@see SubmissionsExportSource} with the shared
 * {@see \FreeFormCertificate\Core\SourceRegistry} (so the single
 * {@see \FreeFormCertificate\Core\BatchedExportDispatcher} can route
 * `type=submissions` requests to it) and keeps the daily stale-job cleanup cron.
 * All export logic lives in the source; the job lifecycle lives in the engine.
 *
 * @package FreeFormCertificate\Admin
 * @since   5.0.0  AJAX-driven batched export.
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\BatchedCsvExport;
use FreeFormCertificate\Core\SourceRegistry;
use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate-submissions CSV export registration + cleanup.
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
	 * Register the submissions source with the shared registry.
	 *
	 * The factory is lazy (built only for a request that dispatches
	 * `type=submissions`), so registering never touches `$wpdb`.
	 *
	 * @return void
	 */
	public function register_source(): void {
		SourceRegistry::register(
			SubmissionsExportSource::TYPE,
			static function (): SubmissionsExportSource {
				return new SubmissionsExportSource( new SubmissionRepository() );
			}
		);
	}

	/**
	 * Daily cleanup: remove temp CSV files + transient option rows left behind
	 * by exports the user abandoned mid-stream (closed the browser before
	 * clicking the download link, lost network, etc.). Walks the unified
	 * `_transient_ffc_export_*` namespace plus the two legacy prefixes
	 * (`ffc_csv_export_` admin / `ffc_public_csv_` front-end) so any job still
	 * in flight across the #772 rename is reclaimed too; for each row whose
	 * `_transient_timeout_*` is past, unlinks the temp file referenced in the
	 * payload and deletes the option pair.
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
			'_transient_timeout_ffc_export_',
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
