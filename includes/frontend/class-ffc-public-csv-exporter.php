<?php
/**
 * PublicCsvExporter
 *
 * Synchronous (no-JS) CSV export for the public-facing download feature:
 * `stream_form_csv()` streams the entire CSV in one request, capped at an
 * admin-configurable row limit (larger forms must use the batched JS path).
 *
 * The batched (JS) path was consolidated onto the shared
 * {@see \FreeFormCertificate\Core\BatchedCsvExport} engine in #772 and now lives
 * in {@see PublicFormsExportSource} (routed by the unified dispatcher); this
 * class keeps only the synchronous fallback. Its column layout mirrors the
 * admin export (both share {@see PublicCsvRowFormatter}) so the two sources
 * download interchangeably.
 *
 * Deliberate keep (#772 audit): `stream_form_csv()` is the one CSV output that
 * writes to `php://output` directly instead of routing through the two Core
 * adapters (`SyncCsvExport` / `BatchedCsvExport`). It stays bespoke on purpose —
 * it is the graceful-degradation half of the only *public/frontend* export: a
 * plain `<form>` POST to `admin-post.php` that works with JavaScript disabled
 * (the admin exports have no such fallback because they run in wp-admin, where
 * JS is assured). It does not become a {@see \FreeFormCertificate\Core\SyncSourceInterface}
 * because (a) it is a direct `admin_post` streaming handler, not an AJAX job, and
 * (b) over the row cap it emits an HTML 413 page (`render_sync_limit_exceeded()`),
 * which does not fit the `rows(): iterable` contract. See CLAUDE.md §3 "CSV export
 * architecture" (the audit-grep note).
 *
 * @package FreeFormCertificate\Frontend
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\CsvExportTrait;
use FreeFormCertificate\Frontend\Csv\PublicCsvRowFormatter;
use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronous exporter for public csv data (no-JS fallback).
 */
class PublicCsvExporter {

	use CsvExportTrait;

	/**
	 * Rows pulled per page while streaming the synchronous export.
	 */
	const EXPORT_BATCH_SIZE = 50;

	/**
	 * Default cap for the synchronous (no-JS) export path. Larger forms
	 * must use the AJAX batched flow, which stays well within the 30–60s
	 * execution-time budget of typical shared hosting. Admins can override
	 * via the `public_csv_sync_max_rows` setting (100–10000).
	 */
	const DEFAULT_SYNC_MAX_ROWS = 2000;

	/**
	 * Minimum user-configurable value for the sync-export cap.
	 */
	const SYNC_MAX_ROWS_MIN = 100;

	/**
	 * Maximum user-configurable value for the sync-export cap.
	 */
	const SYNC_MAX_ROWS_MAX = 10000;

	/**
	 * Repository.
	 *
	 * @var SubmissionRepository
	 */
	protected $repository;

	/**
	 * Row formatter collaborator (headers / row formatting / key scan).
	 *
	 * Lazily constructed so it survives `newInstanceWithoutConstructor()` in
	 * tests; the repository is passed per-call to `scan_dynamic_keys()`.
	 *
	 * @var PublicCsvRowFormatter|null
	 */
	private ?PublicCsvRowFormatter $row_formatter = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository = new SubmissionRepository();
	}

	/**
	 * Lazily build (and memoize) the row formatter bound to this exporter's
	 * repository.
	 */
	private function row_formatter(): PublicCsvRowFormatter {
		if ( null === $this->row_formatter ) {
			$this->row_formatter = new PublicCsvRowFormatter();
		}
		return $this->row_formatter;
	}

	/**
	 * Stream the CSV file for a single form to the browser.
	 *
	 * Sends HTTP headers, writes BOM + column headers + data rows, then exits.
	 * On empty result sets the caller is expected to short-circuit first;
	 * this method still handles the zero-row case by sending a header-only file.
	 *
	 * @param int    $form_id Form post ID.
	 * @param string $status  Submission status to filter by (default 'publish').
	 * @return void Exits after output.
	 */
	public function stream_form_csv( int $form_id, string $status = 'publish' ): void {
		$form_ids = array( $form_id );

		// Refuse synchronous export when the row count exceeds the admin
		// threshold. Large exports must use the AJAX batched flow to avoid
		// hitting the execution-time limit of shared hosting.
		$row_count  = $this->repository->countForExport( $form_ids, $status );
		$sync_limit = self::get_sync_max_rows();
		if ( $row_count > $sync_limit ) {
			$this->render_sync_limit_exceeded( $row_count, $sync_limit );
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit may be disabled.
		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$dynamic_keys         = $this->scan_dynamic_keys( $form_ids, $status );
		$include_edit_columns = $this->repository->hasEditInfo();

		$form_title_raw = get_the_title( $form_id );
		$filename       = \FreeFormCertificate\Core\FilenameHelper::sanitize_filename(
			$form_title_raw ? $form_title_raw : ( 'form-' . $form_id )
		) . '-' . gmdate( 'Y-m-d-His' ) . '.csv';

		/**
		 * Filters the filename used for public CSV export downloads.
		 *
		 * Mirrors the admin hook so integrations can register a single
		 * callback and cover both paths.
		 *
		 * @since 5.4.0
		 *
		 * @param string         $filename Default filename (sanitized, ends in .csv).
		 * @param array<int,int> $form_ids Array with the single form ID being exported.
		 * @param string         $status   Submission status filter.
		 */
		$filename = (string) apply_filters( 'ffc_export_filename', $filename, $form_ids, $status );

		// Discard any buffered output so the CSV is the only payload on the wire.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.CodeAnalysis.EmptyStatement.DetectedWhile -- body intentionally empty; @ swallows the "no buffer" notice.
		while ( @ob_end_clean() ) {
			/* no-op */
		}

		$safe_filename = str_replace( array( "\r", "\n", '"' ), '', $filename );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV download to php://output; CsvWriter receives the borrowed handle.
		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			exit;
		}

		$headers = array_merge(
			$this->get_fixed_headers( $include_edit_columns ),
			$this->build_dynamic_headers( $dynamic_keys )
		);

		/**
		 * Filters the header row of the public CSV export.
		 *
		 * Use this to add custom columns (must match extra values injected
		 * via `ffc_export_data`) or relabel existing ones.
		 *
		 * @since 5.4.0
		 *
		 * @param array<int, string> $headers              Column headers in order.
		 * @param bool               $include_edit_columns Whether edit-tracking columns are included.
		 * @param array<int, int>    $form_ids             Array with the single form ID.
		 */
		$headers = (array) apply_filters( 'ffc_export_headers', $headers, $include_edit_columns, $form_ids );

		$writer = \FreeFormCertificate\Core\Csv::writer( $output );
		$writer->row( $headers );

		// Stream rows in batches using cursor pagination.
		$cursor = PHP_INT_MAX;
		while ( true ) {
			$batch = $this->repository->getExportBatch(
				$form_ids,
				$status,
				$cursor,
				self::EXPORT_BATCH_SIZE
			);

			if ( empty( $batch ) ) {
				break;
			}

			/**
			 * Same filter as the admin CSV export so existing hooks keep working
			 * for the public download path.
			 *
			 * @since 5.1.0
			 */
			$batch = apply_filters( 'ffc_export_data', $batch, $form_ids, $status );

			foreach ( $batch as $row ) {
				$writer->row( $this->format_csv_row( $row, $dynamic_keys, $include_edit_columns ) );
			}

			$last_row = end( $batch );
			$cursor   = (int) $last_row['id'];
			unset( $batch );
		}

		$writer->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output handle this method opened.
		fclose( $output );

		/**
		 * Fires after a public CSV export has finished streaming to the
		 * browser. On the sync path no file persists on disk, so the `$file`
		 * argument is an empty string — use the `$job` payload to identify
		 * the export.
		 *
		 * @since 5.4.0
		 *
		 * @param string              $job_id    Job identifier ('' on the sync path).
		 * @param string              $file      Absolute file path, or '' on the sync path.
		 * @param int                 $processed Number of data rows written.
		 * @param array<string, mixed> $job      Export context (form_ids, status, filename, mode).
		 */
		do_action(
			'ffc_export_completed',
			'',
			'',
			(int) $row_count,
			array(
				'form_ids' => $form_ids,
				'status'   => $status,
				'filename' => $filename,
				'mode'     => 'public-sync',
			)
		);

		exit;
	}

	/**
	 * Resolve the configured sync-export row cap, clamped to the min/max.
	 */
	public static function get_sync_max_rows(): int {
		$value = \FreeFormCertificate\Settings\SettingsReader::get_int( 'public_csv_sync_max_rows', self::DEFAULT_SYNC_MAX_ROWS );

		if ( $value < self::SYNC_MAX_ROWS_MIN ) {
			$value = self::SYNC_MAX_ROWS_MIN;
		}
		if ( $value > self::SYNC_MAX_ROWS_MAX ) {
			$value = self::SYNC_MAX_ROWS_MAX;
		}
		return $value;
	}

	/**
	 * Render a 413-style error page when the sync path is refused.
	 *
	 * @param int $row_count Actual row count for the form.
	 * @param int $limit     Configured sync-export cap.
	 */
	private function render_sync_limit_exceeded( int $row_count, int $limit ): void {
		status_header( 413 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$title   = __( 'Export too large', 'ffcertificate' );
		$message = sprintf(
			/* translators: 1: actual row count, 2: configured max rows */
			__( 'This form has %1$d submissions, which exceeds the synchronous download limit of %2$d rows. Please enable JavaScript in your browser so the export can run as a batched download, or ask an administrator to raise the limit.', 'ffcertificate' ),
			$row_count,
			$limit
		);

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title></head><body>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '</body></html>';
	}

	/**
	 * Fixed CSV headers — mirrors `CsvExporter::get_fixed_headers()`.
	 *
	 * Kept in sync with the admin export so both files have identical columns.
	 *
	 * @param bool $include_edit_columns Whether to append edit-tracking columns.
	 * @return array<int, string>
	 */
	private function get_fixed_headers( bool $include_edit_columns = false ): array {
		return $this->row_formatter()->get_fixed_headers( $include_edit_columns );
	}

	/**
	 * Format one submission row as a CSV line — mirrors
	 * `CsvExporter::format_csv_row()`.
	 *
	 * Thin delegator to {@see PublicCsvRowFormatter::format_csv_row()}.
	 *
	 * @param array<string, mixed> $row Row.
	 * @param array<int, string>   $dynamic_keys Dynamic keys.
	 * @param bool                 $include_edit_columns Include edit columns.
	 * @return array<int, mixed>
	 */
	private function format_csv_row( array $row, array $dynamic_keys, bool $include_edit_columns = false ): array {
		return $this->row_formatter()->format_csv_row( $row, $dynamic_keys, $include_edit_columns );
	}

	/**
	 * Scan all matching records to discover dynamic JSON keys.
	 *
	 * Thin delegator to {@see PublicCsvRowFormatter::scan_dynamic_keys()}.
	 *
	 * @param array<int, int> $form_ids Form IDs.
	 * @param string          $status   Status.
	 * @return array<int, string>
	 */
	private function scan_dynamic_keys( array $form_ids, string $status ): array {
		return $this->row_formatter()->scan_dynamic_keys( $this->repository, $form_ids, $status );
	}
}
