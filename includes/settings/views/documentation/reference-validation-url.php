<?php
/**
 * Documentation partial — Section 6: Validation URL.
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
<!-- 6. Validation URL Section -->
<div class="card">
	<h3 id="reference-validation-url"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Validation URL', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'The Validation URL can be customized with various attributes:', 'ffcertificate' ); ?></p>
	
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Usage', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>{{validation_url}}</code></td>
				<td>
					<?php esc_html_e( 'Default: link to magic, text shows /valid', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>{{validation_url link:X>Y}}</code></td>
				<td>
					<code>{{validation_url link:m>v}}</code> → <?php esc_html_e( 'Link to magic, text /valid', 'ffcertificate' ); ?><br>
					<code>{{validation_url link:v>v}}</code> → <?php esc_html_e( 'Link to /valid, text /valid', 'ffcertificate' ); ?><br>
					<code>{{validation_url link:m>m}}</code> → <?php esc_html_e( 'Link to magic, text magic', 'ffcertificate' ); ?><br>
					<code>{{validation_url link:v>m}}</code> → <?php esc_html_e( 'Link to /valid, text magic', 'ffcertificate' ); ?><br>
					<code>{{validation_url link:v>"Custom Text"}}</code> → <?php esc_html_e( 'Link to /valid, custom text', 'ffcertificate' ); ?><br>
					<code>{{validation_url link:m>"Custom Text"}}</code> →  <?php esc_html_e( 'Link to magic, custom text', 'ffcertificate' ); ?><br>
					<code>{{validation_url link:m>v target:_blank}}</code> → <?php esc_html_e( 'With target', 'ffcertificate' ); ?><br>
					<code>{{validation_url link:m>v color:blue}}</code> → <?php esc_html_e( 'With color link', 'ffcertificate' ); ?><br>
					<code>{{validation_url link:m>"Download (PDF)" color:#ffffff}}</code> → <?php esc_html_e( 'Magic (download) link with custom text and color — custom text may contain spaces', 'ffcertificate' ); ?><br>
				</td>
			</tr>
		</tbody>
	</table>
	<p><?php esc_html_e( 'Available in both the certificate PDF layout and the submitter confirmation email. In the email, "m" (magic link) is the view/download link; pair it with an inline-styled box for a download button.', 'ffcertificate' ); ?></p>
</div>
