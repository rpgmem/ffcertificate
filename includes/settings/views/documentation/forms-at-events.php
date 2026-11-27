<?php
/**
 * Documentation partial — Forms: Certificates at an event.
 *
 * The two ways to hand out a certificate at an event (#2894): the form's
 * page projected for the room, and the event terminal that reads the
 * participant's app QR, side by side.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Forms: Certificates at an event Section -->
<div class="card">
	<h3 id="forms-at-events" class="ffc-icon-award"><?php esc_html_e( 'Certificates at an event', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'A certificate form can be handed out at an event in two ways, and both work at the same time: the form\'s page projected for the room, or a terminal at the entrance that reads the QR in each participant\'s app. A person receives one certificate per form either way.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The projected form page', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Publish a page with the form\'s shortcode and project its QR code, or its address, during the event. Participants open it on their phones and submit the form as on any day. Since anyone who has the address can open it, rely on the form\'s own checks to keep out people who are not there: set the form\'s opening and closing to the hours of the event on the Time tab, and turn on the GPS area on the Geolocation tab, around the place of the event. A password or an allow list can be added on the Security tab.', 'ffcertificate' ); ?> <a href="#forms-schedule"><?php esc_html_e( 'See Schedule', 'ffcertificate' ); ?></a> · <a href="#forms-geolocation"><?php esc_html_e( 'See Geolocation', 'ffcertificate' ); ?></a></p>
	<p><?php esc_html_e( 'The projected page is also the way in for anyone without the app, and the fallback when a terminal that asks for a ticket loses its connection: it pauses and points participants to the page.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The event terminal', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A tablet at the entrance reads the presence QR in the participant\'s app and issues the certificate at once, from the profile: no typing, and the GPS area and password do not apply, since standing at the terminal proves both. An operator sets the terminal up on the terminal itself, By event, ticking the events and certificate forms of the next 7 days, or By place, choosing a GPS location it then follows. It needs the participant\'s app activated on their phone; a field the profile cannot fill is completed later on the phone.', 'ffcertificate' ); ?> <a href="#forms-terminal"><?php esc_html_e( 'See Terminal', 'ffcertificate' ); ?></a> · <a href="#scheduling-audiences"><?php esc_html_e( 'See Check-in terminals', 'ffcertificate' ); ?></a></p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Question', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Projected page', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Terminal', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( 'What the participant needs', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'A phone with a browser', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'The app, activated on their phone', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'What keeps out people who are not there', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'The form\'s hours and GPS area', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'Standing at the terminal', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Where the data comes from', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'Typed into the form', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'The profile', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Without internet', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'Does not work', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'Readings are kept and sent later, except for a form with a ticket', 'ffcertificate' ); ?></td>
			</tr>
		</tbody>
	</table>
</div>
