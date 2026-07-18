<?php
/**
 * Documentation partial — Section 12: Geofence Locations.
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
<!-- Certificates & Forms Section -->
<div class="card">
	<h3 id="feature-certificates" class="ffc-icon-doc"><?php esc_html_e( 'Certificates & Forms', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Create certificate-issuance forms, generate the certificate PDF automatically on submission, and let anyone verify authenticity by QR code or validation link.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Building a form', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Place a form on any page with the [ffc_form id="123"] shortcode, then configure its fields, certificate PDF template and email in the form editor. See the Reference section for the details:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Shortcodes', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the [ffc_form] tag and its attributes', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Template Variables / Tokens', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the {{placeholders}} available in the certificate PDF', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'QR Codes', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the {{qr_code}} verification placeholder', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Validation URL', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'building the certificate verification link', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Geofence: restrict where a form can be submitted', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Define reusable named locations for geofencing restrictions. Locations are shared across all forms and can be assigned as defaults.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Managing Locations:', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Go to Settings > Geolocation tab to add, edit, or delete locations. Each location has:', 'ffcertificate' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Field', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><strong><?php esc_html_e( 'Name', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Descriptive name (e.g. "Main Office", "Campus North")', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Latitude / Longitude', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'GPS coordinates of the center point', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Radius', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Radius in meters around the center point', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Default GPS', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'When enabled, new forms auto-select this location for GPS validation', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Default IP', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'When enabled, new forms auto-select this location for IP validation', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Using in Forms:', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'In the form editor Geofence metabox, choose the area source for GPS and IP validation:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Registered Locations:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Select one or more named locations from a dropdown. Coordinates are resolved at runtime from the registry.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Custom Coordinates:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Enter coordinates manually in the textarea (lat,lng,radius format, one per line). This is the legacy behavior.', 'ffcertificate' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Existing forms that were created before this feature default to "Custom Coordinates" and continue to work without any changes.', 'ffcertificate' ); ?></p>
	</div>
</div>
