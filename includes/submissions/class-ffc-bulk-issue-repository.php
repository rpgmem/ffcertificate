<?php
/**
 * BulkIssueRepository
 *
 * Staging storage of the bulk-issue import (#1945). Every personal value of a
 * file row is encrypted before it reaches the table, and the CPF/RF is kept as
 * the same salted hash submissions use, so the duplicate check needs no
 * plaintext. A job whose rows are gone holds only counters and dates.
 *
 * @package FreeFormCertificate\Submissions
 * @since 6.43.0
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the bulk-issue staging tables (ffc_bulk_issue_*), which are the plugin's own and which WordPress exposes no API for. The staging rows are read once per batch and never cached.

namespace FreeFormCertificate\Submissions;

use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the staging tables of one import at a time.
 */
final class BulkIssueRepository {

	/** Job state while the file is being staged. */
	public const STATUS_STAGING = 'staging';

	/** Job state after the last batch: every staged row is issued or skipped. */
	public const STATUS_DONE = 'done';

	/** Job state of a staging job that was never finished and was purged. */
	public const STATUS_EXPIRED = 'expired';

	/** Row state of a valid line waiting to be issued. */
	public const ROW_PENDING = 'pending';

	/** Row state of a line the planner rejected. */
	public const ROW_SKIPPED = 'skipped';

	/** Row state of a line whose certificate was issued. */
	public const ROW_ISSUED = 'issued';

	/**
	 * Opens a job for a form. Refused when encryption is not configured: a row
	 * can then not be stored without its personal data in clear text.
	 *
	 * @param int $form_id Form the certificates are issued for.
	 * @param int $user_id User who started the import.
	 * @param int $total   Number of data lines in the file.
	 * @return int|\WP_Error Job ID, or an error when the import cannot be staged.
	 */
	public static function create_job( int $form_id, int $user_id, int $total ) {
		global $wpdb;

		if ( ! Encryption::is_configured() ) {
			return new \WP_Error( 'ffc_bulk_issue_encryption_missing', __( 'The import needs the encryption key configured, so that personal data is not stored in clear text.', 'ffcertificate' ) );
		}

		$inserted = $wpdb->insert(
			BulkIssueActivator::jobs_table(),
			array(
				'form_id'    => $form_id,
				'user_id'    => $user_id,
				'status'     => self::STATUS_STAGING,
				'total'      => $total,
				'created_at' => time(),
			),
			array( '%d', '%d', '%s', '%d', '%d' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'ffc_bulk_issue_db_error', __( 'The import could not be staged.', 'ffcertificate' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Stores the planned lines of a job. Each line keeps its personal values
	 * encrypted; the CPF/RF hashes let the batch check for duplicates.
	 *
	 * @param int                                                                                                                                                        $job_id Job ID.
	 * @param array<int, array{line:int, status:string, skip_reason:string, name:string, cpf_rf:string, email:string, fields:array<string, string>, issued_at:int|null}> $rows Planned lines.
	 * @return int Rows stored.
	 */
	public static function add_rows( int $job_id, array $rows ): int {
		global $wpdb;

		$stored = 0;

		foreach ( $rows as $row ) {
			$identifier = self::identifier_hashes( $row['cpf_rf'] );
			$payload    = Encryption::encrypt(
				(string) wp_json_encode(
					array(
						'name'      => $row['name'],
						'cpf_rf'    => $row['cpf_rf'],
						'email'     => $row['email'],
						'fields'    => $row['fields'],
						'issued_at' => $row['issued_at'] ?? null,
					)
				)
			);

			$inserted = $wpdb->insert(
				BulkIssueActivator::rows_table(),
				array(
					'job_id'            => $job_id,
					'line'              => $row['line'],
					'status'            => self::ROW_SKIPPED === $row['status'] ? self::ROW_SKIPPED : self::ROW_PENDING,
					'skip_reason'       => '' === $row['skip_reason'] ? null : $row['skip_reason'],
					'cpf_hash'          => $identifier['cpf_hash'],
					'rf_hash'           => $identifier['rf_hash'],
					'payload_encrypted' => $payload,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false !== $inserted ) {
				++$stored;
			}
		}

		return $stored;
	}

	/**
	 * Removes every staged line of a job. Called when the job ends, so the
	 * personal data does not outlive the import.
	 *
	 * @param int $job_id Job ID.
	 * @return int Rows removed.
	 */
	public static function delete_rows( int $job_id ): int {
		global $wpdb;

		$deleted = $wpdb->delete( BulkIssueActivator::rows_table(), array( 'job_id' => $job_id ), array( '%d' ) );

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Expires jobs that were started before the cutoff and never finished, and
	 * removes their rows. Their counters stay, as the audit trail of the attempt.
	 *
	 * @param int $cutoff Unix time; jobs created before it are expired.
	 * @return int Jobs expired.
	 */
	public static function purge_stale( int $cutoff ): int {
		global $wpdb;

		$jobs = BulkIssueActivator::jobs_table();
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE created_at < %d AND status NOT IN (%s, %s)',
				$jobs,
				$cutoff,
				self::STATUS_DONE,
				self::STATUS_EXPIRED
			)
		);

		$expired = 0;

		foreach ( $ids as $id ) {
			self::delete_rows( (int) $id );
			$wpdb->update(
				$jobs,
				array(
					'status'      => self::STATUS_EXPIRED,
					'finished_at' => time(),
				),
				array( 'id' => (int) $id ),
				array( '%s', '%d' ),
				array( '%d' )
			);
			++$expired;
		}

		return $expired;
	}

	/**
	 * Reads one job's counters and state.
	 *
	 * @param int $job_id Job ID.
	 * @return array{id: int, form_id: int, user_id: int, status: string, total: int, issued: int, skipped: int}|null
	 *         The job, or null when it does not exist.
	 */
	public static function find_job( int $job_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', BulkIssueActivator::jobs_table(), $job_id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::job_shape( $row ) : null;
	}

	/**
	 * The first lines still waiting to be issued, with their personal values
	 * decrypted. A processed line leaves the pending set, so a batch always
	 * takes the rows that remain and needs no cursor.
	 *
	 * @param int $job_id Job ID.
	 * @param int $limit  Maximum lines to read.
	 * @return array<int, array{id: int, cpf_hash: string|null, rf_hash: string|null, data: array<mixed, mixed>|null}>
	 *         `data` is null when the payload cannot be decrypted.
	 */
	public static function pending_rows( int $job_id, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, cpf_hash, rf_hash, payload_encrypted FROM %i WHERE job_id = %d AND status = %s ORDER BY id ASC LIMIT %d',
				BulkIssueActivator::rows_table(),
				$job_id,
				self::ROW_PENDING,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$pending = array();

		foreach ( $rows as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$row  = self::row_shape( $raw );
			$json = Encryption::decrypt( $row['payload'] );
			$data = null === $json ? null : json_decode( $json, true );

			$pending[] = array(
				'id'       => $row['id'],
				'cpf_hash' => $row['cpf_hash'],
				'rf_hash'  => $row['rf_hash'],
				'data'     => is_array( $data ) ? $data : null,
			);
		}

		return $pending;
	}

	/**
	 * Narrows a raw job row to the fields the runner reads, checking each one.
	 *
	 * @param array<mixed, mixed> $row A row as $wpdb returned it.
	 * @return array{id: int, form_id: int, user_id: int, status: string, total: int, issued: int, skipped: int}
	 */
	private static function job_shape( array $row ): array {
		$id      = $row['id'] ?? null;
		$form_id = $row['form_id'] ?? null;
		$user_id = $row['user_id'] ?? null;
		$status  = $row['status'] ?? null;
		$total   = $row['total'] ?? null;
		$issued  = $row['issued'] ?? null;
		$skipped = $row['skipped'] ?? null;

		return array(
			'id'      => is_numeric( $id ) ? (int) $id : 0,
			'form_id' => is_numeric( $form_id ) ? (int) $form_id : 0,
			'user_id' => is_numeric( $user_id ) ? (int) $user_id : 0,
			'status'  => is_string( $status ) ? $status : '',
			'total'   => is_numeric( $total ) ? (int) $total : 0,
			'issued'  => is_numeric( $issued ) ? (int) $issued : 0,
			'skipped' => is_numeric( $skipped ) ? (int) $skipped : 0,
		);
	}

	/**
	 * Narrows a raw staged row to the fields the batch reads, checking each one.
	 *
	 * @param array<mixed, mixed> $row A row as $wpdb returned it.
	 * @return array{id: int, cpf_hash: string|null, rf_hash: string|null, payload: string}
	 */
	private static function row_shape( array $row ): array {
		$id       = $row['id'] ?? null;
		$cpf_hash = $row['cpf_hash'] ?? null;
		$rf_hash  = $row['rf_hash'] ?? null;
		$payload  = $row['payload_encrypted'] ?? null;

		return array(
			'id'       => is_numeric( $id ) ? (int) $id : 0,
			'cpf_hash' => is_string( $cpf_hash ) ? $cpf_hash : null,
			'rf_hash'  => is_string( $rf_hash ) ? $rf_hash : null,
			'payload'  => is_string( $payload ) ? $payload : '',
		);
	}

	/**
	 * Lines of a job still waiting to be issued.
	 *
	 * @param int $job_id Job ID.
	 * @return int
	 */
	public static function count_pending( int $job_id ): int {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE job_id = %d AND status = %s',
				BulkIssueActivator::rows_table(),
				$job_id,
				self::ROW_PENDING
			)
		);

		return null === $count ? 0 : (int) $count;
	}

	/**
	 * Records what happened to one line.
	 *
	 * @param int    $row_id      Row ID.
	 * @param string $status      ROW_ISSUED or ROW_SKIPPED.
	 * @param string $skip_reason Why a line was skipped; '' for an issued line.
	 * @return void
	 */
	public static function mark_row( int $row_id, string $status, string $skip_reason = '' ): void {
		global $wpdb;

		$wpdb->update(
			BulkIssueActivator::rows_table(),
			array(
				'status'      => $status,
				'skip_reason' => '' === $skip_reason ? null : $skip_reason,
			),
			array( 'id' => $row_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Adds to a job's counters. Increments in SQL, so two batches cannot lose
	 * each other's counts.
	 *
	 * @param int $job_id  Job ID.
	 * @param int $issued  Certificates issued in this batch.
	 * @param int $skipped Lines skipped in this batch.
	 * @return void
	 */
	public static function add_counts( int $job_id, int $issued, int $skipped ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET issued = issued + %d, skipped = skipped + %d WHERE id = %d',
				BulkIssueActivator::jobs_table(),
				$issued,
				$skipped,
				$job_id
			)
		);
	}

	/**
	 * Sets a job's final state and when it ended.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status STATUS_DONE or STATUS_EXPIRED.
	 * @return void
	 */
	public static function finish_job( int $job_id, string $status ): void {
		global $wpdb;

		$wpdb->update(
			BulkIssueActivator::jobs_table(),
			array(
				'status'      => $status,
				'finished_at' => time(),
			),
			array( 'id' => $job_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * The CPF or RF of a line as the salted hash the submissions table stores.
	 *
	 * @param string $cpf_rf Identifier as the file gives it; '' when absent.
	 * @return array{cpf_hash: string|null, rf_hash: string|null}
	 */
	private static function identifier_hashes( string $cpf_rf ): array {
		$hashes = array(
			'cpf_hash' => null,
			'rf_hash'  => null,
		);

		$clean = DataSanitizer::normalize_cpf_rf( $cpf_rf );
		if ( '' === $clean ) {
			return $hashes;
		}

		$encrypted = SensitiveFieldRegistry::encrypt_fields(
			SensitiveFieldRegistry::CONTEXT_SUBMISSION,
			'rf' === DataSanitizer::classify_cpf_rf( $clean ) ? array( 'rf' => $clean ) : array( 'cpf' => $clean )
		);

		$hashes['cpf_hash'] = $encrypted['cpf_hash'] ?? null;
		$hashes['rf_hash']  = $encrypted['rf_hash'] ?? null;

		return $hashes;
	}
}
