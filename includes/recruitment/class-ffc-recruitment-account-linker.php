<?php
/**
 * Recruitment Account Linker
 *
 * Creates or links the WordPress accounts of a notice's candidates, in
 * batches, after the notice has left `draft` (#2245).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\UserDashboard\CapabilityManager;
use FreeFormCertificate\UserDashboard\UserCreator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The one query reads the plugin's own ffc_recruitment_* tables, which WordPress exposes no API for; accounts are resolved through UserCreator, never by SQL.
/**
 * Account creation for recruitment candidates, out of the import (#2245).
 *
 * The import used to resolve an account for every row inside its own
 * transaction. That had three costs: a draft notice created accounts for
 * candidates nobody had published, a rolled-back import had already sent
 * each new user a welcome e-mail, and a re-import could move a candidate to
 * whichever account owned the row's e-mail. Now:
 *
 * - **Only past `draft`.** {@see self::schedule()} is a no-op on a draft, and
 *   it is called when a notice reaches `preliminary` or `definitive` and
 *   after an import commits on one.
 * - **No welcome e-mail in bulk.** Accounts are created with `notify=false`;
 *   the candidate is told through the call e-mail.
 * - **Only an empty link is filled.** A candidate that already has an
 *   account keeps it ({@see RecruitmentCandidateWriter::claim_user_id()}), so
 *   a re-import neither repeats the promotion nor logs a false "promoted".
 *
 * Each batch takes the next candidates by id and schedules the following
 * one while a full batch came back, so the job ends whatever the accounts
 * resolve to: termination rides the cursor, never the count of links made.
 *
 * @phpstan-type LinkRow \stdClass&object{id: numeric-string, name: string|null, cpf_hash: string|null, rf_hash: string|null, email_encrypted: string|null}
 */
final class RecruitmentAccountLinker {

	/**
	 * Single-event hook that runs one batch.
	 */
	public const HOOK = 'ffc_recruitment_account_link_batch';

	/**
	 * Candidates resolved per batch.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * Register the batch handler. Not behind `is_admin()`: the batch runs
	 * under WP-Cron.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'handle_batch' ), 10, 2 );
	}

	/**
	 * The batch hook's entry point.
	 *
	 * A `void` wrapper, because a WordPress action callback must not return
	 * a value while {@see self::run_batch()} returns the count the tests and
	 * the CI flow check read.
	 *
	 * @param int $notice_id Notice ID.
	 * @param int $after     Candidate id the previous batch ended at.
	 * @return void
	 */
	public static function handle_batch( int $notice_id, int $after = 0 ): void {
		self::run_batch( $notice_id, $after );
	}

	/**
	 * Queue the first batch for a notice, unless it is a draft or one is
	 * already queued.
	 *
	 * @param int         $notice_id Notice ID.
	 * @param string|null $status    The notice's status when the caller already
	 *                               holds it; read from the notice otherwise.
	 * @return bool True when a batch was queued.
	 */
	public static function schedule( int $notice_id, ?string $status = null ): bool {
		if ( null === $status ) {
			$notice = RecruitmentNoticeReader::get_by_id( $notice_id );
			$status = null === $notice ? 'draft' : (string) $notice->status;
		}
		if ( 'draft' === $status ) {
			return false;
		}

		$args = array( $notice_id, 0 );
		if ( wp_next_scheduled( self::HOOK, $args ) ) {
			return false;
		}

		return true === wp_schedule_single_event( time(), self::HOOK, $args );
	}

	/**
	 * Resolve the accounts of one batch of a notice's unlinked candidates.
	 *
	 * @param int $notice_id Notice ID.
	 * @param int $after     Candidate id the previous batch ended at.
	 * @return int Candidates linked by this batch.
	 */
	public static function run_batch( int $notice_id, int $after = 0 ): int {
		$notice = RecruitmentNoticeReader::get_by_id( $notice_id );
		if ( null === $notice || 'draft' === $notice->status ) {
			return 0;
		}

		global $wpdb;
		/**
		 * The columns the SELECT names, as wpdb returns them.
		 *
		 * @var list<LinkRow>|null $rows
		 */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT c.id, c.name, c.cpf_hash, c.rf_hash, c.email_encrypted
				 FROM %i c
				 INNER JOIN %i cl ON cl.candidate_id = c.id
				 WHERE cl.notice_id = %d AND c.user_id IS NULL AND c.id > %d
				 ORDER BY c.id
				 LIMIT %d',
				RecruitmentCandidateReader::get_table_name(),
				RecruitmentClassificationRepository::get_table_name(),
				$notice_id,
				$after,
				self::BATCH_SIZE
			)
		);
		if ( ! is_array( $rows ) || array() === $rows ) {
			return 0;
		}

		$linked = 0;
		$last   = $after;
		foreach ( $rows as $row ) {
			$last = (int) $row->id;
			if ( self::link( $row ) ) {
				++$linked;
			}
		}

		if ( count( $rows ) === self::BATCH_SIZE ) {
			wp_schedule_single_event( time(), self::HOOK, array( $notice_id, $last ) );
		}

		return $linked;
	}

	/**
	 * Resolve one candidate's account and fill its empty link.
	 *
	 * @param object $row Candidate row.
	 * @phpstan-param LinkRow $row
	 * @return bool True when the link was set by this call.
	 */
	private static function link( object $row ): bool {
		$cpf_hash = is_string( $row->cpf_hash ) && '' !== $row->cpf_hash ? $row->cpf_hash : null;
		$rf_hash  = is_string( $row->rf_hash ) && '' !== $row->rf_hash ? $row->rf_hash : null;
		$email    = '';
		if ( is_string( $row->email_encrypted ) && '' !== $row->email_encrypted ) {
			$email = (string) Encryption::decrypt( $row->email_encrypted );
		}
		if ( null === $cpf_hash && null === $rf_hash && '' === $email ) {
			return false;
		}

		// The name travels with the account (#1480): `name` is one of the keys
		// `UserCreator::sync_user_metadata()` reads, so a created account is
		// named after the person, not after its login.
		$name    = is_string( $row->name ) ? trim( $row->name ) : '';
		$user_id = UserCreator::get_or_create_user_dual(
			$cpf_hash,
			$rf_hash,
			$email,
			'' !== $name ? array( 'name' => $name ) : array(),
			CapabilityManager::CONTEXT_RECRUITMENT,
			false
		);
		if ( ! is_int( $user_id ) || $user_id <= 0 ) {
			return false;
		}

		// Resolving the account fires identity adoption, which may already have
		// claimed this candidacy by its hash; that is this batch's link too.
		$candidate_id = (int) $row->id;
		if ( ! RecruitmentCandidateWriter::claim_user_id( $candidate_id, $user_id ) ) {
			$candidate = RecruitmentCandidateReader::get_by_id( $candidate_id );
			if ( null === $candidate || (int) $candidate->user_id !== $user_id ) {
				return false;
			}
		}

		RecruitmentActivityLogger::candidate_promoted( $candidate_id, $user_id );
		return true;
	}
}
