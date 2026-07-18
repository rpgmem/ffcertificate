<?php
/**
 * Documentation partial — Section 2: PDF Template Variables.
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
<!-- 2. Template Variables Section -->
<div class="card">
	<h3 id="reference-tokens" class="ffc-icon-tag"><?php esc_html_e( 'Template Variables / Tokens', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'Use these variables in your PDF template (HTML editor). They will be automatically replaced with user data:', 'ffcertificate' ); ?></p>
	
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Variable', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Example Output', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>{{name}}</code><br><code>{{nome}}</code></td>
				<td><?php esc_html_e( 'Full name of the participant', 'ffcertificate' ); ?></td>
				<td><em>John Doe</em></td>
			</tr>
			<tr>
				<td><code>{{cpf_rf}}</code></td>
				<td><?php esc_html_e( 'ID/CPF/RF entered by user', 'ffcertificate' ); ?></td>
				<td><em>123.456.789-00</em></td>
			</tr>
			<tr>
				<td><code>{{email}}</code></td>
				<td><?php esc_html_e( 'User email address', 'ffcertificate' ); ?></td>
				<td><em>john_doe@example.com</em></td>
			</tr>
			<tr>
				<td><code>{{auth_code}}</code></td>
				<td><?php esc_html_e( 'Unique authentication code for validation', 'ffcertificate' ); ?></td>
				<td><em>A1B2-C3D4-E5F6</em></td>
			</tr>
			<tr>
				<td><code>{{form_title}}</code></td>
				<td><?php esc_html_e( 'Title of the form/event', 'ffcertificate' ); ?></td>
				<td><em>Workshop 2025</em></td>
			</tr>
			<tr>
				<td><code>{{submission_date}}</code></td>
				<td><?php esc_html_e( 'Date when submission was created (from database)', 'ffcertificate' ); ?></td>
				<td><em>29/12/2025</em></td>
			</tr>
			<tr>
				<td><code>{{print_date}}</code></td>
				<td><?php esc_html_e( 'Current date/time when PDF is being generated', 'ffcertificate' ); ?></td>
				<td><em>20/01/2026</em></td>
			</tr>
			<tr>
				<td><code>{{submission_id}}</code></td>
				<td><?php esc_html_e( 'Numeric submission ID', 'ffcertificate' ); ?></td>
				<td><em>123</em></td>
			</tr>
			<tr>
				<td><code>{{main_address}}</code></td>
				<td><?php esc_html_e( 'Institutional address from Settings > General', 'ffcertificate' ); ?></td>
				<td><em>123 Main St, City</em></td>
			</tr>
			<tr>
				<td><code>{{site_name}}</code></td>
				<td><?php esc_html_e( 'WordPress site name', 'ffcertificate' ); ?></td>
				<td><em>My Organization</em></td>
			</tr>
			<tr>
				<td><code>{{program}}</code></td>
				<td><?php esc_html_e( 'Program/Course name (if custom field exists)', 'ffcertificate' ); ?></td>
				<td><em>Advanced Training</em></td>
			</tr>
			<tr>
				<td><code>{{qr_code}}</code></td>
				<td><?php esc_html_e( 'QR Code image (see section 3 for options)', 'ffcertificate' ); ?></td>
				<td><em>QRCode Image to Magic Link</em></td>
			</tr>
			<tr>
				<td><code>{{validation_url}}</code></td>
				<td><?php esc_html_e( 'Link to page with certificate validation', 'ffcertificate' ); ?></td>
				<td><em>Link to page with certificate validation</em></td>
			</tr>
			<tr>
				<td><code>{{custom_field}}</code></td>
				<td><?php esc_html_e( 'Any custom field name you created', 'ffcertificate' ); ?></td>
				<td><em>[Your Data]</em></td>
			</tr>
			<tr>
				<td><code>{{fill_date}}</code><br><code>{{date}}</code></td>
				<td><?php esc_html_e( 'Date the certificate is generated (alias of {{print_date}})', 'ffcertificate' ); ?></td>
				<td><em>20/01/2026</em></td>
			</tr>
			<tr>
				<td><code>{{schedule}}</code></td>
				<td><?php esc_html_e( 'Effective wall-clock schedule range — the per-submission "Entry/exit exception" override wins (Geofence: Schedule Exception), then the form-level Class Schedule baseline (Geofence → Time → Class Schedule), then the form\'s Time Range, then empty.', 'ffcertificate' ); ?></td>
				<td><em>08:00 – 17:30</em></td>
			</tr>
			<tr>
				<td><code>{{schedule_total}}</code></td>
				<td><?php esc_html_e( 'Total duration of {{schedule}} formatted as a human-readable span (e.g. "9h 30min"). Resolves to empty when {{schedule}} resolves to empty.', 'ffcertificate' ); ?></td>
				<td><em>9h 30min</em></td>
			</tr>
		</tbody>
	</table>
	<p class="description">
		<strong><?php esc_html_e( 'Participant profile fields:', 'ffcertificate' ); ?></strong>
		<?php esc_html_e( 'Any identity, contact, address or employment field your form collects can also be used as a placeholder by its field key — e.g. {{rg}}, {{celular}}, {{endereco}}, {{bairro}}, {{cargo_funcao_acumulo}}. The full catalog of these standard keys is listed in section 11 (Ficha PDF); they resolve in any PDF template when the form captures them.', 'ffcertificate' ); ?>
	</p>
</div>
