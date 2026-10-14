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
	<h3 id="forms-dynamic-fields" class="ffc-icon-layout"><?php esc_html_e( 'Dynamic Fields (Form Builder)', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'A form\'s input fields are defined on the Fields tab ("Form Builder (Fields)") of the form editor\'s "Certificate Form Configuration" box — a sortable list of fields. Each field has a Variable Name (Tag); that tag is the machine key that becomes a {{token}} on the certificate PDF and in emails. Each field is titled by its type, chosen once from the Add New Field menu; to change a type, remove the field and add it again, because the answers already stored under it hold values of the old type.', 'ffcertificate' ); ?></p>

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
			<tr><td><code>text</code></td><td><?php esc_html_e( 'Single-line text, up to 255 characters — for names, roles, codes. Optional minimum and maximum length.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>email</code></td><td><?php esc_html_e( 'Email address — validated as an email on submit.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>number</code></td><td><?php esc_html_e( 'Numeric input. Optional minimum, maximum and decimal places (whole numbers by default).', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>date</code></td><td><?php esc_html_e( 'Date picker. Optional earliest and latest date.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>textarea</code></td><td><?php esc_html_e( 'Multi-line text, up to 2,000 characters — for explanations and comments. Optional minimum and maximum length.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>select</code></td><td><?php esc_html_e( 'Dropdown — one option per row. Can carry quiz points.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>radio</code></td><td><?php esc_html_e( 'Radio buttons — one option per row; the participant picks exactly one. Can carry quiz points.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>checkbox</code></td><td><?php esc_html_e( 'Checkboxes — one option per row; the participant ticks one or more, up to an optional maximum. "Required" means at least one. Not scored in quiz mode.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>hidden</code></td><td><?php esc_html_e( 'Hidden field — no visible control; every entry submits the fixed value set on the field\'s Value input (the server uses that value, never one sent by the browser).', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>info</code></td><td><?php esc_html_e( 'Info block — display-only HTML, collects no value.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>embed</code></td><td><?php esc_html_e( 'Embedded media — display-only, collects no value.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Options and limits', 'ffcertificate' ); ?></h4>
	<ul>
		<li><?php esc_html_e( 'Options are edited one per row, so an option may contain a comma. Drag a row to reorder it; Enter in an option adds the next one. A form saved before keeps working and is converted on its next save.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( '"Shuffle the options for each participant" puts a choice field\'s options in a random order in the browser, so a cached page still shows each participant a different order.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'The participant sees the limits you set — "Between 50 and 2,000 characters.", "Choose up to 3 options.", "Between 01/01/1800 and 31/12/1999." — and a live character counter under text fields.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'The server checks every rule again on submit — a required answer, the length, the range, the number of options ticked and that each answer is one of the options — and refuses the submission listing every problem at once. The browser is a courtesy, never the guard.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Required Form Fields', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A new form starts with every row of Settings → Certificates → Required data whose "Required in the form" switch is on, with the label and type given there. By default they are:', 'ffcertificate' ); ?></p>
	<ul>
		<li><code>name</code> — <?php esc_html_e( 'the participant\'s full name (required): at least two words and 4 characters, checked in the browser and on the server.', 'ffcertificate' ); ?></li>
		<li><code>email</code> — <?php esc_html_e( 'the participant\'s email (required); used to deliver the certificate. An address that is not valid is refused as such, not as a missing one.', 'ffcertificate' ); ?></li>
		<li><code>cpf_rf</code> — <?php esc_html_e( 'the identifier (required). See the note below on CPF/RF validation.', 'ffcertificate' ); ?></li>
	</ul>

	<p><?php esc_html_e( 'A required field is drawn with an amber border and a "Required field" badge: it is always required, its variable name and type cannot change and it cannot be removed; only its label can. A form missing one shows a warning naming it, with an "Add the required fields" button that adds them.', 'ffcertificate' ); ?></p>

	<p><?php esc_html_e( 'Name fields (keys such as name, nome, nome_completo, full_name or participante) are normalized on submit to proper capitalization, keeping Portuguese connectives such as "da" and "de" in lowercase.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'CPF/RF validation is by field name, not by type.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'A field named cpf_rf is validated on submit as a Brazilian document: non-digits are stripped and the value must be exactly 11 digits (CPF, check-digit validated) or 7 digits (RF); the browser masks it and blocks the submit while the number is invalid. The RF check digit is enforced only when Settings → General → RF check digit is on (off by default). There is no separate "CPF field type" — the name is what triggers it. In the PDF, the cpf, cpf_rf and rg tokens are formatted with punctuation (e.g. 000.000.000-00 for a CPF, 000.000-0 for an RF), not masked.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
