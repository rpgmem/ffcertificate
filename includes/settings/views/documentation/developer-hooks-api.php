<?php
/**
 * Documentation partial — Section 17: Developer Hooks.
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
<!-- 17. Developer Hooks Section -->
<div class="card">
	<h3 id="developer-hooks-api" class="ffc-icon-wrench"><?php esc_html_e( 'Hooks, REST & Forms API', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'Developer surface of the plugin: action/filter hooks to extend behavior, the REST API for external integrations, and the authenticated Forms API.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Certificate / PDF Filters', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Hook', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>ffcertificate_certificate_data</code></td><td><?php esc_html_e( 'Modify certificate template data before PDF generation', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_certificate_html</code></td><td><?php esc_html_e( 'Modify the final certificate HTML before rendering', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_certificate_filename</code></td><td><?php esc_html_e( 'Customize the PDF filename', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_pdf_filename</code></td><td><?php esc_html_e( 'Generic PDF filename filter (fires for all PDF types before the type-specific filename filter)', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_after_pdf_generation</code></td><td><?php esc_html_e( 'Action fired after a PDF is generated', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_allowed_html_tags</code></td><td><?php esc_html_e( 'Extend the list of allowed HTML tags in templates', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'QR Code Filters', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Hook', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>ffcertificate_qrcode_url</code></td><td><?php esc_html_e( 'Customize the URL encoded in the QR code', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_qrcode_html</code></td><td><?php esc_html_e( 'Customize the QR code HTML output', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Submission Hooks', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Hook', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>ffcertificate_before_submission_save</code></td><td><?php esc_html_e( 'Before a new submission is saved', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_after_submission_save</code></td><td><?php esc_html_e( 'After a new submission is saved', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_submission_update</code></td><td><?php esc_html_e( 'Before an existing submission is updated', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_after_submission_update</code></td><td><?php esc_html_e( 'After an existing submission is updated', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_submission_delete</code></td><td><?php esc_html_e( 'Before a submission is permanently deleted', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_after_submission_delete</code></td><td><?php esc_html_e( 'After a submission is permanently deleted', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_submission_trashed</code></td><td><?php esc_html_e( 'When a submission is moved to trash', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_submission_restored</code></td><td><?php esc_html_e( 'When a submission is restored from trash', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_data_deletion</code></td><td><?php esc_html_e( 'Before the bulk "delete data" / reset action runs from the Advanced settings', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Email Hooks', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Hook', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>ffcertificate_before_email_send</code></td><td><?php esc_html_e( 'Action fired before any email is sent', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_email</code></td><td><?php esc_html_e( 'Last-mile filter for every plugin email — inspect or rewrite the composed message (to, subject, body, headers, attachments) just before send; fires after the global disable toggle', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_mail_queue_active</code></td><td><?php esc_html_e( 'Filter whether a mail-queue plugin (total-mail-queue) is considered active — force it true/false to control queue-aware behaviour and the install recommendation', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_user_email_subject</code></td><td><?php esc_html_e( 'Filter the email subject sent to users', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_user_email_recipients</code></td><td><?php esc_html_e( 'Filter the email recipients list', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_user_email_body</code></td><td><?php esc_html_e( 'Filter the email body HTML', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_email_plain_text</code></td><td><?php esc_html_e( 'Filter the auto-derived text/plain alternative for every HTML email (return an empty string to send HTML-only)', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_admin_email_recipients</code></td><td><?php esc_html_e( 'Filter admin notification recipients', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Appointment Hooks', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Hook', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>ffcertificate_before_appointment_create</code></td><td><?php esc_html_e( 'Before a new appointment is created', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_after_appointment_create</code></td><td><?php esc_html_e( 'After a new appointment is created', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_appointment_cancelled</code></td><td><?php esc_html_e( 'When an appointment is cancelled', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_available_slots</code></td><td><?php esc_html_e( 'Filter available appointment time slots', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_appointment_receipt_bg_image</code></td><td><?php esc_html_e( 'Customize the appointment receipt background image', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_appointment_receipt_template_file</code></td><td><?php esc_html_e( 'Customize the appointment receipt template file path', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_appointment_receipt_filename</code></td><td><?php esc_html_e( 'Customize the appointment receipt PDF filename', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_self_scheduling_appointment_created_email</code></td><td><?php esc_html_e( 'Filter the "appointment created" email to the user', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_self_scheduling_appointment_confirmed_email</code></td><td><?php esc_html_e( 'Filter the "appointment confirmed" email', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_self_scheduling_appointment_cancelled_email</code></td><td><?php esc_html_e( 'Filter the "appointment cancelled" email', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_self_scheduling_appointment_reminder_email</code></td><td><?php esc_html_e( 'Filter the appointment reminder email', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_self_scheduling_appointment_admin_notification</code></td><td><?php esc_html_e( 'Filter the admin notification for self-scheduling appointments', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_self_scheduling_cancel_appointments_on_delete</code></td><td><?php esc_html_e( 'Whether to cancel appointments when a calendar/user is deleted', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_self_scheduling_send_deletion_notification</code></td><td><?php esc_html_e( 'Whether to send a notification when appointments are removed on deletion', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Ficha PDF Hooks', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Hook', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>ffcertificate_ficha_data</code></td><td><?php esc_html_e( 'Modify ficha template data before PDF generation', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_ficha_html</code></td><td><?php esc_html_e( 'Modify the ficha HTML before rendering', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_ficha_filename</code></td><td><?php esc_html_e( 'Customize the ficha PDF filename', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_ficha_template_file</code></td><td><?php esc_html_e( 'Override the ficha template file path', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Audience Hooks', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Hook', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>ffcertificate_before_audience_booking_create</code></td><td><?php esc_html_e( 'Before an audience booking is created', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_audience_booking_created</code></td><td><?php esc_html_e( 'After an audience booking is created', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_audience_booking_cancelled</code></td><td><?php esc_html_e( 'When an audience booking is cancelled', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_audience_register_capabilities</code></td><td><?php esc_html_e( 'Register custom capabilities for the audience module', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'CSV Export & Settings Hooks', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Hook', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>ffcertificate_csv_export_data</code></td><td><?php esc_html_e( 'Filter exported CSV data before writing', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_csv_export_filename</code></td><td><?php esc_html_e( 'Filter the filename of the CSV download (admin + public)', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_csv_export_headers</code></td><td><?php esc_html_e( 'Filter the CSV header row to add/rename columns', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_csv_export_completed</code></td><td><?php esc_html_e( 'Action fired when a CSV export finishes writing', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_rest_form_schema</code></td><td><?php esc_html_e( 'Filter the REST form schema payload (fields, labels, types)', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_settings_tabs</code></td><td><?php esc_html_e( 'Register custom settings tabs', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_settings_before_save</code></td><td><?php esc_html_e( 'Filter settings data before saving', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_settings_saved</code></td><td><?php esc_html_e( 'Action fired after settings are saved', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_migrations_registry</code></td><td><?php esc_html_e( 'Register custom data migrations', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_migration_strategies</code></td><td><?php esc_html_e( 'Register custom migration strategies', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>ffcertificate_before_short_redirect</code></td><td><?php esc_html_e( 'Action fired before a short URL redirect', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'REST API', 'ffcertificate' ); ?></h4>
		<p>
			<?php esc_html_e( 'External integrations call the FFC REST API under the namespace', 'ffcertificate' ); ?>
			<code>/wp-json/ffc/v1/</code>.
			<?php esc_html_e( 'Endpoints fall into two categories: public-by-design and authenticated.', 'ffcertificate' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Public-by-design endpoints', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'are anonymous-by-contract because they serve the plugin\'s public flows: the form-submission shortcode, the certificate-verification page, and the booking shortcode. They do not accept authentication and are protected by rate limiting, geofence rules, hash_equals comparisons on tokens, and (for submissions) email + CPF/RF format validation.', 'ffcertificate' ); ?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Method', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Endpoint', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Purpose', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>GET</code></td>
					<td><code>/forms/{id}/schema</code></td>
					<td><?php esc_html_e( 'Lightweight form metadata for renderer integrations.', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>POST</code></td>
					<td><code>/forms/{id}/submit</code></td>
					<td><?php esc_html_e( 'Anonymous certificate submission.', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>POST</code></td>
					<td><code>/verify</code></td>
					<td><?php esc_html_e( 'Verify a certificate by auth code.', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>GET</code></td>
					<td><code>/calendars</code>, <code>/calendars/{id}</code>, <code>/calendars/{id}/slots</code></td>
					<td><?php esc_html_e( 'Public booking calendars and available slots.', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>POST</code></td>
					<td><code>/calendars/{id}/appointments</code></td>
					<td><?php esc_html_e( 'Anonymous booking creation.', 'ffcertificate' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Authenticated endpoints', 'ffcertificate' ); ?></h4>
		<p>
			<?php esc_html_e( 'Require a logged-in WordPress user with a specific FFC capability. Locked down in v6.4.1 to plug a config-blob leak. Use one of:', 'ffcertificate' ); ?>
		</p>
		<ul>
			<li>
				<strong><?php esc_html_e( 'Application Passwords (recommended).', 'ffcertificate' ); ?></strong>
				<?php esc_html_e( 'Built into WordPress since 5.6. Edit the integrator\'s user profile, scroll to "Application Passwords", create one named e.g. "FFC API", and use the resulting token with HTTP Basic Auth (username + the generated password). The integrator\'s user must hold the capability listed below.', 'ffcertificate' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Logged-in cookie (same-origin).', 'ffcertificate' ); ?></strong>
				<?php esc_html_e( 'When called from the WordPress admin or front-end while logged in, the request is authenticated automatically. The user must hold the capability.', 'ffcertificate' ); ?>
			</li>
		</ul>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Method', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Endpoint', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Required capability', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Returns', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>GET</code></td>
					<td><code>/forms</code></td>
					<td><code>ffc_view_forms_api</code></td>
					<td><?php esc_html_e( 'List of published forms (id, title, status, dates, link). Capped at 100 per request.', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>GET</code></td>
					<td><code>/forms/{id}</code></td>
					<td><code>ffc_view_forms_api</code></td>
					<td><?php esc_html_e( 'Single form metadata (id, title, status, dates, link). For form structure use /forms/{id}/schema.', 'ffcertificate' ); ?></td>
				</tr>
			</tbody>
		</table>
		<p>
			<strong><?php esc_html_e( 'Granting the capability:', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'Granted automatically to the administrator role on every plugin upgrade. Delegate to other roles (or specific users) via your favourite capability-management plugin or the user-edit screen.', 'ffcertificate' ); ?>
		</p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Example: list forms with curl', 'ffcertificate' ); ?></h4>
		<pre><code>curl -u USERNAME:APP_PASSWORD https://your-site.com/wp-json/ffc/v1/forms?limit=20</code></pre>
		<p>
			<?php esc_html_e( 'Replace USERNAME with the WordPress login of the integrator user, and APP_PASSWORD with the token created from the user\'s "Application Passwords" panel. The user must hold ffc_view_forms_api.', 'ffcertificate' ); ?>
		</p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Forms API', 'ffcertificate' ); ?></h4>
		<p>
			<?php esc_html_e( 'The Forms API is the authenticated slice of the REST API for reading forms programmatically — the GET /forms and GET /forms/{id} endpoints above — gated by the ffc_view_forms_api capability (the forms_api capability domain). Authenticate with an Application Password (recommended) or a same-origin logged-in cookie, and grant ffc_view_forms_api to the integrator\'s user. For the field structure of a form use the public GET /forms/{id}/schema endpoint.', 'ffcertificate' ); ?>
		</p>
	</div>

	<div class="ffc-alert ffc-alert-info">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Why was the previous /forms unauthenticated?', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'It was — and the response embedded the full _ffc_form_config blob, which on a typical install contains allowed/denied user lists, validation codes, generated codes, geofence configuration, and email templates. v6.4.1 closes that hole; the trimmed payload is now safe by construction (id, title, status, dates, link only).', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
