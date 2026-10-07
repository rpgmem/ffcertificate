<?php
/**
 * Documentation partial — Configuration: User Access.
 *
 * Settings → User Access: keeping chosen roles out of wp-admin, where they are
 * redirected, and the admin bar. Documented against ffc-tab-user-access.php and
 * UserDashboard\AccessControl.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Configuration: User Access Section -->
<div class="card">
	<h3 id="config-user-access"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> <?php esc_html_e( 'User Access', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'Settings → User Access keeps people who only need their own certificates, appointments and profile out of the WordPress admin, sending them to the front-end dashboard instead.', 'ffcertificate' ); ?> <a href="#feature-user-dashboard"><?php esc_html_e( 'See User Dashboard & Access.', 'ffcertificate' ); ?></a></p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Setting', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What it does', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><strong><?php esc_html_e( 'Block WP-Admin Access', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Off by default. When on, users of the blocked roles who open wp-admin are redirected. Front-end AJAX requests are never blocked, so forms and the dashboard keep working.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Blocked Roles', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The roles to keep out; the FFC End User role is selected by default. A user is blocked only when every role they hold is in the list, so someone who is also an editor keeps wp-admin access.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Bypass for Administrators', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'On by default: an administrator is never redirected, even when holding a blocked role.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Redirect URL', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Where blocked users land — by default the site\'s /dashboard page.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Redirect Message', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The notice shown on the dashboard page after a redirect.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Show Admin Bar', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Off by default, which hides the WordPress admin bar on the front end for users whose roles are all blocked.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'The "FFC End User" role is assigned automatically to people who submit forms with a CPF or RF.', 'ffcertificate' ); ?></p>
</div>
