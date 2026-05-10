<?php
/**
 * Documentation partial — Section 4: Appointment Receipt Variables.
 *
 * Extracted from `ffc-tab-documentation.php` per S8 of the
 * god-object refactor (rpgmem/ffcertificate#141).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 4. Appointment Receipt Variables Section -->
<div class="card">
	<h3 id="appointment-variables" class="ffc-icon-tag"><?php esc_html_e( '4. Appointment Receipt Variables', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'These variables are available in the appointment receipt PDF template (html/default_appointment_receipt_1.html):', 'ffcertificate' ); ?></p>

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
			<tr><td><code>{{name}}</code></td><td><?php esc_html_e( 'Participant full name', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{cpf_rf}}</code></td><td><?php esc_html_e( 'Participant CPF/RF', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{email}}</code></td><td><?php esc_html_e( 'Participant email', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{main_address}}</code></td><td><?php esc_html_e( 'Institutional address from Settings > General', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{created_at}}</code></td><td><?php esc_html_e( 'Date and time when the appointment was booked', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{print_date}}</code></td><td><?php esc_html_e( 'Date when the receipt PDF is being generated', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{site_name}}</code></td><td><?php esc_html_e( 'WordPress site name', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{qr_code}}</code></td><td><?php esc_html_e( 'QR Code image (accepts same attributes as certificate QR)', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{validation_url}}</code></td><td><?php esc_html_e( 'Validation link for the appointment', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>
</div>
