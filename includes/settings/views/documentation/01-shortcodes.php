<?php
/**
 * Documentation partial — Section 1: Shortcodes.
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
<!-- 1. Shortcodes Section -->
<div class="card">
	<h3 id="shortcodes" class="ffc-icon-pin"><?php esc_html_e( '1. Shortcodes', 'ffcertificate' ); ?></h3>
	
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Shortcode', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>[ffc_form id="123"]</code></td>
				<td>
					<?php esc_html_e( 'Displays the certificate issuance form.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Usage:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Replace "123" with your Form ID from the "All Forms" list.', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>[ffc_verification]</code></td>
				<td>
					<?php esc_html_e( 'Displays the public verification page.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Usage:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Users can validate certificates by entering the authentication code.', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>[ffc_csv_download]</code></td>
				<td>
					<?php esc_html_e( 'Displays a public page where trusted operators can download the submissions CSV, trigger Start Form Early, and Postpone Close for a specific form — all gated by a Form ID and an access hash.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Usage:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Enable "Public Operator Access" (formerly "Public CSV Download") on the form editor to generate a hash, then share the page URL together with the Form ID and hash (e.g. ?form_id=123&hash=...). CSV downloads are only released after the form end date has passed and are capped by the per-form quota configured in the form editor; Start Form Early and Postpone Close have their own eligibility windows configured per form.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Optional attribute:', 'ffcertificate' ); ?></strong> <code>title="Download attendees"</code>
				</td>
			</tr>
			<tr>
				<td><code>[user_dashboard_personal]</code></td>
				<td>
					<?php esc_html_e( 'Displays dashboard page.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Usage:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Logged-in users will be able to view all certificates generated for their own CPF/RF (Brazilian tax identification number).', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>[ffc_self_scheduling id="456"]</code></td>
				<td>
					<?php esc_html_e( 'Displays a personal calendar with appointment booking.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Usage:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Replace "456" with your Calendar ID. Users can view available slots and book appointments.', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>[ffc_audience]</code></td>
				<td>
					<?php esc_html_e( 'Displays the audience scheduling calendar for group bookings.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Usage:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Administrators can schedule activities for audiences (groups) in configured environments.', 'ffcertificate' ); ?>
				</td>
			</tr>
		</tbody>
	</table>
</div>
