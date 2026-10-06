<?php
/**
 * Documentation partial — Configuration: General.
 *
 * The Settings → General tab: appearance and code-editor theme, auto-delete,
 * the RF check-digit switch, date/time formats, institutional address, CSV
 * download URL and branding logos. Part of the functional
 * reorganization (rpgmem/ffcertificate#697). The QR-code defaults moved to
 * their own tab with the QR design (#1563) and are documented with the token.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Configuration: General Section -->
<div class="card">
	<h3 id="config-general"><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span> <?php esc_html_e( 'General', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Settings → General holds the plugin-wide basics.', 'ffcertificate' ); ?></p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Setting', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What it does', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><strong><?php esc_html_e( 'Dark Mode', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Admin appearance: Off, On (always dark), or Auto (follow the operating system).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Code Editor Theme', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Colours of the certificate HTML editor on the form screen: Auto (follows Dark Mode), Light or Dark. New installs default to Dark.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Auto-delete old submissions', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Off by default. When switched on, the daily cleanup permanently deletes published submissions older than the "Delete after (days)" window (default 365).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'RF check digit', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Off by default. When on, forms reject an RF whose check digit does not match. Before turning it on, run the identity audit on the Data Migrations tab to see how many stored RFs would fail.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Main Address', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The institutional address; fills the {{main_address}} token in certificate and appointment templates.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'CSV Download Page URL', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The base URL of the page hosting the [ffc_csv_download] shortcode. When set, the form editor shows the full operator link instead of just the query string.', 'ffcertificate' ); ?> <a href="#forms-public-operator-access"><?php esc_html_e( 'See Public Operator Access.', 'ffcertificate' ); ?></a></td></tr>
			<tr><td><strong><?php esc_html_e( 'Branding logos', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'A government / institution logo and an organization logo, picked from the Media Library. They fill the {{logo_gov}} and {{logo_org}} placeholders in the default Record and appointment-receipt templates; a generic placeholder is used when empty.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Date & time formats', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Pick from a catalog of presets (or a custom PHP date/time pattern) for how dates and times render across the plugin — the {{submission_date}} and {{print_date}} tokens, emails and PDFs. Defaults are d/m/Y and H:i. Separate optional overrides let the PDF use a different date/time format from the rest of the plugin (leave them on "Inherit" to reuse the general format).', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'All rendering goes through the plugin\'s date helper, so changing the site timezone re-renders correctly with no data migration.', 'ffcertificate' ); ?></p>
</div>
