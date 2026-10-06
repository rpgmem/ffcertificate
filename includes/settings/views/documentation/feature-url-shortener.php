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

	<h4><?php esc_html_e( 'Exposing short URLs as the site shortlink', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The Post Types setting has two columns per type: "Shorten" (show the meta box and allow short URLs for that type) and "Expose". When a type is exposed, a published post\'s short URL becomes the site\'s canonical shortlink — WordPress emits it as the', 'ffcertificate' ); ?> <code>rel="shortlink"</code> <?php esc_html_e( 'tag in the page head and the HTTP Link header, and it also backs the editor\'s "Get Shortlink" button and REST. Expose is off by default (opt-in), depends on Shorten, and only takes effect once a short URL exists for the page — otherwise WordPress falls back to its native shortlink.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The "Generate missing short URLs" button creates short URLs for already-published posts of the shortened types that do not have one yet (it runs in timeout-safe batches). This only creates the short URLs — tick "Expose" to publish them as shortlinks.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Editing & the destination.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Manually-created links can be edited (destination URL + title) from the list. Links created for a post/page are not editable — their destination automatically follows the page: if you change the post slug (or the site permalink structure), the short URL keeps redirecting to the current permalink.', 'ffcertificate' ); ?>
		</p>
	</div>

	<h4><?php esc_html_e( 'The list & QR codes', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The list shows each link\'s title, short URL, destination, click count and status, with actions to show a QR code, enable/disable, or trash (trashed links can be restored or permanently deleted). Each link has a QR code (PNG or SVG download) that encodes the short URL itself, so scanning it is also counted.', 'ffcertificate' ); ?> <a href="#reference-qr-codes"><?php esc_html_e( 'See QR Codes', 'ffcertificate' ); ?></a>.</p>
	<h4><?php esc_html_e( 'QR Code Generator', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Short URLs → QR Code Generator makes a QR code for a website, plain text, a Wi-Fi network (Enterprise networks that sign in with a user name included), an e-mail, a phone call, an SMS, a WhatsApp chat, a contact card (vCard), a social profile or an event, styled from the global design and downloaded as PNG or SVG. It stores nothing: only the "Shorten" button creates a short URL, which then appears in the list. It needs ffc_manage_url_shortener.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'A capacity meter and density and contrast warnings help keep the code readable, and the preview panel can also print it. An event code can open the event itself, a pre-filled Google Calendar link, or a signed link to an .ics file; that link may carry an optional "valid until" date, after which it answers that it has expired, while a link without one never expires. The generator reopens with each user\'s last downloaded design (design only, never content), and "Reset to default" restores the global one.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The global design (module shape, corners, colours and gradient, logo, frame and caption, transparent background) is set on Settings → QR Code. It is off by default and switched on separately for certificates and for short URLs.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'QR Codes are also available over REST for external automations:', 'ffcertificate' ); ?> <code>GET /ffc/v1/short-urls/{code}/qr</code> (<?php esc_html_e( 'base64 png/svg, cap-gated by ffc_view_url_shortener via an Application Password).', 'ffcertificate' ); ?> <a href="#developer-hooks-api"><?php esc_html_e( 'See the REST API', 'ffcertificate' ); ?></a>.</p>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Clicks are a counter only.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Each visit increments a click count; the plugin does not store per-click timestamps, referrers or IPs. Disabled and trashed links redirect home and are not counted.', 'ffcertificate' ); ?>
		</p>
	</div>

	<h4><?php esc_html_e( 'Exporting', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The "Export CSV" button downloads the list — code, title, destination, click count and status — as a CSV file, honouring the current search box, status filter and sort order. The export runs as a timeout-safe background job (the same batched engine every other list export uses), so it is safe on shared hosting with large link tables. Gated by the', 'ffcertificate' ); ?> <code>ffc_export_url_shortener</code> <?php esc_html_e( 'capability.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Settings', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Setting', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Default', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><?php esc_html_e( 'Enable the shortener (Settings → Modules)', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'on', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'URL prefix (path segment)', 'ffcertificate' ); ?></td><td><code>go</code></td></tr>
			<tr><td><?php esc_html_e( 'Code length (4–10)', 'ffcertificate' ); ?></td><td><?php esc_html_e( '6', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Redirect type (301 / 302 / 307)', 'ffcertificate' ); ?></td><td><?php esc_html_e( '302', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Auto-create on publish', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'on', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Shortened post types', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'posts & pages', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Exposed post types (as shortlink)', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'none', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<ul>
		<li><code>ffc_view_url_shortener</code> — <?php esc_html_e( 'view the list and download QR codes.', 'ffcertificate' ); ?></li>
		<li><code>ffc_manage_url_shortener</code> — <?php esc_html_e( 'create, edit, enable/disable and regenerate links.', 'ffcertificate' ); ?></li>
		<li><code>ffc_delete_url_shortener</code> — <?php esc_html_e( 'trash, restore and permanently delete.', 'ffcertificate' ); ?></li>
		<li><code>ffc_export_url_shortener</code> — <?php esc_html_e( 'download the short-URL list (codes, targets, click counts) as CSV.', 'ffcertificate' ); ?></li>
	</ul>
</div>
