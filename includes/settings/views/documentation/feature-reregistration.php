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
		<h4><?php esc_html_e( 'Importing answers from a spreadsheet', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'A campaign\'s edit screen carries an import panel: pick one of the campaign\'s audiences, choose a CSV, and the answers are filled in on behalf of those people. It is for the case where the information was already collected elsewhere — on paper, or in a spreadsheet a department keeps — and asking everyone to retype it into the form is not reasonable.', 'ffcertificate' ); ?></p>

		<p><strong><?php esc_html_e( 'One import covers one audience.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'The columns of the file are that audience\'s fields, which is what makes the header unambiguous. A campaign that reaches three audiences takes three imports.', 'ffcertificate' ); ?></p>

		<h4><?php esc_html_e( 'The file', 'ffcertificate' ); ?></h4>
		<ul>
			<li><?php esc_html_e( 'The first row is the header. Each column is named by a field key or by the field label, matched exactly after trimming and ignoring case — never approximately, because a near-match on a mistyped column would silently fill the wrong field.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A column matching no field is ignored and listed back to you. Working notes and columns that are none of the plugin\'s business do not have to be removed first.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A required field with no column at all refuses the file immediately, naming the missing columns — every row would fail, so saying it once beats saying it five thousand times.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'CSV or TXT, up to 10 MB, UTF-8 (a byte-order mark is tolerated).', 'ffcertificate' ); ?></li>
		</ul>

		<h4><?php esc_html_e( 'Check first, then import', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( '"Check file" reads the whole file and reports without writing anything. Only then does "Import" become available. The report counts four things:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Rows in the file', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'everything below the header.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Will be imported', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'rows that will be written.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Already submitted, kept as is', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'that person answered the form themselves. Their own answers are kept and the row is skipped; this is expected, not an error, and it does not stop the import.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Failing', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'rows with a problem, listed by line number with what is wrong.', 'ffcertificate' ); ?></li>
		</ul>
		<p><strong><?php esc_html_e( 'One failing row stops the whole file.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Nothing is imported until every row passes. That is deliberate: the writing happens in batches across several requests and cannot be undone halfway, so the only honest place to guarantee all-or-nothing is before the first write. Fix the lines the report names and check the file again.', 'ffcertificate' ); ?></p>

		<h4><?php esc_html_e( 'Who each row belongs to', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'A row is matched to an existing account by CPF, then by RF, then by e-mail — the same resolution certificates and appointments use, so somebody already known to the plugin is matched rather than duplicated. Only when none of the three matches is an account created, and the person is added to the chosen audience either way.', 'ffcertificate' ); ?></p>
		<p class="description"><strong><?php esc_html_e( 'Two rows resolving to the same account fail the file.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'That is what a shared department mailbox looks like from here: matching by e-mail binds every one of those rows to whoever owns the address. Give each person their own address, or their CPF/RF.', 'ffcertificate' ); ?></p>

		<h4><?php esc_html_e( 'What it does not send', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'An import sends no e-mail — neither the "your account was created" notice nor the submission confirmation. A confirmation would tell somebody their reregistration was received when an operator filed it for them, and at import scale it is one message per row. Use the campaign\'s own invitation to tell people: it reaches imported rows too, and carries the link they need to set a password.', 'ffcertificate' ); ?></p>

		<h4><?php esc_html_e( 'Safety and privacy', 'ffcertificate' ); ?></h4>
		<ul>
			<li><?php esc_html_e( 'Importing needs its own capability, ffc_import_reregistration — holding "manage" is not enough. Loading answers for people who never opened the form writes personal data on their behalf, so it is granted separately.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'The uploaded file is never stored. It is read into a temporary staging area in the database, which is emptied when the import finishes and swept automatically after a day if it is abandoned.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Sensitive fields are encrypted exactly as they are when a member submits the form — the import writes through the same code path, never around it.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'An import in progress belongs to whoever started it. A second operator cannot continue or finish it.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Re-importing the same file cannot duplicate anybody: there is one record per person per campaign, enforced by the database.', 'ffcertificate' ); ?></li>
		</ul>
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
			<li><code>ffc_import_reregistration</code> — <?php esc_html_e( 'load answers from a spreadsheet. Separate from manage on purpose: it writes personal data for people who never opened the form.', 'ffcertificate' ); ?></li>
		</ul>
		<p class="description"><?php esc_html_e( 'The member form itself is gated by login + audience membership, not a capability.', 'ffcertificate' ); ?></p>
	</div>
</div>
