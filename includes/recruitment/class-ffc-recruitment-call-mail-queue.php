<?php
/**
 * RecruitmentCallMailQueue
 *
 * Sends the call e-mails of a bulk call in the background, and records the
 * delivery of every call e-mail (#2694).
 *
 * A bulk call used to send every e-mail inside the request that committed
 * the calls. A long list could time out after the calls were saved, and
 * nothing said which candidates had been written to. The calls are now
 * marked `queued` in the same request, and a WP-Cron batch sends them a few
 * at a time. Each send, queued or immediate, records `sent` or `not_sent`
 * on the call with its time, which is what the Calls tab shows and what
 * Resend acts on.
 *
 * Termination: a batch reads only `queued` calls and every call it reads
 * leaves that state whether the send worked or not, so a batch that finds
 * nothing does nothing and schedules nothing.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background delivery and per-call status of the call e-mails.
 */
final class RecruitmentCallMailQueue {

	/**
	 * WP-Cron hook of one batch.
	 */
	public const HOOK = 'ffc_recruitment_call_mail_batch';

	/**
	 * Calls e-mailed per batch.
	 */
	public const BATCH_SIZE = 20;

	/**
	 * The call's e-mail waits for the background batch.
	 */
	public const STATUS_QUEUED = 'queued';

	/**
	 * The e-mail went out.
	 */
	public const STATUS_SENT = 'sent';

	/**
	 * The e-mail did not go out: no address on file, e-mails switched off,
	 * or a refused send.
	 */
	public const STATUS_NOT_SENT = 'not_sent';

	/**
	 * Register the batch handler. Not behind `is_admin()`: the batch runs
	 * under WP-Cron.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'handle_batch' ), 10, 0 );
	}

	/**
	 * The batch hook's entry point.
	 *
	 * A `void` wrapper, because a WordPress action callback must not return
	 * a value while {@see self::run_batch()} returns the count the tests
	 * read.
	 *
	 * @return void
	 */
	public static function handle_batch(): void {
		self::run_batch();
	}

	/**
	 * Mark calls for background delivery and make sure a batch is queued.
	 *
	 * @param array<int, int> $call_ids Call IDs.
	 * @return int Calls marked.
	 */
	public static function queue( array $call_ids ): int {
		$marked = RecruitmentCallWriter::mark_email_queued( $call_ids );
		if ( $marked > 0 ) {
			self::schedule();
		}
		return $marked;
	}

	/**
	 * Queue a batch unless one is already queued.
	 *
	 * @return bool True when a batch was queued.
	 */
	public static function schedule(): bool {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return false;
		}
		return true === wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * Send one batch of queued call e-mails.
	 *
	 * @return int Calls whose e-mail this batch handled.
	 */
	public static function run_batch(): int {
		$ids = RecruitmentCallReader::get_queued_email_ids( self::BATCH_SIZE );
		foreach ( $ids as $call_id ) {
			self::deliver( $call_id );
		}
		if ( self::BATCH_SIZE === count( $ids ) ) {
			self::schedule();
		}
		return count( $ids );
	}

	/**
	 * Send a call's convocation e-mail now and record the outcome.
	 *
	 * @param int $call_id Call ID.
	 * @return bool True when the message went out.
	 */
	public static function deliver( int $call_id ): bool {
		$sent = RecruitmentEmailDispatcher::send_for_call( $call_id );
		RecruitmentCallWriter::set_email_status(
			$call_id,
			$sent ? self::STATUS_SENT : self::STATUS_NOT_SENT,
			time()
		);
		return $sent;
	}
}
