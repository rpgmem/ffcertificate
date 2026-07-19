<?php
/**
 * Documentation partial — Reference: Emails & Delivery.
 *
 * Documents the one-pipeline email architecture (#662), the configurable
 * "Email Model" chrome, the two token sets, the global disable toggle and
 * deliverability (multipart + total-mail-queue) — the reorganization from #674.
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
	<p><?php esc_html_e( 'Every email the plugin sends — certificate delivery, admin notifications, recruitment convocations, booking confirmations, reregistration invitations — goes through one shared pipeline with one configurable look.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'The one pipeline', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Each email is composed as an inner body, wrapped in a single configurable chrome (header / body card / footer), then sent through one transport chokepoint:', 'ffcertificate' ); ?></p>
		<pre><code><?php echo esc_html__( 'email body  ->  configurable chrome ("Email Model")  ->  send (wp_mail / SMTP)', 'ffcertificate' ); ?></code></pre>
		<p><?php esc_html_e( 'This means every email shares the same branded look, and one global switch can turn all of them off.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'SMTP setup', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Settings → SMTP controls how mail leaves the server:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'WordPress default:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'uses the server\'s PHP mail(). Simple, but frequently flagged as spam.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Custom SMTP (recommended):', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'authenticate against a real provider (host, port, user, password, TLS). The "Popular SMTP Providers" box lists common presets and appears when Custom SMTP is selected.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'The "Email Model" chrome', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The Email Model box (Settings → SMTP) styles the shell shared by every email: header band (logo or site name, colors, alignment, padding), body card (colors, font, size, width), footer (colors + tokenized text) and outer wrapper. It has a live preview and a "Restore default model" button. You edit only the chrome here — the message text of each email is separate.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Email body vs. chrome', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Editable emails (certificate, recruitment convocation, self-scheduling confirmation) let you edit only the inner email body in a visual editor, with a "Restore Default Text" button. The plugin then wraps that body in the Email Model chrome automatically — you never edit the header/footer per email.', 'ffcertificate' ); ?></p>
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
					<td><?php esc_html_e( 'the shell (footer text, logo alt)', 'ffcertificate' ); ?></td>
					<td><code>{{site_title}}</code>, <code>{{site_url}}</code>, <code>{{home_url}}</code>, <code>{{admin_email}}</code>, <code>{{recipient}}</code>, <code>{{date}}</code>, <code>{{year}}</code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Body tokens', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'each email body (per email)', 'ffcertificate' ); ?></td>
					<td><?php esc_html_e( 'the email\'s own placeholders — e.g.', 'ffcertificate' ); ?> <code>{{name}}</code>, <code>{{form_title}}</code>, <code>{{auth_code}}</code>, <code>{{user_name}}</code>, <code>{{calendar_title}}</code></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Turning emails off', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The global "Disable all emails" toggle (Settings → SMTP) is enforced at the single send chokepoint, so it is bypass-proof — nothing is sent while it is on. Every email-editing screen shows a gentle notice while it is active, so you know settings are saved but not sent.', 'ffcertificate' ); ?></p>
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
			<?php esc_html_e( 'Check the "Disable all emails" toggle, configure Custom SMTP, and see Troubleshooting → "Emails not arriving".', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
