<?php
/**
 * Documentation partial — Section 16: URL Shortener & QR Codes.
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
<!-- 16. URL Shortener & QR Codes Section -->
<div class="card">
	<h3 id="feature-url-shortener" class="ffc-icon-link"><?php esc_html_e( 'URL Shortener & QR Codes', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Create short URLs for any page and track clicks. Each short URL has a unique QR code that can be downloaded as PNG or SVG.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'How It Works:', 'ffcertificate' ); ?></h4>
		<ol>
			<li><?php esc_html_e( 'Short URLs are created automatically when a post/page is published (if auto-create is enabled)', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'You can also create short URLs manually from the Short URLs admin page', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'When someone visits a short URL, they are redirected to the destination and the click is counted', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'QR codes always point to the short URL so that scans are tracked', 'ffcertificate' ); ?></li>
		</ol>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Admin Page:', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Access via FFC Forms > Short URLs. From there you can:', 'ffcertificate' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Create new short URLs with a title and destination URL', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'View click statistics (total links, active links, total clicks)', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Enable/disable individual short URLs', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Download QR codes in PNG or SVG format', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Trash, restore, or permanently delete short URLs', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Post/Page Meta Box:', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'When editing a post or page, the "Short URL & QR Code" meta box appears in the sidebar. It shows:', 'ffcertificate' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'The short URL with a copy button', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Click count for this short URL', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'QR code preview with PNG/SVG download buttons', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Regenerate button to create a new short code (the old one stops working)', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Settings:', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Setting', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Default', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Enable URL Shortener', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'Turn the module on or off', 'ffcertificate' ); ?></td>
					<td><?php esc_html_e( 'Enabled', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'URL Prefix', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'The path segment before the short code (e.g. "go" makes URLs like /go/abc123)', 'ffcertificate' ); ?></td>
					<td><code>go</code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Code Length', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'Number of characters in the generated short code (4–10)', 'ffcertificate' ); ?></td>
					<td><code>6</code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Redirect Type', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'HTTP status code for the redirect: 301 (permanent), 302 (temporary), or 307 (temporary, preserves method)', 'ffcertificate' ); ?></td>
					<td><code>302</code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Auto-Create on Publish', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'Automatically generate a short URL when a post or page is published', 'ffcertificate' ); ?></td>
					<td><?php esc_html_e( 'Enabled', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Post Types', 'ffcertificate' ); ?></strong></td>
					<td><?php esc_html_e( 'Which post types show the Short URL meta box and support auto-create', 'ffcertificate' ); ?></td>
					<td><code>post, page</code></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Short URL Statuses:', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong>active</strong> &mdash; <?php esc_html_e( 'Redirects to the destination and counts clicks', 'ffcertificate' ); ?></li>
			<li><strong>disabled</strong> &mdash; <?php esc_html_e( 'Redirect is temporarily paused; visitors are sent to the homepage', 'ffcertificate' ); ?></li>
			<li><strong>trashed</strong> &mdash; <?php esc_html_e( 'Moved to trash; can be restored or permanently deleted', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-alert ffc-alert-info ffc-mt-20">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Tip:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Configure URL Shortener settings in the "URL Shortener" tab. If you change the prefix, rewrite rules are flushed automatically.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
