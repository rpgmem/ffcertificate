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
	<h3 id="reference-security" class="ffc-icon-lock"><?php esc_html_e( 'Security & Restrictions', 'ffcertificate' ); ?></h3>

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
				<td><?php esc_html_e( 'Each submitter must present a single-use code from a generated pool. The code is claimed atomically (one use only) and removed from the pool as soon as the restriction check passes, before the submission is saved.', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Booking participants only', 'ffcertificate' ); ?></strong></td>
				<td><?php esc_html_e( 'Only a person invited by an audience booking\'s post-event link can submit. The link fills in and locks their name and e-mail from their account, works without logging in until the booking\'s form window closes, and names one person: their CPF/RF is typed, and refused when it belongs to another account. Opening the link again after answering returns the same certificate. Without this restriction the form stays open, and an invitation link only fills it in.', 'ffcertificate' ); ?></td>
			</tr>
		</tbody>
	</table>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'If someone submits again with a CPF/RF that already has a certificate, no new one is generated. This is always on — it is what keeps one person from issuing two certificates. Since anyone can type a CPF/RF, the certificate is shown on screen only to its owner while logged in; anyone else is told that it exists, and its link is e-mailed to the address on record, at most once every 15 minutes, with that address shown masked. A certificate with no e-mail on record asks the visitor to contact the organizer. On a form with the Ticket restriction, re-entering a ticket that already issued a certificate returns that certificate; on a quiz form a used ticket is rejected, because there a new submission is a new attempt.', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array( 'title' => __( 'Reprints are automatic.', 'ffcertificate' ) )
	);
	?>

	<h4><?php esc_html_e( 'Per-device limit (fingerprint)', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A form can cap how many submissions come from the same device using a browser/device fingerprint. Empty per-form values inherit the global defaults from Settings → Rate Limit → Device Fingerprint, and the per-form toggle stays disabled until that global subsystem is enabled. Automatic reprints are exempt from the device cap.', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'This is the form/submission device limit; the broader request throttling lives in Settings → Rate Limit.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Other protections', 'ffcertificate' ); ?></h4>
	<ul class="ffc-doc-list">
		<li>
			<strong><?php esc_html_e( 'Honeypot & CAPTCHA:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'An invisible honeypot field plus a challenge the visitor has to pass. Two are available: an arithmetic question that works without JavaScript, and ALTCHA, a proof of work solved in the browser and served entirely from this site. Either way the token is signed, expires and can only be used once. The two can also run side by side, so a visitor without JavaScript still has a way through — that mode is reach, not extra strength, since the server accepts either proof. Choose the mode in Settings → Captcha.', 'ffcertificate' ); ?>
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
			<?php esc_html_e( 'E-mail, CPF, RF, the client IP and the submission\'s extra form data are stored encrypted with AES-256-CBC using encrypt-then-HMAC; a salted hash backs lookups. Display is masked unless the viewer holds a PII capability.', 'ffcertificate' ); ?>
		</li>
	</ul>
</div>
