<?php
/**
 * Documentation partial — Configuration: Cache.
 *
 * The Settings → Cache tab: external page-cache compatibility detection, the
 * page-cache purge, and the plugin's QR-code cache. Part of the functional
 * reorganization (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Configuration: Cache Section -->
<div class="card">
	<h3 id="config-cache" class="ffc-icon-zap"><?php esc_html_e( 'Cache', 'ffcertificate' ); ?></h3>

	<h4><?php esc_html_e( 'Page-cache compatibility', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The tab detects common external caches and reports whether the plugin stays compatible — nothing to configure here. It recognizes LiteSpeed Cache, WP Rocket, W3 Total Cache and WP Super Cache (page caches) and a Redis/persistent object cache.', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Dynamic fragments:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'the captcha/nonce are refreshed by AJAX so a cached page stays valid. The ALTCHA widget fetches its own challenge, so in its modes there is nothing to refresh.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Dashboard exclusion:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'the personal dashboard page is flagged not-to-cache so users never see each other\'s data.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Object cache (Redis):', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'used automatically when a persistent object cache is present.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Purge page caches:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'asks the page-cache plugins, and any integration listening on the ffc_form_cache_purged hook, to drop their copies of every page that shows a published form. Saving a form already does this for that form.', 'ffcertificate' ); ?></li>
	</ul>

	<p><strong><?php esc_html_e( 'Cloudflare:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'when Cloudflare is in front of the site, a Cloudflare Page Cache card reads the edge cache status of the front page. Cloudflare\'s default (static assets only) is safe; with "Cache Everything", APO or a Cache Rule that caches HTML, add a bypass for the form and dashboard URLs or for the wordpress_logged_in cookie.', 'ffcertificate' ); ?></p>

	<p class="description"><?php esc_html_e( 'The form-settings cache, with its Enable, Expiration and daily warming settings, was removed in 6.43.0: WordPress already caches form settings with the post, so the plugin\'s copy saved nothing.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'QR-code cache', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Optionally store generated QR codes in the database (roughly 4 KB per submission) so they are not re-rendered on every PDF. A "Clear all QR-code cache" button flushes them. Statistics show how many codes are cached and their total size.', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'This cache covers certificate QR codes only. Short URLs cache their own QR codes separately, with no setting and no manual clearing.', 'ffcertificate' ); ?></p>
</div>
