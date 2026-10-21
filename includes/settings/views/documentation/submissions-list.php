<?php
/**
 * Documentation partial — Submissions: Admin list & editing.
 *
 * The wp-admin submissions list, the per-submission edit screen, CSV export
 * and the capabilities that gate each. Part of the functional reorganization
 * (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Submissions: Admin list Section -->
<div class="card">
	<h3 id="submissions-list" class="ffc-icon-list"><?php esc_html_e( 'Submissions — list & editing', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Every certificate a form issues is a submission. They are managed under the "Submissions" admin page (inside the plugin menu).', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The list', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The table shows the id, form, email, a short preview of non-sensitive fields, status and date. You can filter by one or more forms and switch between Published, Trash, and the quiz states (Retry, Failed, Awaiting Grading). The search box matches a submission id, an auth code, or an exact email, CPF or RF. Personal columns are decrypted only for the row display; the preview never includes email, CPF/RF or the auth code.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Editing a submission', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The edit screen lets you correct the participant email and the form\'s custom-field values, and link, unlink or re-link the WordPress user. The LGPD consent status is shown for reference only. Identity and integrity fields are read-only: submission id, date, status, magic-link token, IP, CPF/RF and the auth code (so a certificate can never be silently re-pointed).', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The record sits beside a side panel with Save, the magic link with a Copy button, an Open certificate button that opens the public certificate page in a new tab, and the linked user.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'On the edit screen the CPF/RF, the email and the IP start masked for everyone, administrators included. The IP is the address the submission and its consent were recorded from, so it is masked both under System Information and under the consent status. An operator who may see them uses the Reveal button beside each value to fetch it, and every reveal writes an Activity Log entry while the Activity Log is on. The email can be edited only after it is revealed; saving without revealing it keeps the stored address.', 'ffcertificate' ); ?> <?php esc_html_e( 'The linked user\'s account email in the side panel is masked the same way, with its own Reveal button and the same audit.', 'ffcertificate' ); ?></p>

	<p><?php esc_html_e( 'A quiz attempt awaiting grading opens with a "Grade open answers" card above the record: each open answer, a points field from 0 to what the question is worth, and what the automatic questions already earned. Saving the grades decides the attempt — a pass issues the document, a result below the passing score becomes Retry or Failed — and emails the participant the result. It needs the "Grade quiz answers" capability.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'CSV export', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Export the current (filtered) submissions to CSV in the background. The export contains decrypted personal data (email, IP, CPF/RF), the magic-link token and the consent record, so it is gated by its own capability.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Who can do what', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Capability', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Grants', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><code>ffc_view_certificates</code></td><td><?php esc_html_e( 'See the submissions list and the PDF button (read-only).', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_edit_certificates</code></td><td><?php esc_html_e( 'Open and save the submission edit screen.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_grade_certificates</code></td><td><?php esc_html_e( 'Grade the open answers of a quiz attempt awaiting grading, from the submission edit screen.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_manage_certificates</code></td><td><?php esc_html_e( 'Trash / restore and bulk actions, including "Move to form…" (offered when the list is filtered to a single form) and "Send link by e-mail", which sends a link only to the submissions that never received one.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_delete_certificates</code></td><td><?php esc_html_e( 'Delete submissions permanently (required on top of manage).', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_export_certificates</code></td><td><?php esc_html_e( 'Run the CSV export.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_import_certificates</code></td><td><?php esc_html_e( 'Open a form and use its Bulk issue tab to check a participants CSV against that form: the columns are mapped, the issue date and the link e-mail are chosen and every row is previewed. Confirm to issue the certificates in batches, with progress.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_view_certificates_pii</code></td><td><?php esc_html_e( 'Reveal the decrypted CPF / RF / email, and the IP on the edit screen, on demand instead of the masked values; every reveal is audited. Without it the list and the edit screen stay masked, whatever else the operator holds. The certificate PDF is not masked: it prints CPF/RF formatted with punctuation.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Administrators (manage_options) hold all of the above. Personal data starts masked for everyone, on the list and on the edit screen alike: administrators reveal a value with the Reveal button, as a holder of ffc_view_certificates_pii does, the list also offers "Reveal this page", and every reveal is audited. A CSV export carries the personal columns in clear only with ffc_view_certificates_pii, and is recorded in the activity log. See Capabilities & Roles.', 'ffcertificate' ); ?> <a href="#reference-capabilities"><?php esc_html_e( 'Capabilities & Roles', 'ffcertificate' ); ?></a>.</p>
</div>
