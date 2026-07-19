<?php
/**
 * Documentation partial — Feature: Certificates & Forms.
 *
 * Forms overview + geofence + complete template examples. Part of the
 * documentation reorganization (rpgmem/ffcertificate#674).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Certificates & Forms Section -->
<div class="card">
	<h3 id="feature-certificates"><span class="dashicons dashicons-feedback" aria-hidden="true"></span> <?php esc_html_e( 'Certificates & Forms', 'ffcertificate' ); ?></h3>

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

	<details class="ffc-doc-example">
		<summary><strong><?php esc_html_e( 'Complete template examples', 'ffcertificate' ); ?></strong></summary>
	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Example 1: Simple Certificate', 'ffcertificate' ); ?></h4>
		<pre><code>&lt;div style="text-align: center; font-family: Arial; padding: 50px;"&gt;
	&lt;h1&gt;CERTIFICADO&lt;/h1&gt;
	
	&lt;p&gt;
		Certificamos que &lt;strong&gt;{{name}}&lt;/strong&gt;, 
		CPF &lt;strong&gt;{{cpf_rf}}&lt;/strong&gt;, 
		participou do evento &lt;strong&gt;{{form_title}}&lt;/strong&gt;.
	&lt;/p&gt;
	
	&lt;p&gt;Data: {{submission_date}}&lt;/p&gt;
	&lt;p&gt;Código: {{auth_code}}&lt;/p&gt;
	
	{{qr_code:size=150}}
&lt;/div&gt;</code></pre>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Example 2: Certificate with Header & Footer', 'ffcertificate' ); ?></h4>
		<pre><code>&lt;div style="font-family: Arial; padding: 30px;"&gt;
	&lt;!-- Header with logos --&gt;
	&lt;table width="100%"&gt;
		&lt;tr&gt;
			&lt;td width="25%"&gt;
				&lt;img src="https://example.com/logo-left.png" width="150"&gt;
			&lt;/td&gt;
			&lt;td width="50%" style="text-align: center;"&gt;
				&lt;div style="font-size: 10pt;"&gt;
					ORGANIZATION NAME&lt;br&gt;
					DEPARTMENT&lt;br&gt;
					DIVISION
				&lt;/div&gt;
			&lt;/td&gt;
			&lt;td width="25%" style="text-align: right;"&gt;
				&lt;img src="https://example.com/logo-right.png" width="150"&gt;
			&lt;/td&gt;
		&lt;/tr&gt;
	&lt;/table&gt;
	
	&lt;!-- Title --&gt;
	&lt;p style="text-align: center; margin-top: 40px;"&gt;
		&lt;strong style="font-size: 20pt;"&gt;CERTIFICATE OF ATTENDANCE&lt;/strong&gt;
	&lt;/p&gt;
	
	&lt;!-- Body --&gt;
	&lt;div style="text-align: center; margin: 40px 0; font-size: 12pt;"&gt;
		We certify that &lt;strong&gt;{{name}}&lt;/strong&gt;, 
		ID: &lt;strong&gt;{{cpf_rf}}&lt;/strong&gt;, 
		successfully attended the &lt;strong&gt;{{program}}&lt;/strong&gt; program 
		held on December 11, 2025.
	&lt;/div&gt;
	
	&lt;!-- Signature --&gt;
	&lt;table width="100%" style="margin-top: 60px;"&gt;
		&lt;tr&gt;
			&lt;td width="50%"&gt;&lt;/td&gt;
			&lt;td width="50%" style="text-align: center;"&gt;
				&lt;img src="https://example.com/signature.png" height="60"&gt;&lt;br&gt;
				&lt;div style="border-top: 1px solid #000; width: 200px; margin: 5px auto;"&gt;&lt;/div&gt;
				&lt;strong&gt;Director Name&lt;/strong&gt;&lt;br&gt;
				&lt;span style="font-size: 9pt;"&gt;Position Title&lt;/span&gt;
			&lt;/td&gt;
		&lt;/tr&gt;
	&lt;/table&gt;
	
	&lt;!-- Footer with QR Code --&gt;
	&lt;div style="margin-top: 60px;"&gt;
		&lt;table width="100%"&gt;
			&lt;tr&gt;
				&lt;td width="30%"&gt;
					{{qr_code:size=150:margin=0}}
				&lt;/td&gt;
				&lt;td width="70%" style="font-size: 9pt; vertical-align: middle;"&gt;
					Issued: {{submission_date}}&lt;br&gt;
					Verify at: https://example.com/verify/&lt;br&gt;
					Verification Code: &lt;strong&gt;{{auth_code}}&lt;/strong&gt;
				&lt;/td&gt;
			&lt;/tr&gt;
		&lt;/table&gt;
	&lt;/div&gt;
&lt;/div&gt;</code></pre>
	</div>
	</details>
</div>
