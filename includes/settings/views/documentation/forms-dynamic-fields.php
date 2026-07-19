<?php
/**
 * Documentation partial — Forms: Dynamic fields (Form Builder).
 *
 * How a form's input fields are defined in the Form Builder and how each
 * field's tag becomes a {{token}}. Part of the functional reorganization
 * (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Forms: Dynamic fields Section -->
<div class="card">
	<h3 id="forms-dynamic-fields"><span class="dashicons dashicons-forms" aria-hidden="true"></span> <?php esc_html_e( 'Dynamic Fields (Form Builder)', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'A form\'s input fields are defined in the form editor\'s "Form Builder (Fields)" box — a sortable list of fields. Each field has a Variable Name (Tag); that tag is the machine key that becomes a {{token}} on the certificate PDF and in emails.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Every field is a token', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'A field whose Variable Name (Tag) is', 'ffcertificate' ); ?> <code>company</code> <?php esc_html_e( 'is used in the template as', 'ffcertificate' ); ?> <code>{{company}}</code>. <?php esc_html_e( 'No extra registration is needed — the value the participant submits is substituted automatically. See', 'ffcertificate' ); ?> <a href="#reference-tokens"><?php esc_html_e( 'Template Variables / Tokens', 'ffcertificate' ); ?></a> <?php esc_html_e( 'for the built-in tokens.', 'ffcertificate' ); ?></p>
	</div>

	<h4><?php esc_html_e( 'Field types', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Type', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Use', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><code>text</code></td><td><?php esc_html_e( 'Single-line text.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>email</code></td><td><?php esc_html_e( 'Email address — validated as an email on submit.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>number</code></td><td><?php esc_html_e( 'Numeric input.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>date</code></td><td><?php esc_html_e( 'Date picker.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>textarea</code></td><td><?php esc_html_e( 'Multi-line text.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>select</code></td><td><?php esc_html_e( 'Dropdown — fill the Options field (one choice per comma). Can carry quiz points.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>radio</code></td><td><?php esc_html_e( 'Radio buttons — fill the Options field. Can carry quiz points.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>hidden</code></td><td><?php esc_html_e( 'A fixed value submitted without being shown.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>info</code></td><td><?php esc_html_e( 'Info block — display-only HTML, collects no value.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>embed</code></td><td><?php esc_html_e( 'Embedded media — display-only, collects no value.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'The three seed fields', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A new form starts with three fields, which are also the minimal set most templates rely on:', 'ffcertificate' ); ?></p>
	<ul>
		<li><code>name</code> — <?php esc_html_e( 'the participant\'s full name (required).', 'ffcertificate' ); ?></li>
		<li><code>email</code> — <?php esc_html_e( 'the participant\'s email (required); used to deliver the certificate.', 'ffcertificate' ); ?></li>
		<li><code>cpf_rf</code> — <?php esc_html_e( 'the identifier (required). See the note below on CPF/RF validation.', 'ffcertificate' ); ?></li>
	</ul>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'CPF/RF validation is by field name, not by type.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'A field named cpf_rf is validated on submit as a Brazilian document: non-digits are stripped and the value must be exactly 11 digits (CPF, check-digit validated) or 7 digits (RF). There is no separate "CPF field type" — the name is what triggers it. The cpf, cpf_rf and rg tokens are also auto-masked when rendered in a PDF.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
