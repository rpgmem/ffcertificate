<?php
/**
 * BulkIssueRunner
 *
 * Turns a bulk-issue plan into a staged job and issues its lines in batches
 * (#1945). Every certificate goes through SubmissionHandler::process_submission()
 * — the same write the operator's single issue uses — with the e-mail pipeline
 * switched off and the issue date set by the job.
 *
 * When the last line is done the job's personal rows are deleted, and the
 * job keeps only its counters, which is what the audit trail needs.
 *
 * @package FreeFormCertificate\Submissions
 * @since 6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Integrations\EmailHandler;
use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stages a bulk-issue job and promotes its pending lines, batch by batch.
 */
final class BulkIssueRunner {

	/** Lines issued per batch; small enough to finish inside a shared host's time limit. */
	public const BATCH_SIZE = 25;

	/** Daily cron hook that expires jobs left unfinished. */
	public const PURGE_HOOK = 'ffcertificate_bulk_issue_purge';

	/** Age, in seconds, after which an unfinished job is expired (7 days). */
	public const STALE_AFTER = 604800;

	/** Skip reason: the form already has a certificate for this CPF or RF. */
	public const SKIP_ALREADY_ISSUED = 'already_issued';

	/** Skip reason: a field the form requires is empty in the file. */
	public const SKIP_MISSING_REQUIRED = 'missing_required';

	/** Skip reason: the staged payload could not be decrypted. */
	public const SKIP_UNREADABLE = 'unreadable';

	/** Skip reason: the submission handler refused the line. */
	public const SKIP_ISSUE_FAILED = 'issue_failed';

	/**
	 * Wires the runner to the handler that writes certificates and the
	 * repository that reports what was already issued.
	 *
	 * @param SubmissionHandler    $handler     Writes each certificate.
	 * @param SubmissionRepository $submissions Looks up certificates already issued to a participant.
	 * @param EmailHandler|null    $email       Sends the link e-mails; built on first use when null.
	 */
	public function __construct(
		private SubmissionHandler $handler,
		private SubmissionRepository $submissions,
		private ?EmailHandler $email = null
	) {
	}

	/**
	 * Stages a job from a plan. Planned lines are pending; the lines the planner
	 * rejected are stored as skipped, so the job's total matches the file.
	 *
	 * @param int                  $form_id Form the certificates are issued for.
	 * @param int                  $user_id User who started the import.
	 * @param array<string, mixed> $plan    Output of BulkIssuePlanner::plan().
	 * @return int|\WP_Error Job ID, or the staging error.
	 */
	public static function start( int $form_id, int $user_id, array $plan ) {
		$rows = array();

		foreach ( $plan['issue'] as $line ) {
			$rows[] = array(
				'line'        => (int) $line['line'],
				'status'      => BulkIssueRepository::ROW_PENDING,
				'skip_reason' => '',
				'name'        => (string) $line['name'],
				'cpf_rf'      => (string) $line['cpf_rf'],
				'email'       => (string) $line['email'],
				'fields'      => $line['fields'],
				'issued_at'   => $line['issued_at'],
				'send_link'   => $line['send_link'],
			);
		}

		foreach ( $plan['skipped'] as $line ) {
			$rows[] = array(
				'line'        => (int) $line['line'],
				'status'      => BulkIssueRepository::ROW_SKIPPED,
				'skip_reason' => (string) $line['reason'],
				'name'        => '',
				'cpf_rf'      => '',
				'email'       => '',
				'fields'      => array(),
				'issued_at'   => null,
				'send_link'   => false,
			);
		}

		$job_id = BulkIssueRepository::create_job( $form_id, $user_id, count( $rows ) );

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		BulkIssueRepository::add_rows( $job_id, $rows );
		BulkIssueRepository::add_counts( $job_id, 0, count( $plan['skipped'] ) );

		return $job_id;
	}

	/**
	 * Issues the next batch of a job.
	 *
	 * The return value is the number of lines this call changed, and it is what
	 * a caller loops on: each line handled leaves the pending set, so the loop
	 * ends when a batch reports zero, whatever the rules for skipping drift to.
	 * The batch that finds nothing left ends the job.
	 *
	 * @param int $job_id Job ID.
	 * @param int $size   Lines to take in this batch.
	 * @return int Lines changed; 0 when the job is finished or missing.
	 */
	public function process_batch( int $job_id, int $size = self::BATCH_SIZE ): int {
		$job = BulkIssueRepository::find_job( $job_id );

		if ( null === $job || BulkIssueRepository::STATUS_STAGING !== $job['status'] ) {
			return 0;
		}

		$form_id = (int) $job['form_id'];
		$rows    = BulkIssueRepository::pending_rows( $job_id, $size );

		if ( array() === $rows ) {
			$this->finish( $job_id );

			return 0;
		}

		// FormCache answers false for a form that has no stored config or fields.
		$fields      = FormCache::get_form_fields( $form_id );
		$form_config = FormCache::get_form_config( $form_id );
		$fields      = false === $fields ? array() : $fields;
		$form_config = false === $form_config ? array() : $form_config;
		$form_title  = (string) get_the_title( $form_id );
		$issued      = 0;
		$skipped     = 0;

		foreach ( $rows as $row ) {
			$reason = $this->skip_reason( $form_id, $fields, $row );

			if ( '' !== $reason ) {
				BulkIssueRepository::mark_row( $row['id'], BulkIssueRepository::ROW_SKIPPED, $reason );
				++$skipped;
				continue;
			}

			$data            = (array) $row['data'];
			$submission_data = array_merge(
				(array) ( $data['fields'] ?? array() ),
				array(
					'name'   => (string) ( $data['name'] ?? '' ),
					'cpf_rf' => (string) ( $data['cpf_rf'] ?? '' ),
					'email'  => (string) ( $data['email'] ?? '' ),
				)
			);

			$result = $this->handler->process_submission(
				$form_id,
				$form_title,
				$submission_data,
				$submission_data['email'],
				$fields,
				$form_config,
				array(
					'send_email'      => false,
					'submission_date' => $this->issued_at( $data ),
				)
			);

			if ( is_wp_error( $result ) ) {
				BulkIssueRepository::mark_row( $row['id'], BulkIssueRepository::ROW_SKIPPED, self::SKIP_ISSUE_FAILED );
				++$skipped;
				continue;
			}

			BulkIssueRepository::mark_row( $row['id'], BulkIssueRepository::ROW_ISSUED );
			++$issued;

			// The link goes out after the certificate exists, and a refused mail
			// does not undo the issue: the list action sends it again later.
			if ( ! empty( $data['send_link'] ) ) {
				$this->email_handler()->resend_link( (int) $result );
			}
		}

		BulkIssueRepository::add_counts( $job_id, $issued, $skipped );

		if ( 0 === BulkIssueRepository::count_pending( $job_id ) ) {
			$this->finish( $job_id );
		}

		return count( $rows );
	}

	/**
	 * The instant a line's certificate is dated: the date the import chose for
	 * it, or now when the import left the date to the moment of issue.
	 *
	 * @param array<mixed, mixed> $data Decrypted line payload.
	 * @return int
	 */
	private function issued_at( array $data ): int {
		return isset( $data['issued_at'] ) && is_int( $data['issued_at'] ) ? $data['issued_at'] : time();
	}

	/**
	 * The mailer the link e-mails go through. Built on first use, so a batch that
	 * sends no link never constructs it.
	 *
	 * @return EmailHandler
	 */
	private function email_handler(): EmailHandler {
		$this->email ??= new EmailHandler();

		return $this->email;
	}

	/**
	 * Cron callback: expires the stale jobs once a day.
	 *
	 * @return void
	 */
	public static function run_purge(): void {
		self::purge_expired();
	}

	/**
	 * Expires jobs that were started before the cutoff and never finished.
	 *
	 * @param int|null $now Unix time the cutoff counts back from; null for now.
	 * @return int Jobs expired.
	 */
	public static function purge_expired( ?int $now = null ): int {
		return BulkIssueRepository::purge_stale( ( $now ?? time() ) - self::STALE_AFTER );
	}

	/**
	 * Why a staged line must not be issued, or '' when it can be.
	 *
	 * @param int                                                                                         $form_id Form ID.
	 * @param array<int, array<string, mixed>>                                                            $fields  Form fields.
	 * @param array{id: int, cpf_hash: string|null, rf_hash: string|null, data: array<mixed, mixed>|null} $row Pending line.
	 * @return string Skip reason, or '' when the line is issuable.
	 */
	private function skip_reason( int $form_id, array $fields, array $row ): string {
		if ( null === $row['data'] ) {
			return self::SKIP_UNREADABLE;
		}

		$values = array_merge(
			(array) ( $row['data']['fields'] ?? array() ),
			array(
				'name'   => (string) ( $row['data']['name'] ?? '' ),
				'cpf_rf' => (string) ( $row['data']['cpf_rf'] ?? '' ),
				'email'  => (string) ( $row['data']['email'] ?? '' ),
			)
		);

		foreach ( $fields as $field ) {
			if ( empty( $field['required'] ) || ! isset( $field['name'] ) ) {
				continue;
			}

			if ( '' === trim( (string) ( $values[ $field['name'] ] ?? '' ) ) ) {
				return self::SKIP_MISSING_REQUIRED;
			}
		}

		if ( null !== $row['cpf_hash'] && $this->submissions->countByFormAndCpfHash( $form_id, (string) $row['cpf_hash'] ) > 0 ) {
			return self::SKIP_ALREADY_ISSUED;
		}

		if ( null !== $row['rf_hash'] && $this->submissions->countByFormAndRfHash( $form_id, (string) $row['rf_hash'] ) > 0 ) {
			return self::SKIP_ALREADY_ISSUED;
		}

		return '';
	}

	/**
	 * Closes a job: records the state, deletes the staged personal rows and
	 * writes one activity line for the whole import.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	private function finish( int $job_id ): void {
		$job = BulkIssueRepository::find_job( $job_id );

		if ( null === $job ) {
			return;
		}

		BulkIssueRepository::finish_job( $job_id, BulkIssueRepository::STATUS_DONE );
		BulkIssueRepository::delete_rows( $job_id );

		ActivityLog::log(
			'bulk_issue_finished',
			ActivityLog::LEVEL_INFO,
			array(
				'job_id'  => $job_id,
				'form_id' => (int) $job['form_id'],
				'issued'  => (int) $job['issued'],
				'skipped' => (int) $job['skipped'],
			),
			(int) $job['user_id']
		);
	}
}
