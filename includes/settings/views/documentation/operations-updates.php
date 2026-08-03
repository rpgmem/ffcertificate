<?php
/**
 * Documentation partial — Operations: Updates.
 *
 * How new plugin versions reach the site: the self-hosted GitHub-Releases
 * updater that teaches WordPress's native update check to see this plugin.
 * Reviewed against GithubUpdater (rpgmem/ffcertificate#820).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Updates Section -->
<div class="card">
	<h3 id="operations-updates"><span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e( 'Updates', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'This plugin is not distributed through the WordPress.org directory, so it ships its own updater. It teaches WordPress\'s native update check to see the plugin\'s GitHub Releases: when a newer release exists, the update appears in Dashboard → Updates and on the Plugins screen exactly like any other plugin update — "update now", the "View details" changelog modal, and the per-plugin "Enable auto-updates" toggle all work as usual.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'How it behaves', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Notify, opt-in.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'It only surfaces the update; it never force-enables background auto-updates. If you want unattended updates, turn on WordPress\'s own per-plugin auto-update toggle.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Verified downloads.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'The update installs the built release ZIP (not the source snapshot) and verifies its SHA-256 checksum before installing. If the checksum does not match, the install is aborted with "Update package integrity check (SHA-256) failed. Installation aborted." — retry the update; a persistent failure means a corrupted or tampered download.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Light on the network.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'The release lookup is cached for 12 hours and uses conditional requests, so the update check does not hammer the source on every admin page load. A new release can therefore take up to ~12 hours to appear, or use "Check again" on the Updates screen to refresh immediately.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'No token or licence key.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'The source repository is public, so updates need no access token, account or licence — the site just needs to be able to reach github.com over HTTPS. On a locked-down network that blocks outbound HTTPS, the update check cannot see new releases and you would update the plugin manually instead.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
