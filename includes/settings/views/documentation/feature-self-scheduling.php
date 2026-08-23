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

	<p><?php esc_html_e( 'A personal calendar books one-on-one appointments in time slots (e.g. consultations). Each calendar is created under the Scheduling menu → Personal Calendars and configured in four boxes: Calendar Configuration, Working Hours & Availability, Booking Rules & Restrictions, and Email Notifications.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Schedule type: regular or custom', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A calendar uses one of two availability models:', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Regular:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'the weekday "Working hours" pattern — recurring slots generated from per-weekday availability and the slot duration.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Custom (irregular):', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'explicit date/time blocks with their own per-block vacancies (e.g. 03/09 08:00–13:00, 40 places). Only the configured dates are offered, each listed with its real time range and remaining vacancies. Use this when availability does not follow a weekly rhythm.', 'ffcertificate' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'The mode locks to regular or custom once a calendar has bookings, so existing appointments never lose their slot definition.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Configuration', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Setting', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Meaning', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><strong><?php esc_html_e( 'Slot duration / interval', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Appointment length (default 30 min) and an optional break between slots (regular mode).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Capacity per slot', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'How many bookings a single slot accepts (default 1). An optional daily cap limits bookings per day. Custom-mode blocks carry their own per-block capacity.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Per-user block cap', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'On custom calendars, the maximum number of distinct blocks one user may hold (max_blocks_per_user). Counts their active and waitlisted bookings; 0 = no limit. A read-only Occupancy Report meta box shows each block\'s live booked / capacity, occupancy % and waitlist length.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Working hours', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Per-weekday availability (default Mon–Fri 09:00–17:00) — regular mode.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Waitlist', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'When enabled, a full slot/block lets users join a queue (with an optional waitlist capacity) instead of being turned away. Off by default.', 'ffcertificate' ); ?></td></tr>
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

	<h4><?php esc_html_e( 'Waitlist & automatic promotion', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'When a slot or block is full and the waitlist is enabled, a booking joins the queue with a waitlist status that is excluded from every capacity count. The front-end offers a "Join waitlist" action on full slots and reflects the queued state. When a spot later frees up — a cancellation or an admin rejection — the oldest queued booking is promoted automatically and transactionally to confirmed or pending (per the calendar\'s approval setting), and a promotion email is sent.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Emails', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Each calendar independently enables: user confirmation, admin notification, approval notice, cancellation notice and a reminder (default 24h before). When the waitlist is on, an "added to waitlist" notice and a "spot opened up" promotion notice are sent too. All of these are token-based and editable once in Settings → Email texts (the confirmation can also be overridden per calendar); every one is wrapped in the shared Email Model chrome.', 'ffcertificate' ); ?> <a href="#reference-emails"><?php esc_html_e( 'See Emails & Delivery', 'ffcertificate' ); ?></a>.</p>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<ul>
		<li><code>ffc_view_appointments</code> / <code>ffc_manage_appointments</code> — <?php esc_html_e( 'view or configure calendars and appointments.', 'ffcertificate' ); ?></li>
		<li><code>ffc_export_appointments</code> / <code>ffc_delete_appointments</code> — <?php esc_html_e( 'export or delete.', 'ffcertificate' ); ?></li>
		<li><code>ffc_bypass_appointments</code> — <?php esc_html_e( 'book outside the normal private/past/out-of-hours/blocked restrictions.', 'ffcertificate' ); ?></li>
		<li><code>ffc_bypass_appointment_capacity</code> — <?php esc_html_e( 'overbook a full slot or block (skips the capacity check, and the per-user block cap, for an authorised operator).', 'ffcertificate' ); ?></li>
		<li><code>ffc_book_own_appointments</code> / <code>ffc_view_own_appointments</code> / <code>ffc_cancel_own_appointments</code> — <?php esc_html_e( 'the end-user self-service caps.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Receipt PDF', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The appointment receipt (comprovante) PDF draws from the shared certificate template pool — the same editable, duplicable templates managed in the Document Templates hub — rather than a single hardcoded layout. Two receipt defaults ship (Regular and Custom); the Scheduling → Settings → Receipt tab selects, per scheduling mode, which pool template each calendar type uses (falling back to the shipped default when unset).', 'ffcertificate' ); ?> <a href="#document-templates-hub"><?php esc_html_e( 'See the Document Templates hub.', 'ffcertificate' ); ?></a></p>
	<p><?php esc_html_e( 'The receipt template accepts these placeholders:', 'ffcertificate' ); ?></p>
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
			<tr><td><code>{{appointment_time}}</code></td><td><?php esc_html_e( 'Scheduled start time of the appointment', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{appointment_end}}</code>, <code>{{appointment_time_range}}</code></td><td><?php esc_html_e( 'The end time, and the full "start – end" range', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{status}}</code></td><td><?php esc_html_e( 'Appointment status (pending, confirmed, cancelled, completed, no_show)', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{validation_code}}</code></td><td><?php esc_html_e( 'Unique validation code for the appointment', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{name}}</code>, <code>{{cpf_rf}}</code>, <code>{{email}}</code></td><td><?php esc_html_e( 'Participant identity', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{main_address}}</code>, <code>{{site_name}}</code></td><td><?php esc_html_e( 'Institutional address and site name', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{created_at}}</code>, <code>{{print_date}}</code></td><td><?php esc_html_e( 'When it was booked / when the receipt is generated', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{qr_code}}</code>, <code>{{validation_url}}</code></td><td><?php esc_html_e( 'QR image and validation link (same options as certificates)', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>
</div>
