<?php
/**
 * Date-messages manager digest.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\EmailSource;
use FreeFormCertificate\Scheduling\SchedulingMailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The summary a rule's managers receive about one run (#1538).
 *
 * WHY A DAY LATER. A run is scheduled when it starts and reported 24 hours
 * after: by then its batches have finished and a mail queue has had time to
 * drain, so the numbers are final rather than a snapshot of a run in flight.
 *
 * ONE DIGEST PER RUN, AT MOST. `digest_sent_at` is written after the last
 * manager, and a run that already carries it sends nothing, so a duplicated
 * event cannot repeat the e-mail. A run that reached nobody sends no digest.
 *
 * NAMES ARE PII, SO THEY FOLLOW THE CAPABILITY, NOT THE RULE. The detailed
 * mode lists who received the message -- by display name only, never the
 * address or the date -- and only to a manager who may see that on screen
 * (`ffc_view_date_messages_pii`, or an administrator). Every other manager on
 * the same rule receives the counts.
 */
final class Digest {

	/**
	 * Single event, one per run. Listed in `ScheduledTasks`.
	 */
	public const HOOK = 'ffc_date_messages_digest';

	/**
	 * Delay between the start of a run and its digest.
	 */
	public const DELAY = DAY_IN_SECONDS;

	/**
	 * Most names a detailed digest lists.
	 */
	public const NAMES_LIMIT = 200;

	/**
	 * Schedule the digest of a run that just started, when its rule asks for one.
	 *
	 * @param int  $run_id Run.
	 * @param Rule $rule   Its rule.
	 * @return void
	 */
	public static function schedule( int $run_id, Rule $rule ): void {
		if ( $run_id <= 0 || ! $rule->digest_enabled || array() === $rule->digest_user_ids ) {
			return;
		}
		$args = array( $run_id );
		if ( false === wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + self::DELAY, self::HOOK, $args );
		}
	}

	/**
	 * Send a run's digest to its rule's managers.
	 *
	 * @param int $run_id Run.
	 * @return void
	 */
	public static function send( int $run_id ): void {
		$run = DeliveryLog::get_run( $run_id );
		if ( null === $run || self::int( $run['digest_sent_at'] ?? 0 ) > 0 ) {
			return;
		}

		$rule = RuleReader::get_by_id( self::int( $run['rule_id'] ?? 0 ) );
		if ( null === $rule || ! $rule->digest_enabled || array() === $rule->digest_user_ids ) {
			return;
		}

		$counts = array();
		foreach ( array( 'sent', 'opted_out', 'no_email', 'out_of_audience', 'failed' ) as $counter ) {
			$counts[ $counter ] = self::int( $run[ $counter ] ?? 0 );
		}
		if ( 0 === array_sum( $counts ) ) {
			DeliveryLog::mark_digest_sent( $run_id );
			return;
		}

		$detailed = 'detailed' === $rule->digest_mode;
		$names    = $detailed ? self::recipient_names( $run_id ) : array();
		$from     = is_string( $run['target_from'] ?? null ) ? $run['target_from'] : '';
		$to       = is_string( $run['target_to'] ?? null ) ? $run['target_to'] : '';
		$subject  = sprintf(
			/* translators: 1: site name, 2: rule name */
			__( '[%1$s] Date messages summary: %2$s', 'ffcertificate' ),
			(string) get_bloginfo( 'name' ),
			$rule->name
		);

		foreach ( $rule->digest_user_ids as $user_id ) {
			$user = get_userdata( $user_id );
			if ( false === $user || ! is_email( $user->user_email ) || ! self::may_receive( $user ) ) {
				continue;
			}

			$may_see_names = $detailed && ( user_can( $user, 'manage_options' ) || user_can( $user, DateMessagesAdminPage::PII_CAP ) );
			$body          = self::body(
				array(
					'rule_name'   => $rule->name,
					'trigger'     => is_string( $run['trigger_kind'] ?? null ) ? $run['trigger_kind'] : 'cron',
					'target_from' => $from,
					'target_to'   => $to,
					'counts'      => $counts,
					'names'       => $may_see_names ? $names : null,
					'names_limit' => self::NAMES_LIMIT,
				)
			);

			SchedulingMailer::send( $user->user_email, $subject, $body, array(), true, EmailSource::DATE_MESSAGES );
		}

		DeliveryLog::mark_digest_sent( $run_id );
	}

	/**
	 * Whether an account may still receive a digest: the same accounts the
	 * editor offers. Checked at send, so a capability revoked after the rule
	 * was saved -- or an id the form never offered -- receives nothing.
	 *
	 * @param \WP_User $user Account.
	 * @return bool
	 */
	private static function may_receive( \WP_User $user ): bool {
		foreach ( array( 'manage_options', DateMessagesAdminPage::VIEW_CAP, DateMessagesAdminPage::MANAGE_CAP, DateMessagesAdminPage::PII_CAP ) as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The digest body.
	 *
	 * @param array<string, mixed> $args Values for the template.
	 * @return string
	 */
	private static function body( array $args ): string {
		$file = FFC_PLUGIN_DIR . 'templates/emails/date-message-digest.php';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}

	/**
	 * Display names of the people a run delivered to.
	 *
	 * @param int $run_id Run.
	 * @return array<int, string>
	 */
	private static function recipient_names( int $run_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Joins the plugin's log to core wp_users for display names; annotated per line because wp_users is a core table. A digest reads the live log, never a cache.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT u.display_name FROM %i l INNER JOIN %i u ON u.ID = l.user_id WHERE l.run_id = %d AND l.channel = %s ORDER BY u.display_name ASC LIMIT %d',
				DateMessagesActivator::log_table(),
				$wpdb->users,
				$run_id,
				'user',
				self::NAMES_LIMIT
			)
		);

		$out = array();
		foreach ( (array) $names as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * A stored integer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function int( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
