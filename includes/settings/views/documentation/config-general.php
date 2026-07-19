<?php
/**
 * Documentation partial — Configuration: General.
 *
 * The Settings → General tab: appearance, auto-delete, date/time formats,
 * institutional address, CSV download URL and QR-code defaults. Part of the
 * functional reorganization (rpgmem/ffcertificate#697).
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
			<tr><td><strong><?php esc_html_e( 'Auto-delete (days)', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Remove submissions after this many days. 0 disables auto-deletion.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Main Address', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The institutional address; fills the {{main_address}} token in certificate and appointment templates.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'CSV Download Page URL', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The base URL of the page hosting the [ffc_csv_download] shortcode. When set, the form editor shows the full operator link instead of just the query string.', 'ffcertificate' ); ?> <a href="#forms-public-operator-access"><?php esc_html_e( 'See Public Operator Access.', 'ffcertificate' ); ?></a></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Date & time formats', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Pick from a catalog of presets (or a custom PHP date/time pattern) for how dates and times render across the plugin — the {{submission_date}} and {{print_date}} tokens, emails and PDFs. Defaults are d/m/Y and H:i. Separate optional overrides let the PDF use a different date/time format from the rest of the plugin (leave them on "Inherit" to reuse the general format).', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'All rendering goes through the plugin\'s date helper, so changing the site timezone re-renders correctly with no data migration.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'QR Code defaults', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Default size (px), margin (modules) and error-correction level (L / M / Q / H) applied to the {{qr_code}} placeholder when it does not specify its own. Per-placeholder options always win.', 'ffcertificate' ); ?> <a href="#reference-qr-codes"><?php esc_html_e( 'See QR Codes', 'ffcertificate' ); ?></a> <?php esc_html_e( 'for the placeholder syntax.', 'ffcertificate' ); ?></p>
</div>
