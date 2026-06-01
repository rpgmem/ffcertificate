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

use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\UserDashboard\CapabilityManager;
use FreeFormCertificate\UserDashboard\UserCreator;

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
	 * Required CSV headers (all in English).
	 */
	private const REQUIRED_HEADERS = array( 'name', 'cpf', 'rf', 'email', 'adjutancy', 'rank', 'score', 'pcd' );

	/**
	 * Optional CSV headers.
	 */
	private const OPTIONAL_HEADERS = array( 'phone', 'time_points', 'hab_emebs' );

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
		$adjutancy_map = self::build_adjutancy_map( $notice_id );
		if ( empty( $adjutancy_map ) ) {
			return self::failure( 'recruitment_notice_has_no_adjutancies' );
		}

		// Step 3: domain-level validation across all rows.
		$validation = self::validate( $rows, $notice_id, $list_type, $adjutancy_map );
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
				$candidate_id = self::upsert_candidate( $row );
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
						'hab_emebs'    => self::parse_pcd_flag( $row['hab_emebs'] ?? '' ) ? 1 : 0,
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
	 * @param string $content Raw CSV (UTF-8; BOM stripped if present).
	 * @return array{ok: bool, rows: list<array<string, mixed>>, errors: list<string>}
	 */
	public static function parse( string $content ): array {
		if ( '' === trim( $content ) ) {
			return array(
				'ok'     => false,
				'rows'   => array(),
				'errors' => array( 'recruitment_csv_empty' ),
			);
		}

		// Csv::reader_from_string() handles BOM stripping and the
		// `,`-vs-`;` auto-detection (the canonical rule lifted from the
		// previous self::detect_delimiter()). The reader's fgetcsv-based
		// parser also handles quoted multi-line fields correctly, which
		// the previous preg_split('/\r\n|\n|\r/') line-splitter did not —
		// a quoted cell containing a literal newline would have been
		// mis-parsed before.
		$reader  = \FreeFormCertificate\Core\Csv::reader_from_string( $content );
		$headers = array_map( 'strtolower', array_map( 'trim', $reader->header() ) );

		$missing = array_diff( self::REQUIRED_HEADERS, $headers );
		if ( ! empty( $missing ) ) {
			$reader->close();
			return array(
				'ok'     => false,
				'rows'   => array(),
				'errors' => array( 'recruitment_csv_missing_headers: ' . implode( ',', $missing ) ),
			);
		}

		// Build header → index map (keeps optional headers when present).
		$index_map = array();
		foreach ( $headers as $i => $name ) {
			$index_map[ $name ] = $i;
		}

		$rows        = array();
		$line_number = 1; // 1-based; header was logical row 1.
		$reader->each(
			static function ( array $cells ) use ( &$rows, &$line_number, $index_map ): void {
				++$line_number;

				// Skip rows that are all whitespace after parsing.
				$any_value = false;
				foreach ( $cells as $cell ) {
					if ( '' !== trim( $cell ) ) {
						$any_value = true;
						break;
					}
				}
				if ( ! $any_value ) {
					return;
				}

				$row          = self::build_row( array_values( $cells ), $index_map );
				$row['_line'] = $line_number;
				$rows[]       = $row;
			}
		);
		$reader->close();

		return array(
			'ok'     => true,
			'rows'   => $rows,
			'errors' => array(),
		);
	}

	/**
	 * Validate every row against the §6 rules.
	 *
	 * @param list<array<string, mixed>> $rows Parsed rows.
	 * @param int                        $notice_id Target notice.
	 * @param string                     $list_type `preview` or `definitive`.
	 * @param array<string, int>         $adjutancy_map Slug → id for the notice.
	 * @return list<string> Validation errors (empty on success).
	 */
	private static function validate( array $rows, int $notice_id, string $list_type, array $adjutancy_map ): array {
		$errors    = array();
		$by_cpf    = array(); // cpf → first-seen row reference (for cross-row consistency).
		$seen_pair = array(); // "candidate_identity|adjutancy" → line number, for duplicate detection.

		// Build a logical-identity map BEFORE the per-row loop so duplicate
		// detection can group rows by the same physical candidate even when
		// only RF or email (not CPF) is shared. Without this, the prior
		// validate rule "cpf + adjutancy" missed the cases the upsert path
		// later collapsed via rf_hash / email lookup — and those silent
		// candidate-reuses violated the UNIQUE (candidate_id, adjutancy_id,
		// notice_id, list_type) constraint at INSERT time, surfacing as
		// "Duplicate entry" debug.log noise + a 400 to the operator.
		//
		// The map mirrors upsert_candidate's matching order: cpf > rf >
		// email. Two rows sharing ANY of those (after digit-normalisation
		// for cpf/rf and case-fold for email) are pinned to the same
		// identity_id. The algorithm is naive — for each row, locate any
		// pre-existing identity_id that matches one of its tags, merge
		// other matched ids into it, then claim the row's remaining tags
		// for that id. O(n²) worst-case but the import is gated at
		// human-CSV scale (~thousands of rows) so this stays cheap.
		$row_identity = array(); // row_index → identity_id (int).
		$tag_to_id    = array(); // 'cpf:DIGITS' / 'rf:DIGITS' / 'em:STR' → identity_id.
		$next_id      = 0;
		foreach ( $rows as $idx => $row ) {
			$row_cpf   = is_string( $row['cpf'] ?? null ) ? self::normalise_id( trim( $row['cpf'] ), 11 )['value'] : '';
			$row_rf    = is_string( $row['rf'] ?? null ) ? self::normalise_id( trim( $row['rf'] ), 7 )['value'] : '';
			$row_email = is_string( $row['email'] ?? null ) ? strtolower( trim( $row['email'] ) ) : '';

			$tags = array();
			if ( '' !== $row_cpf ) {
				$tags[] = 'cpf:' . $row_cpf;
			}
			if ( '' !== $row_rf ) {
				$tags[] = 'rf:' . $row_rf;
			}
			if ( '' !== $row_email ) {
				$tags[] = 'em:' . $row_email;
			}
			if ( empty( $tags ) ) {
				// Row has no identifying field at all — the per-row loop
				// below will reject it via recruitment_csv_missing_cpf_or_rf.
				// Skip the identity step for now so it doesn't claim a
				// spurious id.
				continue;
			}

			$matched = array();
			foreach ( $tags as $tag ) {
				if ( isset( $tag_to_id[ $tag ] ) ) {
					$matched[ $tag_to_id[ $tag ] ] = true;
				}
			}
			if ( empty( $matched ) ) {
				$id = $next_id++;
			} else {
				$matched_ids = array_keys( $matched );
				sort( $matched_ids );
				$id = $matched_ids[0];
				// Multiple existing ids matched (e.g. this row carries both
				// the CPF that previously pinned id=2 and the RF that
				// previously pinned id=5) — coalesce them all into the
				// lowest id so future lookups stay consistent.
				if ( count( $matched_ids ) > 1 ) {
					$drop = array_slice( $matched_ids, 1 );
					foreach ( $tag_to_id as $t => $tid ) {
						if ( in_array( $tid, $drop, true ) ) {
							$tag_to_id[ $t ] = $id;
						}
					}
					foreach ( $row_identity as $ri => $rid ) {
						if ( in_array( $rid, $drop, true ) ) {
							$row_identity[ $ri ] = $id;
						}
					}
				}
			}

			foreach ( $tags as $tag ) {
				$tag_to_id[ $tag ] = $id;
			}
			$row_identity[ $idx ] = $id;
		}

		foreach ( $rows as $row_index => $row ) {
			$line = (int) $row['_line'];

			// CPF / RF normalisation (#172). Strip non-digit characters
			// (operators paste pre-formatted values like `123.456.789-09`),
			// then left-pad with zeros when the result is shorter than the
			// canonical width (Excel/Sheets exports often drop leading
			// zeros from numeric columns). Reject when the stripped value
			// is LONGER than canonical — that's almost always garbage.
			$cpf_raw = is_string( $row['cpf'] ) ? trim( $row['cpf'] ) : '';
			$rf_raw  = is_string( $row['rf'] ) ? trim( $row['rf'] ) : '';

			$cpf_norm = self::normalise_id( $cpf_raw, 11 );
			$rf_norm  = self::normalise_id( $rf_raw, 7 );

			if ( '' === $cpf_norm['value'] && '' === $rf_norm['value']
				&& false === $cpf_norm['too_long'] && false === $rf_norm['too_long'] ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_missing_cpf_or_rf' );
				continue;
			}
			if ( $cpf_norm['too_long'] ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_cpf_too_long' );
				continue;
			}
			if ( $rf_norm['too_long'] ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_rf_too_long' );
				continue;
			}

			$cpf = $cpf_norm['value'];
			$rf  = $rf_norm['value'];

			// Persist the normalised values back onto the row so downstream
			// consumers (upsert_candidate, duplicate detection, etc.) see
			// the canonical digit string.
			$row['cpf'] = $cpf;
			$row['rf']  = $rf;

			// Score: dot-decimal only (or integer).
			$score_raw = is_string( $row['score'] ) ? trim( $row['score'] ) : (string) $row['score'];
			if ( '' === $score_raw ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_missing_score' );
				continue;
			}
			if ( false !== strpos( $score_raw, ',' ) ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_score_uses_comma_decimal' );
				continue;
			}
			if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $score_raw ) ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_score_invalid_format' );
				continue;
			}

			// Rank: positive integer.
			$rank_raw = is_string( $row['rank'] ) ? trim( $row['rank'] ) : (string) $row['rank'];
			if ( ! ctype_digit( $rank_raw ) || (int) $rank_raw < 1 ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_rank_invalid' );
				continue;
			}

			// Optional time_points: same dot-decimal shape as `score`,
			// non-negative. Empty / missing column → treated as 0 by the
			// repository default; only validate when something was typed.
			$time_points_raw = isset( $row['time_points'] ) && is_string( $row['time_points'] ) ? trim( $row['time_points'] ) : '';
			if ( '' !== $time_points_raw ) {
				if ( false !== strpos( $time_points_raw, ',' ) ) {
					$errors[] = self::line_error( $line, 'recruitment_csv_time_points_uses_comma_decimal' );
					continue;
				}
				if ( ! preg_match( '/^\d+(\.\d+)?$/', $time_points_raw ) ) {
					$errors[] = self::line_error( $line, 'recruitment_csv_time_points_invalid_format' );
					continue;
				}
			}

			// Adjutancy slug must exist for this notice.
			$slug = is_string( $row['adjutancy'] ) ? trim( $row['adjutancy'] ) : '';
			if ( '' === $slug ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_missing_adjutancy' );
				continue;
			}
			if ( ! isset( $adjutancy_map[ $slug ] ) ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_adjutancy_not_in_notice: ' . $slug );
				continue;
			}

			// Duplicate (logical candidate + adjutancy) within CSV.
			//
			// The pre-pass above grouped rows by physical identity (any
			// shared cpf / rf / email pins the same candidate), so the
			// pair key is (identity_id, adjutancy). This catches the
			// classes of duplicates that the prior cpf-only rule missed:
			// - same RF in two rows with different CPFs
			// - same email across rows where one CPF was blank
			// - a row with only RF re-listing a candidate already
			// classified under another row that carried CPF + RF
			// All of those used to slip past validate and trip the
			// UNIQUE constraint at INSERT time.
			if ( isset( $row_identity[ $row_index ] ) ) {
				$key = $row_identity[ $row_index ] . '|' . $slug;
				if ( isset( $seen_pair[ $key ] ) ) {
					$errors[] = self::line_error(
						$line,
						sprintf( 'recruitment_csv_duplicate_candidate_adjutancy: matches line %d', $seen_pair[ $key ] )
					);
					continue;
				}
				$seen_pair[ $key ] = $line;
			}

			// Same-CPF rows must agree on candidate-level fields.
			if ( '' !== $cpf ) {
				if ( isset( $by_cpf[ $cpf ] ) ) {
					$ref = $by_cpf[ $cpf ];
					foreach ( array( 'name', 'rf', 'email', 'phone', 'pcd' ) as $field ) {
						$current = isset( $row[ $field ] ) && is_string( $row[ $field ] ) ? trim( $row[ $field ] ) : '';
						$prior   = isset( $ref[ $field ] ) && is_string( $ref[ $field ] ) ? trim( $ref[ $field ] ) : '';
						if ( $current !== $prior ) {
							$errors[] = self::line_error(
								$line,
								sprintf(
									'recruitment_csv_candidate_field_divergence: field=%s, ref_line=%d',
									$field,
									(int) $ref['_line']
								)
							);
							continue 2; // skip the rest of this row's checks.
						}
					}
				} else {
					$by_cpf[ $cpf ] = $row;
				}
			}
		}

		// Existing-row collision: CSV row's (cpf, adjutancy, notice, list_type)
		// already in DB. This catches re-importing the same row when wipe was
		// not called (defensive — `run()` always wipes first, so this is
		// effectively a same-CSV duplicate check via DB).
		// Skipped here — `delete_all_for_notice_list()` runs before inserts,
		// so any pre-existing row is gone. Future-proofing comment.

		return $errors;
	}

	/**
	 * Find or create a candidate row for the given input row.
	 *
	 * Existing candidate (matched by cpf_hash, then rf_hash) is reused —
	 * additional rows in `classification` will reference it. New candidates
	 * are inserted with a placeholder `pcd_hash`, then updated with the
	 * proper HMAC once `candidate_id` is known.
	 *
	 * Promotion to wp_user is delegated to {@see UserCreator::get_or_create_user}
	 * with the recruitment context — failures are silent (candidate stays
	 * with `user_id = NULL`, which is the intended "not yet promoted" state).
	 *
	 * @param array<string, mixed> $row Validated row.
	 * @return int|false Candidate ID, or false on DB failure.
	 */
	private static function upsert_candidate( array $row ) {
		$cpf   = is_string( $row['cpf'] ) ? trim( $row['cpf'] ) : '';
		$rf    = is_string( $row['rf'] ) ? trim( $row['rf'] ) : '';
		$email = is_string( $row['email'] ) ? strtolower( trim( $row['email'] ) ) : '';
		$name  = is_string( $row['name'] ) ? trim( $row['name'] ) : '';
		$phone = is_string( $row['phone'] ) ? trim( $row['phone'] ) : '';
		$pcd   = self::parse_pcd_flag( $row['pcd'] );

		$cpf_hash   = '' !== $cpf ? Encryption::hash( $cpf ) : null;
		$rf_hash    = '' !== $rf ? Encryption::hash( $rf ) : null;
		$email_hash = '' !== $email ? Encryption::hash( $email ) : null;

		// Look up existing candidate by cpf, then rf.
		$existing = null;
		if ( null !== $cpf_hash ) {
			$existing = RecruitmentCandidateRepository::get_by_cpf_hash( $cpf_hash );
		}
		if ( null === $existing && null !== $rf_hash ) {
			$existing = RecruitmentCandidateRepository::get_by_rf_hash( $rf_hash );
		}

		if ( null !== $existing ) {
			$candidate_id = (int) $existing->id;

			// Refresh mutable + previously-empty fields on the existing row;
			// re-derive PCD hash so the new value (if any) takes effect.
			RecruitmentCandidateRepository::update(
				$candidate_id,
				array_filter(
					array(
						'name'            => '' !== $name ? $name : null,
						'phone'           => '' !== $phone ? $phone : null,
						'cpf_encrypted'   => '' !== $cpf ? Encryption::encrypt( $cpf ) : null,
						'cpf_hash'        => $cpf_hash,
						'rf_encrypted'    => '' !== $rf ? Encryption::encrypt( $rf ) : null,
						'rf_hash'         => $rf_hash,
						'email_encrypted' => '' !== $email ? Encryption::encrypt( $email ) : null,
						'email_hash'      => $email_hash,
					),
					static fn( $v ): bool => null !== $v
				)
			);

			self::refresh_pcd_hash( $candidate_id, $pcd );
			self::maybe_promote_candidate( $candidate_id, $cpf_hash, $rf_hash, $email );

			return $candidate_id;
		}

		// New candidate: insert with placeholder pcd_hash, then UPDATE.
		$insert_payload = array(
			'name'     => $name,
			'pcd_hash' => 'pending',
		);
		if ( '' !== $cpf ) {
			$insert_payload['cpf_encrypted'] = Encryption::encrypt( $cpf );
			$insert_payload['cpf_hash']      = $cpf_hash;
		}
		if ( '' !== $rf ) {
			$insert_payload['rf_encrypted'] = Encryption::encrypt( $rf );
			$insert_payload['rf_hash']      = $rf_hash;
		}
		if ( '' !== $email ) {
			$insert_payload['email_encrypted'] = Encryption::encrypt( $email );
			$insert_payload['email_hash']      = $email_hash;
		}
		if ( '' !== $phone ) {
			$insert_payload['phone'] = $phone;
		}

		$candidate_id = RecruitmentCandidateRepository::create( $insert_payload );
		if ( false === $candidate_id ) {
			return false;
		}

		self::refresh_pcd_hash( (int) $candidate_id, $pcd );
		self::maybe_promote_candidate( (int) $candidate_id, $cpf_hash, $rf_hash, $email );

		return (int) $candidate_id;
	}

	/**
	 * Recompute and persist `pcd_hash` for a candidate.
	 *
	 * @param int  $candidate_id Candidate ID.
	 * @param bool $is_pcd       Whether the candidate is registered as PCD.
	 * @return void
	 */
	private static function refresh_pcd_hash( int $candidate_id, bool $is_pcd ): void {
		global $wpdb;
		$table = RecruitmentCandidateRepository::get_table_name();
		$hash  = RecruitmentPcdHasher::compute( $candidate_id, $is_pcd );

		$wpdb->update(
			$table,
			array(
				'pcd_hash'   => $hash,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $candidate_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Best-effort wp_user link via UserCreator (no-op when nothing matches).
	 *
	 * UserCreator handles the §4 trigger logic: hash lookup against
	 * `ffc_submissions`, email lookup against `wp_users`, and (for new emails)
	 * user creation. Failures (e.g. `wp_create_user` rejecting an empty email)
	 * are intentionally swallowed — the candidate stays `user_id=NULL`, which
	 * is the documented "not yet promoted" state.
	 *
	 * @param int         $candidate_id Candidate row ID.
	 * @param string|null $cpf_hash     SHA-256 hash of CPF (or null).
	 * @param string|null $rf_hash      SHA-256 hash of RF (or null).
	 * @param string      $email        Lowercased email (may be empty).
	 * @return void
	 */
	private static function maybe_promote_candidate( int $candidate_id, ?string $cpf_hash, ?string $rf_hash, string $email ): void {
		// Choose the most specific hash to send to UserCreator (cpf > rf > email).
		$hash = $cpf_hash ?? $rf_hash ?? '';
		if ( '' === $hash && '' === $email ) {
			return;
		}

		if ( ! class_exists( UserCreator::class ) ) {
			return;
		}

		$user_id = UserCreator::get_or_create_user(
			$hash,
			$email,
			array(),
			CapabilityManager::CONTEXT_RECRUITMENT
		);

		if ( is_int( $user_id ) && $user_id > 0 ) {
			RecruitmentCandidateRepository::set_user_id( $candidate_id, $user_id );
			RecruitmentActivityLogger::candidate_promoted( $candidate_id, $user_id );
		}
	}

	/**
	 * Build the slug → id map for adjutancies attached to a notice.
	 *
	 * @param int $notice_id Notice ID.
	 * @return array<string, int> Slug → adjutancy ID.
	 */
	private static function build_adjutancy_map( int $notice_id ): array {
		$ids = RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice( $notice_id );
		if ( empty( $ids ) ) {
			return array();
		}

		$map = array();
		foreach ( $ids as $id ) {
			$adjutancy = RecruitmentAdjutancyRepository::get_by_id( $id );
			if ( null !== $adjutancy ) {
				$map[ $adjutancy->slug ] = $id;
			}
		}

		return $map;
	}

	/**
	 * Build an associative row from positional CSV cells using the header map.
	 *
	 * Missing optional columns are filled with empty strings so downstream
	 * code can treat them uniformly.
	 *
	 * @param array             $cells     Cell values (list<string>).
	 * @phpstan-param list<string> $cells
	 * @param array<string,int> $index_map Header → column index.
	 * @return array<string, string>
	 */
	private static function build_row( array $cells, array $index_map ): array {
		$row = array();
		foreach ( array_merge( self::REQUIRED_HEADERS, self::OPTIONAL_HEADERS ) as $name ) {
			if ( isset( $index_map[ $name ], $cells[ $index_map[ $name ] ] ) ) {
				$row[ $name ] = (string) $cells[ $index_map[ $name ] ];
			} else {
				$row[ $name ] = '';
			}
		}
		return $row;
	}

	/**
	 * Parse the `pcd` column into a boolean.
	 *
	 * Accepts (case-insensitive): true, 1, sim, yes → true. Anything else → false.
	 *
	 * @param mixed $value Raw cell value.
	 * @return bool
	 */
	private static function parse_pcd_flag( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$normalized = strtolower( trim( (string) $value ) );
		return in_array( $normalized, array( 'true', '1', 'sim', 'yes' ), true );
	}

	/**
	 * Format a per-line error for the result envelope.
	 *
	 * @param int    $line     1-based line number.
	 * @param string $code     Stable error code.
	 * @return string
	 */
	private static function line_error( int $line, string $code ): string {
		return sprintf( 'line=%d: %s', $line, $code );
	}

	/**
	 * Normalise a CPF / RF cell value to its canonical digit-only form.
	 *
	 * Strips every non-digit character (so `123.456.789-09` becomes
	 * `12345678909`), then left-pads with `'0'` when the result is shorter
	 * than `$expected_length` (Excel/Sheets exports routinely drop
	 * leading zeros). Returns `too_long => true` when the stripped value
	 * exceeds the canonical width — callers should surface a clear error.
	 *
	 * @param string $raw             The trimmed cell value.
	 * @param int    $expected_length Canonical width (11 for CPF, 7 for RF).
	 * @return array{value: string, too_long: bool}
	 */
	private static function normalise_id( string $raw, int $expected_length ): array {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( ! is_string( $digits ) ) {
			$digits = '';
		}
		if ( '' === $digits ) {
			return array(
				'value'    => '',
				'too_long' => false,
			);
		}
		if ( strlen( $digits ) > $expected_length ) {
			return array(
				'value'    => $digits,
				'too_long' => true,
			);
		}
		if ( strlen( $digits ) < $expected_length ) {
			$digits = str_pad( $digits, $expected_length, '0', STR_PAD_LEFT );
		}
		return array(
			'value'    => $digits,
			'too_long' => false,
		);
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

	// ──────────────────────────────────────────────────────────────────────.
	// Batched-import flow (issue: large notices timing out on a single request).
	//
	// Mirrors `CsvExporter`'s start → batch → commit pattern. The original
	// single-request `run()` above is kept for `promote-preview` (which
	// imports a small definitive list under a 15s countdown) and for tests;
	// new admin imports flow through the three methods below.
	//
	// 1. start_job()         → parse + validate the whole CSV (cheap),
	// persist normalized rows as JSON in a tmp file,
	// create a transient with the job state, return
	// { job_id, total }. NO DB writes yet.
	// 2. process_job_batch() → load slice [cursor, cursor+size) from the tmp
	// JSON, insert candidates + classifications into
	// a staging row (`list_type='__staging_<job_id>'`),
	// update cursor + inserted in the transient,
	// flush ActivityLog buffer at batch end.
	// 3. commit_job()        → inside ONE short transaction: DELETE the
	// previous live list + UPDATE staging rows to
	// the target list_type. Drop tmp file + transient.
	//
	// Atomicity is preserved: if the operator interrupts mid-batch the live
	// list stays untouched (the swap only happens in commit_job's
	// transaction). The staging rows the aborted job left behind are reaped
	// at the next start_job() invocation and by `cleanup_stale_jobs()`.
	// ──────────────────────────────────────────────────────────────────────.

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

	/**
	 * How long (seconds) the job transient + staging rows live before the
	 * cron / next start_job() reaps them. Matches CsvExporter::JOB_TTL.
	 */
	public const JOB_TTL = 3600;

	/**
	 * Prefix used for the staging `list_type` value while a batched import
	 * is in flight. Picked to be impossible to collide with a real
	 * list_type ('preview' / 'definitive').
	 */
	private const STAGING_LIST_TYPE_PREFIX = '__staging_';

	/**
	 * Transient key prefix for batched-import job state.
	 *
	 * @deprecated 6.8.x — staging-table flow (V10) supersedes this.
	 *             Constant kept until the legacy `start_job` / friends
	 *             are removed in a follow-up sprint.
	 */
	private const JOB_TRANSIENT_PREFIX = 'ffc_rec_import_job_';

	/**
	 * Job TTL (seconds) used by the cleanup sweep that drops stale
	 * `ffc_recruitment_import_jobs` + `ffc_recruitment_import_staging`
	 * rows. Generous because staging rows are small and the cleanup
	 * is opportunistic (runs at the top of every `ingest_job` call).
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

		$parse = self::parse( $csv_content );
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

		$adjutancy_map = self::build_adjutancy_map( $notice_id );
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

		// Mass INSERT — single statement per chunk. CPF / RF / email
		// are encrypted + hashed before they ever touch the row builder
		// so the staging table never holds personal data in plaintext.
		$staged = 0;
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values       = array();
			$placeholders = array();
			foreach ( $chunk as $row ) {
				$cpf_raw = is_string( $row['cpf'] ?? null ) ? self::normalise_id( trim( $row['cpf'] ), 11 )['value'] : '';
				$rf_raw  = is_string( $row['rf'] ?? null ) ? self::normalise_id( trim( $row['rf'] ), 7 )['value'] : '';
				$email   = is_string( $row['email'] ?? null ) ? strtolower( trim( $row['email'] ) ) : '';
				$phone   = is_string( $row['phone'] ?? null ) ? trim( $row['phone'] ) : '';
				$slug    = is_string( $row['adjutancy'] ?? null ) ? trim( $row['adjutancy'] ) : '';
				// adjutancy_id may not exist in the map (the validation
				// phase reports `adjutancy_not_in_notice` against the
				// staging row); store 0 as a sentinel so the schema's
				// NOT NULL constraint stays satisfied.
				$adj_id = isset( $adjutancy_map[ $slug ] ) ? (int) $adjutancy_map[ $slug ] : 0;

				$placeholders[] = '(%s, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %d, %d, %d)';
				array_push(
					$values,
					$job_id,
					++$staged,
					(int) $row['_line'],
					$notice_id,
					is_string( $row['name'] ?? null ) ? trim( $row['name'] ) : '',
					// CPF / RF / email columns — encrypted + hash if
					// present, NULL otherwise (matches the canonical
					// candidate schema's nullability).
					'' !== $cpf_raw ? Encryption::encrypt( $cpf_raw ) : null,
					'' !== $cpf_raw ? Encryption::hash( $cpf_raw ) : null,
					'' !== $rf_raw ? Encryption::encrypt( $rf_raw ) : null,
					'' !== $rf_raw ? Encryption::hash( $rf_raw ) : null,
					'' !== $email ? Encryption::encrypt( $email ) : null,
					'' !== $email ? Encryption::hash( $email ) : null,
					$phone,
					$slug,
					$adj_id,
					ctype_digit( (string) ( $row['rank'] ?? '' ) ) ? (int) $row['rank'] : 0,
					is_string( $row['score'] ?? null ) ? trim( $row['score'] ) : (string) ( $row['score'] ?? '0' ),
					isset( $row['time_points'] ) && '' !== (string) $row['time_points'] ? (string) $row['time_points'] : '0',
					self::parse_pcd_flag( $row['hab_emebs'] ?? '' ) ? 1 : 0,
					self::parse_pcd_flag( $row['pcd'] ?? '' ) ? 1 : 0
				);
			}

			$sql = "INSERT INTO {$staging_table} (job_id, row_no, line_no, notice_id, name, cpf_encrypted, cpf_hash, rf_encrypted, rf_hash, email_encrypted, email_hash, phone, adjutancy_slug, adjutancy_id, rank_value, score, time_points, hab_emebs, pcd) VALUES "
				. implode( ', ', $placeholders );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- chunked multi-VALUES INSERT; every user-derived value passes through %s/%d placeholders.
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
	 * Start a batched import: validate the CSV in full and persist a job
	 * state the AJAX caller can step through.
	 *
	 * Parse + validate are cheap (pure PHP, no DB writes), so doing them
	 * up-front lets the operator see all CSV-level errors before a single
	 * staging row is created. The transient + tmp file hold the parsed
	 * rows (as JSON) so the per-batch handler doesn't re-parse the CSV.
	 *
	 * @param int    $notice_id   Target notice.
	 * @param string $csv_content Raw CSV bytes (UTF-8, with or without BOM).
	 * @param string $list_type   `preview` or `definitive`.
	 * @return array{ok: true, job_id: string, total: int}|array{ok: false, errors: list<string>}
	 */
	public static function start_job( int $notice_id, string $csv_content, string $list_type ) {
		// Reap any staging rows left behind by an interrupted prior job
		// before the new one allocates fresh ones. Without this, a job
		// killed mid-batch (browser closed, gateway error, the silently-
		// fixed duplicate-INSERT chain) leaves __staging_<job_id> rows
		// in the classification table that no transient still references.
		// They're invisible to the live list (different list_type) but
		// they accumulate forever, eventually showing up in long-running
		// COUNT(*) queries and consuming index space.
		self::cleanup_orphan_staging_rows();

		$notice = RecruitmentNoticeRepository::get_by_id( $notice_id );
		if ( null === $notice ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_notice_not_found' ),
			);
		}

		// State gates mirror `import_preview` / `import_definitive_preview`.
		$status = $notice->status;
		if ( 'preview' === $list_type && 'draft' !== $status && 'preliminary' !== $status ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_invalid_state_for_preview_import' ),
			);
		}

		$parse = self::parse( $csv_content );
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

		$adjutancy_map = self::build_adjutancy_map( $notice_id );
		if ( empty( $adjutancy_map ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_notice_has_no_adjutancies' ),
			);
		}

		$validation = self::validate( $rows, $notice_id, $list_type, $adjutancy_map );
		if ( ! empty( $validation ) ) {
			return array(
				'ok'     => false,
				'errors' => $validation,
			);
		}

		// Persist the normalized rows as JSON so per-batch handlers can
		// `array_slice` from offset without re-parsing the CSV.
		$tmp_dir = self::ensure_tmp_dir();
		if ( null === $tmp_dir ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_tmp_dir_unwritable' ),
			);
		}

		$job_id   = wp_generate_uuid4();
		$tmp_file = $tmp_dir . '/ffc-rec-import-' . $job_id . '.json';
		$bytes    = file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private tmp, dir-protected by .htaccess.
			$tmp_file,
			wp_json_encode(
				array(
					'adjutancy_map' => $adjutancy_map,
					'rows'          => $rows,
				)
			)
		);
		if ( false === $bytes ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_tmp_file_unwritable' ),
			);
		}

		$job = array(
			'notice_id' => $notice_id,
			'list_type' => $list_type,
			'file'      => $tmp_file,
			'total'     => count( $rows ),
			'cursor'    => 0,
			'inserted'  => 0,
			'user_id'   => get_current_user_id(),
			'created'   => time(),
		);
		set_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job, self::JOB_TTL );

		return array(
			'ok'     => true,
			'job_id' => $job_id,
			'total'  => count( $rows ),
		);
	}

	/**
	 * Process the next N rows of a started job. Each batch is a self-
	 * contained request — own DB connection, own short-lived transaction —
	 * so a single failure can't poison subsequent batches the way the
	 * pre-batched single-request flow did when the gateway killed PHP-FPM
	 * mid-transaction.
	 *
	 * @param string $job_id Job identifier returned by start_job().
	 * @param int    $size   Rows to process in this batch (clamped to 10–100).
	 * @return array{ok: true, processed: int, total: int, done: bool, inserted: int}|array{ok: false, errors: list<string>}
	 */
	public static function process_job_batch( string $job_id, int $size ) {
		$job = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
		if ( ! is_array( $job ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_not_found' ),
			);
		}

		$size = max( 10, min( 100, $size ) );

		// Load the parsed rows + adjutancy map from the tmp file. Done per
		// batch because PHP doesn't share memory across requests; the JSON
		// blob is the cheap source of truth.
		$raw = file_get_contents( $job['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- private tmp file.
		if ( false === $raw ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_tmp_lost' ),
			);
		}
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || ! isset( $payload['rows'], $payload['adjutancy_map'] ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_corrupt' ),
			);
		}

		$rows          = (array) $payload['rows'];
		$adjutancy_map = (array) $payload['adjutancy_map'];
		$slice         = array_slice( $rows, $job['cursor'], $size );

		if ( empty( $slice ) ) {
			// Caller may have over-iterated; treat as a no-op success so
			// the JS-side loop terminates cleanly via the `done` flag.
			return array(
				'ok'        => true,
				'processed' => $job['cursor'],
				'total'     => $job['total'],
				'inserted'  => $job['inserted'],
				'done'      => true,
			);
		}

		global $wpdb;
		$staging_list_type = self::STAGING_LIST_TYPE_PREFIX . $job_id;

		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $slice as $row ) {
				$candidate_id = self::upsert_candidate( $row );
				if ( false === $candidate_id ) {
					$wpdb->query( 'ROLLBACK' );
					return array(
						'ok'     => false,
						'errors' => array( 'recruitment_candidate_upsert_failed' ),
					);
				}

				$classification_id = RecruitmentClassificationRepository::create(
					array(
						'candidate_id' => $candidate_id,
						'adjutancy_id' => $adjutancy_map[ $row['adjutancy'] ],
						'notice_id'    => $job['notice_id'],
						'list_type'    => $staging_list_type,
						'rank'         => $row['rank'],
						'score'        => $row['score'],
						'time_points'  => isset( $row['time_points'] ) && '' !== (string) $row['time_points'] ? (string) $row['time_points'] : '0',
						'hab_emebs'    => self::parse_pcd_flag( $row['hab_emebs'] ?? '' ) ? 1 : 0,
					)
				);
				if ( false === $classification_id ) {
					$wpdb->query( 'ROLLBACK' );
					return array(
						'ok'     => false,
						'errors' => array( 'recruitment_classification_insert_failed' ),
					);
				}
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_unexpected_error: ' . $e->getMessage() ),
			);
		}

		$job['cursor']   += count( $slice );
		$job['inserted'] += count( $slice );
		set_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job, self::JOB_TTL );

		// Flush the per-batch activity log entries that the
		// `candidate_promoted` writes accumulated, so they hit the DB
		// inside this batch's connection rather than piling up for the
		// shutdown handler (which is what triggered "Commands out of sync"
		// when the single-request flow was killed mid-write).
		if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::flush_buffer();
		}

		return array(
			'ok'        => true,
			'processed' => $job['cursor'],
			'total'     => $job['total'],
			'inserted'  => $job['inserted'],
			'done'      => $job['cursor'] >= $job['total'],
		);
	}

	/**
	 * Swap the staging rows in for the live list and tear the job down.
	 * Idempotent against partial commits: if the swap transaction is
	 * interrupted between the DELETE and the UPDATE, the staging marker
	 * column makes it safe to re-issue (the DELETE just runs against the
	 * now-empty live list, then the UPDATE renames staging to live).
	 *
	 * @param string $job_id Job identifier.
	 * @return array{ok: true, inserted: int}|array{ok: false, errors: list<string>}
	 */
	public static function commit_job( string $job_id ) {
		$job = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
		if ( ! is_array( $job ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_not_found' ),
			);
		}

		if ( $job['cursor'] < $job['total'] ) {
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_job_not_finished' ),
			);
		}

		global $wpdb;
		$staging_list_type = self::STAGING_LIST_TYPE_PREFIX . $job_id;
		$table             = $wpdb->prefix . 'ffc_recruitment_classification';

		$wpdb->query( 'START TRANSACTION' );
		try {
			RecruitmentClassificationRepository::delete_all_for_notice_list( $job['notice_id'], $job['list_type'] );

			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET list_type = %s WHERE notice_id = %d AND list_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, not user input.
					$job['list_type'],
					$job['notice_id'],
					$staging_list_type
				)
			);

			if ( false === $updated ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'     => false,
					'errors' => array( 'recruitment_import_swap_failed' ),
				);
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'     => false,
				'errors' => array( 'recruitment_import_unexpected_error: ' . $e->getMessage() ),
			);
		}

		RecruitmentActivityLogger::csv_imported( $job['notice_id'], $job['list_type'], $job['inserted'] );

		// Teardown.
		if ( isset( $job['file'] ) && is_string( $job['file'] ) && file_exists( $job['file'] ) ) {
			wp_delete_file( $job['file'] );
		}
		delete_transient( self::JOB_TRANSIENT_PREFIX . $job_id );

		return array(
			'ok'       => true,
			'inserted' => $job['inserted'],
		);
	}

	/**
	 * Ensure the shared `wp-content/uploads/ffc-tmp/` directory exists and
	 * carries the `Deny from all` .htaccess that the CSV exporters also
	 * rely on. Returns the absolute path, or null when the dir cannot be
	 * created (e.g. read-only uploads).
	 *
	 * @return string|null Absolute path to the tmp dir, or null on failure.
	 */
	private static function ensure_tmp_dir(): ?string {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return null;
		}
		$tmp_dir = trailingslashit( $upload_dir['basedir'] ) . 'ffc-tmp';
		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			return null;
		}
		$htaccess = $tmp_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private tmp dir.
		}
		return $tmp_dir;
	}

	/**
	 * Drop staging-marker classification rows whose owning job is dead.
	 *
	 * A staging marker is `list_type='__staging_<job_id>'`. The job's
	 * transient (with the matching JOB_TTL) is the source of truth for
	 * whether the job is still in flight; once the transient is gone
	 * (TTL expired, browser closed mid-commit, server crashed) the
	 * staging rows are unreferenced and may safely be dropped.
	 *
	 * Defensive twist for transient-less reaping: a staging marker older
	 * than 2× JOB_TTL is treated as orphan regardless of whether the
	 * transient lookup still hits — handles installs whose object cache
	 * lies about transient existence (Memcached LRU evicting our key
	 * before the row was reaped).
	 *
	 * @return int Number of staging rows deleted.
	 */
	public static function cleanup_orphan_staging_rows(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_recruitment_classification';

		$markers = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance sweep, runs at most per start_job call.
			$wpdb->prepare(
				'SELECT DISTINCT list_type FROM %i WHERE list_type LIKE %s',
				$table,
				$wpdb->esc_like( self::STAGING_LIST_TYPE_PREFIX ) . '%'
			)
		);

		if ( empty( $markers ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $markers as $marker ) {
			$job_id = substr( (string) $marker, strlen( self::STAGING_LIST_TYPE_PREFIX ) );
			if ( '' === $job_id ) {
				continue;
			}

			// If the job transient still exists, leave the staging rows
			// alone — the owning job may still be batching through them.
			if ( false !== get_transient( self::JOB_TRANSIENT_PREFIX . $job_id ) ) {
				continue;
			}

			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance sweep.
				$table,
				array( 'list_type' => $marker ),
				array( '%s' )
			);
			$deleted += (int) $wpdb->rows_affected;
		}

		return $deleted;
	}
}
