<?php
/**
 * Documentation partial — Reference: QR Codes.
 *
 * Documents the {{qr_code}} token and its attributes (size, and other
 * customization options) for embedding QR codes in a certificate. The global
 * defaults and design it reads are documented with their tab, config-qr-code.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- QR Code in the Certificate Section -->
<div class="card">
	<h3 id="reference-qr-codes" class="ffc-icon-qr"><?php esc_html_e( 'QR Code Options & Attributes', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'The QR code can be customized with various attributes:', 'ffcertificate' ); ?></p>
	
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Usage', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>{{qr_code}}</code></td>
				<td>
					<?php esc_html_e( 'Default QR code (uses settings from QR Code tab)', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Default size:', 'ffcertificate' ); ?></strong> 200x200px
				</td>
			</tr>
			<tr>
				<td><code>{{qr_code:size=150}}</code></td>
				<td>
					<?php esc_html_e( 'Custom size (150x150 pixels)', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Range:', 'ffcertificate' ); ?></strong> <?php esc_html_e( '50px–1000px (recommended 100–500)', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>{{qr_code:margin=0}}</code></td>
				<td>
					<?php esc_html_e( 'No white margin around QR code', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Range:', 'ffcertificate' ); ?></strong> 0-10 <?php esc_html_e( '(default: 2)', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>{{qr_code:error=H}}</code></td>
				<td>
					<?php esc_html_e( 'Error correction level', 'ffcertificate' ); ?><br>
					<strong><?php esc_html_e( 'Options:', 'ffcertificate' ); ?></strong><br>
					• <code>L</code> = <?php esc_html_e( 'Low (7%)', 'ffcertificate' ); ?><br>
					• <code>M</code> = <?php esc_html_e( 'Medium (15% - recommended)', 'ffcertificate' ); ?><br>
					• <code>Q</code> = <?php esc_html_e( 'Quartile (25%)', 'ffcertificate' ); ?><br>
					• <code>H</code> = <?php esc_html_e( 'High (30%)', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>{{qr_code:size=200:margin=1:error=M}}</code></td>
				<td><?php esc_html_e( 'Combining multiple attributes (separate with colons)', 'ffcertificate' ); ?></td>
			</tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Global QR Code settings', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The certificate PDF draws this code with the plugin-wide settings on Settings → QR Code: their defaults fill in any attribute the placeholder leaves out, and their design is used when it is applied to certificates.', 'ffcertificate' ); ?> <a href="#config-qr-code"><?php esc_html_e( 'See QR Code settings', 'ffcertificate' ); ?></a>.</p>
	<p class="description"><?php esc_html_e( 'With the design applied to certificates, the {{qr_code}} placeholder is drawn as an SVG and is not cached; without it, a PNG is generated and may be served from the QR Code cache.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Signed QR codes', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A form chooses what its certificate\'s QR code carries on the Layout tab of the form editor, under QR code: Link only (the default, as before), Signed, compact, or Signed, full. A signed mode signs a record of each certificate when it is issued: form, certificate number, validation code, issue date, name, a keyed fingerprint of the CPF/RF (never the number itself), course, workload and event dates. The layout is not part of the record, so changing the design never breaks a signature.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The verification page checks the certificate against its signed record and says one of three things: integrity confirmed, changed after signing (naming the fields that changed), or signature not valid. Editing a signed certificate signs it again.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Signing needs a key, generated once on Settings → Certificates, in the Integrity signature card, by someone with the Danger Zone capability. Rotating it retires the old key, which keeps verifying what it signed. Until a key exists, forms with a signed mode print the link only, and each certificate that could not be signed is recorded in the activity log.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The signature rides in the part of the link after #, which a browser never sends to the server. The verification page checks it in the browser with the site\'s public keys and lists what was signed, even when the site does not answer; when it does, a QR code printed for an earlier version of the certificate is reported as changed after issue. Without internet, the offline verifier downloaded from the Integrity signature card checks a pasted link the same way. QR codes printed before a form switched to a signed mode carry the link only and verify as before.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Each QR code is drawn with the largest payload its printed size can carry readably: the full one where it fits, else the compact one, else the link only.', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'A signed QR code carries more data, so it needs more room on the page and is drawn without the logo of the QR Code design. The form editor warns when the layout\'s QR code is smaller than the mode needs to be read reliably on paper, and the visual editor\'s QR code panel says which modes the selected code\'s size prints readably.', 'ffcertificate' ); ?></p>
</div>
