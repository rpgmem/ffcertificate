<?php
/**
 * BatchedExportSourceInterface
 *
 * The per-domain descriptor a {@see BatchedCsvExport} engine drives to produce a
 * timeout-safe, AJAX-batched CSV download. Each source owns ONLY its domain
 * specifics — authorization, filters, columns, row formatting, and a keyset
 * (id-cursor) page query — while the engine owns the shared job lifecycle
 * (temp file, transient job state, chunk loop, download, cleanup).
 *
 * Split from the two near-duplicate batched exporters (`Admin\CsvExporter`,
 * `PublicCsvExporter`) so the lifecycle machinery lives in one place and each
 * exporter shrinks to a source. (See issue #772.)
 *
 * Contract notes:
 *  - `build_context()` is called ONCE at start; its result (dynamic-key set,
 *    feature flags, …) is frozen into the job and handed back to `header()`,
 *    `fetch_page()` and `format_row()` on every subsequent request, so the
 *    column set never drifts between batches.
 *  - `fetch_page()` MUST be a stable keyset page: `WHERE id < $cursor ORDER BY
 *    … , id DESC LIMIT $size`. `cursor_of()` extracts the next cursor (the last
 *    row's id) from a page's final row.
 *  - The three `authorize_*` hooks run the source-specific gate for each phase
 *    (start = cap/nonce/rate-limit/access; batch/download = ownership fence).
 *    They MUST terminate the request on denial (wp_send_json_error / wp_die).
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
 * A domain source driven by the batched CSV export engine.
 */
interface BatchedExportSourceInterface {

	/**
	 * Stable identifier for this source, used to route the dispatcher.
	 *
	 * @return string
	 */
	public function type(): string;

	/**
	 * Gate the START request (capability / nonce / rate-limit / access).
	 * Must terminate the request (wp_send_json_error / wp_die) on denial.
	 *
	 * @return void
	 */
	public function authorize_start(): void;

	/**
	 * Gate a BATCH request for an existing job (ownership fence).
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_batch( array $job ): void;

	/**
	 * Gate the DOWNLOAD request for an existing job (ownership fence).
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_download( array $job ): void;

	/**
	 * Owner fields stamped into the job at start and re-checked by
	 * {@see self::authorize_batch()} / {@see self::authorize_download()} — e.g.
	 * `['user_id' => …]` for admin, `['ip_hash' => …]` for anonymous callers.
	 *
	 * @return array<string, mixed>
	 */
	public function job_owner_fields(): array;

	/**
	 * Extract + validate the export filters from the current request.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_filters(): array;

	/**
	 * Total matching rows, for progress reporting + the empty-check.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function count( array $filters ): int;

	/**
	 * Build the per-job context frozen at start and carried across every
	 * request (e.g. the unioned dynamic-key set, feature flags).
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, mixed>
	 */
	public function build_context( array $filters ): array;

	/**
	 * The header row.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context from {@see self::build_context()}.
	 * @return array<int, string>
	 */
	public function header( array $filters, array $context ): array;

	/**
	 * The suggested download filename.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return string
	 */
	public function filename( array $filters, array $context ): string;

	/**
	 * Fetch one keyset page: rows with `id < $cursor`, ordered `… , id DESC`,
	 * limited to `$size`.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @param int                  $cursor  Exclusive upper-bound id (PHP_INT_MAX on the first page).
	 * @param int                  $size    Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function fetch_page( array $filters, array $context, int $cursor, int $size ): array;

	/**
	 * The next cursor: the id of a page's last row.
	 *
	 * @param array<string, mixed> $row The last row of a page.
	 * @return int
	 */
	public function cursor_of( array $row ): int;

	/**
	 * Format one raw row into a CSV line.
	 *
	 * @param array<string, mixed> $row     Raw row.
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, mixed>
	 */
	public function format_row( array $row, array $context ): array;

	/**
	 * Extra fields merged into the START response JSON (beyond `job_id` +
	 * `total`). Lets a source hand the client a job-scoped secret (e.g. a
	 * per-job nonce) for user-less ownership fences. Empty for capability-gated
	 * admin sources.
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Job state.
	 * @return array<string, mixed>
	 */
	public function extra_start_response( string $job_id, array $job ): array;

	/**
	 * Fired once, when the last batch completes and the file is ready. Lets a
	 * source run domain side-effects (audit log, completion action) without the
	 * engine knowing about them.
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Final job state.
	 * @return void
	 */
	public function on_complete( string $job_id, array $job ): void;

	/**
	 * Fired in the download handler after the file is confirmed to exist and
	 * immediately before the bytes are streamed — the reliable point to write a
	 * "delivered" audit row (the client may abort mid-stream once readfile
	 * starts). No-op for sources that need no pre-delivery side effect.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function on_before_download( array $job ): void;
}
