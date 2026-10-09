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
</div>
