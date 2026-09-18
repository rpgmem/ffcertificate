<?php
/**
 * Reregistration Email Handler
 *
 * Sends invitation, reminder, and confirmation emails for reregistration campaigns.
 * Uses SchedulingMailer for the shared chrome + transport.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.11.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Scheduling\SchedulingMailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for reregistration email operations.
 *
 * @phpstan-import-type ReregistrationRow from ReregistrationRepository
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionReader
 */
class ReregistrationEmailHandler {

	use \FreeFormCertificate\Core\EmailHelperTrait;

	/**
	 * How many submissions one reminder batch processes (#1232 step 2).
	 *
	 * 50 rather than 100, because the per-participant bottleneck is not just the
	 * `wp_mail()`: `PasswordInvite::issue_for()` does a phpass hash --
	 * deliberately slow -- plus an `UPDATE wp_users`, and the send writes a log
	 * row on top. That is ~3 writes per person, inside a visitor's request.
	 *
	 * With the sibling plugin `total-mail-queue` active the `wp_mail()` becomes
	 * an INSERT (it short-circuits at `pre_wp_mail`, with no SMTP handshake),
	 * but the other writes are still there -- which is why the batch was not
	 * sized assuming the queue is installed.
	 *
	 * @var int
	 */
	public const REMINDER_BATCH_SIZE = 50;

	/**
	 * The single event that continues a reminder batch.
	 *
	 * Registered in the orchestrator (`Loader`), alongside the other scheduled
	 * events: cron registration is orchestrator lifecycle, not module bootstrap
	 * -- the distinction `CLAUDE.md` fixes for the `*Loader` classes.
	 *
	 * @var string
	 */
	public const REMINDER_BATCH_HOOK = 'ffc_reregistration_reminder_batch';

	/**
	 * Seconds between one batch and the next.
	 *
	 * It is a FLOOR, not a promise: WP-Cron is fired by a visitor's request, so
	 * on an idle site the next batch goes out when somebody turns up. Whoever
	 * needs a real cadence configures `DISABLE_WP_CRON` plus a system cron.
	 *
	 * @var int
	 */
	public const REMINDER_BATCH_DELAY = 60;

	/**
	 * Statuses for which the invitation must not ask the person to complete
	 * anything, because the campaign already holds their answer (#1300).
	 *
	 * It is deliberately NOT the complement of
	 * {@see ReregistrationSubmissionReader::UNFINISHED_STATUSES}: that list
	 * carries `expired` and `rejected`, and both of those people DO still have
	 * something to do -- `rejected` is even in the frontend's
	 * `SUBMITTABLE_STATUSES`. Only `submitted` and `approved` mean "recorded".
	 *
	 * @since 6.26.0
	 * @var list<string>
	 */
	private const INVITATION_RECORDED_STATUSES = array( 'submitted', 'approved' );

	/**
	 * Send invitation emails to whoever is still awaiting one.
	 *
	 * **Idempotent by construction** (#1190): it asks
	 * {@see ReregistrationSubmissionReader::get_awaiting_invitation()} who is
	 * owed an email and stamps `invited_at` on the ones it reached, so running
	 * it twice in a row sends nothing the second time. It used to ask for
	 * `status = 'pending'` and mail all of them — a proxy that re-invited
	 * everybody who had ignored the first email, which is why it could only
	 * ever be called on a status transition.
	 *
	 * A deadline that moved forward re-opens the door for whoever has not
	 * finished; see that method and `UNFINISHED_STATUSES` for who that is.
	 *
	 * @param int $reregistration_id Reregistration ID.
	 * @return int Number of emails sent.
	 */
	public static function send_invitations( int $reregistration_id ): int {
		if ( self::emails_disabled() ) {
			return 0;
		}

		$rereg = ReregistrationRepository::get_by_id( $reregistration_id );
		if ( ! $rereg || empty( $rereg->email_invitation_enabled ) ) {
			return 0;
		}

		$extended_at = isset( $rereg->deadline_extended_at ) ? (int) $rereg->deadline_extended_at : 0;

		$submissions = ReregistrationSubmissionReader::get_awaiting_invitation(
			$reregistration_id,
			$extended_at > 0 ? $extended_at : null
		);

		$template = self::effective_template( 'reregistration-invitation' );
		if ( ! $template ) {
			return 0;
		}

		$count  = 0;
		$mailed = array();
		foreach ( $submissions as $sub ) {
			// The password-setting link is issued PER USER and per send
			// (#1212). Issuing rotates the key, so the last email is always the
			// one that counts -- which is the right behaviour for a resent
			// invitation.
			//
			// The other two travel together, because a sentence saying the
			// reregistration is already recorded under a button saying
			// "Complete Reregistration" makes the email contradict itself --
			// which is worse than the single wrong sentence this fixes. The
			// DESTINATION is right for both audiences (the dashboard shows the
			// form to whoever can still submit and the record to whoever
			// cannot), so only the label moves.
			$extra = array(
				'set_password_url' => \FreeFormCertificate\Core\PasswordInvite::issue_for( (int) $sub->user_id ),
			);
			$extra = array_merge( $extra, self::invitation_status_vars( (string) $sub->status ) );

			if ( self::send_to_user( (int) $sub->user_id, $rereg, $template, $extra ) ) {
				++$count;
				$mailed[] = (int) $sub->id;
			}
		}

		// Only the ones that actually went out. A send that failed leaves the
		// row unstamped, so the next run tries it again instead of burying it.
		ReregistrationSubmissionWriter::mark_invited( $mailed );

		// Activity log.
		self::log(
			'reregistration_invitations_sent',
			0,
			array(
				'reregistration_id' => $reregistration_id,
				'count'             => $count,
			)
		);

		return $count;
	}

	/**
	 * The two invitation variables that depend on what the person has already
	 * done, keyed on the submission's status at send time.
	 *
	 * WHY STATUS AND NOT PROVENANCE (#1300)
	 *
	 * The obvious reading is "this row was imported, so do not tell them to
	 * fill anything in", and it is the wrong discriminator. Anyone who used
	 * the dashboard banner before the operator pressed *Send invitations* is
	 * in exactly the same position, and has been since long before the CSV
	 * import existed -- the banner shows for any active campaign, independently
	 * of `invited_at`. One sentence keyed on the status covers both, and needs
	 * no "was imported" marker: `send_invitations()` already holds each row.
	 *
	 * The alternative that was rejected is excluding these people from the
	 * invitation altogether. It would strand a created account without
	 * `{{set_password_url}}`, which is the one thing it actually needs (#1212).
	 *
	 * THE HONEST LIMITATION
	 *
	 * The invitation body is operator-editable, and these two tokens are a new
	 * kind of thing in that editor: the operator can move them or delete them,
	 * but not change what they say. An install that has ALREADY customised the
	 * body carries its own copy of the old fixed sentence, so nothing there
	 * changes either -- the tokens are simply absent. Both are accepted rather
	 * than worked around; two tokens with editable defaults would mean teaching
	 * `EmailTemplateOptions` about per-value defaults, which is a larger change
	 * to make pre-emptively for a one-sentence difference.
	 *
	 * @since 6.26.0
	 * @param string $status Submission status at send time.
	 * @return array<string, string>
	 */
	private static function invitation_status_vars( string $status ): array {
		$recorded = in_array( $status, self::INVITATION_RECORDED_STATUSES, true );

		return array(
			'status_line'  => $recorded
				? __( 'Your reregistration is already recorded — you can review it on your dashboard.', 'ffcertificate' )
				: __( 'Please complete your reregistration before the deadline.', 'ffcertificate' ),
			'action_label' => $recorded
				? __( 'View My Reregistration', 'ffcertificate' )
				: __( 'Complete Reregistration', 'ffcertificate' ),
		);
	}

	/**
	 * Send reminder emails to pending/in-progress members.
	 *
	 * @param int        $reregistration_id Reregistration ID.
	 * @param array<int> $user_ids          Specific user IDs (empty = all pending).
	 * @return int Number of emails sent.
	 */
	public static function send_reminders( int $reregistration_id, array $user_ids = array() ): int {
		return self::dispatch_reminders( $reregistration_id, $user_ids, 0, 0 )['sent'];
	}

	/**
	 * One BATCH of reminders, and the rescheduling of the next when queue remains.
	 *
	 * WHY BATCH AT ALL
	 *
	 * `run_automated_reminders()` runs on wp-cron, that is, INSIDE A VISITOR'S
	 * REQUEST. Unbounded, a campaign of thousands of participants made that
	 * visitor pay for thousands of `wp_mail()` calls -- and, per participant, a
	 * phpass hash and an `UPDATE wp_users` on top, from
	 * `PasswordInvite::issue_for()`. The per-item stamp delivered in step 1
	 * already made the send resumable between daily runs, but the reach stayed
	 * limited to (what fits in one run) x `reminder_days`: on a large campaign
	 * the deadline expires before everyone has been reminded.
	 *
	 * THE PAYLOAD CARRIES THE CURSOR, AND THAT IS NOT OPTIONAL
	 *
	 * See the docblock of {@see ReregistrationSubmissionReader::get_awaiting_reminder()}:
	 * a submission whose user was deleted never receives a stamp, so a loop
	 * driven by `reminder_sent_at IS NULL` alone would fetch it forever. These
	 * are two scalars -- campaign id and cursor -- which is also what the `cron`
	 * option can carry for free: it is autoloaded and unserialized on EVERY
	 * request of the site, so a large payload is expensive everywhere, all the
	 * time.
	 *
	 * THE LAST PAGE IS WHAT ENDS IT
	 *
	 * A page smaller than the batch means the queue ran out; only a FULL page
	 * reschedules. A campaign of 50 or fewer finishes in a single run, exactly
	 * as it did before this step.
	 *
	 * @param int $reregistration_id The campaign ID.
	 * @param int $after_id          Cursor: only rows with an `id` greater than this.
	 * @return void
	 */
	public static function send_reminder_batch( int $reregistration_id, int $after_id = 0 ): void {
		$result = self::dispatch_reminders( $reregistration_id, array(), $after_id, self::REMINDER_BATCH_SIZE );

		if ( $result['seen'] < self::REMINDER_BATCH_SIZE ) {
			return;
		}

		$args = array( $reregistration_id, $result['last_id'] );

		// Without this guard, two cron runs over the same campaign -- possible
		// when two visitors fire wp-cron almost together -- would enqueue two
		// identical batches.
		if ( wp_next_scheduled( self::REMINDER_BATCH_HOOK, $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::REMINDER_BATCH_DELAY, self::REMINDER_BATCH_HOOK, $args );
	}

	/**
	 * The dispatch itself, shared by the manual path and by the cron batch.
	 *
	 * It returns `seen` and `last_id` besides `sent` because whoever decides to
	 * reschedule needs the PAGE SIZE, not how many emails went out: a full page
	 * on which three sends failed still has queue ahead of it, and stopping
	 * there would leave the rest of the campaign to the following day.
	 *
	 * @param int        $reregistration_id The campaign ID.
	 * @param array<int> $user_ids          Explicit IDs (the manual path).
	 * @param int        $after_id          Keyset cursor.
	 * @param int        $limit             Page size; `0` means no limit.
	 * @return array{sent: int, seen: int, last_id: int}
	 */
	private static function dispatch_reminders( int $reregistration_id, array $user_ids, int $after_id, int $limit ): array {
		$empty = array(
			'sent'    => 0,
			'seen'    => 0,
			'last_id' => $after_id,
		);

		if ( self::emails_disabled() ) {
			return $empty;
		}

		$rereg = ReregistrationRepository::get_by_id( $reregistration_id );
		if ( ! $rereg || empty( $rereg->email_reminder_enabled ) ) {
			return $empty;
		}

		$template = self::effective_template( 'reregistration-reminder' );
		if ( ! $template ) {
			return $empty;
		}

		// With explicit ids the operator is asking for a send to THOSE people,
		// so the mark does not filter -- it is a deliberate resend. Without
		// them it is the cron: there, who has not been reminded yet decides.
		if ( ! empty( $user_ids ) ) {
			$submissions = array();
			foreach ( $user_ids as $uid ) {
				$sub = ReregistrationSubmissionReader::get_by_reregistration_and_user( $reregistration_id, (int) $uid );
				if ( $sub && in_array( $sub->status, ReregistrationSubmissionReader::REMINDABLE_STATUSES, true ) ) {
					$submissions[] = $sub;
				}
			}
		} else {
			// One reminder per campaign, plus one on every deadline extension --
			// the same rule the invitation has applied since #1190, now with
			// `reminder_sent_at` in place of `invited_at` (#1232).
			$extended_at = isset( $rereg->deadline_extended_at ) ? (int) $rereg->deadline_extended_at : 0;
			$submissions = ReregistrationSubmissionReader::get_awaiting_reminder(
				$reregistration_id,
				$extended_at > 0 ? $extended_at : null,
				$after_id,
				$limit
			);
		}

		$days_left = max( 0, (int) ( ( strtotime( $rereg->end_date ) - time() ) / 86400 ) );

		$count   = 0;
		$last_id = $after_id;
		foreach ( $submissions as $sub ) {
			// The cursor advances even when the send fails. That is what stops a
			// submission whose user was deleted from jamming the queue: it is
			// stepped past today and retried in tomorrow's sweep.
			$last_id = (int) $sub->id;

			// In the reminder too: whoever never set a password can act on
			// neither the invitation NOR the reminder, and the dashboard button
			// requires a login.
			$extra = array(
				'days_left'        => (string) $days_left,
				'set_password_url' => \FreeFormCertificate\Core\PasswordInvite::issue_for( (int) $sub->user_id ),
			);
			if ( self::send_to_user( (int) $sub->user_id, $rereg, $template, $extra ) ) {
				// Stamped IMMEDIATELY, not at the end of the loop: this method
				// runs on wp-cron, inside a visitor's request, and a timeout
				// partway through would leave everyone who already received it
				// unmarked -- resending on the next run, which is the defect
				// this work fixes.
				ReregistrationSubmissionWriter::mark_reminded( (int) $sub->id );
				++$count;
			}
		}

		self::log(
			'reregistration_reminders_sent',
			0,
			array(
				'reregistration_id' => $reregistration_id,
				'count'             => $count,
			)
		);

		return array(
			'sent'    => $count,
			'seen'    => count( $submissions ),
			'last_id' => $last_id,
		);
	}

	/**
	 * Send confirmation email to a user after submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return bool
	 */
	public static function send_confirmation( int $submission_id ): bool {
		if ( self::emails_disabled() ) {
			return false;
		}

		$submission = ReregistrationSubmissionReader::get_by_id( $submission_id );
		if ( ! $submission ) {
			return false;
		}

		$rereg = ReregistrationRepository::get_by_id( (int) $submission->reregistration_id );
		if ( ! $rereg || empty( $rereg->email_confirmation_enabled ) ) {
			return false;
		}

		$template = self::effective_template( 'reregistration-confirmation' );
		if ( ! $template ) {
			return false;
		}

		$status_label = ReregistrationSubmissionReader::get_status_label( $submission->status );

		// Build magic link URL for direct verification.
		$magic_link_url = '';
		if ( ! empty( $submission->magic_token ) ) {
			$magic_link_url = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $submission->magic_token );
		}

		$auth_code_formatted = ! empty( $submission->auth_code )
			? \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $submission->auth_code, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_REREGISTRATION )
			: '';

		return self::send_to_user(
			(int) $submission->user_id,
			$rereg,
			$template,
			array(
				'submission_status' => $status_label,
				'magic_link_url'    => $magic_link_url,
				'auth_code'         => $auth_code_formatted,
			)
		);
	}

	/**
	 * Run automated reminders for all active campaigns.
	 *
	 * Called by the daily cron job. Sends reminders when:
	 * - Campaign is active
	 * - email_reminder_enabled = 1
	 * - Days until end_date <= reminder_days
	 *
	 * @return void
	 */
	public static function run_automated_reminders(): void {
		if ( self::emails_disabled() ) {
			return;
		}

		global $wpdb;
		$table = ReregistrationRepository::get_table_name();

		// Get active campaigns where reminder is due.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron sweep for campaigns whose reminder is due, over the plugin's own ffc_* table; a cached list is exactly what a due-date sweep must not read.
		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
                 WHERE status = 'active'
                   AND email_reminder_enabled = 1
                   AND DATEDIFF(end_date, CURDATE()) <= reminder_days
                   AND DATEDIFF(end_date, CURDATE()) >= 0",
				$table
			)
		);

		if ( empty( $campaigns ) ) {
			return;
		}

		foreach ( $campaigns as $campaign ) {
			// The first batch is SYNCHRONOUS, the rest rescheduled. A campaign of
			// `REMINDER_BATCH_SIZE` or fewer finishes right here, identical to
			// the previous behaviour; only the large ones become a queue.
			self::send_reminder_batch( (int) $campaign->id, 0 );
		}
	}

	/**
	 * The effective subject + body for a reregistration email — the admin's SMTP
	 * email-body-hub override when set, else the shipped file default (#662, hub
	 * #964). Returns null (⇒ callers skip the send) when the body is unavailable,
	 * matching the previous `EmailTemplates::load()` null-guard.
	 *
	 * @param string $name Allowlisted template basename.
	 * @return array{subject:string, body:string}|null
	 */
	private static function effective_template( string $name ): ?array {
		$body = \FreeFormCertificate\Core\EmailTemplates::effective_body( $name, 'body' );
		if ( '' === $body ) {
			return null;
		}
		return array(
			'subject' => \FreeFormCertificate\Core\EmailTemplates::effective_body( $name, 'subject' ),
			'body'    => $body,
		);
	}

	/**
	 * Send an email to a specific user.
	 *
	 * @param int                   $user_id     User ID.
	 * @param object                $rereg       Reregistration object.
	 * @param array<string, string> $template    Template with 'subject' and 'body' keys.
	 * @param array<string, string> $extra_vars  Additional template variables.
	 * @phpstan-param ReregistrationRow $rereg
	 * @return bool
	 */
	private static function send_to_user( int $user_id, object $rereg, array $template, array $extra_vars = array() ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		// `get_option()` is mixed and this one is written by the
		// dashboard activator as a post id; anything that is not a
		// number falls back rather than being cast (#1060).
		$dashboard_page_id = get_option( 'ffc_dashboard_page_id' );
		$dashboard_url     = is_numeric( $dashboard_page_id ) && (int) $dashboard_page_id > 0
			? get_permalink( (int) $dashboard_page_id )
			: home_url( '/dashboard' );

		$variables = array_merge(
			array(
				'user_name'            => $user->display_name,
				'reregistration_title' => $rereg->title,
				'audience_name'        => $rereg->audience_name ?? '',
				'start_date'           => DateFormatter::format_date( $rereg->start_date ),
				'end_date'             => DateFormatter::format_date( $rereg->end_date ),
				'dashboard_url'        => $dashboard_url,
				'site_name'            => get_bloginfo( 'name' ),
			),
			$extra_vars
		);

		// An empty `href` is worse than an ordinary link: `issue_for()` only
		// returns '' when the key could not be issued, and in that case the
		// email still goes out. Degrading to the dashboard keeps the button
		// useful for whoever already has a password and never produces a dead
		// link (#1212).
		if ( isset( $variables['set_password_url'] ) && '' === $variables['set_password_url'] ) {
			$variables['set_password_url'] = $dashboard_url;
		}

		$tokens = array();
		foreach ( $variables as $key => $value ) {
			$tokens[ '{{' . $key . '}}' ] = (string) $value;
		}
		$subject = \FreeFormCertificate\Core\TokenResolver::resolve( $template['subject'], $tokens );
		$body    = \FreeFormCertificate\Core\TokenResolver::resolve( $template['body'], $tokens );

		return SchedulingMailer::send( $user->user_email, $subject, $body, array(), true, \FreeFormCertificate\Core\EmailSource::REREGISTRATION );
	}

	/**
	 * Check if all emails are globally disabled.
	 * Delegates to EmailHelperTrait::ffc_emails_disabled().
	 */
	private static function emails_disabled(): bool {
		return self::ffc_emails_disabled();
	}

	/**
	 * Log an email event.
	 *
	 * @param string               $type    Event type.
	 * @param int                  $user_id User ID (0 for system events).
	 * @param array<string, mixed> $data    Extra data.
	 * @return void
	 */
	private static function log( string $type, int $user_id, array $data ): void {
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				$type,
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				$data,
				$user_id
			);
		}
	}
}
