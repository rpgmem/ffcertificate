<?php
/**
 * Documentation partial — Section 18: Troubleshooting.
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
<!-- 18. Troubleshooting Section -->
<div class="card">
	<h3 id="troubleshooting" class="ffc-icon-wrench"><?php esc_html_e( '18. Troubleshooting', 'ffcertificate' ); ?></h3>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Problem', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Solution', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Variable not replaced', 'ffcertificate' ); ?> <code>{{name}}</code></td>
				<td>
					• <?php esc_html_e( 'Check spelling matches exactly', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Ensure field exists in form', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Use lowercase for custom fields', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Image not showing in PDF', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Use absolute URLs (https://...)', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Check image is publicly accessible', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Add width/height attributes', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'QR Code too large/small', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Use:', 'ffcertificate' ); ?> <code>{{qr_code:size=150}}</code><br>
					• <?php esc_html_e( 'Recommended: 100-200px for certificates', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Formatting not showing (bold, italic)', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Use HTML tags:', 'ffcertificate' ); ?> <code>&lt;strong&gt;</code> <code>&lt;em&gt;</code><br>
					• <?php esc_html_e( 'Or inline style:', 'ffcertificate' ); ?> <code>style="font-weight: bold;"</code>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Layout broken in PDF', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Use tables for complex layouts', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Always use inline styles', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Test with simple content first', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Settings not saving between tabs', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Update to latest version', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Clear WordPress cache', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Clear browser cache (Ctrl+F5)', 'ffcertificate' ); ?>
				</td>
			</tr>
		</tbody>
	</table>

	<div class="ffc-alert ffc-alert-info ffc-mt-20">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Need More Help?', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'For additional support, check the plugin repository documentation or contact support.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
