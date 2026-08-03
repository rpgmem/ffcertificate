<?php
/**
 * Documentation partial — Configuration: Activity Log viewer.
 *
 * Settings → Activity Log: the audit-trail viewer (filters, table, stats, CSV
 * export). Distinct from the *logging configuration* (enable / retention /
 * level / categories) documented on the Advanced page. Reviewed against
 * TabActivityLog / AdminActivityLogPage (rpgmem/ffcertificate#802).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Activity Log Section -->
<div class="card">
	<h3 id="config-activity-log"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <?php esc_html_e( 'Activity Log', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Settings → Activity Log is the read side of the audit trail. It shows who did what and when — logins to operator screens, submissions, exports, deletions, settings changes and the other events the plugin records. You configure what is recorded (turn logging on, the retention window, the level and which categories) on the Advanced page; this tab is where you read and export what was recorded.', 'ffcertificate' ); ?> <a href="#config-advanced"><?php esc_html_e( 'See Advanced → Activity Log settings', 'ffcertificate' ); ?></a>.</p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'What the viewer offers', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Filters', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'narrow the table by category, level, user and date range.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Statistics', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'a summary of event counts for the current view.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Export CSV', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'download the log as a CSV file, honouring the active filters. It runs as a timeout-safe background job, so a long history exports cleanly on shared hosting.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
		<ul>
			<li><code>ffc_view_activity_log</code> — <?php esc_html_e( 'reach the tab and inspect the audit trail. Deliberately independent of the settings view capability, so an audit-only operator can read the log without any other settings access.', 'ffcertificate' ); ?></li>
			<li><code>ffc_export_activity_log</code> — <?php esc_html_e( 'download the audit trail as CSV. Split out from the view capability (rpgmem/ffcertificate#711) so a view-only operator cannot bulk-extract the trail.', 'ffcertificate' ); ?></li>
		</ul>
	</div>
</div>
