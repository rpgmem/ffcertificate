<?php
/**
 * Documentation partial — Forms: Schedule (open/close window).
 *
 * The form's submission window (date/time), the "Event Schedule" reference
 * that feeds {{schedule}}, and the one-shot Start Early / Postpone Close
 * operator actions. Part of the functional reorganization
 * (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Forms: Schedule Section -->
<div class="card">
	<h3 id="forms-schedule"><span class="dashicons dashicons-clock" aria-hidden="true"></span> <?php esc_html_e( 'Schedule (open / close window)', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'A form can accept submissions only inside a date/time window. The window is configured in the form editor\'s Geofence box, "Time" tab. Outside the window the form is blocked with a configurable message (or hidden entirely).', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The submission window', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Setting', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Meaning', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><?php esc_html_e( 'Enable date/time restriction', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Master toggle. When off, the form is always open (subject to other restrictions).', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Start / end date', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'The first and last day the form accepts submissions.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Start / end time', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'The daily open and close time (wall-clock, site timezone).', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Multi-day', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Off: the form is open only on the start date, between the two times. On: it spans start → end date.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Time mode (daily / span)', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Daily: the same open/close time applies each day. Span: one continuous window from start date+time to end date+time.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Blocked behavior', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Before / during / after the window you can independently show a message, show only the title + message, or hide the form.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Event Schedule is not the submission window.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'The "Class Schedule" / event times on the same tab are a reference printed on the certificate via the {{schedule}} and {{schedule_total}} tokens — they do not open or close the form. See', 'ffcertificate' ); ?> <a href="#reference-tokens"><?php esc_html_e( 'Template Variables / Tokens', 'ffcertificate' ); ?></a>.
		</p>
	</div>

	<h4><?php esc_html_e( 'Start Early / Postpone Close', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Two one-shot actions let a trusted operator adjust the window without logging into WordPress:', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Start Form Early', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'moves the start time to "now" so the form opens ahead of schedule (same day only).', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Postpone Close', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'extends the closing so submissions keep coming in.', 'ffcertificate' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'Each action fires once per form; afterwards edit the start/end time in the form editor to change the window again. Both are exposed through', 'ffcertificate' ); ?> <a href="#forms-public-operator-access"><?php esc_html_e( 'Public Operator Access', 'ffcertificate' ); ?></a> <?php esc_html_e( 'and must be enabled there per form.', 'ffcertificate' ); ?></p>
</div>
