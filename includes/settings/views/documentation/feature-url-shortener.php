<?php
/**
 * Documentation partial — Short URLs.
 *
 * The URL shortener: creating and auto-creating short links, the editor box
 * and the post lifecycle, retired codes, the inactive-link page, previous
 * prefixes, the redirect type, the list, QR codes, click counting, settings
 * and capabilities. The QR Code Generator, where manual short URLs are
 * created, has its own topic. Reviewed against the url-shortener module for
 * the functional reorganization (rpgmem/ffcertificate#697) and the module
 * audit (rpgmem/ffcertificate#2337).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Short URLs Section -->
<div class="card">
	<h3 id="feature-url-shortener" class="ffc-icon-link"><?php esc_html_e( 'Short URLs', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'The URL shortener turns long links into short, click-counted redirects. It has its own top-level "Short URLs" admin menu.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Creating short URLs', 'ffcertificate' ); ?></h4>
	<ul>
		<li><strong><?php esc_html_e( 'Manually', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'in Short URLs → QR Code Generator (the "New short URL" button on the list opens it), on the Website or Social profile type with "Create a short URL" on: enter the destination and a title, and the short URL is created when the code is downloaded or printed. The short code is generated automatically (there is no custom-slug field).', 'ffcertificate' ); ?> <a href="#feature-qr-generator"><?php esc_html_e( 'See the QR Code Generator', 'ffcertificate' ); ?></a>.</li>
		<li><strong><?php esc_html_e( 'Automatically', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'when auto-create is on, publishing a post/page (of the enabled post types) creates a short URL for its permalink, and so does a later save while it has none. Opening the editor never creates one.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'From the editor', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'when auto-create is off, the "Short URL & QR Code" box offers a Generate button; with auto-create on it says the short URL is created when the post is saved.', 'ffcertificate' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'The public short link is', 'ffcertificate' ); ?> <code>https://your-site/{prefix}/{code}</code> <?php esc_html_e( '(default prefix', 'ffcertificate' ); ?> <code>go</code><?php esc_html_e( ', e.g.', 'ffcertificate' ); ?> <code>/go/abc123</code>). <?php esc_html_e( 'Codes are case-sensitive: aBc123 and abc123 are two different codes, which is what lets the generator draw from upper and lower case. A post never has more than one short URL, whatever its state.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The editor box', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Everyone who can edit the post sees the box: its status (active, disabled or in the trash), the link with a copy button, its clicks and, while it is active, its QR code to download. Copying the link and downloading the QR code need only permission to edit the post.', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Reactivate', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'shown when the link is disabled or in the trash. It brings back the same code, so its clicks and every QR code already printed keep working.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Regenerate', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'shown when the link is active. The same link receives a new code: its clicks, title and destination carry over, the old code stops working at once and is retired for good. Use it when a code must stop working, for example after it was shared by mistake.', 'ffcertificate' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'Both need ffc_manage_url_shortener and permission to edit that post, and both update the box in place, without reloading the editor.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The link follows its post', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Trashing a post disables its short URL; restoring and publishing it again reactivates it; deleting the post for good deletes the short URL and retires its code.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Retired codes', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A code leaves use when its link is deleted (one at a time, by Empty Trash or by the cleanup tool) or regenerated. It is then recorded as retired and is never issued again, so a printed link or QR code can never start pointing somewhere else. Administrators see them under "Retired" in the list\'s status filter.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'When a link is not active', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'An unknown, disabled, trashed or retired code shows a short page of its own, without the site\'s theme, saying the link is no longer active. It takes the visitor to the home page after 15 seconds, with a link to go now. It answers 410 (Gone) for a disabled, trashed or retired code and 404 for a code that never existed, and asks search engines not to index it.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Changing the prefix', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Previous prefixes are remembered: a visit to an old prefix with a known code is sent on to the current prefix, so links and QR codes already printed keep working, and the click counts once. The prefix cannot be left empty, and a previous prefix that has since become the address of a page is refused until the page or the prefix changes.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Redirect type', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( '302 (temporary) is the default and the recommended choice: the browser asks again on every visit, so switching a link off or changing its destination works at once and every visit is counted. 307 behaves the same way. 301 (permanent) is still available, but a browser keeps it for good: switching the link off or changing it no longer reaches earlier visitors, and their clicks are not counted. A 301 saved before this recommendation was moved to 302 once on update.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Destinations', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A short URL may send to https, http, ftp, ftps, sftp, ssh, mailto, tel and sms addresses, never to javascript:, data: or file:. The same list applies to the generator and to the list\'s edit dialog. Two soft warnings appear before saving, and neither blocks it: a scheme other than http or https, which some phones and QR readers do not follow out of a redirect, and an address ending in a file extension that downloads a file (.pdf, .zip, .docx, .xlsx…), read from the address alone.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Exposing short URLs as the site shortlink', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The Post Types setting has two columns per type: "Shorten" (show the meta box and allow short URLs for that type) and "Expose". When a type is exposed, a published post\'s short URL becomes the site\'s canonical shortlink — WordPress emits it as the', 'ffcertificate' ); ?> <code>rel="shortlink"</code> <?php esc_html_e( 'tag in the page head and the HTTP Link header, and it also backs the editor\'s "Get Shortlink" button and REST. Expose is off by default (opt-in), depends on Shorten, and only takes effect once a short URL exists for the page — otherwise WordPress falls back to its native shortlink.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The "Generate missing short URLs" button creates short URLs for already-published posts of the saved shortened types that have none, in any state. It runs in batches while the page stays open; leaving the page stops it, and running it again picks up where it stopped. If the Shorten column was changed and not saved, it asks to save first. This only creates the short URLs — turn on "Expose" to publish them as shortlinks.', 'ffcertificate' ); ?></p>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'Manually-created links can be edited (destination URL + title) from the list. Links created for a post/page are not editable — their destination automatically follows the page: if you change the post slug (or the site permalink structure), the short URL keeps redirecting to the current permalink.', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array( 'title' => __( 'Editing & the destination.', 'ffcertificate' ) )
	);
	?>

	<h4><?php esc_html_e( 'The list & QR codes', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The list shows each link\'s title, short URL, destination, click count and status, with actions to show a QR code, switch it on or off, edit, or trash (trashed links can be restored, as disabled, or permanently deleted). The short URL is a button that copies it. Users who may only view the list see no switch, Edit or Trash. Each link has a QR code (PNG or SVG download) that encodes the short URL itself, so scanning it is also counted.', 'ffcertificate' ); ?> <?php esc_html_e( 'Their design follows Settings → QR Code when it is applied to short URLs.', 'ffcertificate' ); ?> <a href="#config-qr-code"><?php esc_html_e( 'See QR Code settings', 'ffcertificate' ); ?></a>.</p>
	<p><?php esc_html_e( 'QR Codes are also available over REST for external automations:', 'ffcertificate' ); ?> <code>GET /ffc/v1/short-urls/{code}/qr</code> (<?php esc_html_e( 'base64 png/svg, cap-gated by ffc_view_url_shortener via an Application Password).', 'ffcertificate' ); ?> <a href="#developer-hooks-api"><?php esc_html_e( 'See the REST API', 'ffcertificate' ); ?></a>.</p>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'Each visit increments a click count; the plugin does not store per-click timestamps, referrers or IPs. Inactive links show the page above and are not counted, and neither are HEAD requests or link previews (WhatsApp, Slack, social networks…). Redirects are sent with no-cache headers; a click that a page cache answers anyway never reaches the counter.', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array( 'title' => __( 'Clicks are a counter only.', 'ffcertificate' ) )
	);
	?>

	<h4><?php esc_html_e( 'Exporting', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The "Export CSV" button downloads the list — code, title, destination, click count, status, creator and dates — as a CSV file, honouring the current search box and status filter. Rows come newest first by creation, not in the on-screen sort order. The export runs as a timeout-safe background job (the same batched engine every other list export uses), so it is safe on shared hosting with large link tables. Gated by the', 'ffcertificate' ); ?> <code>ffc_export_url_shortener</code> <?php esc_html_e( 'capability.', 'ffcertificate' ); ?></p>

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
			<tr><td><?php esc_html_e( 'Redirect type (302 / 307 / 301)', 'ffcertificate' ); ?></td><td><?php esc_html_e( '302', 'ffcertificate' ); ?> (<?php esc_html_e( 'recommended', 'ffcertificate' ); ?>)</td></tr>
			<tr><td><?php esc_html_e( 'Auto-create on publish', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'on', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Shortened post types', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'posts & pages', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Exposed post types (as shortlink)', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'none', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<ul>
		<li><code>ffc_view_url_shortener</code> — <?php esc_html_e( 'view the list and download QR codes.', 'ffcertificate' ); ?></li>
		<li><code>ffc_manage_url_shortener</code> — <?php esc_html_e( 'create, edit, enable/disable and regenerate links; in the editor box, Reactivate and Regenerate also need permission to edit the post.', 'ffcertificate' ); ?></li>
		<li><code>ffc_delete_url_shortener</code> — <?php esc_html_e( 'trash, restore and permanently delete.', 'ffcertificate' ); ?></li>
		<li><code>ffc_export_url_shortener</code> — <?php esc_html_e( 'download the short-URL list (codes, targets, click counts) as CSV.', 'ffcertificate' ); ?></li>
	</ul>
</div>
