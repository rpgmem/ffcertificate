<?php
/**
 * Date-message runner.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\EmailSource;
use FreeFormCertificate\Scheduling\SchedulingMailer;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends a rule's messages for a range of target days, in batches (#1538).
 *
 * WHY BATCHES, WHEN A MAIL QUEUE DOES THE SENDING. With `total-mail-queue`
 * active, `wp_mail()` only enqueues -- retries and pacing are the queue's job,
 * so this class has none. What remains is PHP's time limit: resolving, building
 * and enqueueing still costs per person, so a page of BATCH_SIZE is handled per
 * request and the rest continues as a single cron event, the shape
 * `ReregistrationEmailHandler::send_reminder_batch()` already uses.
 *
 * ONE RUN, MANY DAYS. A run covers `target_from` to `target_to`. The daily job
 * opens one per rule over two days -- today's target and yesterday's -- so a
 * day the cron missed is caught up the next morning; the log's unique key
 * makes the second look at yesterday send nothing it already sent. A manual
 * send covers whatever range the operator picked, up to MAX_RANGE_DAYS.
 */
final class Runner {

	/**
	 * Continuation hook (single events). Listed in `ScheduledTasks`.
	 */
	public const BATCH_HOOK = 'ffc_date_messages_batch';

	/**
	 * People handled per request.
	 */
	public const BATCH_SIZE = 200;

	/**
	 * Widest manual range, in days.
	 */
	public const MAX_RANGE_DAYS = 31;

	/**
	 * Seconds between batches.
	 */
	private const BATCH_DELAY = 30;

	/**
	 * Register the continuation callback.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::BATCH_HOOK, array( self::class, 'process' ), 10, 3 );
	}

	/**
	 * Today in the site timezone, at midnight.
	 *
	 * @return \DateTimeImmutable
	 */
	public static function today(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'today', wp_timezone() );
	}

	/**
	 * The daily job: one run per active rule over today's and yesterday's
	 * targets.
	 *
	 * @return void
	 */
	public static function run_daily(): void {
		if ( SettingsReader::emails_disabled() ) {
			return;
		}

		$today = self::today();
		foreach ( RuleReader::active() as $rule ) {
			if ( ! $rule->send_to_user ) {
				continue;
			}
			// A rule sent N days BEFORE the date has a negative offset, so
			// the date it is about today is today minus the offset.
			$target = $today->modify( sprintf( '%+d days', -$rule->offset_days ) );
			self::start( $rule, $target->modify( '-1 day' ), $target, 'cron', 0 );
		}
	}

	/**
	 * Open a run and process its first batch now.
	 *
	 * @param Rule               $rule       The rule.
	 * @param \DateTimeImmutable $from       First target day.
	 * @param \DateTimeImmutable $to         Last target day.
	 * @param string             $trigger    'cron' or 'manual'.
	 * @param int                $created_by Operator, 0 for the cron.
	 * @return int|\WP_Error Run id.
	 */
	public static function start( Rule $rule, \DateTimeImmutable $from, \DateTimeImmutable $to, string $trigger, int $created_by ) {
		$days = (int) $from->diff( $to )->format( '%r%a' );
		if ( $days < 0 || $days >= self::MAX_RANGE_DAYS ) {
			return new \WP_Error(
				'ffc_date_messages_range',
				sprintf(
					/* translators: %d: maximum number of days */
					__( 'Choose a range of 1 to %d days, starting on or before its end.', 'ffcertificate' ),
					self::MAX_RANGE_DAYS
				)
			);
		}

		$run_id = DeliveryLog::start_run( $rule->id, $trigger, $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ), $created_by );
		if ( $run_id <= 0 ) {
			return new \WP_Error( 'ffc_date_messages_run', __( 'Could not record the run.', 'ffcertificate' ) );
		}

		// Scheduled before the first batch, so a run that finishes in one
		// request still gets its digest.
		Digest::schedule( $run_id, $rule );

		self::process( $run_id, $from->format( 'Y-m-d' ), 0 );
		return $run_id;
	}

	/**
	 * Handle one batch of one target day, then hand off what is left.
	 *
	 * @param int    $run_id Run.
	 * @param string $target Target day, `Y-m-d`.
	 * @param int    $after  Keyset cursor.
	 * @return void
	 */
	public static function process( int $run_id, string $target, int $after ): void {
		$run  = DeliveryLog::get_run( $run_id );
		$rule = null === $run ? null : RuleReader::get_by_id( (int) $run['rule_id'] );
		$day  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $target, wp_timezone() );

		if ( null === $run || false === $day ) {
			return;
		}
		if ( null === $rule || SettingsReader::emails_disabled() ) {
			// A rule deleted mid-run, or every e-mail switched off: stop
			// cleanly rather than claim deliveries that cannot go out.
			DeliveryLog::finish_run( $run_id );
			return;
		}

		$source = DateSources::get( $rule->source );
		$page   = ( new RecipientResolver() )->resolve( $rule, $day, $after, self::BATCH_SIZE );
		$today  = self::today();

		foreach ( $page['rows'] as $row ) {
			switch ( $row['decision'] ) {
				case RecipientResolver::WILL_SEND:
					self::deliver( $run_id, $rule, $source, $row, $day, $today );
					break;
				case RecipientResolver::OPTED_OUT:
				case RecipientResolver::NO_EMAIL:
				case RecipientResolver::OUT_OF_AUDIENCE:
					DeliveryLog::bump( $run_id, $row['decision'] );
					break;
			}
		}

		if ( ! $page['complete'] ) {
			self::schedule( $run_id, $target, $page['cursor'] );
			return;
		}

		$next = $day->modify( '+1 day' )->format( 'Y-m-d' );
		if ( $next <= (string) $run['target_to'] ) {
			self::schedule( $run_id, $next, 0 );
			return;
		}

		DeliveryLog::finish_run( $run_id );
	}

	/**
	 * Claim, build and send one message.
	 *
	 * @param int                                                                $run_id Run.
	 * @param Rule                                                               $rule   Rule.
	 * @param DateSourceInterface|null                                           $source Source.
	 * @param array{user_id: int, email: string, name: string, decision: string} $row    Recipient.
	 * @param \DateTimeImmutable                                                 $day    Target day.
	 * @param \DateTimeImmutable                                                 $today  Today.
	 * @return void
	 */
	private static function deliver( int $run_id, Rule $rule, ?DateSourceInterface $source, array $row, \DateTimeImmutable $day, \DateTimeImmutable $today ): void {
		if ( null === $source ) {
			return;
		}

		$occurrence = $day->format( 'Y-m-d' );

		// Claim first: a concurrent run that loses the insert does not send.
		if ( ! DeliveryLog::claim( $run_id, $rule->id, $row['user_id'], $occurrence ) ) {
			return;
		}

		$message = MessageBuilder::build( $rule, $source, $row, $day, $today );
		$sent    = SchedulingMailer::send( $row['email'], $message['subject'], $message['body'], array(), true, EmailSource::DATE_MESSAGES );

		if ( $sent ) {
			DeliveryLog::bump( $run_id, 'sent' );
			return;
		}

		// Refused before it left the plugin: release the claim so the next
		// run may try again, and count the failure.
		DeliveryLog::release( $rule->id, $row['user_id'], $occurrence );
		DeliveryLog::bump( $run_id, 'failed' );
	}

	/**
	 * Queue the next batch, once.
	 *
	 * @param int    $run_id Run.
	 * @param string $target Target day.
	 * @param int    $after  Cursor.
	 * @return void
	 */
	private static function schedule( int $run_id, string $target, int $after ): void {
		$args = array( $run_id, $target, $after );
		if ( false === wp_next_scheduled( self::BATCH_HOOK, $args ) ) {
			wp_schedule_single_event( time() + self::BATCH_DELAY, self::BATCH_HOOK, $args );
		}
	}
}
