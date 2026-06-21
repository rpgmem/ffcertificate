<?php
/**
 * Recruitment CSV Staging Service
 *
 * The staging-based batched import flow (V10) extracted from
 * {@see RecruitmentCsvImporter} (#563 Sprint 6, PR 6c). Large notices time
 * out on a single synchronous request, so admin imports run as a four-phase
 * job against the dedicated `ffc_recruitment_import_jobs` /
 * `ffc_recruitment_import_staging` tables:
 *
 *   1. {@see ingest_job()}    — parse the CSV + mass-insert normalized rows
 *                               into staging (no candidate/wp_user writes).
 *   2. {@see validate_job()}  — SQL `GROUP BY ... HAVING` validation over the
 *                               staged rows; marks the job validated/invalid.
 *   3. {@see promote_batch()} — promote one chunk of staged rows to canonical
 *                               candidates via {@see CandidatePersister}.
 *   4. {@see commit_job()}    — atomic swap of the staged classification list
 *                               into the live table inside one transaction.
 *
 * Atomicity: the live list is only swapped in commit_job's transaction, so an
 * interrupted job never leaves a half-replaced list. Abandoned staging rows
 * are reaped by {@see cleanup_stale_staging_jobs()} on the next ingest.
 *
 * The synchronous single-request flow (`RecruitmentCsvImporter::run()`, used
 * by promote-preview's 15s-countdown definitive import) stays on the importer
 * and shares candidate writes with this service via {@see CandidatePersister}.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
/**
 * Service: ingest → validate → promote → commit batched CSV imports.
 */
final class CsvStagingService {

	/**
	 * Job + staging rows older than this are reaped on the next ingest.
	 */
	private const STAGING_JOB_TTL_SECONDS = 86400;

	/**
	 * Phase 1 of the staging-based batched import (V10) — ingest the
	 * raw CSV into the dedicated staging tables. NO promotion happens
	 * here; the next phase (`validate_job`) inspects the staged rows
	 * via SQL before any wp_user / candidate row is touched.
	 *
	 * Confidentiality: CPF, RF and email are encrypted + hashed on the
	 * way in, mirroring the canonical `ffc_recruitment_candidate`
	 * shape. A DB dump of the staging table is no more revealing than
	 * a dump of the candidate table.
	 *
	 * The row set is mass-inserted in chunks of 200 to stay clear of
	 * `max_allowed_packet` on default MySQL configs.
	 *
	 * Steps:
	 *   1. State-machine gate (preview imports only from draft / preliminary).
	 *   2. `parse()` the CSV bytes into normalized rows.
	 *   3. `build_adjutancy_map()` to resolve slug → adjutancy_id.
	 *   4. Opportunistic sweep of stale jobs (TTL).
	 *   5. INSERT INTO ffc_recruitment_import_jobs (status='ingested').
	 *   6. Mass INSERT INTO ffc_recruitment_import_staging.
	 *
	 * Returns `{ ok: true, job_id, total }` so the next phase
	 * (`validate_job`) can target the same job.
	 *
	 * @param int    $notice_id   Target notice.
	 * @param string $csv_content Raw CSV bytes (UTF-8, with or without BOM).
	 * @param string $list_type   `preview` or `definitive`.
	 * @return array{ok: true, job_id: string, total: int}|array{ok: false, errors: list<string>}
	 */
	public static function ingest_job( int $notice_id, string $csv_content, string $list_type ) {
		$notice = RecruitmentNoticeRepository::get_by_id( $notice_id );
		if ( null === $notice ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_notice_not_found' ),
			);
		}

		if ( 'preview' === $list_type && 'draft' !== $notice->status && 'preliminary' !== $notice->status ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_invalid_state_for_preview_import' ),
			);
		}

		$parse = CsvParser::parse( $csv_content );
		if ( ! $parse['ok'] ) {
			return array(
				'ok'     => false,
				'errors' => $parse['errors'],
			);
		}
		$rows = $parse['rows'];
		if ( empty( $rows ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_csv_empty' ),
			);
		}

		$adjutancy_map = CandidatePersister::build_adjutancy_map( $notice_id );
		if ( empty( $adjutancy_map ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_notice_has_no_adjutancies' ),
			);
		}

		// Reap stale jobs + their staging rows before allocating a new
		// one. Idempotent and bounded — only deletes rows older than the
		// generous JOB TTL.
		self::cleanup_stale_staging_jobs();

		global $wpdb;
		$jobs_table    = $wpdb->prefix . 'ffc_recruitment_import_jobs';
		$staging_table = $wpdb->prefix . 'ffc_recruitment_import_staging';

		$job_id = wp_generate_uuid4();
		$now    = current_time( 'mysql' );
		$user   = (int) get_current_user_id();

		// Job row first so a partially-ingested staging set without an
		// owning job is impossible (FK-like invariant enforced in code,
		// since the staging table has no real FK to jobs).
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ingest path; row is the source of truth from here on.
			$jobs_table,
			array(
				'job_id'          => $job_id,
				'notice_id'       => $notice_id,
				'list_type'       => $list_type,
				'status'          => 'ingested',
				'total'           => count( $rows ),
				'processed_count' => 0,
				'user_id'         => $user,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_insert_failed' ),
			);
		}

		// Mass INSERT — single statement per chunk. Staging keeps CPF /
		// RF / email as normalised plaintext: the table is ephemeral
		// (24h TTL, behind admin auth, reaped by
		// `cleanup_stale_staging_jobs`, never reachable from public REST)
		// so the synchronous ingest skips the per-row crypto that was
		// pushing this phase past the gateway timeout. The canonical
		// `ffc_recruitment_candidate` table still encrypts + hashes via
		// `upsert_candidate()` during the promote phase.
		$staged = 0;
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values       = array();
			$placeholders = array();
			foreach ( $chunk as $row ) {
				$cpf_raw = is_string( $row['cpf'] ?? null ) ? CsvParser::normalise_id( trim( $row['cpf'] ), 11 )['value'] : '';
				$rf_raw  = is_string( $row['rf'] ?? null ) ? CsvParser::normalise_id( trim( $row['rf'] ), 7 )['value'] : '';
				$email   = is_string( $row['email'] ?? null ) ? strtolower( trim( $row['email'] ) ) : '';
				$phone   = is_string( $row['phone'] ?? null ) ? trim( $row['phone'] ) : '';
				$slug    = is_string( $row['adjutancy'] ?? null ) ? trim( $row['adjutancy'] ) : '';
				// adjutancy_id may not exist in the map (the validation
				// phase reports `adjutancy_not_in_notice` against the
				// staging row); store 0 as a sentinel so the schema's
				// NOT NULL constraint stays satisfied.
				$adj_id = isset( $adjutancy_map[ $slug ] ) ? (int) $adjutancy_map[ $slug ] : 0;

				$placeholders[] = '(%s, %d, %d, %d, %s, %s, %s, %s, %s, %s, %d, %d, %s, %s, %d, %d)';
				array_push(
					$values,
					$job_id,
					++$staged,
					(int) $row['_line'],
					$notice_id,
					is_string( $row['name'] ?? null ) ? trim( $row['name'] ) : '',
					$cpf_raw,
					$rf_raw,
					$email,
					$phone,
					$slug,
					$adj_id,
					ctype_digit( (string) ( $row['rank'] ?? '' ) ) ? (int) $row['rank'] : 0,
					is_string( $row['score'] ?? null ) ? trim( $row['score'] ) : (string) ( $row['score'] ?? '0' ),
					isset( $row['time_points'] ) && '' !== (string) $row['time_points'] ? (string) $row['time_points'] : '0',
					CsvParser::parse_pcd_flag( $row['hab_emebs'] ?? '' ) ? 1 : 0,
					CsvParser::parse_pcd_flag( $row['pcd'] ?? '' ) ? 1 : 0
				);
			}

			$sql = "INSERT INTO {$staging_table} (job_id, row_no, line_no, notice_id, name, cpf_normalized, rf_normalized, email, phone, adjutancy_slug, adjutancy_id, rank_value, score, time_points, hab_emebs, pcd) VALUES "
				. implode( ', ', $placeholders );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- chunked multi-VALUES INSERT; table from $wpdb->prefix; every user-derived value passes through %s/%d placeholders.
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
			if ( false === $result ) {
				// Roll back the entire job — leaves no partial staging.
				$wpdb->delete( $jobs_table, array( 'job_id' => $job_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $staging_table, array( 'job_id' => $job_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return array(
					'ok'     => false,
					'errors' => array( 'recruitment_import_staging_insert_failed' ),
				);
			}
		}

		return array(
			'ok'     => true,
			'job_id' => $job_id,
			'total'  => count( $rows ),
		);
	}

	/**
	 * Sweep stale jobs (and their staging rows) older than the TTL.
	 *
	 * Called at the top of `ingest_job` so each new import starts from
	 * a clean tail of forgotten jobs. The DELETE pair is guarded by
	 * `created_at < NOW() - INTERVAL` so it's bounded and safe to call
	 * on every import.
	 *
	 * @return int Number of staging rows deleted (best-effort count).
	 */
	private static function cleanup_stale_staging_jobs(): int {
		global $wpdb;
		$jobs_table    = $wpdb->prefix . 'ffc_recruitment_import_jobs';
		$staging_table = $wpdb->prefix . 'ffc_recruitment_import_staging';

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance sweep.
			$wpdb->prepare(
				"DELETE s FROM {$staging_table} s INNER JOIN {$jobs_table} j ON s.job_id = j.job_id WHERE j.created_at < (NOW() - INTERVAL %d SECOND)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
				self::STAGING_JOB_TTL_SECONDS
			)
		);
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance sweep.
			$wpdb->prepare(
				"DELETE FROM {$jobs_table} WHERE created_at < (NOW() - INTERVAL %d SECOND)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				self::STAGING_JOB_TTL_SECONDS
			)
		);

		return (int) $deleted;
	}

	/**
	 * Phase 2 of the staging-based batched import (V10) — validate every
	 * staged row via SQL `GROUP BY ... HAVING` queries against the
	 * `ffc_recruitment_import_staging` indexes built for this purpose.
	 *
	 * No row hits the canonical schema yet. If anything fails the job is
	 * marked `invalid` and the staging rows are preserved for the
	 * operator to inspect (purged by the next `ingest_job` TTL sweep);
	 * the operator fixes the CSV and re-imports under a new job_id.
	 *
	 * Rules enforced (each one SQL-bounded, no row-by-row PHP):
	 *
	 *   1. `missing_cpf_or_rf`: cpf_normalized = '' AND rf_normalized = ''.
	 *   2. `missing_adjutancy`: adjutancy_slug is empty.
	 *   3. `adjutancy_not_in_notice`: adjutancy_slug present but
	 *      adjutancy_id is the 0 sentinel (the ingest map missed it).
	 *   4. `duplicate_candidate_adjutancy`: same (cpf|rf|email) +
	 *      adjutancy_id appears in more than one row. Three queries
	 *      (one per identifier) cover the physical-identity collapse
	 *      that `upsert_candidate` would otherwise perform downstream.
	 *   5. `candidate_field_divergence`: rows sharing the same
	 *      cpf_normalized must agree on name / email / rf_normalized /
	 *      phone / pcd. Detected via `COUNT(DISTINCT ...) > 1` in a
	 *      single GROUP BY pass.
	 *
	 * Job status transitions:
	 *   ingested → validated  (no errors)
	 *   ingested → invalid    (errors collected; staging preserved)
	 *
	 * @param string $job_id Job identifier.
	 * @return array{ok: true, errors: list<string>}|array{ok: false, errors: list<string>}
	 */
	public static function validate_job( string $job_id ) {
		global $wpdb;
		$jobs_table    = $wpdb->prefix . 'ffc_recruitment_import_jobs';
		$staging_table = $wpdb->prefix . 'ffc_recruitment_import_staging';

		$job = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- validate path; row is authoritative.
			$wpdb->prepare( "SELECT job_id, status FROM {$jobs_table} WHERE job_id = %s", $job_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix.
		);
		if ( null === $job ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_not_found' ),
			);
		}
		if ( ! in_array( $job->status, array( 'ingested', 'invalid' ), true ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_invalid_state_for_validate' ),
			);
		}

		$errors = array();

		// Rule 1 — missing identifier (CPF and RF both empty).
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT line_no FROM {$staging_table} WHERE job_id = %s AND cpf_normalized = '' AND rf_normalized = '' ORDER BY row_no", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);
		foreach ( $rows as $line ) {
			$errors[] = CsvValidator::line_error( (int) $line, 'recruitment_csv_missing_cpf_or_rf' );
		}

		// Rule 2 — missing adjutancy slug.
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT line_no FROM {$staging_table} WHERE job_id = %s AND adjutancy_slug = '' ORDER BY row_no", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);
		foreach ( $rows as $line ) {
			$errors[] = CsvValidator::line_error( (int) $line, 'recruitment_csv_missing_adjutancy' );
		}

		// Rule 3 — adjutancy slug present but not attached to the notice
		// (adjutancy_id=0 is the sentinel ingest writes when the map
		// lookup misses).
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT line_no, adjutancy_slug FROM {$staging_table} WHERE job_id = %s AND adjutancy_slug <> '' AND adjutancy_id = 0 ORDER BY row_no", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);
		foreach ( $rows as $r ) {
			$errors[] = CsvValidator::line_error( (int) $r->line_no, 'recruitment_csv_adjutancy_not_in_notice: ' . $r->adjutancy_slug );
		}

		// Rule 4 — duplicate (logical candidate + adjutancy). Three
		// passes, one per identifier column. Each `GROUP BY col,
		// adjutancy_id HAVING COUNT(*) > 1` returns the offending value +
		// the CSV lines that share it; we surface every line past the
		// first as a duplicate of that first line.
		foreach ( array( 'cpf_normalized', 'rf_normalized', 'email' ) as $id_col ) {
			$dup_groups = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT GROUP_CONCAT(line_no ORDER BY row_no) AS csv_lines FROM {$staging_table} WHERE job_id = %s AND {$id_col} <> '' GROUP BY {$id_col}, adjutancy_id HAVING COUNT(*) > 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- id_col is hard-coded above; not user input.
					$job_id
				)
			);
			foreach ( $dup_groups as $group ) {
				$lines = array_map( 'intval', explode( ',', (string) $group->csv_lines ) );
				$first = (int) array_shift( $lines );
				foreach ( $lines as $line ) {
					$errors[] = CsvValidator::line_error(
						$line,
						sprintf( 'recruitment_csv_duplicate_candidate_adjutancy: matches line %d', $first )
					);
				}
			}
		}

		// Rule 5 — same cpf_normalized rows must agree on candidate-level
		// fields. `COUNT(DISTINCT …)` against each field in one pass.
		// `%i` placeholder for the table name keeps WPCS happy without a
		// sniff suppression.
		$diverge_groups = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT GROUP_CONCAT(line_no ORDER BY row_no) AS csv_lines,
					COUNT(DISTINCT name)          AS d_name,
					COUNT(DISTINCT email)         AS d_email,
					COUNT(DISTINCT rf_normalized) AS d_rf,
					COUNT(DISTINCT phone)         AS d_phone,
					COUNT(DISTINCT pcd)           AS d_pcd
				FROM %i
				WHERE job_id = %s AND cpf_normalized <> \'\'
				GROUP BY cpf_normalized
				HAVING d_name > 1 OR d_email > 1 OR d_rf > 1 OR d_phone > 1 OR d_pcd > 1',
				$staging_table,
				$job_id
			)
		);
		foreach ( $diverge_groups as $group ) {
			$lines = array_map( 'intval', explode( ',', (string) $group->csv_lines ) );
			$first = (int) array_shift( $lines );
			$which = array();
			if ( $group->d_name > 1 ) {
				$which[] = 'name';
			}
			if ( $group->d_email > 1 ) {
				$which[] = 'email';
			}
			if ( $group->d_rf > 1 ) {
				$which[] = 'rf';
			}
			if ( $group->d_phone > 1 ) {
				$which[] = 'phone';
			}
			if ( $group->d_pcd > 1 ) {
				$which[] = 'pcd';
			}
			$field_list = implode( '+', $which );
			foreach ( $lines as $line ) {
				$errors[] = CsvValidator::line_error(
					$line,
					sprintf( 'recruitment_csv_candidate_field_divergence: field=%s, ref_line=%d', $field_list, $first )
				);
			}
		}

		// Transition the job. Status determines what /promote and
		// /commit accept; staging rows stay put either way.
		$next_status = empty( $errors ) ? 'validated' : 'invalid';
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$jobs_table,
			array(
				'status'     => $next_status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'job_id' => $job_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		return array(
			'ok'     => true,
			'errors' => $errors,
		);
	}

	/**
	 * Phase 3 of the staging-based batched import (V10) — promote one
	 * chunk of staged rows.
	 *
	 * Each batch:
	 *   1. SELECTs the next `$size` unprocessed rows for the job.
	 *   2. For each row, reads the plaintext CPF / RF / email straight
	 *      out of staging and calls the existing `upsert_candidate()` —
	 *      ALL the heavy lifting (Encryption::hash + encrypt against the
	 *      canonical candidate table, candidate lookups, wp_user creation
	 *      via UserCreator) stays in that one helper. We DON'T duplicate
	 *      it. Crypto only happens here, on this side of the gateway
	 *      timeout boundary, in batches the operator can monitor.
	 *   3. Stores the resolved candidate_id back on the staging row
	 *      and flips `processed=1`.
	 *   4. Increments `processed_count` on the job in a single UPDATE.
	 *
	 * No classification row is created here — that's phase 4's swap.
	 * If the gateway times out mid-batch, the next call picks up the
	 * next unprocessed row (idempotent: a row that DID get upserted
	 * just gets reused on retry because `upsert_candidate` is
	 * lookup-then-create).
	 *
	 * @param string $job_id Job identifier.
	 * @param int    $size   Rows to process in this batch (clamped to 10–100).
	 * @return array{ok: true, processed: int, total: int, done: bool}|array{ok: false, errors: list<string>}
	 */
	public static function promote_batch( string $job_id, int $size ) {
		global $wpdb;
		$jobs_table    = $wpdb->prefix . 'ffc_recruitment_import_jobs';
		$staging_table = $wpdb->prefix . 'ffc_recruitment_import_staging';

		$job = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$jobs_table} WHERE job_id = %s", $job_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( null === $job ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_not_found' ),
			);
		}
		if ( ! in_array( $job->status, array( 'validated', 'promoting' ), true ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_invalid_state_for_promote' ),
			);
		}

		$size = max( 10, min( 100, $size ) );

		$batch = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, name, cpf_normalized, rf_normalized, email, phone, pcd FROM {$staging_table} WHERE job_id = %s AND processed = 0 ORDER BY row_no LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id,
				$size
			)
		);

		if ( empty( $batch ) ) {
			// Nothing left to process — caller may have over-iterated.
			// Mirror the job's processed_count back so the response
			// reflects ground truth.
			return array(
				'ok'        => true,
				'processed' => (int) $job->processed_count,
				'total'     => (int) $job->total,
				'done'      => true,
			);
		}

		// Flip the job to 'promoting' on the first non-empty batch so
		// subsequent /validate or /commit calls see the correct state.
		if ( 'validated' === $job->status ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$jobs_table,
				array(
					'status'     => 'promoting',
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'job_id' => $job_id ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		}

		$processed_now = 0;
		foreach ( $batch as $row ) {
			// Plaintext straight out of staging — upsert_candidate
			// applies Encryption::encrypt + Encryption::hash before the
			// values reach the permanent ffc_recruitment_candidate table.
			$candidate_id = CandidatePersister::upsert_candidate(
				array(
					'name'  => $row->name,
					'cpf'   => is_string( $row->cpf_normalized ) ? $row->cpf_normalized : '',
					'rf'    => is_string( $row->rf_normalized ) ? $row->rf_normalized : '',
					'email' => is_string( $row->email ) ? $row->email : '',
					'phone' => is_string( $row->phone ) ? $row->phone : '',
					'pcd'   => (int) $row->pcd ? '1' : '0',
				)
			);
			if ( false === $candidate_id ) {
				return array(
					'ok'     => false,
					'errors' => array( 'recruitment_candidate_upsert_failed' ),
				);
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$staging_table,
				array(
					'candidate_id' => (int) $candidate_id,
					'processed'    => 1,
				),
				array( 'id' => (int) $row->id ),
				array( '%d', '%d' ),
				array( '%d' )
			);
			++$processed_now;
		}

		// Bump the per-job counter in a single statement. UPDATE … +N
		// is atomic per row, but a SELECT-then-UPDATE pair would race
		// with a concurrent retry from a frantic JS retry loop.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$jobs_table} SET processed_count = processed_count + %d, updated_at = %s WHERE job_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$processed_now,
				current_time( 'mysql' ),
				$job_id
			)
		);

		// Flush the per-batch activity log entries that the
		// `candidate_promoted` writes accumulated. Same rationale as
		// the legacy flow: keep the shutdown handler off the hot path.
		if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::flush_buffer();
		}

		$new_processed = (int) $job->processed_count + $processed_now;
		return array(
			'ok'        => true,
			'processed' => $new_processed,
			'total'     => (int) $job->total,
			'done'      => $new_processed >= (int) $job->total,
		);
	}

	/**
	 * Phase 4 of the staging-based batched import (V10) — atomic swap
	 * of the staging classification list into the canonical
	 * `ffc_recruitment_classification` table.
	 *
	 * All the work — INSERT INTO classification … SELECT FROM staging
	 * — happens inside one short transaction. The wipe of the previous
	 * live list is part of the same transaction, so the operator never
	 * sees a half-replaced list to readers. If anything fails, the
	 * rollback leaves the previous live list intact AND the staging
	 * rows preserved for a retry.
	 *
	 * Preconditions:
	 *   - job.status must be 'promoting' or 'committed' (the latter
	 *     covers a retry after a partial commit_job that succeeded the
	 *     swap but failed the cleanup step).
	 *   - All staging rows for the job must be processed (candidate_id
	 *     resolved). Otherwise the operator runs another
	 *     /promote-batch first.
	 *
	 * Steps inside the transaction:
	 *   1. DELETE FROM classification WHERE notice_id=X AND list_type=Y.
	 *   2. INSERT INTO classification (…) SELECT …, candidate_id, …
	 *      FROM staging WHERE job_id=X AND processed=1.
	 *   3. UPDATE jobs SET status='committed'.
	 *
	 * After the transaction commits:
	 *   4. DELETE staging + DELETE jobs row for the job. These are
	 *      idempotent — a crash between the commit and the cleanup
	 *      just leaves an orphaned 'committed' job + its staging,
	 *      reaped on the next ingest_job TTL sweep.
	 *
	 * @param string $job_id Job identifier.
	 * @return array{ok: true, inserted: int}|array{ok: false, errors: list<string>}
	 */
	public static function commit_job( string $job_id ) {
		global $wpdb;
		$jobs_table           = $wpdb->prefix . 'ffc_recruitment_import_jobs';
		$staging_table        = $wpdb->prefix . 'ffc_recruitment_import_staging';
		$classification_table = $wpdb->prefix . 'ffc_recruitment_classification';

		$job = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$jobs_table} WHERE job_id = %s", $job_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( null === $job ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_not_found' ),
			);
		}
		if ( ! in_array( $job->status, array( 'promoting', 'committed' ), true ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_invalid_state_for_commit' ),
			);
		}

		// Defensive: confirm promotion really finished. The JS loop
		// should already guarantee this, but a manual /commit POST
		// without the preceding batches must not be able to swap a
		// half-populated staging set.
		$unpromoted = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$staging_table} WHERE job_id = %s AND processed = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);
		if ( $unpromoted > 0 ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_not_finished' ),
			);
		}

		$wpdb->query( 'START TRANSACTION' );
		try {
			RecruitmentClassificationRepository::delete_all_for_notice_list( (int) $job->notice_id, (string) $job->list_type );

			$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'INSERT INTO %i
						(candidate_id, adjutancy_id, notice_id, list_type, `rank`, score, time_points, hab_emebs, status, created_at, updated_at)
					 SELECT candidate_id, adjutancy_id, notice_id, %s, rank_value, score, time_points, hab_emebs, %s, %s, %s
					 FROM %i
					 WHERE job_id = %s AND processed = 1
					 ORDER BY row_no',
					$classification_table,
					(string) $job->list_type,
					'empty',
					current_time( 'mysql' ),
					current_time( 'mysql' ),
					$staging_table,
					$job_id
				)
			);
			if ( false === $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'     => false,
					'errors' => array( 'recruitment_import_swap_failed' ),
				);
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$jobs_table,
				array(
					'status'     => 'committed',
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'job_id' => $job_id ),
				array( '%s', '%s' ),
				array( '%s' )
			);

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_unexpected_error: ' . $e->getMessage() ),
			);
		}

		RecruitmentActivityLogger::csv_imported( (int) $job->notice_id, (string) $job->list_type, (int) $inserted );

		// Post-commit cleanup. Outside the transaction so the swap is
		// already durable; if these fail, the orphan reaper at the top
		// of ingest_job handles it eventually.
		$wpdb->delete( $staging_table, array( 'job_id' => $job_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $jobs_table, array( 'job_id' => $job_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'ok'       => true,
			'inserted' => (int) $inserted,
		);
	}
}
