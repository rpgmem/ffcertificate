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
	<h3 id="config-user-access" class="ffc-icon-key"><?php esc_html_e( 'User Access', 'ffcertificate' ); ?></h3>
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

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Operator roles in wp-admin', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Independently of the settings above, an account whose roles are all FFC operator roles (a module viewer, operator or manager, or FFC Read-Only) sees a scoped wp-admin: the core menus it has no use for (Posts, Comments, Tools, Plugins and the like) are hidden, the admin bar is pruned, and opening an admin page outside its module redirects to the module\'s own screen. Its profile, the dashboard home and the AJAX endpoints stay reachable.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'An account that also holds a non-FFC role — an Editor who was given an FFC role, for example — keeps the whole of wp-admin, and administrators (manage_options) are never scoped. This is a convenience, not the security boundary: every FFC screen and action still checks its capability.', 'ffcertificate' ); ?></p>
	</div>
</div>
