<?php
/**
 * Documentation partial — Scheduling: Audience Calendars.
 *
 * Audiences (groups), their schedules and environments ("spaces"), the
 * free-form booking flow, notifications and CSV import/export. Part of the
 * functional reorganization (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Scheduling: Audience Calendars Section -->
<div class="card">
	<h3 id="scheduling-audiences" class="ffc-icon-users"><?php esc_html_e( 'Audience Calendars', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Audience calendars book shared spaces (rooms, equipment, "ambientes") for named groups of people, rather than fixed one-on-one slots. Everything lives under the Scheduling menu.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Audiences (groups)', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'An audience is a named group of members, with a color, an active/inactive status and an optional 3-level parent hierarchy. Members are imported or self-join (when allowed): an audience can let users join or leave its child groups from their dashboard, with a cap on how many child groups one user may join, and its descendants inherit the setting. Audiences also carry the custom fields used by reregistration.', 'ffcertificate' ); ?> <a href="#feature-audiences"><?php esc_html_e( 'See Audience Custom Fields', 'ffcertificate' ); ?></a>.</p>

	<h4><?php esc_html_e( 'Schedules & environments', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A schedule is a bookable calendar; inside it, each environment is a space (room/resource) with its own weekly working hours (default 08:00–18:00, per-day closable) and color. A schedule controls visibility, how far ahead bookings are allowed, per-user booking permissions, and the notification settings below.', 'ffcertificate' ); ?> <?php esc_html_e( 'A new calendar starts public.', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'On a record that already exists, a switch saves the moment you flip it, without the Save button. A new record keeps the Save button until it is created.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The editors', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A calendar is edited in vertical tabs: General, Notifications and Event list & badges, then, once the calendar is saved, its environments (the Environments list of that calendar), User access and Closed dates. One Save button beside the tabs saves the first three together; the shortcode sits beside it.', 'ffcertificate' ); ?> <?php esc_html_e( 'Add New on the Environments tab opens the environment form with that calendar already chosen.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'An audience is edited in three tabs: Audience, Members (add and remove members) and Reregistration fields. The member count in the audiences list opens the Members tab.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The Members tab and a calendar\'s User access tab show each person\'s account email masked, for everyone. Audiences have no personal-data capability of their own, so revealing it follows the appointments one: administrators, the appointments admin role and holders of ffc_view_appointments_pii get a Reveal button, and every reveal writes an Activity Log entry. The audience roles alone see the email masked.', 'ffcertificate' ); ?></p>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'Unlike personal calendars, an audience booking has a free start/end time. Two bookings that overlap the same environment are a hard conflict (blocked); overlaps for the same audience or user are soft conflicts the booker can acknowledge. Each booking needs a short description (15–300 characters).', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array( 'title' => __( 'Free-form times, not fixed slots.', 'ffcertificate' ) )
	);
	?>

	<h4><?php esc_html_e( 'Booking flow', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Publish a calendar with the shortcode:', 'ffcertificate' ); ?> <code>[ffc_audience schedule_id="0" environment_id="0" view="month"]</code>. <?php esc_html_e( 'schedule_id/environment_id of 0 show everything the visitor may access; view is month or week. Bookings are made through the plugin\'s REST API and can target one or more audiences or an individual, and are cancelled with a required reason.', 'ffcertificate' ); ?> <?php esc_html_e( 'The Shortcode column of Audience Calendars shows each calendar\'s shortcode with a copy button, and the Environments view shows each environment\'s, tied to its calendar.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Notifications', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Each schedule independently notifies affected users on booking and cancellation, and can attach an ICS calendar invite. Two opt-in admin toggles (off by default) also notify a recipient list (comma-separated; blank falls back to the site admin) on new bookings and cancellations.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Admin pages', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The Scheduling menu lists Dashboard, Personal Calendars, Appointments, Audience Calendars, Audiences, Audience Bookings and Settings. A new personal calendar is added with the Add New button of Personal Calendars or the Dashboard\'s quick action.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Audience Calendars has two views, switched by the links under its heading: Calendars and Environments (named with the label you give environments). The environment count in a calendar\'s row opens that calendar\'s environments.', 'ffcertificate' ); ?> <?php esc_html_e( 'Add New on a calendar\'s Environments tab, or on the Environments view filtered to one calendar, opens the new environment with that calendar already chosen.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Audience Bookings lists every booking (exportable as CSV with ffc_export_audiences). Global holidays, set on the Scheduling → Settings → General tab, block bookings on those dates.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Import / export', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The Scheduling → Settings → Import & Export tab imports and exports audiences (name, color, parent) and members (email, name, audience) as CSV, optionally creating WordPress users for imported members.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<ul>
		<li><code>ffc_view_audiences</code> — <?php esc_html_e( 'view the Scheduling menu and audience pages.', 'ffcertificate' ); ?> <?php esc_html_e( 'On its own it opens them read-only: the calendar, environment and audience edit forms and the members screen show their fields disabled, with no Save, Add or Remove, under a read-only notice.', 'ffcertificate' ); ?></li>
		<li><code>ffc_manage_audiences</code> — <?php esc_html_e( 'create audiences, schedules, environments and members.', 'ffcertificate' ); ?></li>
		<li><code>ffc_import_audiences</code> / <code>ffc_export_audiences</code> / <code>ffc_delete_audiences</code> — <?php esc_html_e( 'import, export, delete.', 'ffcertificate' ); ?></li>
		<li><code>ffc_bypass_audiences</code> — <?php esc_html_e( 'book outside a calendar\'s rules after confirming a warning, on calendars whose Admin bypass toggle is on. Held by the FFC Audience Calendars - Administrator role and FFC Administrator.', 'ffcertificate' ); ?></li>
		<li><code>ffc_view_own_audience_bookings</code> — <?php esc_html_e( 'the end-user cap; booking/cancel rights come from per-schedule permissions.', 'ffcertificate' ); ?></li>
	</ul>
</div>
