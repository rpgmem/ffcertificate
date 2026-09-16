<?php
/**
 * Documentation partial — Reregistration: Campaigns.
 *
 * Reregistration campaigns: configuration, the dashboard-delivered member flow,
 * statuses, custom fields, integrations and capabilities. Reviewed against the
 * reregistration module for the functional reorganization
 * (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Reregistration: Campaigns Section -->
<div class="card">
	<h3 id="feature-reregistration"><span class="dashicons dashicons-update-alt" aria-hidden="true"></span> <?php esc_html_e( 'Reregistration Campaigns', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'A reregistration campaign collects updated information from the members of one or more audiences over a set period, with optional emails and an approval workflow. Campaigns are managed under the Reregistration admin menu.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Workflow', 'ffcertificate' ); ?></h4>
		<ol>
			<li><?php esc_html_e( 'Create a campaign and link it to one or more audiences.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Set the start and end dates.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Configure the emails (invitation, reminder, confirmation) and whether submissions auto-approve.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Activate the campaign — a submission is created for every audience member and invitation emails go out.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Members see a banner on their dashboard and fill in the form there.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Admins review and approve or reject each submission (unless auto-approve is on).', 'ffcertificate' ); ?></li>
		</ol>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Campaign settings', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Auto-approve', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'submissions are approved on submit instead of waiting for review.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Invitation email', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'sent to all members when the campaign is activated.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Reminder email', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'sent automatically when the deadline is within N days (default 7). Each member receives it ONCE per campaign, not once a day: the send is stamped on the submission, and the daily sweep skips whoever already has that stamp.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Confirmation email', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'sent after a member submits; carries the Record magic link.', 'ffcertificate' ); ?></li>
		</ul>
		<p class="description"><?php esc_html_e( 'All three emails go through the shared Email Model chrome and the one pipeline.', 'ffcertificate' ); ?> <a href="#reference-emails"><?php esc_html_e( 'See Emails & Delivery', 'ffcertificate' ); ?></a>.</p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'When a reminder is sent again', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The reminder is sent once per member per campaign. There are exactly two ways it goes out a second time, and both are deliberate:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'You extend the deadline', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'moving the end date forward reopens the reminder for everyone who has not finished, once per extension. Extending twice sends twice. This mirrors how the invitation already behaves.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'You send it by hand', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'resending to specific members from the campaign screen always goes out, whether or not they were already reminded.', 'ffcertificate' ); ?></li>
		</ul>
		<p class="description"><strong><?php esc_html_e( 'A manual resend replaces that member\'s automatic reminder — it does not add to it.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'The manual send stamps the submission just like the automatic one, so the daily sweep will skip that member for the rest of the campaign. Someone you remind by hand today will not also be reminded by the system tomorrow.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Large campaigns: reminders go out in batches', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'A campaign with up to 50 members awaiting a reminder is sent in one go, exactly as before. Above that, the daily sweep sends the first 50 and queues the rest, 50 at a time, about a minute apart. Nobody receives two emails: each send is stamped on the member the moment it goes out, so a batch that is interrupted resumes where it stopped instead of starting over.', 'ffcertificate' ); ?></p>
		<p class="description"><strong><?php esc_html_e( 'The one-minute spacing is a floor, not a promise.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'WordPress runs scheduled tasks when a visitor loads a page, so on a quiet site the next batch goes out when the next visitor arrives. If a large campaign has to finish within a set window, ask your host to run WP-Cron from the server (DISABLE_WP_CRON plus a system cron) instead of relying on visitor traffic.', 'ffcertificate' ); ?></p>
		<p class="description"><?php esc_html_e( 'If the sibling total-mail-queue plugin is installed, these emails land in its queue rather than being sent one by one — check its own settings for how many it releases per interval, since its defaults are deliberately slow and a large campaign can outrun them.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'The member form', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'There is no separate reregistration shortcode. Active campaigns appear as a banner on the member\'s personal dashboard (the user_dashboard_personal shortcode); the form loads and submits there over AJAX. Members are targeted by audience membership, not by matching a CPF/RF. On submit, the plugin generates an authentication code and a Record magic link, syncs mapped fields to the user profile, and sends the confirmation email.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Statuses', 'ffcertificate' ); ?></h4>
		<p><strong><?php esc_html_e( 'Campaign:', 'ffcertificate' ); ?></strong> <code>draft</code> · <code>active</code> · <code>expired</code> · <code>closed</code>.</p>
		<p><strong><?php esc_html_e( 'Submission:', 'ffcertificate' ); ?></strong></p>
		<ul>
			<li><code>pending</code> — <?php esc_html_e( 'created for the member, not started.', 'ffcertificate' ); ?></li>
			<li><code>in_progress</code> — <?php esc_html_e( 'a draft was saved but not submitted.', 'ffcertificate' ); ?></li>
			<li><code>submitted</code> — <?php esc_html_e( 'submitted, awaiting review.', 'ffcertificate' ); ?></li>
			<li><code>approved</code> / <code>rejected</code> — <?php esc_html_e( 'reviewed (rejections can carry notes).', 'ffcertificate' ); ?></li>
			<li><code>expired</code> — <?php esc_html_e( 'the campaign ended before submission.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Fields & Record', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'A campaign shows the union of the custom fields of its linked audiences (standard identity/contact fields plus any custom ones). Each submission can be exported as a Record PDF.', 'ffcertificate' ); ?> <a href="#feature-audiences"><?php esc_html_e( 'See Audience Custom Fields', 'ffcertificate' ); ?></a> <?php esc_html_e( 'and', 'ffcertificate' ); ?> <a href="#feature-record"><?php esc_html_e( 'Record PDF', 'ffcertificate' ); ?></a>.</p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Integrations (read-only REST)', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Endpoint', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Returns', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>GET /wp-json/ffc/v1/forms/{id}/schema</code></td><td><?php esc_html_e( 'Lightweight read-only form metadata (id, title, fields).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>GET /wp-json/ffc/v1/user/reregistrations</code></td><td><?php esc_html_e( 'The current user\'s reregistration submissions.', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Submitting and saving drafts happen over the dashboard AJAX endpoints, not REST.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
		<ul>
			<li><code>ffc_view_reregistration</code> — <?php esc_html_e( 'view campaigns and submissions.', 'ffcertificate' ); ?></li>
			<li><code>ffc_manage_reregistration</code> — <?php esc_html_e( 'create/edit campaigns, approve/reject, manage custom fields, generate Records.', 'ffcertificate' ); ?></li>
			<li><code>ffc_export_reregistration</code> / <code>ffc_delete_reregistration</code> — <?php esc_html_e( 'export CSV / delete a campaign.', 'ffcertificate' ); ?></li>
		</ul>
		<p class="description"><?php esc_html_e( 'The member form itself is gated by login + audience membership, not a capability.', 'ffcertificate' ); ?></p>
	</div>
</div>
