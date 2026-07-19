<?php
/**
 * Documentation partial — Configuration: Advanced.
 *
 * The Settings → Advanced tab: activity log, certificate-editor preferences &
 * mandatory tags, debug toggles, the public-CSV default limit and the Danger
 * Zone. Part of the functional reorganization (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Configuration: Advanced Section -->
<div class="card">
	<h3 id="config-advanced"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span> <?php esc_html_e( 'Advanced', 'ffcertificate' ); ?></h3>

	<h4><?php esc_html_e( 'Activity log', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'An audit trail (useful for LGPD). When it is off, debug logging is also disabled.', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Enable activity log', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'master switch.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Retention (days)', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'auto-delete entries older than this (default 90; 0 keeps them indefinitely).', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Minimum level', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'debug / info / warning / error.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Categories', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'per-area toggles (submissions, scheduling, public access, users, recruitment, migrations, system).', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Certificate editor preferences', 'ffcertificate' ); ?></h4>
	<ul>
		<li><strong><?php esc_html_e( 'Code editor theme', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'Auto / Light / Dark for the certificate HTML editor on the form screen.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Required certificate tags', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'one {{tag}} per line; the form editor refuses to save a certificate layout that is missing any of them. {{auth_code}} is always required (and injected if absent) so every certificate stays verifiable.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Debug toggles', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A set of per-area debug switches, grouped Client (frontend, geofence, QR, browser environment), Server/Processing (form processor, PDF, email, encryption, REST, user manager) and Admin/Operational (admin, self-scheduling, audience, migrations, activity log). All default off — turn one on only while diagnosing, since it increases logging.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Default download limit', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The download quota pre-filled when Public Operator Access is enabled on a new form (each form can override it).', 'ffcertificate' ); ?> <a href="#forms-public-operator-access"><?php esc_html_e( 'See Public Operator Access.', 'ffcertificate' ); ?></a></p>

	<div class="ffc-doc-note ffc-mt-20">
		<p>
			<strong class="ffc-icon-lock"><?php esc_html_e( 'Danger Zone.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Two destructive tools live here: "Delete all plugin data on uninstall" (when on, deleting the plugin drops its tables, options, roles/capabilities and transients — off by default), and a "Delete submissions" action (all, or for one form, with an option to reset the ID counter). Both are irreversible.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
