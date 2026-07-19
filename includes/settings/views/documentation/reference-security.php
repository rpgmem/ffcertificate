<?php
/**
 * Documentation partial — Forms: Security & Restrictions.
 *
 * Form-level access restrictions (password / allow- & denylist / tickets /
 * per-device limit), reprint prevention, and the other anti-abuse features.
 * Expanded for the functional reorganization (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Forms: Security & Restrictions Section -->
<div class="card">
	<h3 id="reference-security"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Security & Restrictions', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'The form editor\'s "Restrictions and Tickets" box gates who may submit. Restrictions can be combined; when none is selected the form is open. They are checked in a fixed order: Password → Denylist → Allowlist → Ticket.', 'ffcertificate' ); ?></p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Restriction', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'How it works', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><strong><?php esc_html_e( 'Single Password', 'ffcertificate' ); ?></strong></td>
				<td><?php esc_html_e( 'One shared password everyone must type to submit.', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Allowlist', 'ffcertificate' ); ?></strong></td>
				<td><?php esc_html_e( 'Only the listed CPF/RF numbers may submit (one per line; masked or unmasked both work).', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Denylist', 'ffcertificate' ); ?></strong></td>
				<td><?php esc_html_e( 'The listed CPF/RF numbers are blocked. The denylist takes priority over the allowlist.', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Ticket (unique codes)', 'ffcertificate' ); ?></strong></td>
				<td><?php esc_html_e( 'Each submitter must present a single-use code from a generated pool. The code is claimed atomically (one use only) and consumed after a successful submission.', 'ffcertificate' ); ?></td>
			</tr>
		</tbody>
	</table>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Reprints are automatic.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'If someone submits again with a CPF/RF (or ticket) that already has a certificate, they are returned their existing certificate instead of generating a new one. This is always on — it is what keeps one person from issuing two certificates.', 'ffcertificate' ); ?>
		</p>
	</div>

	<h4><?php esc_html_e( 'Per-device limit (fingerprint)', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A form can cap how many submissions come from the same device using a browser/device fingerprint. Empty per-form values inherit the global defaults from Settings → Rate Limit → Device Fingerprint, and the per-form toggle stays disabled until that global subsystem is enabled. Automatic reprints are exempt from the device cap.', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'This is the form/submission device limit; the broader request throttling lives in Settings → Rate Limit.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Other protections', 'ffcertificate' ); ?></h4>
	<ul class="ffc-doc-list">
		<li>
			<strong><?php esc_html_e( 'Honeypot & Math CAPTCHA:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Invisible honeypot field plus a basic math challenge to stop bots.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Rate limiting:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Configurable submission throttling by IP, email, CPF/RF and more (Settings → Rate Limit).', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Geofencing (GPS + IP):', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Restrict where the form may be submitted.', 'ffcertificate' ); ?> <a href="#forms-geolocation"><?php esc_html_e( 'See Geolocation.', 'ffcertificate' ); ?></a>
		</li>
		<li>
			<strong><?php esc_html_e( 'Encryption at rest (LGPD):', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'CPF and RF are stored encrypted with AES-256-CBC using encrypt-then-HMAC; a salted hash backs lookups. Display is masked unless the viewer holds a PII capability.', 'ffcertificate' ); ?>
		</li>
	</ul>
</div>
