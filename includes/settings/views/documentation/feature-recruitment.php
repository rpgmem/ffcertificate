<?php
/**
 * Documentation partial — Recruitment.
 *
 * Public-tender candidate queues: notices, adjutancies, reasons, candidates,
 * public shortcodes, settings and capabilities. Reviewed against the
 * recruitment module for the functional reorganization
 * (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Recruitment Section -->
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
			<tr><td><?php esc_html_e( 'Notices', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Create and edit notices (editais). Each notice has a unique code, a lifecycle status, and a configurable set of public columns.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Adjutancies', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Manage adjutancies — the named units (with a color) attached to a notice to organize and filter its candidates.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Candidates', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Look up candidates by CPF/RF, import them (CSV), and record call-ups and outcomes.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Reasons', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Manage the reusable reason labels for the preliminary list (e.g. granted / denied / appeal), and which preliminary statuses each applies to.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Settings', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Module-wide options: convocation email, public-queue tuning, status colors, and the PII-reveal audit toggle.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Notice lifecycle', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A notice moves through these statuses; the public shortcode exposes the classification from preliminary onward:', 'ffcertificate' ); ?></p>
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
			<tr><td><code>definitive</code></td><td><?php esc_html_e( 'Yes', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Final ranking in force; candidates can be called.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>closed</code></td><td><?php esc_html_e( 'Yes', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Finished; still shown publicly with a "closed" banner. Reopening back to definitive requires a reason.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Public shortcodes', 'ffcertificate' ); ?></h4>
	<ul>
		<li><code>[ffc_recruitment_queue notice="EDITAL-01"]</code> — <?php esc_html_e( 'the public classification list for a notice (the notice code is required). Shows only the columns the notice marks public. Accepts an optional adjutancy="slug" attribute, and honors the ?q (name search), ?adjutancy, ?subscription (pcd/geral) and ?page_top / ?page_bottom URL filters.', 'ffcertificate' ); ?></li>
		<li><code>[ffc_recruitment_my_calls]</code> — <?php esc_html_e( 'shows the logged-in candidate their own call-ups (no attributes). Matched by the candidate record linked to their WordPress account.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Import & call-ups', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Candidates are imported from CSV (headers: name, cpf, rf, email, adjutancy, rank, score, pcd, plus optional phone, time_points, hab_emebs) into either the preliminary or the definitive list. Call-ups are recorded per candidate; calling out of rank order requires a reason. Outcomes move a candidate through called → accepted / not_shown / hired / withdrew.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Capability', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Grants', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><code>ffc_view_recruitment</code></td><td><?php esc_html_e( 'read-only access to the admin pages.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_manage_recruitment</code></td><td><?php esc_html_e( 'create / edit notices, candidates and adjutancies.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_import_recruitment</code> / <code>ffc_call_recruitment</code></td><td><?php esc_html_e( 'import candidates / record call-ups.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_delete_recruitment</code></td><td><?php esc_html_e( 'delete notices, candidates and adjutancies.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_view_recruitment_reasons</code> / <code>ffc_manage_recruitment_reasons</code></td><td><?php esc_html_e( 'view / edit the reason catalog.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_view_recruitment_settings</code> / <code>ffc_manage_recruitment_settings</code></td><td><?php esc_html_e( 'view / save the Settings tab.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_view_recruitment_pii</code></td><td><?php esc_html_e( 'reveal unmasked CPF/RF/email — without it those columns stay masked.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-lock"><?php esc_html_e( 'Privacy:', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'CPF, RF and email are stored encrypted and shown masked by default in both the admin lists and the public queue; unmasking requires the ffc_view_recruitment_pii capability, and a candidate can always see their own data. Convocation emails always mask these fields.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
