<?php
/**
 * Documentation partial — Configuration: Scheduled Tasks.
 *
 * Settings → Scheduled Tasks: the read-out of every WP-Cron task the plugin
 * schedules (next run, last real run, lateness), the per-task time of day for
 * daily tasks, and the server crontab line generator. Documented against
 * TabScheduledTasks / ffc-tab-scheduled-tasks.php and Core\ScheduledTasks
 * (rpgmem/ffcertificate#1538).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Configuration: Scheduled Tasks Section -->
<div class="card">
	<h3 id="config-scheduled-tasks"><span class="dashicons dashicons-clock" aria-hidden="true"></span> <?php esc_html_e( 'Scheduled Tasks', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'Settings → Scheduled Tasks lists the background tasks the plugin runs through WP-Cron — cleanups, reminders, cache warming, date messages and the like — and helps you keep them running on time.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'The task table', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Frequency, next run and last run', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'WordPress only records when a task is due, so the plugin records the last real run itself; it appears after a task first runs.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'State', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'a recurring task whose last run is older than its interval allows is marked late, and a warning tops the page. A task whose module is turned off on the Modules tab still fires but does nothing.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Time of day', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'each daily task can be given a time, in the site timezone. Leave it empty to keep the task where it is. Saving times needs the settings-management capability.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Queued one-off tasks', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'tasks created per piece of work (one per submission, per reminder batch, per date-messages batch) and removed once they run. A number that keeps growing means WP-Cron is not running.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<h4><?php esc_html_e( 'Server cron', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'By default WP-Cron runs only when someone visits the site, so on a quiet site a daily task can run hours late. The tab generates a crontab line — WP-CLI (recommended, needs WP-CLI on the server), or HTTP with wget or curl (works on any host) — at an interval you pick. Add it with "crontab -e" as the user that owns the WordPress files, or paste it into your hosting panel.', 'ffcertificate' ); ?></p>
	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'The line does not decide when tasks run.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'WordPress keeps each task\'s schedule; the server line only makes sure due tasks run promptly, so a short interval is right. Once the line is installed you can add DISABLE_WP_CRON to wp-config.php so visits stop triggering WP-Cron — but with that constant set and no server line, no task runs at all.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
