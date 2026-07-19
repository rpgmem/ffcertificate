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
	<h3 id="reference-shortcodes"><span class="dashicons dashicons-shortcode" aria-hidden="true"></span> <?php esc_html_e( 'Shortcodes', 'ffcertificate' ); ?></h3>
	
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
					<strong><?php esc_html_e( 'Usage:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Administrators can schedule activities for audiences (groups) in configured environments.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Optional attributes:', 'ffcertificate' ); ?></strong>
					<code>schedule_id="789"</code> <?php esc_html_e( '(open a specific schedule directly),', 'ffcertificate' ); ?>
					<code>environment_id="12"</code> <?php esc_html_e( '(scope to one environment),', 'ffcertificate' ); ?>
					<code>view="month"</code> <?php esc_html_e( '(initial calendar view — "month" or "week").', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>[ffc_recruitment_queue notice="EDITAL-01"]</code></td>
				<td>
					<?php esc_html_e( 'Displays the public candidate queue (classification list) for a recruitment notice.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Usage:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Set "notice" to the notice code. The list shows only the columns marked public in the notice editor, and only while the notice is in a public state (preliminary / definitive); drafts and closed notices are hidden.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Optional attribute:', 'ffcertificate' ); ?></strong> <code>adjutancy="sao-paulo"</code> <?php esc_html_e( '(pre-filter to one adjutancy).', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'URL filters:', 'ffcertificate' ); ?></strong> <code>?q=</code> <?php esc_html_e( '(name search),', 'ffcertificate' ); ?> <code>?adjutancy=</code>, <code>?subscription=</code> <?php esc_html_e( '(pcd / geral),', 'ffcertificate' ); ?> <code>?page_top=</code> / <code>?page_bottom=</code> <?php esc_html_e( '(pagination).', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>[ffc_recruitment_my_calls]</code></td>
				<td>
					<?php esc_html_e( 'Shows the logged-in candidate their own call-ups (convocations) across recruitment notices.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Usage:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Place on a members area page. Candidates are matched by the CPF/RF on their account; no attributes are required.', 'ffcertificate' ); ?>
				</td>
			</tr>
		</tbody>
	</table>
</div>
