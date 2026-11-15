<?php
/**
 * Documentation partial — Reference: Template Variables / Tokens.
 *
 * Lists the {{token}} variables available in the PDF template and the user
 * data each one is replaced with when a certificate is generated.
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
				<td><code>{{name}}</code></td>
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
				<td><?php esc_html_e( 'Unique authentication code for validation, printed with the certificate prefix "C-"', 'ffcertificate' ); ?></td>
				<td><em>C-A1B2-C3D4-E5F6</em></td>
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
				<td><code>{{logo_gov}}</code></td>
				<td><?php esc_html_e( 'Government / institution logo URL from Settings > General > Branding (falls back to a shipped placeholder). Use inside an <img src="{{logo_gov}}">.', 'ffcertificate' ); ?></td>
				<td><em>https://example.com/wp-content/uploads/logo.png</em></td>
			</tr>
			<tr>
				<td><code>{{logo_org}}</code></td>
				<td><?php esc_html_e( 'Organization logo URL from Settings > General > Branding (falls back to a shipped placeholder). Use inside an <img src="{{logo_org}}">.', 'ffcertificate' ); ?></td>
				<td><em>https://example.com/wp-content/uploads/logo.png</em></td>
			</tr>
			<tr>
				<td><code>{{program}}</code></td>
				<td><?php esc_html_e( 'Program/Course name (if custom field exists)', 'ffcertificate' ); ?></td>
				<td><em>Advanced Training</em></td>
			</tr>
			<tr>
				<td><code>{{qr_code}}</code></td>
				<td><?php esc_html_e( 'QR Code image.', 'ffcertificate' ); ?> <a href="#reference-qr-codes"><?php esc_html_e( 'See QR Code Options & Attributes.', 'ffcertificate' ); ?></a></td>
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
				<td><?php esc_html_e( 'Date when the submission was created (alias of {{submission_date}}) — note this is the submission date, not the PDF generation date', 'ffcertificate' ); ?></td>
				<td><em>29/12/2025</em></td>
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
			<tr>
				<td><code>{{schedule:date}}</code></td>
				<td><?php esc_html_e( 'The event\'s first day, from the Event Schedule date on the Time tab — never the form\'s opening date. Empty when the form has no event date. Add a PHP date format to choose how it prints, as in {{schedule:date:format=d/m/Y}}; without one it uses the PDF date format from Settings → General.', 'ffcertificate' ); ?></td>
				<td><em>19/12/2025</em></td>
			</tr>
			<tr>
				<td><code>{{schedule:dates}}</code></td>
				<td><?php esc_html_e( 'The event\'s day for a one-day event, or its first and last days. Takes the same format as {{schedule:date}}.', 'ffcertificate' ); ?></td>
				<td><em>19/12/2025 to 20/12/2025</em></td>
			</tr>
			<tr>
				<td><code>{{model:key}}</code></td>
				<td><?php esc_html_e( 'A field the certificate template declares, filled by each form that references the template, or the field\'s default when the form leaves it empty. A date field takes a format, as in {{model:key:format=d/m/Y}}. A key the template does not declare prints as written.', 'ffcertificate' ); ?></td>
				<td><em>Sala 2</em></td>
			</tr>
			<tr>
				<td><code>{{signer:name}}</code> <code>{{signer:rf}}</code> <code>{{signer:role}}</code> <code>{{signer:signature}}</code></td>
				<td><?php esc_html_e( 'The form\'s signer, chosen on the Layout tab from Certificates → Signers: name and RF from their WordPress profile at generation time, the role or sector, and the URL of the signature image. The RF prints in full on the certificate and masked in previews.', 'ffcertificate' ); ?></td>
				<td><em>Maria Souza</em></td>
			</tr>
			<tr>
				<td><code>{{ticket}}</code></td>
				<td><?php esc_html_e( 'The single-use ticket code the participant entered (forms with the Ticket restriction only).', 'ffcertificate' ); ?></td>
				<td><em>TK01-AB2C-3D4E</em></td>
			</tr>
		</tbody>
	</table>
	<p class="description">
		<strong><?php esc_html_e( 'Participant profile fields:', 'ffcertificate' ); ?></strong>
		<?php esc_html_e( 'Any identity, contact, address or employment field your form collects can also be used as a placeholder by its field key — e.g. {{rg}}, {{celular}}, {{endereco}}, {{bairro}}, {{cargo_funcao_acumulo}}. The full catalog of these standard keys is listed on the Record PDF page; they resolve in any PDF template when the form captures them.', 'ffcertificate' ); ?>
	</p>
	<p class="description">
		<strong><?php esc_html_e( 'Quiz forms:', 'ffcertificate' ); ?></strong>
		<code>{{score}}</code>, <code>{{max_score}}</code>, <code>{{score_percent}}</code>, <code>{{correct_count}}</code>, <code>{{question_count}}</code>
		<a href="#feature-quiz"><?php esc_html_e( 'See Quiz / Evaluation.', 'ffcertificate' ); ?></a>
	</p>
	<p class="description"><?php esc_html_e( '{{name}} is filled from a form field whose key is "name"; first_name, last_name and full_name are not certificate tokens (they belong to the date-message emails).', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Custom fields as variables', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Any custom field you create in Form Builder automatically becomes a template variable:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Step 1:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Create a field in Form Builder (e.g. field name "company")', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Step 2:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Use it in the template:', 'ffcertificate' ); ?> <code>{{company}}</code></li>
			<li><strong><?php esc_html_e( 'Step 3:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'The value is replaced automatically in the PDF', 'ffcertificate' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'So fields named', 'ffcertificate' ); ?> <code>company</code>, <code>department</code>, <code>course_hours</code> <?php esc_html_e( 'become', 'ffcertificate' ); ?> <code>{{company}}</code>, <code>{{department}}</code>, <code>{{course_hours}}</code>.</p>
	</div>
</div>
