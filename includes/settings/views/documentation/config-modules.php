<?php
/**
 * Documentation partial — Configuration: Modules.
 *
 * Settings → Modules: the per-module enable/disable switches that gate each
 * feature module's bootstrap (and its crons). Documented against
 * TabModulos / ffc-tab-modulos.php (rpgmem/ffcertificate#800).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Modules Section -->
<div class="card">
	<h3 id="config-modules"><span class="dashicons dashicons-screenoptions" aria-hidden="true"></span> <?php esc_html_e( 'Modules', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Settings → Modules lets you turn individual feature modules on or off. Every module ships enabled; disabling one hides its admin screens and stops its runtime (including its scheduled/cron work), but never deletes its data — re-enable it at any time and everything is back. Each switch saves instantly and takes effect on the next page load.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'The modules you can toggle', 'ffcertificate' ); ?></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Module', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What turning it off does', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><?php esc_html_e( 'Certificates', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Hides the certificate admin and stops public form submissions. While off, the daily expired-ticket cleanup is paused (unredeemed ticket codes of ended forms are not purged until it is re-enabled).', 'ffcertificate' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Audiences / Scheduling', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Removes audience groups and the scheduling admin.', 'ffcertificate' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Self-Scheduling', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Removes appointment self-booking and its admin screens.', 'ffcertificate' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Reregistration', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Removes the reregistration campaign flow and its admin.', 'ffcertificate' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'URL Shortener', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Disables the built-in short URLs, redirects and QR codes.', 'ffcertificate' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Recruitment', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Removes recruitment calls, candidates and the public queue.', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Disable hides, it does not delete.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'A disabled module keeps every row it already stored; only its screens and runtime stop. Public URLs that a disabled module used to answer (a short link, a public recruitment queue) stop responding until you turn it back on.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
