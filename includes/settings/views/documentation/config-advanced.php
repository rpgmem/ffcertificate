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

	<h4><?php esc_html_e( 'Encryption Key Health', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'CPF/RF and e-mail are encrypted at rest. By default FFC derives its encryption key and search-hash salt from your WordPress secret keys (SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY). This panel reports whether that secret material is strong, and lets you optionally decouple FFC from the WordPress salts.', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Status', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'ok / weak / placeholder / missing, taken across the secrets that actually feed the key + salt.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Key / salt source', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the WordPress keys, or the FFC constants once decoupled.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Active key fingerprint', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'a non-reversible fingerprint (the key itself is never displayed) so you can confirm which key is live and detect a rotation.', 'ffcertificate' ); ?></li>
	</ul>

	<p>
		<strong><?php esc_html_e( 'Decoupling (optional hardening).', 'ffcertificate' ); ?></strong>
		<?php esc_html_e( 'Define FFC_ENCRYPTION_KEY and FFC_HASH_SALT (32+ chars each) in wp-config.php so a future WordPress salt rotation cannot make your encrypted data unreadable. The panel generates ready-to-paste, self-documented snippets — each value is produced in your browser and is never saved or sent, and the plugin never writes wp-config.php for you.', 'ffcertificate' ); ?>
	</p>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-lock"><?php esc_html_e( 'One-way — plan before you edit.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Defining a constant is effectively one-way: new writes use the new key the moment it is defined. On a site that already stores data, adopt the constants and then run the Encryption Key Rotation (Settings → Migrations) to re-encrypt existing records. Removing or blindly changing a constant on a live site makes existing records unreadable until the exact value is restored — it is never a blind edit.', 'ffcertificate' ); ?>
		</p>
	</div>

	<p><strong><?php esc_html_e( 'Rotating to a new key (re-key).', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'To replace an in-use encryption key without downtime, use the panel\'s "Rotate to a new key" helper:', 'ffcertificate' ); ?></p>
	<ol>
		<li><?php esc_html_e( 'Copy your current FFC_ENCRYPTION_KEY line in wp-config.php and rename the copy to FFC_ENCRYPTION_KEY_PREVIOUS (keep its value).', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'Replace FFC_ENCRYPTION_KEY with the newly generated value.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'Run the Encryption Key Rotation until it reaches 100% — records under the previous key are read via the fallback and re-encrypted under the new key.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'Remove FFC_ENCRYPTION_KEY_PREVIOUS from wp-config.php.', 'ffcertificate' ); ?></li>
	</ol>
	<p class="description"><?php esc_html_e( 'The rotation is only available when both FFC_ENCRYPTION_KEY and FFC_HASH_SALT are defined, and only one previous key is supported at a time — finish one rotation before starting the next. FFC_HASH_SALT needs no "previous": the search hashes are rebuilt from the decrypted values during the rotation.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-note ffc-mt-20">
		<p>
			<strong class="ffc-icon-lock"><?php esc_html_e( 'Danger Zone.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Two destructive tools live here: "Delete all plugin data on uninstall" (when on, deleting the plugin drops its tables, options, roles/capabilities and transients — off by default), and a "Delete submissions" action (all, or for one form, with an option to reset the ID counter). Both are irreversible.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
