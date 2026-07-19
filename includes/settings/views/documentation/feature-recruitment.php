<?php
/**
 * Documentation partial — Section 20: Recruitment.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 20. Recruitment Section -->
<div class="card">
	<h3 id="feature-recruitment"><span class="dashicons dashicons-groups" aria-hidden="true"></span> <?php esc_html_e( 'Recruitment', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'The Recruitment module manages public-tender candidate queues: import classified candidates, publish the ranking, and record call-ups (convocations). It lives under the top-level "Recruitment" admin menu.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Admin tabs', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Tab', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Purpose', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><?php esc_html_e( 'Notices', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Create and edit notices (editais). Each notice has a code, a lifecycle status, and a configurable set of public columns.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Adjutancies', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Manage adjutancies (regional units) used to group and filter candidates.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Candidates', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Browse, import (CSV) and edit candidates; record call-ups and outcomes.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Reasons', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Manage the reusable reason labels (e.g. for non-attendance) and which preliminary statuses each applies to.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Settings', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Module-wide options.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Notice lifecycle', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A notice moves through these statuses; only public statuses are exposed by the public shortcode:', 'ffcertificate' ); ?></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Public?', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Meaning', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><code>draft</code></td><td><?php esc_html_e( 'No', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Being prepared; never shown publicly.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>preliminary</code></td><td><?php esc_html_e( 'Yes', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Provisional ranking published for review.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>active</code> <?php esc_html_e( '(definitive)', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Yes', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Final ranking in force; candidates can be called.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>closed</code></td><td><?php esc_html_e( 'No', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Finished; no longer listed publicly.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Public shortcodes', 'ffcertificate' ); ?></h4>
	<p>
		<?php esc_html_e( 'Two shortcodes expose recruitment data on the front end (full attributes on the Shortcodes reference page):', 'ffcertificate' ); ?>
	</p>
	<ul>
		<li><code>[ffc_recruitment_queue notice="EDITAL-01"]</code> — <?php esc_html_e( 'the public classification list for a notice. Shows only the columns marked public in the notice editor, and only while the notice is preliminary or active. Supports an "adjutancy" attribute and the ?q / ?adjutancy / ?subscription / ?page_top / ?page_bottom URL filters.', 'ffcertificate' ); ?></li>
		<li><code>[ffc_recruitment_my_calls]</code> — <?php esc_html_e( 'shows the logged-in candidate their own call-ups, matched by the CPF/RF on their account.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Access is gated by granular capabilities (grantable per user on the profile screen):', 'ffcertificate' ); ?></p>
	<ul>
		<li><code>ffc_view_recruitment</code> — <?php esc_html_e( 'view the recruitment admin pages.', 'ffcertificate' ); ?></li>
		<li><code>ffc_manage_recruitment</code> — <?php esc_html_e( 'create / edit notices, candidates, adjutancies and reasons.', 'ffcertificate' ); ?></li>
		<li><code>ffc_import_recruitment</code> — <?php esc_html_e( 'import candidates from CSV.', 'ffcertificate' ); ?></li>
		<li><code>ffc_call_recruitment</code> — <?php esc_html_e( 'record call-ups / convocations.', 'ffcertificate' ); ?></li>
		<li><code>ffc_view_recruitment_pii</code> — <?php esc_html_e( 'reveal unmasked personal data (CPF/RF/email) — without it, those columns stay masked.', 'ffcertificate' ); ?></li>
	</ul>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-lock"><?php esc_html_e( 'Privacy:', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'Personal data columns (CPF, RF, email) are masked by default in both the admin lists and the public queue; unmasking requires the ffc_view_recruitment_pii capability.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
