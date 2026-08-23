<?php
/**
 * Documentation partial — Reference: Emails & Delivery.
 *
 * The one-pipeline email architecture (#662): the SMTP transport, the shared
 * "Email Model" chrome, the per-email "Email texts" hub, the token sets, the
 * global disable toggle and deliverability. Reflects the SMTP-tab split into
 * three sibling tabs — SMTP / Email Model / Email texts (#976).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Emails & Delivery Section -->
<div class="card">
	<h3 id="reference-emails"><span class="dashicons dashicons-email" aria-hidden="true"></span> <?php esc_html_e( 'Emails & Delivery', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'Every email the plugin sends — certificate delivery, admin notifications, recruitment convocations, booking confirmations, reregistration invitations, audience notices — goes through one shared pipeline with one configurable look.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'The one pipeline', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Each email is composed as an inner body, wrapped in a single configurable chrome (header / body card / footer), then sent through one transport chokepoint:', 'ffcertificate' ); ?></p>
		<pre><code><?php echo esc_html__( 'email body  ->  configurable chrome ("Email Model")  ->  send (wp_mail / SMTP)', 'ffcertificate' ); ?></code></pre>
		<p><?php esc_html_e( 'This means every email shares the same branded look, and one global switch can turn all of them off.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Three email tabs.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'The email settings live in the Communication group as three sibling tabs, read in this order:', 'ffcertificate' ); ?>
		</p>
		<ul>
			<li><strong><?php esc_html_e( 'SMTP', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the transport (how mail leaves the server) plus the global "Disable all emails" switch.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Email Model', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the shared chrome (header / body card / footer) that wraps every email, with a live preview and a test-send.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Email texts', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the per-email subject + body editor for every plugin email, plus a read-only "All plugin emails" directory.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'SMTP setup', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The SMTP tab controls how mail leaves the server:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'WordPress default:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'uses the server\'s PHP mail(). Simple, but frequently flagged as spam.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Custom SMTP (recommended):', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'authenticate against a real provider (host, port, user, password, TLS). The "Popular SMTP Providers" box lists common presets and appears when Custom SMTP is selected.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'The "Email Model" chrome', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The Email Model tab styles the shell shared by every email: header band (logo or site name, colors, alignment, padding), body card (colors, font, size, width), footer (colors + tokenized text) and outer wrapper. It has a live preview and a "Restore default model" button. You edit only the chrome here — the message text of each email is separate, on the Email texts tab.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'The "Email texts" hub — edit every email\'s wording', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The Email texts tab is the single place to edit the default subject + body of every plugin email. It is no longer just three editable emails — all the plugin\'s token-based emails are editable here, grouped by feature. Pick an email from the selector and only that one\'s editor opens; each has its own {{token}} help and a "Restore Default Text" button. A subject + body left equal to the shipped default clears the override, so the email keeps tracking the built-in default automatically.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'The emails you can edit here (grouped by feature):', 'ffcertificate' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Feature', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Emails', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><strong><?php esc_html_e( 'Certificates', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Certificate delivered to the user', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Self-scheduling', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Booking confirmation, approval, cancellation, reminder, waitlist-promotion, added-to-waitlist, and the "calendar deleted → appointment cancelled" notice', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Reregistration', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Campaign invitation, reminder and confirmation', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Audiences', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'New scheduled activity and activity-cancelled notices', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Recruitment', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Recruitment call (convocation)', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Account access', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Access-granted notice (sent when a user gains plugin capabilities)', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
		<p><?php esc_html_e( 'Subjects follow one shape — "Event: {{reference}}" (a short event title, a colon, then the record it refers to). Bodies follow one visual standard — an event-title heading in a semantic colour (green = confirmed, red = cancelled, amber = reminder, purple = waitlist, blue = informational), a greeting, one context line, and a details box.', 'ffcertificate' ); ?> <a href="#email-texts-hub"><?php esc_html_e( 'See the Email texts hub page.', 'ffcertificate' ); ?></a></p>
		<p><?php esc_html_e( 'Editing here is gated by the dedicated ffc_manage_email_templates capability, so email-copy editing can be delegated — or withheld — independently of the blanket Settings capability. Individual forms can still override the certificate email per-form (see Forms → Email).', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Two token sets', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Placeholders use double braces. Two distinct sets exist:', 'ffcertificate' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Set', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Resolved in', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tokens', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Chrome tokens', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'the shell (footer text, logo alt) — edited on the Email Model tab', 'ffcertificate' ); ?></td>
					<td><code>{{site_title}}</code>, <code>{{site_url}}</code>, <code>{{home_url}}</code>, <code>{{admin_email}}</code>, <code>{{recipient}}</code>, <code>{{date}}</code>, <code>{{year}}</code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Body tokens', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'each email body (per email) — edited on the Email texts tab', 'ffcertificate' ); ?></td>
					<td><?php esc_html_e( 'the email\'s own placeholders — e.g.', 'ffcertificate' ); ?> <code>{{name}}</code>, <code>{{form_title}}</code>, <code>{{auth_code}}</code>, <code>{{user_name}}</code>, <code>{{calendar_title}}</code>, <code>{{schedule_name}}</code></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Turning emails off', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The global "Disable all emails" toggle (SMTP tab) is enforced at the single send chokepoint, so it is bypass-proof — nothing is sent while it is on. Every email-editing screen shows a gentle notice while it is active, so you know settings are saved but not sent.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Deliverability', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Multipart:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'every HTML email is sent as multipart/alternative — the HTML plus an auto-derived plain-text part — which improves spam scoring and text-only client rendering. No configuration needed.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Bulk sends:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'install the sibling total-mail-queue plugin for queueing, retries and backoff. Because every plugin email goes through wp_mail(), it is captured automatically once activated.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Developer hooks:', 'ffcertificate' ); ?></strong> <code>ffcertificate_email</code> <?php esc_html_e( '(inspect/rewrite the composed message before send) and', 'ffcertificate' ); ?> <code>ffcertificate_email_plain_text</code> <?php esc_html_e( '(customize or suppress the plain-text part). See the Developer page.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-alert ffc-alert-info ffc-mt-20">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Not receiving email?', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Check the "Disable all emails" toggle (SMTP tab), configure Custom SMTP, and see Troubleshooting → "Emails not arriving".', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
