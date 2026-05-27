<?php
/**
 * Documentation partial — Section 5: QR Code Options & Attributes.
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
<!-- 5. QR Code Options Section -->
<div class="card">
	<h3 id="qr-code" class="ffc-icon-phone"><?php esc_html_e( '5. QR Code Options & Attributes', 'ffcertificate' ); ?></h3>
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
					<strong><?php esc_html_e( 'Range:', 'ffcertificate' ); ?></strong> <?php esc_html_e( '100px–500px', 'ffcertificate' ); ?>
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
				<td><code>{{qr_code:error_level=H}}</code></td>
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
				<td><code>{{qr_code:size=200:margin=1:error_level=M}}</code></td>
				<td><?php esc_html_e( 'Combining multiple attributes (separate with colons)', 'ffcertificate' ); ?></td>
			</tr>
		</tbody>
	</table>
</div>
