<?php
/**
 * Documentation partial — Section 9: Audience Custom Fields.
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
<!-- 9. Audience Custom Fields Section -->
<div class="card">
	<h3 id="feature-audiences"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> <?php esc_html_e( 'Audiences', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Audiences are named groups of people (imported or self-registered) with their own scheduling calendars, booking flow and notifications. Publish an audience booking form with the [ffc_audience] shortcode, import members from CSV, and collect group-specific data with audience custom fields.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Audience custom fields', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Define custom data fields per audience group. These fields are shown during reregistration and on the WordPress user profile.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Supported Field Types:', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Type', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>text</code></td><td><?php esc_html_e( 'Single-line text input', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>textarea</code></td><td><?php esc_html_e( 'Multi-line text input', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>number</code></td><td><?php esc_html_e( 'Numeric input', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>date</code></td><td><?php esc_html_e( 'Date picker (YYYY-MM-DD)', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>select</code></td><td><?php esc_html_e( 'Dropdown with predefined options', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>dependent_select</code></td><td><?php esc_html_e( 'Two linked dropdowns (e.g. Division → Sector); the second list depends on the first choice.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>checkbox</code></td><td><?php esc_html_e( 'Boolean yes/no toggle', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>working_hours</code></td><td><?php esc_html_e( 'Day/time availability grid for scheduling.', 'ffcertificate' ); ?></td></tr>
				<tr><td><code>acknowledgment</code></td><td><?php esc_html_e( 'Display-only notice (e.g. a "Termo de Ciência"); renders static HTML, collects no input.', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Validation Options:', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong>CPF</strong> &mdash; <?php esc_html_e( 'Validates Brazilian CPF format', 'ffcertificate' ); ?></li>
			<li><strong>Email</strong> &mdash; <?php esc_html_e( 'Validates email format', 'ffcertificate' ); ?></li>
			<li><strong>Phone</strong> &mdash; <?php esc_html_e( 'Validates phone number format', 'ffcertificate' ); ?></li>
			<li><strong>Regex</strong> &mdash; <?php esc_html_e( 'Custom regular expression pattern', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'How It Works:', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Step 1:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Go to Audiences > Edit an audience > Custom Fields tab', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Step 2:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Add fields with labels, types, and validation rules', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Step 3:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Fields appear on user profiles and reregistration forms automatically', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Step 4:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Child audiences inherit fields from parent audiences', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Data Storage:', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Field definitions are stored in the ffc_custom_fields table. User data is stored as JSON in wp_usermeta under the key ffc_custom_fields_data.', 'ffcertificate' ); ?></p>
	</div>
</div>
