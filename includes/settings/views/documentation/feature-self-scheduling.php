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
	<h3 id="feature-self-scheduling" class="ffc-icon-calendar"><?php esc_html_e( 'Personal Calendars', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'A personal calendar books one-on-one appointments in time slots (e.g. consultations). Each calendar is created under the Scheduling menu → Personal Calendars and configured in one Calendar Configuration box, split into tabs: Calendar, Slots & hours, Booking window, Cancellation, Approval & waitlist, Who can see and book, Administrator bypass, and Emails. A dot on the Cancellation and Administrator bypass tabs shows whether each is on.', 'ffcertificate' ); ?></p>

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
			<tr><td><strong><?php esc_html_e( 'Slot duration / interval', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Appointment length (default 30 min) and an optional break between slots (regular mode).', 'ffcertificate' ); ?> <?php esc_html_e( 'Each working range is stepped by the duration plus the break, and a slot that would end after the range closes is not offered. A booking is accepted only at one of those start times; slots that already started, or that start inside the minimum notice, are hidden from anyone without the bypass.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Capacity per slot', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'How many bookings a single slot accepts (default 1). An optional daily cap limits bookings per day. Custom-mode blocks carry their own per-block capacity.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Per-user block cap', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'On custom calendars, the maximum number of distinct blocks one user may hold (max_blocks_per_user). Counts their active and waitlisted bookings; 0 = no limit. A read-only Occupancy tab, shown once a custom calendar is saved, lists each block\'s live booked / capacity, occupancy % and waitlist length.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Working hours', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Per-weekday availability (default Mon–Fri 09:00–17:00) — regular mode.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Waitlist', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'When enabled, a full slot/block lets users join a queue (with an optional waitlist capacity) instead of being turned away. Off by default.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Advance booking window', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Minimum notice (hours) and how far ahead bookings are allowed (default 30 days).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Cancellation', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Whether users can cancel, and the minimum hours before the appointment (default 24).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Minimum interval between bookings', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Stops a user from booking again within this many hours of their last booking (default 24; 0 disables it).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Requires approval', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'When on, bookings start as pending until an admin approves them.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Blocked dates', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Blackout full days, time ranges or recurring patterns (per calendar or global).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Visibility', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Public or private viewing/booking, with configurable messages when restricted.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Business-hours restrictions', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Optionally limit viewing the calendar, or only booking on it, to the configured working hours.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Admin bypass', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Per calendar: whether holders of manage_options or ffc_bypass_appointments may book outside the advance window, on blocked dates, outside working hours and past the daily/interval limits. Turn it off to apply every rule to administrators too.', 'ffcertificate' ); ?> <?php esc_html_e( 'A booking that breaks one of those rules first opens a warning listing every rule it breaks; its "I agree" button unlocks after 15 seconds, the server saves nothing without that confirmation, and each confirmed booking is written to the Activity Log. Capacity is never bypassed.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Names', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'This calendar\'s own names for its states (legend and day cell, the bookings badge in the singular and the plural included) and its choice to show or hide the closed day\'s name. An empty field keeps the general name from Calendar Colors and Labels, on the General tab of the Scheduling settings; colors stay general. The calendar\'s name wins over the general one, which wins over the default.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Status', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Active, Inactive (no new bookings) or Archived.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Booking flow', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Publish the calendar on any page with the shortcode:', 'ffcertificate' ); ?> <code>[ffc_self_scheduling id="123"]</code> <?php esc_html_e( '(the id is the calendar post ID shown in the editor). The visitor picks an open slot and submits name, email, CPF/RF and optional notes (with honeypot + captcha + LGPD consent). A guest booking auto-creates or links a WordPress user from the CPF/RF + email.', 'ffcertificate' ); ?> <?php esc_html_e( 'The account is created or linked only once the booking is accepted, so a refused booking leaves no account and sends no e-mail. A guest booking files only itself under the account: older unlinked records carrying the same CPF/RF are claimed when the person logs in or through Identity Resolution, never from a public form. Booking counts against the IP limit on the Rate Limit tab, and the slot and month reads against the Calendar — Slots read limit.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Status lifecycle:', 'ffcertificate' ); ?> <code>pending</code> → <code>confirmed</code> → <code>completed</code>, <?php esc_html_e( 'or', 'ffcertificate' ); ?> <code>cancelled</code> / <code>no_show</code>. <?php esc_html_e( 'A booking is confirmed immediately unless the calendar requires approval.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The Appointments list opens sorted by appointment date, newest first, and by start time, latest first, within a day. Dates and times use the formats set on the General tab of FFC Settings.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'On the Appointments list, Confirm is offered only on a pending appointment: it recounts the slot first and refuses one that has filled, and it sends the approval notice only when the calendar\'s Emails tab turns it on. Cancel, which on a pending appointment is how a booking is rejected, asks for a reason, is offered while the appointment is pending, confirmed or on the waitlist, and sends the person the calendar\'s cancellation e-mail carrying that reason when the Emails tab turns it on. A cancelled, completed or no-show appointment can no longer be confirmed or cancelled.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Without the scheduling bypass, an appointment can be cancelled through its cancellation link or from the person\'s dashboard only while it is pending, confirmed or on the waitlist and has not started. A slot that has started takes nobody from the waitlist.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Deleting a calendar permanently cancels every appointment from today on that is pending, confirmed or on the waitlist, and sends each person the calendar-deleted notice. The Delete Permanently link in the trash says how many appointments that is before it deletes.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'On the Appointments list, a confirmed appointment whose date has come offers Attended and No-show; either mark is replaced by Undo attendance, which puts the appointment back to confirmed. Marking attendance needs ffc_manage_appointments and sends no e-mail.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Old appointments close themselves: once a day, an appointment whose date passed the number of days set under Close Old Appointments on the Personal Calendars tab of the Scheduling settings (default 7, 0 turns it off) and that is still confirmed is marked completed, and one still pending is cancelled with a reason saying nobody approved it in time. No e-mail is sent. When any are due, the Appointments list says how many and offers Close old appointments now, which runs the same close-out at once; each run that changed something leaves one Activity Log entry.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Waitlist & automatic promotion', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'When a slot or block is full and the waitlist is enabled, a booking joins the queue with a waitlist status that is excluded from every capacity count. The front-end offers a "Join waitlist" action on full slots and reflects the queued state. When a spot later frees up — a cancellation or an admin rejection — the oldest queued booking is promoted automatically and transactionally to confirmed or pending (per the calendar\'s approval setting), and a promotion email is sent.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Emails', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Each calendar independently enables: user confirmation, admin notification, approval notice, cancellation notice and a reminder (default 24h before). When the waitlist is on, an "added to waitlist" notice and a "spot opened up" promotion notice are sent too. The user-facing ones are token-based and editable once in Settings → Email texts (the confirmation can also be overridden per calendar); the admin notification has a fixed body. Every one is wrapped in the shared Email Model chrome. A user who turns off "Appointment reminder" in their dashboard notification preferences does not receive the reminder.', 'ffcertificate' ); ?> <?php esc_html_e( 'An hourly task sends each reminder once, to a confirmed appointment that starts within the calendar\'s reminder hours, measured in the timezone set in WordPress. A run that was missed is caught up by the next one; an appointment booked closer than that is reminded at the next run.', 'ffcertificate' ); ?> <a href="#reference-emails"><?php esc_html_e( 'See Emails & Delivery', 'ffcertificate' ); ?></a>.</p>

	<h4><?php esc_html_e( 'Cleaning up a calendar', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The Clean Up Appointments box beside the calendar editor counts the calendar\'s appointments: total, past, future and cancelled. Everyone who can open the calendar sees the counts; only a holder of ffc_purge_appointments sees the actions, in a box marked as a danger zone:', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Delete cancelled / Delete past:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'delete those appointments for good, after a confirmation that states how many go.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Cancel future:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'cancels every appointment from today on that still holds a place (pending, confirmed or on the waitlist), records who cancelled it and why, and sends each person the calendar\'s cancellation e-mail when the calendar\'s Emails tab turns it on. Nothing is deleted; the cancelled appointments can then go with "Delete cancelled".', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Delete all:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'deletes every appointment of the calendar.', 'ffcertificate' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'Cancel future and Delete all only proceed after you type the number of appointments they reach. If that number changed since the page loaded, the cleanup stops and asks for a reload. Every cleanup writes one row to the Activity Log with who ran it, the calendar, the action, the count and the ids of the appointments.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<ul>
		<li><code>ffc_view_calendars</code> / <code>ffc_manage_calendars</code> — <?php esc_html_e( 'view the calendars read-only, or create, edit, publish and delete them (structure, working hours and options).', 'ffcertificate' ); ?></li>
		<li><code>ffc_view_appointments</code> / <code>ffc_manage_appointments</code> — <?php esc_html_e( 'view or manage the appointments booked on those calendars.', 'ffcertificate' ); ?></li>
		<li><code>ffc_export_appointments</code> / <code>ffc_delete_appointments</code> — <?php esc_html_e( 'export, or delete one appointment from the list.', 'ffcertificate' ); ?></li>
		<li><code>ffc_purge_appointments</code> — <?php esc_html_e( 'use the Clean Up Appointments box on a calendar. Held by the Personal Calendars - Bookings - Administrator role and by FFC Administrator; a Bookings Manager deletes one appointment at a time.', 'ffcertificate' ); ?></li>
		<li><code>ffc_view_appointments_pii</code> — <?php esc_html_e( 'reveal the decrypted CPF / RF / e-mail on an appointment, and the e-mail in the appointments list, with the Reveal button; every reveal is audited. Without it those values stay masked, even for an operator who can manage appointments. The appointment detail and the list mask them for administrators and the appointments admin role too; they reveal them the same way.', 'ffcertificate' ); ?> <?php esc_html_e( 'The phone on the appointment detail is masked and revealed the same way, the list offers "Reveal this page", and an export carries e-mail, phone and IP in clear only with this capability. The audience screens reveal through ffc_view_audiences_pii since 6.40.0.', 'ffcertificate' ); ?></li>
		<li><code>ffc_bypass_appointments</code> — <?php esc_html_e( 'book outside the normal private/past/out-of-hours/blocked restrictions.', 'ffcertificate' ); ?> <?php esc_html_e( 'It applies to personal calendars only; audience calendars have their own ffc_bypass_audiences.', 'ffcertificate' ); ?></li>
		<li><code>ffc_bypass_appointment_capacity</code> — <?php esc_html_e( 'overbook a full slot or block (skips the capacity check, and the per-user block cap, for an authorised operator).', 'ffcertificate' ); ?></li>
		<li><code>ffc_book_own_appointments</code> / <code>ffc_view_own_appointments</code> / <code>ffc_cancel_own_appointments</code> — <?php esc_html_e( 'the end-user self-service caps.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Receipt PDF', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The appointment receipt (comprovante) PDF draws from the shared certificate template pool — the same editable, duplicable templates managed in the Document Templates hub — rather than a single hardcoded layout. Two receipt defaults ship (Regular and Custom); the Scheduling → Settings → Receipt tab selects, per scheduling mode, which pool template each calendar type uses (falling back to the shipped default when unset).', 'ffcertificate' ); ?> <a href="#document-templates-hub"><?php esc_html_e( 'See the Document Templates hub.', 'ffcertificate' ); ?></a></p>
	<p><?php esc_html_e( 'The receipt opens unmasked only for the person it belongs to: signed in as its owner, or through the link in their e-mails, which carries their confirmation token. Staff who can open the Appointments list reach it from View Receipt with the e-mail, phone and CPF/RF masked and without the token, which no staff screen shows, so a member of staff cannot cancel as the guest; the audited Reveal stays on the appointment\'s admin screen. View as user shows no receipt link.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The receipt PDF is built in the visitor\'s browser, after booking or from the receipt page, and the same browser check a certificate form runs comes first: an app\'s own browser (Instagram, WhatsApp and others), where saving the file often fails, is asked to open the page in the phone\'s browser, with "Continue anyway", which then downloads it; a browser missing what the PDF needs is stopped. Booking itself never waits on this. Settings → Certificates → Browser check turns it off, and each stop is written to the Activity Log as a pre-flight block naming the appointment.', 'ffcertificate' ); ?></p>
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
