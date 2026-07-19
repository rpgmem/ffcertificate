<?php
/**
 * Documentation partial — Short URLs.
 *
 * The URL shortener: creating and auto-creating short links, the list, QR
 * codes, click counting, settings and capabilities. Reviewed against the
 * url-shortener module for the functional reorganization
 * (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Short URLs Section -->
<div class="card">
	<h3 id="feature-url-shortener"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> <?php esc_html_e( 'Short URLs', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'The URL shortener turns long links into short, click-counted redirects. It has its own top-level "Short URLs" admin menu.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Creating short URLs', 'ffcertificate' ); ?></h4>
	<ul>
		<li><strong><?php esc_html_e( 'Manually', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'enter a destination URL and an optional title; the short code is generated automatically (there is no custom-slug field).', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Automatically', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'when auto-create is on, publishing a post/page (of the enabled post types) creates a short URL for its permalink.', 'ffcertificate' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'The public short link is', 'ffcertificate' ); ?> <code>https://your-site/{prefix}/{code}</code> <?php esc_html_e( '(default prefix', 'ffcertificate' ); ?> <code>go</code><?php esc_html_e( ', e.g.', 'ffcertificate' ); ?> <code>/go/abc123</code>). <?php esc_html_e( 'Regenerating a link issues a new code and retires the old one.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The list & QR codes', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The list shows each link\'s title, short URL, destination, click count and status, with actions to show a QR code, enable/disable, or trash (trashed links can be restored or permanently deleted). Each link has a QR code (PNG or SVG download) that encodes the short URL itself, so scanning it is also counted.', 'ffcertificate' ); ?> <a href="#reference-qr-codes"><?php esc_html_e( 'See QR Codes', 'ffcertificate' ); ?></a>.</p>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Clicks are a counter only.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Each visit increments a click count; the plugin does not store per-click timestamps, referrers or IPs. Disabled and trashed links redirect home and are not counted.', 'ffcertificate' ); ?>
		</p>
	</div>

	<h4><?php esc_html_e( 'Settings', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Setting', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Default', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><?php esc_html_e( 'Enable the shortener', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'on', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'URL prefix (path segment)', 'ffcertificate' ); ?></td><td><code>go</code></td></tr>
			<tr><td><?php esc_html_e( 'Code length (4–10)', 'ffcertificate' ); ?></td><td><?php esc_html_e( '6', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Redirect type (301 / 302 / 307)', 'ffcertificate' ); ?></td><td><?php esc_html_e( '302', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Auto-create on publish', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'on', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Enabled post types', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'posts & pages', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<ul>
		<li><code>ffc_view_url_shortener</code> — <?php esc_html_e( 'view the list and download QR codes.', 'ffcertificate' ); ?></li>
		<li><code>ffc_manage_url_shortener</code> — <?php esc_html_e( 'create, edit, enable/disable and regenerate links.', 'ffcertificate' ); ?></li>
		<li><code>ffc_delete_url_shortener</code> — <?php esc_html_e( 'trash, restore and permanently delete.', 'ffcertificate' ); ?></li>
	</ul>
</div>
