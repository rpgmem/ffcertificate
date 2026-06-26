<?php
/**
 * Recruitment CSV Validator
 *
 * Pure row-validation layer extracted from {@see RecruitmentCsvImporter}
 * (#563 Sprint 6, PR 6b). Holds the §6 rule set that turns parsed rows into
 * a list of line-numbered error codes: CPF/RF presence + width, score and
 * time-points format, rank, adjutancy membership, and the cross-row
 * physical-candidate duplicate / field-divergence detection.
 *
 * Stateless and side-effect-free — it reads the parsed rows and the
 * notice's adjutancy map (built by the importer) and returns errors; it
 * never touches the database or WordPress globals.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless validation helpers for recruitment CSV imports.
 */
final class CsvValidator {

	/**
	 * Validate every row against the §6 rules.
	 *
	 * @param list<array<string, mixed>> $rows Parsed rows.
	 * @param int                        $notice_id Target notice.
	 * @param string                     $list_type `preview` or `definitive`.
	 * @param array<string, int>         $adjutancy_map Slug → id for the notice.
	 * @return list<string> Validation errors (empty on success).
	 */
	public static function validate( array $rows, int $notice_id, string $list_type, array $adjutancy_map ): array {
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
			$row_cpf   = is_string( $row['cpf'] ?? null ) ? CsvParser::normalise_id( trim( $row['cpf'] ), 11 )['value'] : '';
			$row_rf    = is_string( $row['rf'] ?? null ) ? CsvParser::normalise_id( trim( $row['rf'] ), 7 )['value'] : '';
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

			$cpf_norm = CsvParser::normalise_id( $cpf_raw, 11 );
			$rf_norm  = CsvParser::normalise_id( $rf_raw, 7 );

			// An empty normalised value already implies too_long === false
			// (normalise_id only sets too_long when the digit string is
			// non-empty and over-length), so checking value emptiness alone
			// captures the "no identifier supplied" case.
			if ( '' === $cpf_norm['value'] && '' === $rf_norm['value'] ) {
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
	 * Format a per-line error for the result envelope.
	 *
	 * Shared by both this validator and the staging ingest path
	 * ({@see RecruitmentCsvImporter::validate_job()}).
	 *
	 * @param int    $line     1-based line number.
	 * @param string $code     Stable error code.
	 * @return string
	 */
	public static function line_error( int $line, string $code ): string {
		return sprintf( 'line=%d: %s', $line, $code );
	}
}
