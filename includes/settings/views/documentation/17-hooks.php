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
	<h3 id="hooks" class="ffc-icon-wrench"><?php esc_html_e( '17. Developer Hooks', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'The plugin provides action and filter hooks for developers to extend or customize behavior.', 'ffcertificate' ); ?></p>

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
</div>
