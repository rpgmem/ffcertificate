<?php
/**
 * Deactivator
 * This class handles the logic when the plugin is deactivated or uninstalled.
 *
 * @package FreeFormCertificate
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivator.
 */
class Deactivator {

	/**
	 * Cron hook names used before the `ffc_` prefix (#2323).
	 *
	 * @var list<string>
	 */
	public const LEGACY_CRON_HOOKS = array(
		'ffcertificate_daily_cleanup_hook',
		'ffcertificate_reregistration_expire_hook',
		'ffcertificate_self_scheduling_reminder_scan',
		'ffcertificate_self_scheduling_closeout',
		'ffcertificate_audience_post_event_invites',
		'ffcertificate_bulk_issue_purge',
	);

	/**
	 * Deactivation hook logic.
	 * Usually, we only flush rewrite rules here to avoid breaking permalinks.
	 */
	public static function deactivate(): void {
		// Clear scheduled cron tasks.
		wp_clear_scheduled_hook( 'ffc_daily_cleanup' );
		wp_unschedule_hook( 'ffc_activity_log_cleanup_continue' );
		wp_clear_scheduled_hook( 'ffc_bulk_issue_purge' );
		wp_clear_scheduled_hook( 'ffc_self_scheduling_reminder_scan' );
		wp_clear_scheduled_hook( 'ffc_self_scheduling_closeout' );
		wp_clear_scheduled_hook( 'ffc_audience_post_event_invites' );
		wp_clear_scheduled_hook( 'ffc_reregistration_expire' );
		// The names these hooks had before #2323. The relay that moved their
		// events is gone, so an install that skipped the releases carrying it
		// may still hold one; clearing it costs nothing.
		foreach ( self::LEGACY_CRON_HOOKS as $legacy_hook ) {
			wp_clear_scheduled_hook( $legacy_hook );
		}
		// A pending reminder batch would otherwise fire into code that is no
		// longer loaded (#1538 -- it was cleared nowhere).
		wp_unschedule_hook( 'ffc_reregistration_reminder_batch' );
		wp_unschedule_hook( 'ffc_reregistration_invitation_batch' );
		wp_unschedule_hook( 'ffc_recruitment_account_link_batch' );
		wp_unschedule_hook( 'ffc_audience_notification_batch' );
		// The one-shot submission pipeline and its pre-#1248 name (#2343): a
		// pending event would otherwise fire into code that is not loaded.
		wp_unschedule_hook( \FreeFormCertificate\Submissions\SubmissionHandler::ASYNC_PIPELINE_HOOK );
		wp_unschedule_hook( 'ffcertificate_process_submission_hook' );
		\FreeFormCertificate\Admin\ExpiredTicketsCleanup::unschedule();
		\FreeFormCertificate\Integrations\CloudflareCidrRefresh::unschedule();
		\FreeFormCertificate\DateMessages\DateMessagesCron::unschedule();
		\FreeFormCertificate\Submissions\FormCache::unschedule_cache_warming();

		flush_rewrite_rules();
	}
}
