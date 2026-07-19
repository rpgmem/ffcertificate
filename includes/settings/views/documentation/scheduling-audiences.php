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
	<h3 id="scheduling-audiences"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> <?php esc_html_e( 'Audience Calendars (Spaces)', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Audience calendars book shared spaces (rooms, equipment, "ambientes") for named groups of people, rather than fixed one-on-one slots. Everything lives under the Scheduling menu.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Audiences (groups)', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'An audience is a named group of members, with a color, an active/inactive status and an optional 3-level parent hierarchy. Members are imported or self-join (when allowed). Audiences also carry the custom fields used by reregistration.', 'ffcertificate' ); ?> <a href="#feature-audiences"><?php esc_html_e( 'See Audience Custom Fields', 'ffcertificate' ); ?></a>.</p>

	<h4><?php esc_html_e( 'Schedules & environments', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A schedule is a bookable calendar; inside it, each environment is a space (room/resource) with its own weekly working hours (default 08:00–18:00, per-day closable) and color. A schedule controls visibility, how far ahead bookings are allowed, per-user booking permissions, and the notification settings below.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Free-form times, not fixed slots.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Unlike personal calendars, an audience booking has a free start/end time. Two bookings that overlap the same environment are a hard conflict (blocked); overlaps for the same audience or user are soft conflicts the booker can acknowledge. Each booking needs a short description (15–300 characters).', 'ffcertificate' ); ?>
		</p>
	</div>

	<h4><?php esc_html_e( 'Booking flow', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Publish a calendar with the shortcode:', 'ffcertificate' ); ?> <code>[ffc_audience schedule_id="0" environment_id="0" view="month"]</code>. <?php esc_html_e( 'schedule_id/environment_id of 0 show everything the visitor may access; view is month or week. Bookings are made through the plugin\'s REST API and can target one or more audiences or an individual, and are cancelled with a required reason.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Notifications', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Each schedule independently notifies affected users on booking and cancellation, and can attach an ICS calendar invite. Two opt-in admin toggles (off by default) also notify a recipient list (comma-separated; blank falls back to the site admin) on new bookings and cancellations.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Import / export', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The Settings tab imports and exports audiences (name, color, parent) and members (email, name, audience) as CSV, optionally creating WordPress users for imported members.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<ul>
		<li><code>ffc_view_audiences</code> — <?php esc_html_e( 'view the Scheduling menu and audience pages.', 'ffcertificate' ); ?></li>
		<li><code>ffc_manage_audiences</code> — <?php esc_html_e( 'create audiences, schedules, environments and members.', 'ffcertificate' ); ?></li>
		<li><code>ffc_import_audiences</code> / <code>ffc_export_audiences</code> / <code>ffc_delete_audiences</code> — <?php esc_html_e( 'import, export, delete.', 'ffcertificate' ); ?></li>
		<li><code>ffc_view_own_audience_bookings</code> — <?php esc_html_e( 'the end-user cap; booking/cancel rights come from per-schedule permissions.', 'ffcertificate' ); ?></li>
	</ul>
</div>
