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
	<p><?php esc_html_e( 'An audience is a named group of members, with a color, an active/inactive status and an optional 3-level parent hierarchy. Members are imported or self-join (when allowed): an audience can let users join or leave its child groups from their dashboard, with a cap on how many self-join groups one user may belong to. Audiences also carry the custom fields used by reregistration.', 'ffcertificate' ); ?> <a href="#feature-audiences"><?php esc_html_e( 'See Audience Custom Fields', 'ffcertificate' ); ?></a>.</p>
	<p><?php esc_html_e( 'A top-level audience with child groups sets self-join in one choice of four: Off, This audience only, This audience + children and grandchildren, or Children and grandchildren only. The choice is written to the audience and to every group below it; with the audience itself closed, the dashboard shows it as a heading over the groups users can join. When the groups below disagree, no option is selected and a notice gives the audience\'s own state and how many of its children and grandchildren allow self-join; saving leaves everything as it is until you pick an option. A group created under the audience starts with self-join on when every existing child and grandchild allows it, and off otherwise, including under an audience with no groups yet. An audience without child groups keeps one switch.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'On the new-audience form, picking a parent audience replaces the self-join switch with "Inherits from the audience above.": the new group takes the value described above.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The cap is set under Scheduling → Settings → Audience Calendars → Self-Join, "Groups per user": from 1 to 20, 2 by default. Every membership in a group that allows self-join counts, however it was added; a user at the cap leaves a group before joining another, and the dashboard says how many groups they may join.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Two more switches on a top-level audience cover it and every child and grandchild; the groups below show the state read-only. "Visible to members only" hides the whole audience from the dashboard\'s Join groups panel for users who belong to none of its groups, however they were added (by you, by an import or by self-join), and the server refuses a join request from them; calendars, bookings and the rest of the dashboard are not affected. "Keep at least one group", available only while the first is on, stops a member from leaving the last group they hold in the audience: its Leave button is disabled with a line saying why, the server refuses the request, and "Leave all groups" does nothing and names the audience. Removing members on this screen is not affected.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'When a user is at the cap, or holds only one group of an audience that keeps its members, the other self-join groups of that audience offer "Switch to this": in one step the user leaves a group they hold there and joins the new one. With several groups held there, the dashboard asks which one to replace. A switch only moves between groups of the same top-level audience, so it never takes a user past the cap.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Schedules & environments', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A schedule is a bookable calendar; inside it, each environment is a space (room/resource) with its own weekly working hours (default 08:00–18:00, per-day closable) and color. A schedule controls visibility, how far ahead bookings are allowed, per-user booking permissions, and the notification settings below.', 'ffcertificate' ); ?> <?php esc_html_e( 'A new calendar starts public.', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'On a record that already exists, a switch saves the moment you flip it, without the Save button. A new record keeps the Save button until it is created.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The editors', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A calendar is edited in vertical tabs: General, Notifications and Event list & badges, then, once the calendar is saved, its environments (the Environments list of that calendar), User access and Closed dates. One Save button beside the tabs saves the first three together; the shortcode sits beside it.', 'ffcertificate' ); ?> <?php esc_html_e( 'Add New on the Environments tab opens the environment form with that calendar already chosen.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'An audience is edited in three tabs: Audience, Members (add and remove members) and Reregistration fields. The member count in the audiences list opens the Members tab.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The Members tab and a calendar\'s User access tab show each person\'s account email masked, for everyone. Holders of ffc_view_audiences_pii (the Audience Calendars Manager and Administrator roles, FFC Administrator and WordPress administrators) get a Reveal button beside each address and "Reveal this page" above the members list, and every reveal writes an Activity Log entry. The Viewer and Operator roles see the email masked.', 'ffcertificate' ); ?></p>

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

	<h4><?php esc_html_e( 'Booking rules', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Every booking is checked on the server, whoever makes it: the calendar and the space must be active; the date must not be in the past, beyond the calendar\'s future days limit (counted in the site\'s time zone), a global or calendar holiday, or a day the space is closed; and both the start and the end must fall inside the space\'s opening hours. An all-day booking takes the space\'s whole opening window for that day. Two bookings may not overlap in one space.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'With every space of a calendar shown ("All" in the space filter), a day is open when at least one active space opens on it, and the booking form lists only the spaces open that day. A day on which no space opens renders closed and cannot be picked.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Admin bypass and Override Conflicts', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Each calendar has an Admin bypass toggle (General tab, on by default). While it is on, holders of manage_options or ffc_bypass_audiences may open closed days and book any space, the closed ones marked with their reasons (closed, holiday, outside hours, past date, beyond the booking window). Turn it off to apply every rule to them too.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'A user whose User access row carries Override Conflicts, or a bypass holder, may book over an occupied slot. The earlier booking is then cancelled, and its owner receives the "booking cancelled by an administrator" email with the reason.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Either way the booking first opens one warning listing every rule it breaks, conflicts included. Its "I agree" button unlocks after 15 seconds and Cancel works at once; the server saves nothing without that confirmation, and each confirmed booking is written to the Activity Log with its reasons.', 'ffcertificate' ); ?></p>

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
		<li><code>ffc_view_audiences_pii</code> — <?php esc_html_e( 'reveal the account email of the people on the Members and User access tabs and the sensitive custom fields on another user\'s profile, with an audited Reveal; a members export carries the email in clear only with it. Existing holders of ffc_manage_audiences received it on update.', 'ffcertificate' ); ?></li>
		<li><code>ffc_bypass_audiences</code> — <?php esc_html_e( 'book outside the calendar rules and over an occupied slot, after the warning, on calendars whose Admin bypass toggle is on; also see every calendar and cancel any booking. Held by the FFC Audience Calendars - Administrator role and FFC Administrator. ffc_bypass_appointments no longer reaches audience calendars.', 'ffcertificate' ); ?></li>
		<li><code>ffc_view_own_audience_bookings</code> — <?php esc_html_e( 'the end-user cap; booking/cancel rights come from per-schedule permissions.', 'ffcertificate' ); ?></li>
	</ul>
</div>
