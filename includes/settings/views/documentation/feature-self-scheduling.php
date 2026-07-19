<?php
/**
 * Documentation partial — Scheduling: Personal Calendars (self-scheduling).
 *
 * Creating and configuring a personal appointment calendar, the booking flow,
 * emails, capabilities and the receipt-PDF tokens. Expanded for the functional
 * reorganization (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Scheduling: Personal Calendars Section -->
<div class="card">
	<h3 id="feature-self-scheduling"><span class="dashicons dashicons-calendar" aria-hidden="true"></span> <?php esc_html_e( 'Personal Calendars (Appointments)', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'A personal calendar books one-on-one appointments in fixed time slots (e.g. consultations). Each calendar is created under the Scheduling menu → Personal Calendars and configured in four boxes: Calendar Configuration, Working Hours & Availability, Booking Rules & Restrictions, and Email Notifications.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Configuration', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Setting', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Meaning', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><strong><?php esc_html_e( 'Slot duration / interval', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Appointment length (default 30 min) and an optional break between slots.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Capacity per slot', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'How many bookings a single slot accepts (default 1). An optional daily cap limits bookings per day.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Working hours', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Per-weekday availability (default Mon–Fri 09:00–17:00).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Advance booking window', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Minimum notice (hours) and how far ahead bookings are allowed (default 30 days).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Cancellation', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Whether users can cancel, and the minimum hours before the appointment (default 24).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Requires approval', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'When on, bookings start as pending until an admin approves them.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Blocked dates', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Blackout full days, time ranges or recurring patterns (per calendar or global).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Visibility', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Public or private viewing/booking, with configurable messages when restricted.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Booking flow', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Publish the calendar on any page with the shortcode:', 'ffcertificate' ); ?> <code>[ffc_self_scheduling id="123"]</code> <?php esc_html_e( '(the id is the calendar post ID shown in the editor). The visitor picks an open slot and submits name, email, CPF/RF and optional notes (with honeypot + captcha + LGPD consent). A guest booking auto-creates or links a WordPress user from the CPF/RF + email.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Status lifecycle:', 'ffcertificate' ); ?> <code>pending</code> → <code>confirmed</code> → <code>completed</code>, <?php esc_html_e( 'or', 'ffcertificate' ); ?> <code>cancelled</code> / <code>no_show</code>. <?php esc_html_e( 'A booking is confirmed immediately unless the calendar requires approval.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Emails', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Each calendar independently enables: user confirmation, admin notification, approval notice, cancellation notice and a reminder (default 24h before). The confirmation body is editable (tokens {{user_name}}, {{user_email}}, {{calendar_title}}, {{appointment_date}}, {{appointment_time}}) and wrapped in the shared Email Model chrome.', 'ffcertificate' ); ?> <a href="#reference-emails"><?php esc_html_e( 'See Emails & Delivery', 'ffcertificate' ); ?></a>.</p>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<ul>
		<li><code>ffc_view_appointments</code> / <code>ffc_manage_appointments</code> — <?php esc_html_e( 'view or configure calendars and appointments.', 'ffcertificate' ); ?></li>
		<li><code>ffc_export_appointments</code> / <code>ffc_delete_appointments</code> — <?php esc_html_e( 'export or delete.', 'ffcertificate' ); ?></li>
		<li><code>ffc_scheduling_bypass</code> — <?php esc_html_e( 'book outside the normal private/past/out-of-hours/blocked restrictions.', 'ffcertificate' ); ?></li>
		<li><code>ffc_book_own_appointments</code> / <code>ffc_view_own_appointments</code> / <code>ffc_cancel_own_appointments</code> — <?php esc_html_e( 'the end-user self-service caps.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Receipt PDF tokens', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The appointment receipt PDF template accepts these placeholders:', 'ffcertificate' ); ?></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Variable', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><code>{{calendar_title}}</code></td><td><?php esc_html_e( 'Name of the scheduling calendar', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{appointment_date}}</code></td><td><?php esc_html_e( 'Scheduled date of the appointment', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{appointment_time}}</code></td><td><?php esc_html_e( 'Scheduled time of the appointment', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{status}}</code></td><td><?php esc_html_e( 'Appointment status (pending, confirmed, cancelled, completed, no_show)', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{validation_code}}</code></td><td><?php esc_html_e( 'Unique validation code for the appointment', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{name}}</code>, <code>{{cpf_rf}}</code>, <code>{{email}}</code></td><td><?php esc_html_e( 'Participant identity', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{main_address}}</code>, <code>{{site_name}}</code></td><td><?php esc_html_e( 'Institutional address and site name', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{created_at}}</code>, <code>{{print_date}}</code></td><td><?php esc_html_e( 'When it was booked / when the receipt is generated', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{qr_code}}</code>, <code>{{validation_url}}</code></td><td><?php esc_html_e( 'QR image and validation link (same options as certificates)', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>
</div>
