<?php
/**
 * Documentation partial — Configuration: Cache.
 *
 * The Settings → Cache tab: external page-cache compatibility detection plus
 * the plugin's own form-settings and QR-code caches. Part of the functional
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
	<h3 id="config-cache"><span class="dashicons dashicons-performance" aria-hidden="true"></span> <?php esc_html_e( 'Cache', 'ffcertificate' ); ?></h3>

	<h4><?php esc_html_e( 'Page-cache compatibility (detection only)', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The tab detects common external caches and reports whether the plugin stays compatible — nothing to configure here. It recognizes LiteSpeed Cache, WP Rocket, W3 Total Cache and WP Super Cache (page caches) and a Redis/persistent object cache.', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Dynamic fragments:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'the captcha/nonce are refreshed by AJAX so a cached page stays valid.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Dashboard exclusion:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'the personal dashboard page is flagged not-to-cache so users never see each other\'s data.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Object cache (Redis):', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'used automatically when a persistent object cache is present.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Form cache', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Caches each form\'s resolved settings to speed up rendering.', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Enable cache', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'turn form-settings caching on or off.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Expiration', 'ffcertificate' ); ?></strong> — <?php esc_html_e( '15 minutes, 30 minutes, 1 hour (default) or 1 day.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Automatic warming', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'a daily job pre-loads every published form into the cache.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Warm / Clear now', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'buttons to pre-load or flush the form cache immediately.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'QR-code cache', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Optionally store generated QR codes in the database (roughly 4 KB per submission) so they are not re-rendered on every PDF. A "Clear all QR-code cache" button flushes them. Statistics show how many codes are cached and their total size.', 'ffcertificate' ); ?></p>
</div>
