<?php
/**
 * Documentation partial — Configuration: Activity Log viewer.
 *
 * Settings → Activity Log: the audit-trail viewer (filters, table, stats, CSV
 * export). Distinct from the *logging configuration* (enable / retention /
 * level / items) documented on the Advanced page. Reviewed against
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
	<h3 id="config-activity-log" class="ffc-icon-history"><?php esc_html_e( 'Activity Log', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Settings → Activity Log is the read side of the audit trail. It shows who did what and when — submissions, exports, deletions, settings changes, revealed personal data and the other events the plugin records. You configure what is recorded (the switch, the retention window, the level and the audit and secondary items) on the Advanced page; this tab is where you read and export what was recorded.', 'ffcertificate' ); ?> <a href="#config-advanced"><?php esc_html_e( 'See Advanced → Activity Log settings', 'ffcertificate' ); ?></a>.</p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'What the viewer offers', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Filters', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'narrow the table by level, action, user (or System / Anonymous, for what no account did) and a From / To date range in the site\'s timezone, where To covers the whole day; or search the entries by text. The line above the table counts the entries that match, and "No entries match these filters" means a filter is set, as opposed to a log with nothing recorded yet.', 'ffcertificate' ); ?> <?php esc_html_e( 'Each action is listed by a readable name, such as "Appointment Personal Data Revealed" or "Audience Account Email Revealed"; an action with no name of its own shows its code, with spaces for the underscores.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Statistics', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'an activity summary for the last 30 days: the total, the counts by level and the most frequent actions.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Export CSV', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'download the log as a CSV file with the filters on screen when you click it. It runs as a timeout-safe background job, so a long history exports cleanly on shared hosting. The button shows only to holders of the export capability.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
		<ul>
			<li><code>ffc_view_activity_log</code> — <?php esc_html_e( 'reach the tab and inspect the audit trail, with the filters, the search and the Reveal buttons working. Independent of the settings view capability, so it can be granted to an account without any other settings access.', 'ffcertificate' ); ?></li>
			<li><code>ffc_export_activity_log</code> — <?php esc_html_e( 'download the audit trail as CSV. Split out from the view capability (rpgmem/ffcertificate#711) so a view-only operator cannot bulk-extract the trail.', 'ffcertificate' ); ?></li>
			<li><code>ffc_view_activity_log_pii</code> — <?php esc_html_e( 'reveal the client address of an entry, or its details, with the Reveal button, and export them in clear. Without it every address, e-mail address and CPF, RF or other personal value on the tab, in an entry\'s details and in the CSV is masked. A clear export is recorded in the log; a reveal on this tab is not, since each one would add an entry to the log being read.', 'ffcertificate' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Among the FFC roles only FFC Administrator holds these three: the log records what every other role did, so no module role and not FFC Read-only carries it. WordPress administrators keep it too.', 'ffcertificate' ); ?></p>
	</div>
</div>
