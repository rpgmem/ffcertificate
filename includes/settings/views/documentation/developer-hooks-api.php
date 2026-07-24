<?php
/**
 * Documentation partial — Developer: Hooks, REST & Forms API.
 *
 * The plugin's developer surface — action/filter hooks, the REST API (two
 * namespaces) and the authenticated Forms API. Reviewed against the code for
 * the functional reorganization (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Developer: Hooks, REST & Forms API Section -->
<div class="card">
	<h3 id="developer-hooks-api"><span class="dashicons dashicons-editor-code" aria-hidden="true"></span> <?php esc_html_e( 'Hooks, REST & Forms API', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'The plugin\'s developer surface: action/filter hooks to extend behavior, a REST API for integrations, and the authenticated Forms API. For the front-end shortcodes see the Shortcodes reference.', 'ffcertificate' ); ?> <a href="#reference-shortcodes"><?php esc_html_e( 'Shortcodes', 'ffcertificate' ); ?></a>.</p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Filters', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Filter', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Purpose', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>ffcertificate_email</code></td><td><?php esc_html_e( 'Last-mile hook on the composed message ( to / subject / body / headers / attachments ) just before send — after the global disable toggle, before the plain-text part is derived.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_email_plain_text</code></td><td><?php esc_html_e( 'Customize or suppress the auto-derived plain-text part (return an empty string for HTML-only).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_scheduling_email</code></td><td><?php esc_html_e( 'Filter the to / subject / body of scheduling (appointment / audience / reregistration) emails.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_mail_queue_active</code></td><td><?php esc_html_e( 'Override detection of the sibling total-mail-queue plugin.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_certificate_html</code></td><td><?php esc_html_e( 'Rewrite the certificate HTML before it is rendered to PDF.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_allowed_html_tags</code></td><td><?php esc_html_e( 'Adjust the allowed HTML tags in certificate templates.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_qrcode_url</code></td><td><?php esc_html_e( 'Change the URL a certificate QR code encodes.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_ficha_data</code> / <code>ffcertificate_ficha_html</code> / <code>ffcertificate_ficha_template_file</code></td><td><?php esc_html_e( 'Filter the Ficha PDF tokens, final HTML, or template file.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_rest_form_schema</code></td><td><?php esc_html_e( 'Filter the payload of GET /forms/{id}/schema.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_settings_tabs</code></td><td><?php esc_html_e( 'Register a custom settings tab.', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Action', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Fires', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>ffcertificate_after_submission_save</code></td><td><?php esc_html_e( 'After a submission is saved — args ( submission_id, form_id, submission_data, user_email ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_after_submission_update</code> / <code>ffcertificate_after_submission_delete</code></td><td><?php esc_html_e( 'After a submission is edited or deleted.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_after_appointment_create</code> / <code>ffcertificate_appointment_cancelled</code></td><td><?php esc_html_e( 'Self-scheduling appointment lifecycle.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_audience_booking_created</code> / <code>ffcertificate_audience_booking_cancelled</code></td><td><?php esc_html_e( 'Audience booking lifecycle ( booking_id ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_short_redirect</code></td><td><?php esc_html_e( 'Before a short-URL redirect is sent.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_export_completed</code></td><td><?php esc_html_e( 'After a CSV export finishes ( renamed from ffcertificate_csv_export_completed in 6.17.0 ).', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Signatures vary — check the source for the exact argument list before hooking.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'REST API', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Two namespaces: most endpoints live under', 'ffcertificate' ); ?> <code>ffc/v1</code>, <?php esc_html_e( 'while the Recruitment module lives under', 'ffcertificate' ); ?> <code>ffcertificate/v1</code>. <?php esc_html_e( 'Auth ranges from public, to logged-in, to capability-gated.', 'ffcertificate' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Endpoint', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Auth', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>GET /ffc/v1/forms/{id}/schema</code></td><td><?php esc_html_e( 'Public — read-only form structure.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>POST /ffc/v1/forms/{id}/submit</code> · <code>POST /ffc/v1/verify</code></td><td><?php esc_html_e( 'Public.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>GET /ffc/v1/calendars</code> · <code>…/{id}/slots</code></td><td><?php esc_html_e( 'Public (IP rate-limited).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>GET /ffc/v1/user/*</code> · <code>/ffc/v1/audience/bookings</code></td><td><?php esc_html_e( 'Logged-in (own data).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>GET /ffc/v1/submissions</code></td><td><?php esc_html_e( 'Admin capability.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>…/ffcertificate/v1/recruitment/*</code></td><td><?php esc_html_e( 'Recruitment capabilities (manage / import / call / delete).', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Forms API', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The authenticated read slice of ffc/v1, gated by the ffc_view_forms_api capability (via a WordPress Application Password or a same-origin cookie):', 'ffcertificate' ); ?></p>
		<ul>
			<li><code>GET /ffc/v1/forms</code> — <?php esc_html_e( 'paginated list (use', 'ffcertificate' ); ?> <code>?per_page=20&amp;page=1</code>, <?php esc_html_e( 'per_page capped at 100). Returns id, title, status, dates and link.', 'ffcertificate' ); ?></li>
			<li><code>GET /ffc/v1/forms/{id}</code> — <?php esc_html_e( 'one form\'s metadata.', 'ffcertificate' ); ?></li>
			<li><code>GET /ffc/v1/forms/{id}/schema</code> — <?php esc_html_e( 'public; the field structure ( name, label, type, required, options ) integrations need to build a matching form.', 'ffcertificate' ); ?></li>
		</ul>
		<pre><code>curl -u user:app_password "https://example.com/wp-json/ffc/v1/forms?per_page=20&amp;page=1"</code></pre>
	</div>
</div>
