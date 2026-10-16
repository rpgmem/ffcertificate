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
	<h3 id="operations-updates" class="ffc-icon-sync"><?php esc_html_e( 'Updates', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'This plugin is not distributed through the WordPress.org directory, so it ships its own updater. It teaches WordPress\'s native update check to see the plugin\'s GitHub Releases: when a newer release exists, the update appears in Dashboard → Updates and on the Plugins screen exactly like any other plugin update — "update now", the "View details" changelog modal, and the per-plugin "Enable auto-updates" toggle all work as usual.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'How it behaves', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Notify, opt-in.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'It only surfaces the update; it never force-enables background auto-updates. If you want unattended updates, turn on WordPress\'s own per-plugin auto-update toggle.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Verified downloads.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'The update installs the built release ZIP (not the source snapshot) and verifies its SHA-256 checksum before installing. If the checksum does not match, the install is aborted with "Update package integrity check (SHA-256) failed. Installation aborted." — retry the update; a persistent failure means a corrupted or tampered download.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Light on the network.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'The release lookup is cached for 12 hours and uses conditional requests, so the update check does not hammer the source on every admin page load. A new release can therefore take up to ~12 hours to appear, or use "Check again" on the Updates screen to refresh immediately.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Upgrade notice.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Dashboard → Updates shows a short summary of the release on offer, taken from that release\'s own readme, together with the WordPress and PHP versions it requires and the WordPress version it was tested up to, so you can read what changes before applying it.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'View details.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'The "View details" modal shows the plugin\'s banner and the tabs a WordPress.org plugin has: description, installation, FAQ, changelog and screenshots, taken from the offered release\'s readme. The screenshots load from GitHub when the modal opens; ratings and active installs are not shown, because only WordPress.org has them.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'In your language, screenshots in place.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'The tabs and the banner are shown in your language when the plugin ships a translation of them; the changelog stays in English. A screenshot opens larger without leaving the window: the arrows move between them, and the wheel, a pinch or a click zooms in.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'The source repository is public, so updates need no access token, account or licence — the site just needs to reach api.github.com and raw.githubusercontent.com over HTTPS. On a locked-down network that blocks outbound HTTPS, the update check cannot see new releases and you would update the plugin manually instead.', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array( 'title' => __( 'No token or licence key.', 'ffcertificate' ) )
	);
	?>
</div>
