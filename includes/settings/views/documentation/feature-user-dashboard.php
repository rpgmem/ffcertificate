<?php
/**
 * Documentation partial — Feature: User Dashboard & Access.
 *
 * Documents the front-end personal dashboard ([user_dashboard_personal]) and
 * its access control — the reorganization from #674.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- User Dashboard & Access Section -->
<div class="card">
	<h3 id="feature-user-dashboard" class="ffc-icon-user"><?php esc_html_e( 'User Dashboard & Access', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'A front-end panel where each logged-in user sees their own data — issued certificates, appointments and profile — without any admin access.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Adding the dashboard', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Place the shortcode on any page (for example a "My Account" page):', 'ffcertificate' ); ?></p>
		<pre><code>[user_dashboard_personal]</code></pre>
		<p><?php esc_html_e( 'The page is excluded from full-page caching automatically, because it renders user-specific data that must never be served from a shared cache.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'What the user sees', 'ffcertificate' ); ?></h4>
		<ul>
			<li><?php esc_html_e( 'Their own issued certificates, with download / verification links', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Their self-scheduling appointments (with receipt / cancel actions where allowed)', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'The reregistration banner and "Download Record" action when a reregistration applies to them', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Their audience bookings, and the Recruitment tab with their call-ups, when they belong to an audience or are linked to a candidate', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A Profile tab, read from the plugin\'s own user profile, where they edit their display name, phone, department, organization, notes and birth date (stored encrypted, and the date Date Messages uses)', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Notification Preferences: an "Appointment reminder" toggle and, when the Date Messages module is on, a "Date messages (such as birthday greetings)" toggle that opts them out; both are on until turned off', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Change Password, and Privacy & Data (LGPD) buttons to request an export or the deletion of their personal data', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Access control', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Must be logged in:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'the dashboard resolves data from the current user and shows nothing to anonymous visitors.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Own data only:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'records are matched to the current user (and their CPF/RF); a user never sees another user\'s data.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'View as user:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'holders of ffc_view_as_user can open the dashboard as another user, under a banner, from the users list or the user-edit screen ("Login as User").', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Roles & capabilities:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'admin surfaces are gated separately by FFC capabilities — see the Capabilities & Roles reference page.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Profile custom fields', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The identity/contact/address/employment fields live on the plugin\'s user profile and are mapped to the reregistration and audience data, so a user\'s details stay consistent across a certificate PDF, a record and their profile. WordPress\'s first and last name follow the plugin\'s full name (first word / the rest), and editing First/Last Name on the user-edit screen updates it back.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'Administrators see and edit the same fields on the user-edit screen, in the "FFC Custom Data" section, grouped by the audiences the user belongs to. They behave as on the reregistration form: CPF, RF, RG and phone fields are masked and validated, dependent selects follow their parent, and a field mapped to the profile is saved there, encrypted when it holds personal data, so the profile, the dashboard and the next reregistration read the same value.', 'ffcertificate' ); ?></p>
	</div>
</div>
