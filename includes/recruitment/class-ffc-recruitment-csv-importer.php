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
	private const OPTIONAL_HEADERS = array( 'phone' );

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
		$content = self::strip_utf8_bom( $content );
		if ( '' === trim( $content ) ) {
			return array(
				'ok'     => false,
				'rows'   => array(),
				'errors' => array( 'recruitment_csv_empty' ),
			);
		}

		$lines = preg_split( "/\r\n|\n|\r/", $content );
		if ( ! is_array( $lines ) ) {
			return array(
				'ok'     => false,
				'rows'   => array(),
				'errors' => array( 'recruitment_csv_unparseable' ),
			);
		}

		// Header row. Auto-detect delimiter from the header line: support
		// both comma and semicolon variants (the latter is the default in
		// many BR/EU spreadsheet exports). Detection is by occurrence
		// count on the header — semicolons win iff there are more `;`
		// than `,` outside of quoted segments.
		$header_line = (string) array_shift( $lines );
		$delimiter   = self::detect_delimiter( $header_line );
		$headers     = self::parse_csv_line( $header_line, $delimiter );
		$headers     = array_map( 'strtolower', array_map( 'trim', $headers ) );

		$missing = array_diff( self::REQUIRED_HEADERS, $headers );
		if ( ! empty( $missing ) ) {
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
		$line_number = 1; // 1-based; header was line 1.
		foreach ( $lines as $line ) {
			++$line_number;
			$line = (string) $line;

			if ( '' === trim( $line ) ) {
				continue; // empty rows skipped per §6.
			}

			$cells = self::parse_csv_line( $line, $delimiter );

			// Skip rows that are all whitespace after parsing.
			$any_value = false;
			foreach ( $cells as $cell ) {
				if ( '' !== trim( $cell ) ) {
					$any_value = true;
					break;
				}
			}
			if ( ! $any_value ) {
				continue;
			}

			$row          = self::build_row( $cells, $index_map );
			$row['_line'] = $line_number;
			$rows[]       = $row;
		}

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
		$seen_pair = array(); // "cpf|adjutancy" → line number, for duplicate detection.

		foreach ( $rows as $row ) {
			$line = (int) $row['_line'];

			// CPF / RF presence + digits-only.
			$cpf = is_string( $row['cpf'] ) ? trim( $row['cpf'] ) : '';
			$rf  = is_string( $row['rf'] ) ? trim( $row['rf'] ) : '';
			if ( '' === $cpf && '' === $rf ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_missing_cpf_or_rf' );
				continue;
			}
			if ( '' !== $cpf && ! ctype_digit( $cpf ) ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_cpf_must_be_digits_only' );
				continue;
			}
			if ( '' !== $rf && ! ctype_digit( $rf ) ) {
				$errors[] = self::line_error( $line, 'recruitment_csv_rf_must_be_digits_only' );
				continue;
			}

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

			// Duplicate (cpf + adjutancy) within CSV.
			if ( '' !== $cpf ) {
				$key = $cpf . '|' . $slug;
				if ( isset( $seen_pair[ $key ] ) ) {
					$errors[] = self::line_error(
						$line,
						sprintf( 'recruitment_csv_duplicate_cpf_adjutancy: matches line %d', $seen_pair[ $key ] )
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
	 * Strip a UTF-8 BOM if present.
	 *
	 * @param string $content Raw bytes.
	 * @return string Content with BOM removed.
	 */
	private static function strip_utf8_bom( string $content ): string {
		if ( 0 === strncmp( $content, "\xEF\xBB\xBF", 3 ) ) {
			return substr( $content, 3 );
		}
		return $content;
	}

	/**
	 * Parse a single CSV line using `str_getcsv`. The delimiter is detected
	 * once from the header line (see {@see self::detect_delimiter()}) and
	 * then forwarded to every subsequent body line so a mixed-quoting file
	 * still parses consistently.
	 *
	 * @param string $line      CSV line.
	 * @param string $delimiter Field delimiter (`,` or `;`).
	 * @return list<string>
	 */
	private static function parse_csv_line( string $line, string $delimiter = ',' ): array {
		$cells = str_getcsv( $line, $delimiter );
		// Coerce nulls (str_getcsv returns null for missing cells in some versions) to empty strings.
		return array_map(
			static function ( $cell ): string {
				return is_string( $cell ) ? $cell : '';
			},
			$cells
		);
	}

	/**
	 * Detect the CSV field delimiter by inspecting the header line.
	 *
	 * Supports `,` (default) and `;` (common in BR/EU spreadsheet exports
	 * where comma is the locale decimal separator). Counts occurrences
	 * outside of double-quoted segments so a quoted comma inside a header
	 * label doesn't tip the detection. Ties resolve to `,` for backward
	 * compatibility with files that worked pre-detection.
	 *
	 * @param string $header_line The CSV header line.
	 * @return string `,` or `;`.
	 */
	private static function detect_delimiter( string $header_line ): string {
		$comma_count     = 0;
		$semicolon_count = 0;
		$in_quotes       = false;
		$length          = strlen( $header_line );
		for ( $i = 0; $i < $length; $i++ ) {
			$ch = $header_line[ $i ];
			if ( '"' === $ch ) {
				$in_quotes = ! $in_quotes;
				continue;
			}
			if ( $in_quotes ) {
				continue;
			}
			if ( ',' === $ch ) {
				++$comma_count;
			} elseif ( ';' === $ch ) {
				++$semicolon_count;
			}
		}
		return $semicolon_count > $comma_count ? ';' : ',';
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
}
