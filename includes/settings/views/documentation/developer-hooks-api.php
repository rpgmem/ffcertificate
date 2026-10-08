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
	<h3 id="developer-hooks-api" class="ffc-icon-code"><?php esc_html_e( 'Hooks, REST & Forms API', 'ffcertificate' ); ?></h3>
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
				<tr><td><code>ffcertificate_scheduling_email</code></td><td><?php esc_html_e( 'Filter the to / subject / body of the audience, reregistration and date-message emails. Self-scheduling appointment emails do not pass through it (use ffcertificate_email).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_mail_queue_active</code></td><td><?php esc_html_e( 'Override detection of the sibling total-mail-queue plugin.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_certificate_html</code></td><td><?php esc_html_e( 'Rewrite the certificate HTML before it is rendered to PDF.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_allowed_html_tags</code></td><td><?php esc_html_e( 'Adjust the allowed HTML tags in certificate templates.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_qrcode_url</code></td><td><?php esc_html_e( 'Change the URL a certificate QR code encodes.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_record_data</code> / <code>ffcertificate_record_html</code> / <code>ffcertificate_record_filename</code> / <code>ffcertificate_record_template_html</code> / <code>ffcertificate_record_template_file</code></td><td><?php esc_html_e( 'Filter the Record PDF tokens, final HTML, filename, the pool-resolved template HTML, or the legacy template file. Renamed from the ffcertificate_ficha_* family in 6.26.0; the old names stopped firing in 6.28.0.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_ip_resolver_mode</code></td><td><?php esc_html_e( 'Client-IP resolution strategy for ClientIpResolver — "legacy" (default) or "secure" (trusted-proxy + Cloudflare autodetect). Normally set from the IP Diagnostics tab.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_trusted_proxies</code> / <code>ffc_cloudflare_ip_ranges</code></td><td><?php esc_html_e( 'Extend the trusted reverse-proxy CIDRs, or the Cloudflare edge ranges, the secure strategy honours when walking X-Forwarded-For / CF-Connecting-IP.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_ip_shadow_logging</code></td><td><?php esc_html_e( 'Opt in (off by default) to log where the legacy and secure IP strategies diverge, to validate a switch before flipping.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_validate_rf_check_digit</code></td><td><?php esc_html_e( 'Whether validate_rf() also requires the RF check digit, rejecting a mistyped number at the form instead of storing it. Off by default; normally set from Settings → General, and the filter overrides that setting — args ( enforce ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_rest_form_schema</code></td><td><?php esc_html_e( 'Filter the payload of GET /forms/{id}/schema.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_settings_tabs</code></td><td><?php esc_html_e( 'Register a custom settings tab.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_certificate_data</code></td><td><?php esc_html_e( 'The token map used to build the certificate HTML — args ( data, submission_id, submission_row ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_certificate_filename</code></td><td><?php esc_html_e( 'The certificate PDF filename — args ( filename, form_title, auth_code, submission_id ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_pdf_filename</code></td><td><?php esc_html_e( 'Every plugin PDF filename, before the per-type filters — args ( filename, type, entity_id, auth_code ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_qrcode_html</code></td><td><?php esc_html_e( 'The img tag that replaces a certificate {{qr_code}} placeholder — args ( img_html, url, submission_id ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_appointment_receipt_template_html</code> / <code>ffcertificate_appointment_receipt_template_file</code></td><td><?php esc_html_e( 'The appointment receipt template: return HTML to replace it — args ( html, schedule_type ) — or point to another file inside the plugin or theme — args ( file ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_appointment_receipt_filename</code> / <code>ffcertificate_appointment_receipt_bg_image</code></td><td><?php esc_html_e( 'The receipt PDF filename — args ( filename, calendar_id, validation_code, appointment ) — and its background image URL — args ( url, appointment, calendar ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_available_slots</code></td><td><?php esc_html_e( 'The bookable slots of a self-scheduling calendar day — args ( slots, calendar_id, date, calendar ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_self_scheduling_cancel_appointments_on_delete</code> / <code>ffcertificate_self_scheduling_send_deletion_notification</code></td><td><?php esc_html_e( 'When a calendar is deleted: whether its appointments are cancelled — args ( true, calendar_id, post_id ) — and whether each person is notified — args ( true, appointment ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_user_email_subject</code> / <code>ffcertificate_user_email_recipients</code> / <code>ffcertificate_user_email_body</code></td><td><?php esc_html_e( 'The certificate confirmation email — args ( subject, form_title, form_config ), ( to, form_title, submission_data ) and ( body, to, form_title, submission_data ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_admin_email_recipients</code></td><td><?php esc_html_e( 'Who receives the new-submission admin notification — args ( recipients, form_title, data ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_emit_source_headers</code></td><td><?php esc_html_e( 'Whether to stamp the mail-queue source headers on an email — args ( emit, source_key ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_export_headers</code> / <code>ffc_export_filename</code> / <code>ffc_export_data</code></td><td><?php esc_html_e( 'Submissions CSV exports (admin and public): the header row — args ( headers, include_edit_columns, form_ids ) — the filename — args ( filename, form_ids, status ) — and each batch of rows — args ( rows, form_ids, status ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_settings_before_save</code></td><td><?php esc_html_e( 'The sanitized ffc_settings array before it is written — args ( clean, current ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_settings_page_entry_caps</code></td><td><?php esc_html_e( 'The capabilities that let a user open the Settings page — args ( caps ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_scheduling_settings_tabs</code></td><td><?php esc_html_e( 'Add a tab to the Scheduling settings screen — args ( tabs ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_migrations_registry</code> / <code>ffcertificate_migration_strategies</code></td><td><?php esc_html_e( 'Register a migration card and its strategy on Settings → Data Migrations — args ( migrations ) / ( strategies ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_schedule_exception_form_url</code></td><td><?php esc_html_e( 'The page URL used for a form\'s schedule-exception link — args ( url, form_id ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_ipapi_use_https</code></td><td><?php esc_html_e( 'Call the IP geolocation API over HTTPS instead of HTTP — args ( false ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_github_updater_enabled</code> / <code>ffc_github_updater_repo</code></td><td><?php esc_html_e( 'Turn off the GitHub update check — args ( true ) — or point it at another repository — args ( repo ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_create_identity_account</code></td><td><?php esc_html_e( 'Creates the account an identity split moves records onto — args ( null, email ), returns a user id or WP_Error. A filter rather than a direct call because the split and the single account-creation path are in modules that would otherwise depend on each other. It deliberately does NOT resolve the identifier first: the identifier resolves to the account the records are being taken off.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_resolve_identity_account</code></td><td><?php esc_html_e( 'Which account already answers to a set of identifiers — args ( 0, cpf_hash, rf_hash, email ), returns a user id or 0. Read-only: it resolves and never creates, which is what makes it safe to ask BEFORE the filter below. Orphan adoption asks it so the screen can say whether a login was opened or merely matched.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_adopt_identity_account</code></td><td><?php esc_html_e( 'The account for a set of identifiers: the one that answers to them, or a new one, with every unlinked record carrying them adopted into it — args ( null, cpf_hash, rf_hash, email ), returns a user id or WP_Error. Unlike ffc_create_identity_account it DOES resolve first: an orphan has no account to be taken off, so resolving is the point rather than the thing to avoid.', 'ffcertificate' ); ?></td></tr>
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
				<tr><td><code>ffcertificate_after_submission_update</code> / <code>ffcertificate_after_submission_delete</code></td><td><?php esc_html_e( 'After a submission is edited — args ( submission_id, update_data ) — or deleted — args ( submission_id ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_after_appointment_create</code> / <code>ffcertificate_appointment_cancelled</code></td><td><?php esc_html_e( 'Self-scheduling appointment lifecycle — args ( appointment_id, data, calendar ) on create, ( appointment_id, appointment, reason, cancelled_by ) on cancel.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_audience_booking_created</code> / <code>ffcertificate_audience_booking_cancelled</code></td><td><?php esc_html_e( 'Audience booking lifecycle — args ( booking_id ) on create, ( booking_id, reason ) on cancel.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_short_redirect</code></td><td><?php esc_html_e( 'Before a short-URL redirect is sent — args ( record, target_url, redirect_type ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_export_completed</code></td><td><?php esc_html_e( 'After a submissions CSV export finishes (admin or public) — args ( job_id, file, rows, job ). The other exports do not fire it. Renamed from ffcertificate_csv_export_completed in 6.17.0.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_adopt_orphaned_identity_records</code></td><td><?php esc_html_e( 'After a person is resolved to a WordPress user, so a module can claim its own records that carry the same CPF/RF hash and no user yet — args ( cpf_hash, rf_hash, user_id ). Submissions and appointments are adopted directly; recruitment candidacies subscribe to this.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_grant_certificate_capabilities</code></td><td><?php esc_html_e( 'Per account repaired by the "Restore Access to Owned Certificates" migration — args ( user_id ). An action rather than a direct call because the migration and the capability owner are in modules that would otherwise depend on each other.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_submission_save</code></td><td><?php esc_html_e( 'Before a public submission is stored — args ( form_id, submission_data, user_email, form_config ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_submission_update</code> / <code>ffcertificate_before_submission_delete</code></td><td><?php esc_html_e( 'Before a submission is edited — args ( submission_id, email, data ) — or permanently deleted — args ( submission_id ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_submission_trashed</code> / <code>ffcertificate_submission_restored</code></td><td><?php esc_html_e( 'A submission moved to or restored from the trash — args ( submission_id ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_after_pdf_generation</code></td><td><?php esc_html_e( 'After a certificate\'s PDF payload is built — args ( pdf_data, submission_id ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_email_send</code></td><td><?php esc_html_e( 'Before the certificate confirmation email is composed — args ( submission_id, user_email, form_id, form_config ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_appointment_create</code></td><td><?php esc_html_e( 'Before a self-scheduling appointment is stored — args ( data, calendar ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_self_scheduling_appointment_created_email</code> / <code>…_confirmed_email</code> / <code>…_cancelled_email</code> / <code>…_waitlisted_email</code> / <code>…_promoted_email</code> / <code>…_reminder_email</code> / <code>…_admin_notification</code></td><td><?php esc_html_e( 'Triggers for the self-scheduling emails; the plugin\'s own email handler listens to them — args ( appointment, calendar ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_audience_booking_create</code></td><td><?php esc_html_e( 'Before an audience booking is stored — args ( booking_data ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_audience_created</code></td><td><?php esc_html_e( 'After an audience (group) is created — args ( audience_id, data ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_audience_register_capabilities</code></td><td><?php esc_html_e( 'While the Audience module registers its capabilities — no args.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffc_form_cache_purged</code></td><td><?php esc_html_e( 'After a form\'s cached data is purged — args ( form_id, reason ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_settings_saved</code></td><td><?php esc_html_e( 'After the ffc_settings option is saved — args ( clean ).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_data_deletion</code></td><td><?php esc_html_e( 'Before a Danger Zone data deletion runs — args ( target, reset_counter ).', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Signatures vary — check the source for the exact argument list before hooking.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'CSV exports (shared batched engine)', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Every data export in the plugin (submissions, public forms, url-shortener, activity log, audience bookings, appointments, reregistration) flows through one shared, timeout-safe engine rather than a bespoke exporter. A source implements the export contract (auth, columns, filename, and a keyset-paged fetch), and one AJAX trio (start → batch × N → download) drives it over a temp file — safe for large datasets on shared hosts where the request time limit cannot be raised.', 'ffcertificate' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Rows are fetched by keyset (id-descending), so a long export is stable across concurrent inserts — the order is by id, not the on-screen sort.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'The completion action', 'ffcertificate' ); ?> <code>ffc_export_completed</code> <?php esc_html_e( 'fires when a submissions export finishes; the other exports do not fire it.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Exports that decrypt PII write their temp file under a protected uploads directory (Deny-from-all on Apache), with random filenames, unlinked after download and swept daily. On nginx that path must be denied at the server block.', 'ffcertificate' ); ?></li>
		</ul>
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
				<tr><td><code>GET /ffc/v1/calendars</code> · <code>…/{id}</code> · <code>…/{id}/slots</code></td><td><?php esc_html_e( 'Public (IP rate-limited).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>POST /ffc/v1/calendars/{id}/appointments</code></td><td><?php esc_html_e( 'Public — books an appointment.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>GET</code> / <code>DELETE /ffc/v1/appointments/{id}</code></td><td><?php esc_html_e( 'Read or cancel one appointment — requires access to that appointment.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>GET /ffc/v1/user/certificates</code> · <code>…/appointments</code> · <code>…/reregistrations</code> · <code>…/summary</code> · <code>…/audience-bookings</code> · <code>…/joinable-groups</code> · <code>GET</code> / <code>PUT /ffc/v1/user/profile</code></td><td><?php esc_html_e( 'Logged-in (own data).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>POST /ffc/v1/user/change-password</code> · <code>…/privacy-request</code> · <code>…/audience-group/join</code> · <code>…/audience-group/leave</code> · <code>…/audience-group/leave-all</code></td><td><?php esc_html_e( 'Logged-in (acts on the current user).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>GET /ffc/v1/audience/bookings</code> · <code>POST /ffc/v1/audience/conflicts</code></td><td><?php esc_html_e( 'Public — anonymous callers get bookings of the schedules they may read, without personal details.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>POST /ffc/v1/audience/bookings</code> · <code>DELETE …/bookings/{id}</code></td><td><?php esc_html_e( 'Logged-in — creating needs ffc_view_own_audience_bookings (or the scheduling bypass); cancelling needs ownership of the booking or the bypass.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>GET /ffc/v1/submissions</code> · <code>…/{id}</code> · <code>GET /ffc/v1/certificates/calendar</code></td><td><?php esc_html_e( 'ffc_view_certificates (personal data stays masked without the PII capability).', 'ffcertificate' ); ?></td></tr>
					<tr><td><code>POST /ffc/v1/operator/certificates</code> · <code>GET .../{id}/pdf</code></td><td><?php esc_html_e( 'Operator — Application Password + ffc_manage_certificates (the PDF route: that capability OR ownership of the certificate).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>GET /ffc/v1/short-urls/{code}/qr</code></td><td><?php esc_html_e( 'Read tier — Application Password + ffc_view_url_shortener. Returns the short URL\'s QR Code as base64 (png/svg).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>…/ffcertificate/v1/recruitment/*</code></td><td><?php esc_html_e( 'Recruitment capabilities (manage / import / call / delete).', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>GET /ffcertificate/v1/recruitment/me/recruitment</code></td><td><?php esc_html_e( 'Logged-in — the current candidate\'s own recruitment data.', 'ffcertificate' ); ?></td></tr>
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

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Operator API (authenticated issuance)', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The plugin\'s only authenticated write API: a field-operator app issues certificates over WordPress Application Passwords (HTTP Basic auth, so capability checks work with no nonce). Added in 6.18.0.', 'ffcertificate' ); ?></p>
		<ul>
			<li><code>POST /ffc/v1/operator/certificates</code> — <?php esc_html_e( 'issue a certificate. The JSON body carries form_id plus the form\'s fields; it reuses the same required-field / CPF-RF / e-mail validation and the same storage as the public form, so the result is byte-for-byte a normal submission. Gated by ffc_manage_certificates (admins pass via manage_options). Returns the submission id, the formatted authentication code and the PDF endpoint URL.', 'ffcertificate' ); ?></li>
			<li><code>GET /ffc/v1/operator/certificates/{id}/pdf</code> — <?php esc_html_e( 'fetch a certificate\'s PDF payload (HTML + assets, rendered to PDF client-side — the plugin has no server-side PDF binary). Any logged-in user may call it; the handler then requires ffc_manage_certificates OR ownership of that specific certificate.', 'ffcertificate' ); ?></li>
		</ul>
		<div class="ffc-doc-note">
			<p>
				<strong class="ffc-icon-info"><?php esc_html_e( 'Why no captcha / geofence / per-IP limit here?', 'ffcertificate' ); ?></strong><br>
				<?php esc_html_e( 'Those defend the anonymous public form. The operator endpoint is authenticated and capability-gated, so the gate itself is the control; a generous per-operator rate-limit (300/hour, 2000/day) stays on only as a runaway guard. Per-IP limits are deliberately omitted because many field operators legitimately share one office IP.', 'ffcertificate' ); ?>
			</p>
		</div>
		<pre><code>curl -u operator:app_password -H "Content-Type: application/json" -d '{"form_id":12,"name":"Jane Doe","cpf_rf":"12345678901","email":"jane@example.com"}' "https://example.com/wp-json/ffc/v1/operator/certificates"</code></pre>

		<h4><?php esc_html_e( 'Short URL QR Code (base64)', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Fetch a short URL\'s QR Code for reuse in an external automation (e.g. N8N). Cap-gated by ffc_view_url_shortener via an Application Password. The QR encodes the short URL itself, so scans are click-counted, and PNGs are served from the same database cache the admin uses.', 'ffcertificate' ); ?></p>
		<ul>
			<li><code>GET /ffc/v1/short-urls/{code}/qr</code> — <?php esc_html_e( 'returns JSON', 'ffcertificate' ); ?> <code>{ code, short_url, format, mime, data_base64 }</code>. <?php esc_html_e( 'Query args:', 'ffcertificate' ); ?> <code>format=png|svg</code> (<?php esc_html_e( 'default png', 'ffcertificate' ); ?>), <code>size</code> (100–1000, <?php esc_html_e( 'default 300', 'ffcertificate' ); ?>). <?php esc_html_e( '404 for an unknown code.', 'ffcertificate' ); ?></li>
		</ul>
		<pre><code>curl -u user:app_password "https://example.com/wp-json/ffc/v1/short-urls/abc123/qr?format=png"</code></pre>
	</div>
</div>
