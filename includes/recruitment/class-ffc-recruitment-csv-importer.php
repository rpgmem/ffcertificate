<?php
/**
 * Recruitment CSV Importer
 *
 * Atomic CSV-driven population of the recruitment classification list for a
 * given notice. The full input is parsed, validated, then either written in
 * a single InnoDB transaction (wipe-and-reinsert) or rolled back so the
 * previous list survives any validation failure.
 *
 * Acts as a service layer over the recruitment repositories: the importer
 * never bypasses {@see RecruitmentCandidateRepository} / {@see
 * RecruitmentClassificationRepository} writes, and it delegates wp_user
 * promotion to the existing {@see \FreeFormCertificate\UserDashboard\UserCreator}
 * (called with the new {@see CapabilityManager::CONTEXT_RECRUITMENT}).
 *
 * Validation rules implemented (mirroring §6 of the implementation plan):
 *
 *   - Headers in English only; missing required headers fail the import.
 *   - At least one of `cpf` / `rf` per row.
 *   - CPF / RF: digits only (punctuation rejected).
 *   - email: lowercased on import; falsy is allowed (no email is the
 *     "candidate not promoted yet" signal).
 *   - score: dot-decimal only (comma rejected).
 *   - adjutancy slug must exist AND be attached to the notice via the
 *     `ffc_recruitment_notice_adjutancy` junction.
 *   - duplicate (cpf + adjutancy) within the CSV → reject.
 *   - same CPF appearing in N rows must agree on candidate-level fields
 *     (name / rf / email / phone / pcd) — first row wins as reference.
 *   - empty rows (every column blank/whitespace) are silently skipped.
 *   - notice must be in `draft` or `preliminary` for `preview` imports.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
/**
 * Service: parse + validate + write recruitment CSV imports atomically.
 *
 * All public methods return a result envelope:
 *
 *   array{
 *     success:  bool,
 *     inserted: int,                 // candidate+classification rows committed
 *     errors:   list<string>,        // human-readable, line-numbered when row-specific
 *   }
 *
 * On `success: false` the database is unchanged (rollback). On `success: true`
 * the candidate and classification tables reflect the imported list and the
 * previous list (if any) for the same `(notice_id, list_type)` has been
 * wiped.
 *
 * @phpstan-type ImportResult array{success: bool, inserted: int, errors: list<string>}
 */
final class RecruitmentCsvImporter {

	/**
	 * Run a `preview` import on a notice in `draft` or `preliminary` state.
	 *
	 * Used by `POST /notices/{id}/import` (sprint 9.1). Sprint 5's notice
	 * state machine surfaces a friendlier error if the notice is in
	 * `active` / `closed`; this importer just checks for the basic
	 * write-eligible states.
	 *
	 * @param int    $notice_id Target notice.
	 * @param string $csv_content Raw CSV bytes (UTF-8, with or without BOM).
	 * @return ImportResult
	 */
	public static function import_preview( int $notice_id, string $csv_content ): array {
		$notice = RecruitmentNoticeRepository::get_by_id( $notice_id );
		if ( null === $notice ) {
			return self::failure( 'recruitment_notice_not_found' );
		}

		$status = $notice->status;
		if ( 'draft' !== $status && 'preliminary' !== $status ) {
			return self::failure( 'recruitment_invalid_state_for_preview_import' );
		}

		return self::run( $notice_id, $csv_content, 'preview' );
	}

	/**
	 * Run a `definitive` import on a notice during the promote-preview flow.
	 *
	 * Caller (sprint 5's PromotionService) is responsible for gating this on
	 * the 15s countdown + zero-calls-history check. The importer only
	 * verifies the notice exists and writes to `list_type='definitive'`,
	 * wiping any pre-existing definitive rows in the same transaction.
	 *
	 * @param int    $notice_id Target notice.
	 * @param string $csv_content Raw CSV bytes.
	 * @return ImportResult
	 */
	public static function import_definitive( int $notice_id, string $csv_content ): array {
		$notice = RecruitmentNoticeRepository::get_by_id( $notice_id );
		if ( null === $notice ) {
			return self::failure( 'recruitment_notice_not_found' );
		}

		return self::run( $notice_id, $csv_content, 'definitive' );
	}

	/**
	 * Core import flow: parse → validate → atomic wipe-and-reinsert.
	 *
	 * Returns an envelope; never throws. The transaction is rolled back on
	 * any validation error or DB failure. On success, all rows are committed
	 * and the previous list for `(notice_id, list_type)` has been replaced.
	 *
	 * @param int    $notice_id Notice ID.
	 * @param string $csv_content Raw CSV bytes.
	 * @param string $list_type `preview` or `definitive`.
	 * @return ImportResult
	 */
	private static function run( int $notice_id, string $csv_content, string $list_type ): array {
		// Step 1: parse CSV into normalized row arrays.
		$parse = self::parse( $csv_content );
		if ( ! $parse['ok'] ) {
			return self::failure_with_messages( $parse['errors'] );
		}
		$rows = $parse['rows'];

		if ( empty( $rows ) ) {
			return self::failure( 'recruitment_csv_empty' );
		}

		// Step 2: build adjutancy slug → id map for this notice.
		$adjutancy_map = CandidatePersister::build_adjutancy_map( $notice_id );
		if ( empty( $adjutancy_map ) ) {
			return self::failure( 'recruitment_notice_has_no_adjutancies' );
		}

		// Step 3: domain-level validation across all rows.
		$validation = CsvValidator::validate( $rows, $notice_id, $list_type, $adjutancy_map );
		if ( ! empty( $validation ) ) {
			return self::failure_with_messages( $validation );
		}

		// Step 4: transactional write.
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		try {
			RecruitmentClassificationRepository::delete_all_for_notice_list( $notice_id, $list_type );

			$inserted = 0;
			foreach ( $rows as $row ) {
				$candidate_id = CandidatePersister::upsert_candidate( $row );
				if ( false === $candidate_id ) {
					$wpdb->query( 'ROLLBACK' );
					return self::failure( 'recruitment_candidate_upsert_failed' );
				}

				$classification_id = RecruitmentClassificationRepository::create(
					array(
						'candidate_id' => $candidate_id,
						'adjutancy_id' => $adjutancy_map[ $row['adjutancy'] ],
						'notice_id'    => $notice_id,
						'list_type'    => $list_type,
						'rank'         => $row['rank'],
						'score'        => $row['score'],
						// Optional CSV-extension columns added in v6 —
						// the repository defaults to 0 / 0 when these
						// keys are missing. hab_emebs accepts the same
						// case-insensitive truthy set as the pcd column
						// (true/1/sim/yes).
						'time_points'  => isset( $row['time_points'] ) && '' !== (string) $row['time_points'] ? (string) $row['time_points'] : '0',
						'hab_emebs'    => CsvParser::parse_pcd_flag( $row['hab_emebs'] ?? '' ) ? 1 : 0,
					)
				);
				if ( false === $classification_id ) {
					$wpdb->query( 'ROLLBACK' );
					return self::failure( 'recruitment_classification_insert_failed' );
				}

				++$inserted;
			}

			$wpdb->query( 'COMMIT' );

			RecruitmentActivityLogger::csv_imported( $notice_id, $list_type, $inserted );

			return array(
				'success'  => true,
				'inserted' => $inserted,
				'errors'   => array(),
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return self::failure( 'recruitment_import_unexpected_error: ' . $e->getMessage() );
		}
	}

	/**
	 * Parse raw CSV content into normalized associative rows.
	 *
	 * Stable public entry point; delegates to {@see CsvParser::parse()} (the
	 * pure string→rows layer extracted in #563 Sprint 6).
	 *
	 * @param string $content Raw CSV (UTF-8; BOM stripped if present).
	 * @return array{ok: bool, rows: list<array<string, mixed>>, errors: list<string>}
	 */
	public static function parse( string $content ): array {
		return CsvParser::parse( $content );
	}

	/**
	 * Build a failed-import result envelope with a single error.
	 *
	 * @param string $code Error code.
	 * @return ImportResult
	 */
	private static function failure( string $code ): array {
		return array(
			'success'  => false,
			'inserted' => 0,
			'errors'   => array( $code ),
		);
	}

	/**
	 * Build a failed-import result envelope with N errors.
	 *
	 * @param array $messages Error codes / messages (list<string>).
	 * @phpstan-param list<string> $messages
	 * @return ImportResult
	 */
	private static function failure_with_messages( array $messages ): array {
		return array(
			'success'  => false,
			'inserted' => 0,
			'errors'   => $messages,
		);
	}

	/**
	 * Batch size used by AJAX callers when they don't override it.
	 *
	 * Aligned with `CsvExporter::EXPORT_BATCH_SIZE`. Candidate INSERTs are
	 * heavier than the exporter's reads (each candidate runs through
	 * `wp_create_user` when promotion fires, plus encryption + activity
	 * log), so smaller batches are safer on cheap hosts; the JS clamps any
	 * caller-provided value to `[10, 100]`.
	 */
	public const BATCH_SIZE_DEFAULT = 50;

	// ──────────────────────────────────────────────────────────────────────.
	// Staging-based batched-import flow (V10). Large notices time out on a
	// single synchronous request, so admin imports run as a four-phase job.
	// The implementation lives in {@see CsvStagingService}; the four public
	// entry points below stay here as stable façade delegators (the REST
	// controller + the batched test-suite reference them by this class name).
	// The synchronous single-request flow (`run()`, used by promote-preview)
	// stays on this class and shares candidate writes via CandidatePersister.
	// ──────────────────────────────────────────────────────────────────────.

	/**
	 * Phase 1 — ingest raw CSV into the staging tables. {@see CsvStagingService::ingest_job()}.
	 *
	 * @param int    $notice_id   Target notice.
	 * @param string $csv_content Raw CSV bytes (UTF-8, with or without BOM).
	 * @param string $list_type   `preview` or `definitive`.
	 * @return array{ok: true, job_id: string, total: int}|array{ok: false, errors: list<string>}
	 */
	public static function ingest_job( int $notice_id, string $csv_content, string $list_type ) {
		return CsvStagingService::ingest_job( $notice_id, $csv_content, $list_type );
	}

	/**
	 * Phase 2 — SQL-validate the staged rows. {@see CsvStagingService::validate_job()}.
	 *
	 * @param string $job_id Job identifier.
	 * @return array{ok: true, errors: list<string>}|array{ok: false, errors: list<string>}
	 */
	public static function validate_job( string $job_id ) {
		return CsvStagingService::validate_job( $job_id );
	}

	/**
	 * Phase 3 — promote one chunk of staged rows. {@see CsvStagingService::promote_batch()}.
	 *
	 * @param string $job_id Job identifier.
	 * @param int    $size   Rows to process in this batch (clamped to 10–100).
	 * @return array{ok: true, processed: int, total: int, done: bool}|array{ok: false, errors: list<string>}
	 */
	public static function promote_batch( string $job_id, int $size ) {
		return CsvStagingService::promote_batch( $job_id, $size );
	}

	/**
	 * Phase 4 — atomic swap of the staged list into the live table. {@see CsvStagingService::commit_job()}.
	 *
	 * @param string $job_id Job identifier.
	 * @return array{ok: true, inserted: int}|array{ok: false, errors: list<string>}
	 */
	public static function commit_job( string $job_id ) {
		return CsvStagingService::commit_job( $job_id );
	}
}
