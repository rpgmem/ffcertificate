<?php
/**
 * Documentation partial — Section 11: Ficha PDF.
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
<!-- 11. Ficha PDF Section -->
<div class="card">
	<h3 id="feature-ficha" class="ffc-icon-doc"><?php esc_html_e( 'Ficha PDF', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Generate a PDF record (ficha) for reregistration submissions. Available for submitted and approved submissions.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Where to Download:', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Admin:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Click the "Ficha" button next to any submission in the Reregistration > Submissions list', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'User Dashboard:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Click "Download Ficha" on the reregistration banner after submitting', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Template Variables:', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The ficha template (html/default_ficha_template.html) supports these variables:', 'ffcertificate' ); ?></p>

		<h5><?php esc_html_e( 'System Variables', 'ffcertificate' ); ?></h5>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Variable', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>{{display_name}}</code></td><td><?php esc_html_e( 'User full name', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{reregistration_title}}</code></td><td><?php esc_html_e( 'Campaign name', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{audience_name}}</code></td><td><?php esc_html_e( 'Audience group name', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{submission_status}}</code></td><td><?php esc_html_e( 'Current status (Submitted, Approved, etc.)', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{submitted_at}}</code></td><td><?php esc_html_e( 'Submission date', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{custom_fields_section}}</code></td><td><?php esc_html_e( 'Auto-generated section with all custom field values', 'ffcertificate' ); ?></td></tr>
					<tr><td><code>{{termo_ciencia}}</code></td><td><?php esc_html_e( 'Acknowledgment notice ("Termo de Ciência") HTML — editable per-audience in the Reregistration Fields section, with a shipped default fallback.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{site_name}}</code></td><td><?php esc_html_e( 'WordPress site name', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{generation_date}}</code></td><td><?php esc_html_e( 'Date when the PDF was generated', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>

		<h5><?php esc_html_e( 'Personal Data (Dados Pessoais)', 'ffcertificate' ); ?></h5>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Variable', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>{{sexo}}</code></td><td><?php esc_html_e( 'Gender', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{estado_civil}}</code></td><td><?php esc_html_e( 'Marital status', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{rf}}</code></td><td><?php esc_html_e( 'RF identifier', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{data_nascimento}}</code></td><td><?php esc_html_e( 'Birth date', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{cpf}}</code></td><td><?php esc_html_e( 'CPF number', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{rg}}</code></td><td><?php esc_html_e( 'RG number', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{vinculo}}</code></td><td><?php esc_html_e( 'Employment relationship', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{unidade_lotacao}}</code></td><td><?php esc_html_e( 'Assigned unit', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{unidade_exercicio}}</code></td><td><?php esc_html_e( 'Work unit', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{divisao}}</code></td><td><?php esc_html_e( 'Division', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{setor}}</code></td><td><?php esc_html_e( 'Sector', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>

		<h5><?php esc_html_e( 'Address (Endereço)', 'ffcertificate' ); ?></h5>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Variable', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>{{endereco}}</code></td><td><?php esc_html_e( 'Street address', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{endereco_numero}}</code></td><td><?php esc_html_e( 'Address number', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{endereco_complemento}}</code></td><td><?php esc_html_e( 'Address complement', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{bairro}}</code></td><td><?php esc_html_e( 'Neighborhood', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{cidade}}</code></td><td><?php esc_html_e( 'City', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{uf}}</code></td><td><?php esc_html_e( 'State', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{cep}}</code></td><td><?php esc_html_e( 'Postal code', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>

		<h5><?php esc_html_e( 'Contact (Contatos)', 'ffcertificate' ); ?></h5>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Variable', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>{{phone}}</code></td><td><?php esc_html_e( 'Phone number', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{celular}}</code></td><td><?php esc_html_e( 'Mobile phone', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{email_institucional}}</code></td><td><?php esc_html_e( 'Institutional email', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{email_particular}}</code></td><td><?php esc_html_e( 'Personal email', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{contato_emergencia}}</code></td><td><?php esc_html_e( 'Emergency contact name', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{tel_emergencia}}</code></td><td><?php esc_html_e( 'Emergency contact phone', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>

		<h5><?php esc_html_e( 'Work Schedule (Jornada)', 'ffcertificate' ); ?></h5>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Variable', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>{{jornada}}</code></td><td><?php esc_html_e( 'Working hours', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{horario_trabalho}}</code></td><td><?php esc_html_e( 'Work schedule (HTML section)', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{acumulo_cargos}}</code></td><td><?php esc_html_e( 'Job accumulation status', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{jornada_acumulo}}</code></td><td><?php esc_html_e( 'Accumulation working hours', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{cargo_funcao_acumulo}}</code></td><td><?php esc_html_e( 'Accumulated position/function', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{horario_trabalho_acumulo}}</code></td><td><?php esc_html_e( 'Accumulated work schedule (HTML section)', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>{{sindicato}}</code></td><td><?php esc_html_e( 'Union', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-note">
		<h4><?php esc_html_e( 'Dependent-select fields (Division / Sector):', 'ffcertificate' ); ?></h4>
		<p>
			<?php esc_html_e( 'A dependent_select custom field (e.g. divisao_setor) exposes three placeholders: the combined value plus its two halves. Use whichever the layout needs.', 'ffcertificate' ); ?>
		</p>
		<ul>
			<li><code>{{divisao_setor}}</code> — <?php esc_html_e( 'combined "Parent - Child" value.', 'ffcertificate' ); ?></li>
			<li><code>{{divisao_setor_parent}}</code> — <?php esc_html_e( 'the parent half only (e.g. the Division).', 'ffcertificate' ); ?></li>
			<li><code>{{divisao_setor_child}}</code> — <?php esc_html_e( 'the child half only (e.g. the Sector).', 'ffcertificate' ); ?></li>
		</ul>
		<p>
			<?php esc_html_e( 'The same _parent / _child split is available for any dependent_select field by appending those suffixes to its field key.', 'ffcertificate' ); ?>
		</p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Customization:', 'ffcertificate' ); ?></h4>
		<p>
			<?php esc_html_e( 'The ficha template can be customized using the filter:', 'ffcertificate' ); ?>
			<code>ffcertificate_ficha_template_file</code>
		</p>
	</div>
</div>
