<?php
/**
 * Documentation partial — Configuration: IP Diagnostics.
 *
 * Settings → IP Diagnostics: how the plugin resolves the client IP (legacy or
 * secure trusted-proxy strategy), the live diagnosis of the current request and
 * the Cloudflare range list. Documented against TabIpDiagnostics,
 * IpDiagnosticsSettingsReader and Core\ClientIpResolver (#899).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Configuration: IP Diagnostics Section -->
<div class="card">
	<h3 id="config-ip-diagnostics"><span class="dashicons dashicons-networking" aria-hidden="true"></span> <?php esc_html_e( 'IP Diagnostics', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'Settings → IP Diagnostics decides how the plugin works out a visitor\'s IP address. That one choice governs every IP read in the plugin: the activity log, IP geolocation, rate limiting, the geofence and the public listing throttle.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Resolution strategy', 'ffcertificate' ); ?></h4>
	<ul>
		<li><strong><?php esc_html_e( 'Legacy', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the effective default. It trusts client-supplied headers such as X-Forwarded-For, so a forged header can spoof the address.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Secure (recommended)', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'a trusted-proxy model: a forwarded header is honoured only when the request really comes from Cloudflare or a proxy you trust, so the real client is found and cannot be forged.', 'ffcertificate' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'With the secure strategy, the trusted-proxy mode says whom to believe: Auto (detect Cloudflare or a known proxy, else use the direct connection — recommended), Cloudflare, Custom (your own proxy CIDRs, one per line — never Cloudflare\'s, which are kept up to date automatically) or Direct (ignore every forwarded header).', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'What the tab shows', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Current request diagnosis', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'how your own request would be resolved, the headers received, and what each strategy would do with a forged X-Forwarded-For. IPs are masked unless you are a full administrator.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Cloudflare ranges', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the official Cloudflare IP lists, refreshed daily (a bundled list is used until the first refresh), with a button to refresh them now.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Shadow-divergence logging', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'off by default. When on, it logs (with a hashed IP) every request where the two strategies would disagree, so you can measure the impact before switching.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Why Legacy is still the default:', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'switching changes which address rate limits and the geofence see, which can matter on shared hosting. The tab recommends Secure, and putting Cloudflare (free plan) in front of the site for an address that cannot be forged.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
