<?php
/**
 * SyncSourceInterface
 *
 * The per-domain descriptor for a *synchronous*, bounded CSV export — one that
 * streams to the browser in a single request via {@see SyncCsvExport} (backed by
 * {@see CsvStreamer} → {@see HttpCsvDownload}), as opposed to the timeout-safe,
 * AJAX-batched {@see BatchedExportSourceInterface}. Use this contract when the
 * row set is provably small/bounded (a ring buffer, a hierarchy, a hand-written
 * sample) so a single `php://output` stream can't blow the execution-time
 * budget.
 *
 * A source owns ONLY its domain specifics — the per-request authorization gate,
 * the download filename, the header row, and a `rows()` iterable (an array or a
 * generator). The driver owns the shared streaming lifecycle. Sources keep their
 * existing entry points (`admin_post_*` / page-load handlers); this contract is
 * about de-duplicating the *shape*, not changing the delivery. (Issue #772.)
 *
 * Contract notes:
 *  - `authorize()` runs the source-specific capability + nonce gate and MUST
 *    terminate the request (`wp_die()`) on denial — the driver calls it first
 *    and assumes success if it returns.
 *  - `rows()` is streamed lazily; a generator keeps peak memory to one row.
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
 * A domain source streamed synchronously by {@see SyncCsvExport}.
 */
interface SyncSourceInterface {

	/**
	 * Gate the request (capability + nonce). MUST terminate the request
	 * (`wp_die()`) on denial; returns normally when the caller is authorized.
	 *
	 * @return void
	 */
	public function authorize(): void;

	/**
	 * The suggested download filename (ends in `.csv`).
	 *
	 * @return string
	 */
	public function filename(): string;

	/**
	 * The header row.
	 *
	 * @return array<int, string>
	 */
	public function header(): array;

	/**
	 * The data rows, streamed lazily (array or generator). Each element is one
	 * CSV line as an ordered value array.
	 *
	 * @return iterable
	 * @phpstan-return iterable<array<int, mixed>>
	 */
	public function rows(): iterable;
}
