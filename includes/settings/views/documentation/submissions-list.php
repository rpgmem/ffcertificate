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
	<h3 id="submissions-list"><span class="dashicons dashicons-list-view" aria-hidden="true"></span> <?php esc_html_e( 'Submissions — list & editing', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Every certificate a form issues is a submission. They are managed under the "Submissions" admin page (inside the plugin menu).', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The list', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The table shows the id, form, email, a short preview of non-sensitive fields, status and date. You can filter by form and switch between Published, Trash, and the quiz states (Retry, Failed). Personal columns are decrypted only for the row display; the preview never includes email, CPF/RF or the auth code.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Editing a submission', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The edit screen lets you correct the participant email and the form\'s custom-field values, and manage the linked WordPress user and the LGPD consent record. Identity and integrity fields are read-only: submission id, date, status, magic-link token, IP, CPF/RF and the auth code (so a certificate can never be silently re-pointed).', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'CSV export', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Export the current (filtered) submissions to CSV in the background. The export contains decrypted personal data (email, IP, CPF/RF) and the magic-link token, so it is gated by its own capability.', 'ffcertificate' ); ?></p>

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
			<tr><td><code>ffc_manage_certificates</code></td><td><?php esc_html_e( 'Trash / restore and bulk actions.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_delete_certificates</code></td><td><?php esc_html_e( 'Delete submissions permanently (required on top of manage).', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_export_certificates</code></td><td><?php esc_html_e( 'Run the CSV export.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Administrators (manage_options) hold all of the above. See Capabilities & Roles.', 'ffcertificate' ); ?> <a href="#reference-capabilities"><?php esc_html_e( 'Capabilities & Roles', 'ffcertificate' ); ?></a>.</p>
</div>
