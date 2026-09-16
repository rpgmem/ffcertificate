<?php
/**
 * Reregistration CSV import — the ingest phase (#1214).
 *
 * Stages a spreadsheet of a campaign's answers so the later phases can work
 * from the database instead of the file. The shape is recruitment's
 * (`CsvStagingService`): ingest → validate → promote → commit, a job header
 * beside the staged rows, a TTL, and a chunked mass INSERT.
 *
 * **What it does NOT copy is the staged row itself.** Recruitment stages into
 * typed columns because its domain fixes them; here the columns are rows of
 * `ffc_custom_fields` keyed by `audience_id` and defined per audience by the
 * operator, so the row is staged as JSON with only the columns validation
 * queries on lifted out beside it.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.26.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\Csv;
use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\UserDashboard\UserManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Staging writes against the plugin's own ffc_* tables; WordPress exposes no API for a chunked multi-row INSERT and there is nothing to cache on an import path.
/**
 * Stages a reregistration import CSV into `ffc_reregistration_import_staging`.
 */
class ReregistrationImportStagingService {

	/**
	 * How long an abandoned job survives before the opportunistic sweep takes
	 * it. One day, matching the recruitment importer: long enough that a
	 * validate the operator left open over lunch still promotes, short enough
	 * that cleartext identifiers do not linger.
	 *
	 * @var int
	 */
	private const STAGING_JOB_TTL_SECONDS = 86400;

	/**
	 * Rows per INSERT. 200 is the recruitment importer's figure, chosen to
	 * stay clear of `max_allowed_packet` on default MySQL configs — and the
	 * payload here is a JSON blob per row, so the same ceiling binds sooner.
	 *
	 * @var int
	 */
	private const INSERT_CHUNK = 200;

	/**
	 * Resolve a CSV header against one audience's field definitions.
	 *
	 * **Matching is exact, on `field_key` first and `field_label` second**,
	 * after trimming and case-folding — an operator who exported the campaign
	 * gets labels, one who read the docs writes keys, and both work. Nothing
	 * fuzzy: a near-match on a mistyped column would write the wrong field
	 * silently, which is worse than reporting the column as unknown.
	 *
	 * A column that matches nothing is **ignored and reported** (#1214): the
	 * spreadsheet an operator maintains carries notes and working columns that
	 * are none of our business, and refusing the file over one of them would
	 * make the import unusable for the people it is for.
	 *
	 * @param array<int, string> $header      The CSV's first row.
	 * @param int                $audience_id Audience whose fields define the columns.
	 * @return array{mapped: array<int, string>, ignored: list<string>, missing_required: list<string>}
	 */
	public static function map_header( array $header, int $audience_id ): array {
		$fields = CustomFieldReader::get_by_audience_with_parents( $audience_id, true );

		$by_key   = array();
		$by_label = array();
		$required = array();
		foreach ( $fields as $field ) {
			$key                          = (string) $field->field_key;
			$by_key[ self::fold( $key ) ] = $key;
			$label                        = self::fold( (string) $field->field_label );
			if ( '' !== $label && ! isset( $by_label[ $label ] ) ) {
				$by_label[ $label ] = $key;
			}
			if ( ! empty( $field->is_required ) ) {
				$required[ $key ] = true;
			}
		}

		$mapped  = array();
		$ignored = array();
		foreach ( $header as $index => $column ) {
			$folded = self::fold( (string) $column );
			if ( '' === $folded ) {
				continue;
			}
			$key = $by_key[ $folded ] ?? $by_label[ $folded ] ?? null;
			if ( null === $key || in_array( $key, $mapped, true ) ) {
				// An unmapped column, or a second column claiming a field a
				// previous one already took — the first wins, the duplicate is
				// reported like any other column we cannot place.
				$ignored[] = (string) $column;
				continue;
			}
			$mapped[ (int) $index ] = $key;
		}

		$missing_required = array_values( array_diff( array_keys( $required ), $mapped ) );

		return array(
			'mapped'           => $mapped,
			'ignored'          => $ignored,
			'missing_required' => $missing_required,
		);
	}

	/**
	 * Stage a CSV against one audience of one campaign.
	 *
	 * **One import is scoped to one audience**, because the field set lives in
	 * `ffc_custom_fields` keyed by `audience_id`: fixing the audience is what
	 * makes the header→`field_key` map determinate. A campaign reaching several
	 * audiences takes several imports.
	 *
	 * **A required field with no column at all refuses the file here**, before
	 * anything is staged. That is not the same check as the one the validate
	 * phase makes: an absent *column* means every row would fail, so answering
	 * in one line beats staging five thousand rows to say it five thousand
	 * times. An absent *cell* in a row that has the column is data, and belongs
	 * to validate — where, by #1214's decision, it blocks the whole job rather
	 * than dropping its row.
	 *
	 * @param int    $reregistration_id Campaign the import belongs to.
	 * @param int    $audience_id       Audience whose fields the CSV carries.
	 * @param string $csv_content       Raw CSV bytes (UTF-8, BOM tolerated).
	 * @param int    $user_id           Operator who owns the job.
	 * @return array{ok: true, job_id: string, total: int, mapped: array<int, string>, ignored: list<string>}|array{ok: false, errors: list<string>, missing_required?: list<string>}
	 */
	public static function ingest_job( int $reregistration_id, int $audience_id, string $csv_content, int $user_id ) {
		$audience_ids = ReregistrationRepository::get_audience_ids( $reregistration_id );
		if ( ! in_array( $audience_id, array_map( 'intval', $audience_ids ), true ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'rereg_import_audience_not_in_campaign' ),
			);
		}

		$reader = Csv::reader_from_string( $csv_content );
		$header = $reader->header();
		if ( array() === $header ) {
			$reader->close();
			return array(
				'ok'     => false,
				'errors' => array( 'rereg_import_csv_empty' ),
			);
		}

		$map = self::map_header( $header, $audience_id );
		if ( array() === $map['mapped'] ) {
			$reader->close();
			return array(
				'ok'     => false,
				'errors' => array( 'rereg_import_no_column_matched' ),
			);
		}
		if ( array() !== $map['missing_required'] ) {
			$reader->close();
			return array(
				'ok'               => false,
				'errors'           => array( 'rereg_import_required_column_absent' ),
				'missing_required' => $map['missing_required'],
			);
		}

		$rows = array();
		$reader->each(
			static function ( array $row ) use ( &$rows, $map ): void {
				$payload = array();
				foreach ( $map['mapped'] as $index => $field_key ) {
					$payload[ $field_key ] = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
				}
				$rows[] = $payload;
			}
		);
		$reader->close();

		if ( array() === $rows ) {
			return array(
				'ok'     => false,
				'errors' => array( 'rereg_import_csv_has_no_rows' ),
			);
		}

		self::cleanup_stale_jobs();

		$job_id = wp_generate_uuid4();
		if ( ! self::insert_job( $job_id, $reregistration_id, $audience_id, $user_id, count( $rows ) ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'rereg_import_job_insert_failed' ),
			);
		}

		if ( ! self::insert_rows( $job_id, $reregistration_id, $audience_id, $rows ) ) {
			self::delete_job( $job_id );
			return array(
				'ok'     => false,
				'errors' => array( 'rereg_import_staging_insert_failed' ),
			);
		}

		return array(
			'ok'      => true,
			'job_id'  => $job_id,
			'total'   => count( $rows ),
			'mapped'  => $map['mapped'],
			'ignored' => $map['ignored'],
		);
	}


	/**
	 * Resolve a staged row to an existing user, creating nothing.
	 *
	 * Thin wrapper over `UserManager::resolve_existing_user()`, which is the
	 * read-only sibling of the resolver promotion will use. Keeping the lookup
	 * THERE rather than here is what makes the two impossible to drift apart —
	 * and the module-boundary guard is what surfaced it: reimplementing the
	 * query here opened a `Reregistration → Repositories` edge that did not
	 * exist, which was the smell before it was an argument.
	 *
	 * @param string $cpf_normalized Digits-only CPF, or ''.
	 * @param string $rf_normalized  Digits-only RF, or ''.
	 * @param string $email          Lowercased e-mail, or ''.
	 * @return int User id, or 0 when promotion would create one.
	 */
	public static function resolve_existing_user( string $cpf_normalized, string $rf_normalized, string $email ): int {
		$cpf_hash = '' !== $cpf_normalized ? Encryption::hash( $cpf_normalized ) : null;
		$rf_hash  = '' !== $rf_normalized ? Encryption::hash( $rf_normalized ) : null;

		return UserManager::resolve_existing_user( $cpf_hash, $rf_hash, $email );
	}

	/**
	 * Validate every staged row of a job and decide whether it may promote.
	 *
	 * **All or nothing, and the boundary is here (#1214).** Promotion is batched
	 * across requests precisely because of timeouts, so a failure in batch five
	 * cannot undo what batches one to four wrote. The only place the guarantee
	 * can hold is this one: validate everything, and refuse to promote at all if
	 * anything failed. That is what the stage-all → validate → promote shape
	 * exists for.
	 *
	 * So a row outcome is one of three, and only the middle one is tolerated:
	 *
	 * - `ready` — will be written.
	 * - `skipped` — the person has already submitted. **Expected state, not a
	 *   defect in the spreadsheet**, so it does not block: the operator's file
	 *   simply contains someone who got there first, and their own answers are
	 *   theirs to keep (#1214).
	 * - `failed` — anything else. Blocks the whole job.
	 *
	 * @param string $job_id Job UUID from {@see self::ingest_job()}.
	 * @return array{ok: bool, status: string, total: int, ready: int, skipped: int, failed: int, failures: list<array{line: int, error: string}>}|array{ok: false, errors: list<string>}
	 */
	public static function validate_job( string $job_id ) {
		global $wpdb;

		$job = self::get_job( $job_id );
		if ( null === $job ) {
			return array(
				'ok'     => false,
				'errors' => array( 'rereg_import_job_not_found' ),
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, row_no, line_no, payload, cpf_normalized, rf_normalized, email FROM %i WHERE job_id = %s ORDER BY row_no ASC',
				self::staging_table(),
				$job_id
			)
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			return array(
				'ok'     => false,
				'errors' => array( 'rereg_import_job_has_no_rows' ),
			);
		}

		$fields = array();
		foreach ( CustomFieldReader::get_by_audience_with_parents( (int) $job->audience_id, true ) as $field ) {
			$fields[ (string) $field->field_key ] = $field;
		}

		$counts    = array(
			'ready'   => 0,
			'skipped' => 0,
			'failed'  => 0,
		);
		$failures  = array();
		$seen_user = array();

		foreach ( $rows as $row ) {
			$line    = (int) $row->line_no;
			$payload = json_decode( (string) $row->payload, true );
			$payload = is_array( $payload ) ? $payload : array();

			$user_id       = self::resolve_existing_user( (string) $row->cpf_normalized, (string) $row->rf_normalized, (string) $row->email );
			$submission_id = 0;
			$status        = 'ready';
			$error         = '';

			// Two rows resolving to one account is certainly wrong, and it is
			// how a shared institutional mailbox presents: e-mail matching binds
			// every one of them to whichever account that address hits (#1295).
			// Catching it here means the operator sees it before a single write.
			if ( 0 !== $user_id && isset( $seen_user[ $user_id ] ) ) {
				$status = 'failed';
				$error  = sprintf( 'rereg_import_duplicate_identity:%d', $seen_user[ $user_id ] );
			} elseif ( 0 === $user_id && '' === (string) $row->email ) {
				// No identifier matched and no e-mail to create an account
				// from. `wp_create_user` would reject the empty address, so
				// promotion cannot succeed for this row.
				$status = 'failed';
				$error  = 'rereg_import_no_identifier';
			}

			if ( 'ready' === $status && 0 !== $user_id ) {
				$submission = ReregistrationSubmissionReader::get_by_reregistration_and_user( (int) $job->reregistration_id, $user_id );
				if ( null !== $submission ) {
					$submission_id = (int) $submission->id;
					if ( ! in_array( (string) $submission->status, array( 'pending', 'draft' ), true ) ) {
						$status = 'skipped';
						$error  = sprintf( 'rereg_import_already_submitted:%s', (string) $submission->status );
					}
				}
			}

			if ( 'ready' === $status ) {
				foreach ( $fields as $key => $field ) {
					$check = CustomFieldReader::validate_field_value( $field, $payload[ $key ] ?? '' );
					if ( is_wp_error( $check ) ) {
						$status = 'failed';
						$error  = sprintf( '%s:%s', (string) $check->get_error_code(), $key );
						break;
					}
				}
			}

			if ( 0 !== $user_id && 'failed' !== $status ) {
				$seen_user[ $user_id ] = $line;
			}

			++$counts[ $status ];
			if ( 'failed' === $status ) {
				$failures[] = array(
					'line'  => $line,
					'error' => $error,
				);
			}

			$wpdb->update(
				self::staging_table(),
				array(
					'user_id'       => $user_id,
					'submission_id' => $submission_id,
					'row_status'    => $status,
					'error'         => '' !== $error ? $error : null,
				),
				array( 'id' => (int) $row->id ),
				array( '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);
		}

		$blocked = $counts['failed'] > 0;
		$status  = $blocked ? 'blocked' : 'validated';
		self::set_job_status( $job_id, $status );

		return array(
			'ok'       => ! $blocked,
			'status'   => $status,
			'total'    => count( $rows ),
			'ready'    => $counts['ready'],
			'skipped'  => $counts['skipped'],
			'failed'   => $counts['failed'],
			'failures' => $failures,
		);
	}

	/**
	 * Read a job header.
	 *
	 * @param string $job_id Job UUID.
	 * @return object|null
	 */
	public static function get_job( string $job_id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %s LIMIT 1', self::jobs_table(), $job_id )
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Move a job to a new phase.
	 *
	 * @param string $job_id Job UUID.
	 * @param string $status New status.
	 * @return void
	 */
	private static function set_job_status( string $job_id, string $status ): void {
		global $wpdb;

		$wpdb->update(
			self::jobs_table(),
			array(
				'status'     => $status,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'job_id' => $job_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * The staging table name.
	 *
	 * @return string
	 */
	public static function staging_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_reregistration_import_staging';
	}

	/**
	 * The job header table name.
	 *
	 * @return string
	 */
	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_reregistration_import_jobs';
	}

	/**
	 * Case-fold and trim a header cell or field name for comparison.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function fold( string $value ): string {
		return strtolower( trim( $value ) );
	}

	/**
	 * Write the job header.
	 *
	 * @param string $job_id            Job UUID.
	 * @param int    $reregistration_id Campaign id.
	 * @param int    $audience_id       Audience id.
	 * @param int    $user_id           Owner.
	 * @param int    $total             Row count.
	 * @return bool
	 */
	private static function insert_job( string $job_id, int $reregistration_id, int $audience_id, int $user_id, int $total ): bool {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->insert(
			self::jobs_table(),
			array(
				'job_id'            => $job_id,
				'reregistration_id' => $reregistration_id,
				'audience_id'       => $audience_id,
				'status'            => 'ingested',
				'total'             => $total,
				'processed_count'   => 0,
				'user_id'           => $user_id,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Mass-insert the staged rows, in chunks.
	 *
	 * `row_no` is the 1-based position among the body rows and `line_no` the
	 * 1-based line in the file — they differ by the header, and the operator
	 * reads the second one when opening the spreadsheet.
	 *
	 * @param string                      $job_id            Job UUID.
	 * @param int                         $reregistration_id Campaign id.
	 * @param int                         $audience_id       Audience id.
	 * @param list<array<string, string>> $rows              Mapped payloads.
	 * @return bool
	 */
	private static function insert_rows( string $job_id, int $reregistration_id, int $audience_id, array $rows ): bool {
		global $wpdb;

		$table  = self::staging_table();
		$row_no = 0;

		foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $payload ) {
				++$row_no;

				$cpf   = DataSanitizer::normalize_cpf_rf( (string) ( $payload['cpf'] ?? '' ) );
				$rf    = DataSanitizer::normalize_cpf_rf( (string) ( $payload['rf'] ?? '' ) );
				$email = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );

				$placeholders[] = '(%s, %d, %d, %d, %d, %s, %s, %s, %s, %s)';
				$values[]       = $job_id;
				$values[]       = $row_no;
				$values[]       = $row_no + 1;
				$values[]       = $reregistration_id;
				$values[]       = $audience_id;
				$values[]       = (string) wp_json_encode( $payload );
				$values[]       = substr( $cpf, 0, 11 );
				$values[]       = substr( $rf, 0, 7 );
				$values[]       = substr( $email, 0, 255 );
				$values[]       = 'staged';
			}

			$sql = "INSERT INTO {$table} (job_id, row_no, line_no, reregistration_id, audience_id, payload, cpf_normalized, rf_normalized, email, row_status) VALUES " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				. implode( ', ', $placeholders );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Chunked multi-VALUES INSERT; the table comes from $wpdb->prefix and every user-derived value passes through a %s/%d placeholder.
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $values ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete a job and its staged rows.
	 *
	 * @param string $job_id Job UUID.
	 * @return void
	 */
	private static function delete_job( string $job_id ): void {
		global $wpdb;
		$wpdb->delete( self::staging_table(), array( 'job_id' => $job_id ), array( '%s' ) );
		$wpdb->delete( self::jobs_table(), array( 'job_id' => $job_id ), array( '%s' ) );
	}

	/**
	 * Drop jobs older than the TTL, rows first.
	 *
	 * Opportunistic, on the ingest path, for the reason recruitment does the
	 * same: an abandoned job holds cleartext identifiers, and no cron is
	 * guaranteed to run on a site that imports once a year.
	 *
	 * @return void
	 */
	private static function cleanup_stale_jobs(): void {
		global $wpdb;

		$jobs    = self::jobs_table();
		$staging = self::staging_table();

		$wpdb->query(
			$wpdb->prepare(
				"DELETE s FROM {$staging} s INNER JOIN {$jobs} j ON s.job_id = j.job_id WHERE j.created_at < ( UTC_TIMESTAMP() - INTERVAL %d SECOND )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
				self::STAGING_JOB_TTL_SECONDS
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$jobs} WHERE created_at < ( UTC_TIMESTAMP() - INTERVAL %d SECOND )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				self::STAGING_JOB_TTL_SECONDS
			)
		);
	}
}
