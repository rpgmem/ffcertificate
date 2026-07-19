<?php
/**
 * Documentation partial — Submissions: Downloading the certificate.
 *
 * How a participant retrieves the certificate PDF — the magic download link vs
 * the public validation page — and on-demand regeneration. Part of the
 * functional reorganization (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Submissions: Download Section -->
<div class="card">
	<h3 id="submissions-download"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Downloading the certificate', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Certificates are not stored as files — the PDF is regenerated on demand each time it is requested, always reflecting the current template. There are two ways to reach it, both served from the public /valid page.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Magic download link', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A one-click link that downloads the participant\'s own certificate. The secret token travels in the URL hash fragment (#token=…), so the server never sees it in logs. The token is the only credential — there is no separate login — so the link should be treated as private. It has no captcha, but it is rate-limited by IP and every access is logged.', 'ffcertificate' ); ?></p>
	<pre><code>https://example.com/valid/#token=&lt;magic_token&gt;</code></pre>

	<h4><?php esc_html_e( 'Public validation page', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The /valid page (the [ffc_verification] shortcode) also offers a manual lookup: anyone can type a certificate\'s authentication code to confirm it is genuine and view its status. This path is protected by a nonce, a honeypot + math captcha, and IP rate-limiting.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Magic link vs. validation page.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'The magic link is for the participant (one-click download of their PDF); the public page is for anyone verifying authenticity by code. Both are emitted from the {{validation_url}} token, whose link DSL chooses which destination and link text to render.', 'ffcertificate' ); ?> <a href="#reference-validation-url"><?php esc_html_e( 'See Validation URL', 'ffcertificate' ); ?></a>. <?php esc_html_e( 'The QR code embedded in the PDF points at the same magic link.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
