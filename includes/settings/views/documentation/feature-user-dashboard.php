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
			<li><?php esc_html_e( 'Their personal-calendar appointments (with receipt / cancel actions where allowed)', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'The reregistration banner and "Download Record" action when a reregistration applies to them', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'View answers on a reregistration they sent, which opens their answers under the row even after the campaign closed. CPF, RF, RG, phones, e-mails and the birth date arrive masked behind a Reveal button; viewing as a user, the reveal is recorded in the activity log.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Their audience bookings, and the Recruitment tab with their call-ups, when they belong to an audience or are linked to a candidate', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A post-event certificate form still waiting for them: the booking shows Fill in the form, their personal link, and when the form closes, and a notice at the top of the tab counts the forms waiting. Viewing as a user shows that a form waits, never its link.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A Profile tab, read from the plugin\'s own user profile, where they edit their display name, phone, department, organization, notes and birth date (stored encrypted, and the date Date Messages uses)', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Notification Preferences: an "Appointment reminder" toggle and, when the Date Messages module is on, a "Date messages (such as birthday greetings)" toggle and an "Announcements from this site" toggle, each of which opts them out; all are on until turned off', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Change Password, and Privacy & Data (LGPD) buttons to request an export or the deletion of their personal data', 'ffcertificate' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'The personal-calendar and group-calendar tabs split the list into Upcoming, Past and Cancelled, each heading showing how many items it holds. Each section pages on its own, 5 upcoming, 3 past and 2 cancelled items at a time, and the filter bar brings every section back to its first page.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'The Recruitment tab, My Calls, sorts the candidate\'s classifications by status, with a counter for each at the top. Called holds one card per active call, with the presentation date and time, how many days away it is and an "Add to calendar" button that downloads an .ics file. Waiting lists final classifications first and then preliminary ones, 5 rows at a time. Closed lists hired, withdrew, did not show up and "Deadline passed", 2 rows at a time. A call still marked as called moves from Called to Closed as "Deadline passed" the day after its presentation date, in the site\'s timezone, until the team records the result. An i button beside a badge explains a passed deadline or a reverted call, with its dates and the reason given. An empty section is not shown.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'The dashboard is drawn with the plugin\'s own palette and icons, so it follows Dark Mode, and its fields, buttons and status badges look like those of the public forms. With Public Design switched on it also takes the design\'s font, accent colour, field corners and card corners.', 'ffcertificate' ); ?> <a href="#config-public-design"><?php esc_html_e( 'Public Design', 'ffcertificate' ); ?></a></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Access control', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Must be logged in:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'the dashboard resolves data from the current user and shows nothing to anonymous visitors.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Own data only:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'records are matched to the current user (and their CPF/RF); a user never sees another user\'s data.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Masked personal data:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'the person\'s own CPF/RF, e-mail addresses, phone and birth date appear masked on every tab, each with a Reveal button, so a shared or projected screen does not show them. Revealing one\'s own data writes no activity-log entry, and a masked field cannot be saved over until it is revealed. Documents the person downloads (a certificate PDF, a receipt, a record) keep printing the values in clear.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'View as user:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'holders of ffc_view_as_user can open the dashboard as another user, under a banner, from the users list or the user-edit screen ("Login as User"). The other user\'s personal data appears masked, as it does to its owner, and each Reveal there writes an activity-log entry.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Roles & capabilities:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'admin surfaces are gated separately by FFC capabilities — see the Capabilities & Roles reference page.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Profile custom fields', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The identity/contact/address/employment fields live on the plugin\'s user profile and are mapped to the reregistration and audience data, so a user\'s details stay consistent across a certificate PDF, a record and their profile. WordPress\'s first and last name follow the plugin\'s full name (first word / the rest), and editing First/Last Name on the user-edit screen updates it back.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'Administrators see and edit the same fields on the user-edit screen, in the "FFC Custom Data" section, grouped by the audiences the user belongs to. They behave as on the reregistration form: CPF, RF, RG and phone fields are masked and validated, dependent selects follow their parent, and a field mapped to the profile is saved there, encrypted when it holds personal data, so the profile, the dashboard and the next reregistration read the same value.', 'ffcertificate' ); ?></p>
	</div>
</div>
