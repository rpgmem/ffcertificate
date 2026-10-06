<?php
/**
 * Documentation partial — Reference: QR Codes.
 *
 * Documents the {{qr_code}} token and its attributes (size, and other
 * customization options) for embedding QR codes in a certificate.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 5. QR Code Options Section -->
<div class="card">
	<h3 id="reference-qr-codes"><span class="dashicons dashicons-camera" aria-hidden="true"></span> <?php esc_html_e( 'QR Code Options & Attributes', 'ffcertificate' ); ?></h3>
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

	<h4><?php esc_html_e( 'QR Code defaults', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Default size (px), margin (modules) and error-correction level (L / M / Q / H) applied to the {{qr_code}} placeholder when it does not specify its own. Per-placeholder options always win.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'QR Code Design', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Settings → QR Code also sets the shape of the modules and of the three corner markers, their colours and an optional gradient, with a live preview that warns about low contrast and inverted colours. The design is applied only where it is switched on: the {{qr_code}} placeholder of certificates, and the QR codes of short URLs. Elsewhere the plain black-and-white code is kept.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'A logo from the Media Library can be drawn in the centre (error correction is raised to H automatically), and a frame (banner, badge, bubble, pill, speech, circle, brackets or double) can carry a short caption such as "Scan to verify", optionally with an icon. A frame makes the image taller than wide; certificates keep the width the placeholder asks for and grow in height.', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'With the design applied to certificates, the {{qr_code}} placeholder is drawn as an SVG and is not cached; without it, a PNG is generated and may be served from the QR Code cache.', 'ffcertificate' ); ?></p>
</div>
