<?php
/**
 * Documentation partial — Reference: Shortcodes.
 *
 * Documents the plugin's public shortcodes ([ffc_form], [ffc_verification], …)
 * and how to place them on a page.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 1. Shortcodes Section -->
<div class="card">
	<h3 id="reference-shortcodes" class="ffc-icon-code"><?php esc_html_e( 'Shortcodes', 'ffcertificate' ); ?></h3>
	
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
				<td><code>[ffc_geofence date_end="2026-12-31" locations="school"]…[/ffc_geofence]</code></td>
				<td>
					<?php esc_html_e( 'Shows the content between the tags only to a visitor inside a date/time window and/or inside an area, with the same checks as a geofenced form.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Usage:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Place it in a page or post. The content is not in the page HTML: once the browser checks pass it is loaded from the server, which re-checks the date/time window and, when asked, the IP location. Without any location or area attribute, the default GPS and IP locations of Settings → Geolocation apply.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Optional attributes:', 'ffcertificate' ); ?></strong>
					<code>date_start</code> / <code>date_end</code> <?php esc_html_e( '(YYYY-MM-DD),', 'ffcertificate' ); ?>
					<code>time_start</code> / <code>time_end</code> <?php esc_html_e( '(HH:MM),', 'ffcertificate' ); ?>
					<code>time_mode="span"</code> <?php esc_html_e( '(one continuous period instead of the same hours every day),', 'ffcertificate' ); ?>
					<code>locations="id1,id2"</code> / <code>ip_locations</code> <?php esc_html_e( '(location ids from Settings → Geolocation),', 'ffcertificate' ); ?>
					<code>areas="lat, lng, radius; …"</code> / <code>ip_areas</code> <?php esc_html_e( '(one-off areas, radius in meters),', 'ffcertificate' ); ?>
					<code>gps="no"</code>, <code>ip="yes"</code>, <code>logic="and"</code> <?php esc_html_e( '(which checks run, and whether GPS and IP must both pass),', 'ffcertificate' ); ?>
					<code>hide_mode="hide"</code>, <code>message="…"</code> <?php esc_html_e( '(hide the block entirely, or the message shown instead of the content).', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Limits:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'GPS coordinates come from the visitor\'s browser and can be faked, so a GPS check controls who sees the content, not who is allowed to: never put anything secret behind it. The date/time and IP checks are repeated on the server. The shortcode only works in the content of a page or post, not in widgets or templates.', 'ffcertificate' ); ?>
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
					<strong><?php esc_html_e( 'Usage:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Set "notice" to the notice code. The list shows only the columns marked public in the notice editor. Draft notices show a "being prepared" message instead of data; preliminary, definitive and closed notices show the list (a closed notice with a "Notice closed" banner).', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Optional attribute:', 'ffcertificate' ); ?></strong> <code>adjutancy="sao-paulo"</code> <?php esc_html_e( '(pre-filter to one adjutancy).', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Without a notice:', 'ffcertificate' ); ?></strong> <code>[ffc_recruitment_queue]</code> <?php esc_html_e( 'shows a selector of every notice in its preliminary or definitive list (drafts and closed notices are left out); the visitor picks one and its list appears below. With a single notice on offer it opens directly. The notice edit screen has the shortcode ready to copy.', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Optional attribute:', 'ffcertificate' ); ?></strong> <code>list="preliminary"</code> / <code>list="definitive"</code> <?php esc_html_e( '(offer only notices in that list; with a fixed notice, show a "not published" message while the notice is not in that list).', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'URL filters:', 'ffcertificate' ); ?></strong> <code>?notice=</code> <?php esc_html_e( '(the notice chosen in the selector),', 'ffcertificate' ); ?> <code>?q=</code> <?php esc_html_e( '(name search),', 'ffcertificate' ); ?> <code>?adjutancy=</code>, <code>?subscription=</code> <?php esc_html_e( '(pcd / geral),', 'ffcertificate' ); ?> <code>?page_top=</code> / <code>?page_bottom=</code> <?php esc_html_e( '(pagination).', 'ffcertificate' ); ?>
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
