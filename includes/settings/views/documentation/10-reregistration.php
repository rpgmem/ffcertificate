<?php
/**
 * Documentation partial — Section 10: Reregistration.
 *
 * Extracted from `ffc-tab-documentation.php` per S8 of the
 * god-object refactor (rpgmem/ffcertificate#141).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 10. Reregistration Section -->
<div class="card">
	<h3 id="reregistration" class="ffc-icon-note"><?php esc_html_e( '10. Reregistration', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Create reregistration campaigns to collect updated information from audience members. Campaigns run for a set period and can include email notifications.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Workflow:', 'ffcertificate' ); ?></h4>
		<ol>
			<li><?php esc_html_e( 'Create a reregistration campaign linked to an audience', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Set the start and end dates for the campaign period', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Configure email notifications (invitation, reminder, confirmation)', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Activate the campaign — invitation emails are sent automatically', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Users see a banner on their dashboard and complete the reregistration form', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Admins review and approve/reject submissions', 'ffcertificate' ); ?></li>
		</ol>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Campaign Settings:', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Setting', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Auto-approve', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'Automatically approve submissions without manual review', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Invitation Email', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'Send email to all audience members when campaign is activated', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Reminder Email', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'Send reminder email N days before the deadline (configurable)', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Confirmation Email', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'Send confirmation email after user submits the form', 'ffcertificate' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Submission Statuses:', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong>pending</strong> &mdash; <?php esc_html_e( 'User has not yet submitted', 'ffcertificate' ); ?></li>
			<li><strong>draft</strong> &mdash; <?php esc_html_e( 'User saved a draft but did not submit', 'ffcertificate' ); ?></li>
			<li><strong>submitted</strong> &mdash; <?php esc_html_e( 'Submitted and awaiting review', 'ffcertificate' ); ?></li>
			<li><strong>approved</strong> &mdash; <?php esc_html_e( 'Approved by admin', 'ffcertificate' ); ?></li>
			<li><strong>rejected</strong> &mdash; <?php esc_html_e( 'Rejected by admin (with notes)', 'ffcertificate' ); ?></li>
			<li><strong>expired</strong> &mdash; <?php esc_html_e( 'Campaign ended without submission', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'REST API Endpoints:', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Method', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Endpoint', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>GET</code></td>
					<td><code>/wp-json/ffc/v1/forms/{id}/schema</code></td>
					<td><?php esc_html_e( 'Read-only form metadata (id, title, fields) for integrations', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>GET</code></td>
					<td><code>/wp-json/ffc/v1/user/reregistrations</code></td>
					<td><?php esc_html_e( 'List active reregistrations for the current user', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>POST</code></td>
					<td><code>/wp-json/ffc/v1/user/reregistration/{id}/submit</code></td>
					<td><?php esc_html_e( 'Submit reregistration form data', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>POST</code></td>
					<td><code>/wp-json/ffc/v1/user/reregistration/{id}/draft</code></td>
					<td><?php esc_html_e( 'Save draft without submitting', 'ffcertificate' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</div>
