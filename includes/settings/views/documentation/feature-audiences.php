<?php
/**
 * Documentation partial — Feature: Audience Custom Fields.
 *
 * Documents the per-audience custom data fields and their supported field
 * types, reused during reregistration and on the WordPress user profile.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 9. Audience Custom Fields Section -->
<div class="card">
	<h3 id="feature-audiences" class="ffc-icon-id"><?php esc_html_e( 'Audience Custom Fields', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Audiences are named groups of people (imported or self-registered). Their calendars, booking flow and notifications are covered under Audience Calendars; this page covers the group-specific custom fields an audience can collect (reused by reregistration and the user profile).', 'ffcertificate' ); ?> <a href="#scheduling-audiences"><?php esc_html_e( 'See Audience Calendars', 'ffcertificate' ); ?></a>.</p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Audience custom fields', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Define custom data fields per audience group. These fields are shown during reregistration and on the WordPress user profile.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'Once anybody has answered a custom field, in a reregistration or on their profile, its key, type, sensitivity and profile binding are locked and it cannot be deleted, because the answers are stored and read by them. The label, help text and options stay editable. To stop asking it, deactivate it: the answers already given keep appearing in review, the Record and the CSV export.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Global fields and inheritance', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Reregistration → Custom Fields edits the global list: the fields every audience asks. Below it, each audience inherits from its parent audiences, and a sub-audience from its audience. The audience\'s Reregistration fields tab shows where each field comes from (Global, From a parent, Changed here, Hidden here, Only here).', 'ffcertificate' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Customize copies one inherited field into the audience, where its label, group, options, validation and whether it is required or active can change. The key, type, sensitivity and profile binding follow the inherited field, and the answers stay under it, so a person answering in two audiences answers once. Back to inherited discards the change.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Hide stops asking an inherited field in the audience and its sub-audiences, required fields included. Answers already stored stay. Show again asks it once more.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'The side column of the global page lists, per audience, how many fields it changed, hid or asks on its own; each name opens that audience\'s fields tab.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Audiences used to receive their own copy of each standard field. On the update, a copy identical to what the audience inherits became plain inheritance, a different one became the audience\'s change, and an inactive one hides the field there; the answers moved with them (Settings → Data Migrations, "Move Answers to Inherited Fields"). A copy whose type, sensitivity or profile binding differs stays as "Own copy" when somebody answered it, with its answers, and is asked in place of the inherited one; one nobody answered inherits like the rest. New audiences receive no copy.', 'ffcertificate' ); ?></li>
		</ul>
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
			<li><strong><?php esc_html_e( 'Step 1:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Go to Scheduling → Audiences, edit an audience, and open its Reregistration fields tab', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Step 2:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Add fields with labels, types, and validation rules', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Step 3:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Fields appear on user profiles and reregistration forms automatically', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Step 4:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Child audiences inherit fields from parent audiences', 'ffcertificate' ); ?></li>
		</ul>
		<p class="description"><?php esc_html_e( 'Viewing the field definitions needs ffc_view_custom_fields and editing them needs ffc_manage_custom_fields; without either, the tab is hidden.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Data Storage:', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Field definitions are stored in the ffc_custom_fields table. User data is stored as JSON in wp_usermeta under the key ffc_custom_fields_data; fields mapped to a profile key (such as CPF, RF, RG or birth date) are also written, encrypted, to the plugin user profile.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'A field cannot be mapped to the account e-mail, or to anything else WordPress keeps in its users table, nor to the plugin\'s own preferences: the editor refuses such a mapping, so a form can never change how someone signs in.', 'ffcertificate' ); ?></p>
	</div>
</div>
