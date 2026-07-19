<?php
/**
 * Documentation partial — Forms: Public Operator Access.
 *
 * The hash-gated public page that lets a trusted operator without a WordPress
 * login download the submissions CSV, preview certificates, and drive the
 * Start-Early / Postpone-Close actions. Part of the functional reorganization
 * (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Forms: Public Operator Access Section -->
<div class="card">
	<h3 id="forms-public-operator-access"><span class="dashicons dashicons-share" aria-hidden="true"></span> <?php esc_html_e( 'Public Operator Access', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Public Operator Access (formerly "Public CSV Download") lets a trusted operator who does NOT have a WordPress login interact with a single form through a secret, per-form link. It is enabled in the form editor\'s "Public Operator Access" box.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The per-form link', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'When enabled, each form gets its own secret hash. The operator opens a page carrying the', 'ffcertificate' ); ?> <code>[ffc_csv_download]</code> <?php esc_html_e( 'shortcode with the form id and hash in the query string:', 'ffcertificate' ); ?></p>
	<pre><code>https://example.com/operator-page/?form_id=123&amp;hash=&lt;secret&gt;</code></pre>
	<p><?php esc_html_e( 'The hash is the only credential — treat the link as a password. Set the base page under Settings → General ("CSV Download Page URL") so the editor shows the full link instead of just the query string. The hash is regenerated on demand and is deliberately NOT copied when a form is duplicated.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'What the operator can do', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Each capability is an independent per-form toggle:', 'ffcertificate' ); ?></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Action', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Notes', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><?php esc_html_e( 'Preview certificate', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Render the certificate template (on by default).', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Download submissions CSV', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'On by default, but only unlocks after the form\'s end date has passed and while download quota remains.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Start Form Early', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Off by default. One-shot — see Schedule.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Postpone Close', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Off by default. One-shot — see Schedule.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-lock"><?php esc_html_e( 'CSV download unlocks only after the form closes.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'A form with no end date never releases the CSV. Downloads are also capped by a per-form quota (blank inherits the global Default Download Limit under Settings → Advanced), and every download is written to a per-form audit ring buffer (timestamp, IP, mode, result) that admins can export.', 'ffcertificate' ); ?>
		</p>
	</div>

	<h4><?php esc_html_e( 'CPF gate on the CSV', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The CSV can additionally require the operator to prove a CPF before it releases. Modes:', 'ffcertificate' ); ?></p>
	<ul>
		<li><code>none</code> — <?php esc_html_e( 'no CPF check.', 'ffcertificate' ); ?></li>
		<li><code>audit</code> — <?php esc_html_e( 'ask for a CPF and log it, but never block.', 'ffcertificate' ); ?></li>
		<li><code>participants</code> — <?php esc_html_e( 'the CPF must match one of the form\'s own submissions.', 'ffcertificate' ); ?></li>
		<li><code>owner</code> — <?php esc_html_e( 'the CPF must match the form author\'s account CPF.', 'ffcertificate' ); ?></li>
		<li><code>whitelist</code> — <?php esc_html_e( 'the CPF must be on a per-form allowlist.', 'ffcertificate' ); ?></li>
	</ul>
</div>
