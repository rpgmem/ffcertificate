<?php
/**
 * Documentation partial — Configuration: Advanced.
 *
 * The Settings → Advanced tab: activity log, the public-CSV sync limit,
 * mandatory certificate tags, debug toggles, the public-CSV default limit and the Danger
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
	<h3 id="config-advanced" class="ffc-icon-sliders"><?php esc_html_e( 'Advanced', 'ffcertificate' ); ?></h3>

	<h4><?php esc_html_e( 'Activity log', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'An audit trail (useful for LGPD). When it is off, debug logging is also disabled.', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Enable activity log', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'master switch, on by default on fresh installs and after the update.', 'ffcertificate' ); ?> <?php esc_html_e( 'Switching it or an audit item off, and changing the retention or the record limit, needs the Danger Zone capability, so the audit trail cannot be switched off or shortened without it. Each such change is itself recorded, before it takes effect.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Retention (days)', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'auto-delete entries older than this (default 90, at most 3,650; 0 keeps them indefinitely).', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Max records', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the daily cleanup also deletes the oldest entries beyond this number (default 100,000, about 60 MB; from 1,000 to 1,000,000).', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Minimum level', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'debug / info / warning / error. It filters the secondary items only; the audit items are recorded whatever the level.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Audit items', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'on by default: access to personal data (reveals, unmasked exports, identity tools), the Danger Zone and maintenance (deletions, cleanups, migrations and key rotation, settings that delete or suppress data) and permissions (role and user changes).', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Secondary items', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'off by default, one toggle each: users, certificates, personal and audience calendars, reregistration and recruitment, public access and security, and system errors.', 'ffcertificate' ); ?> <?php esc_html_e( 'While the log is off the items and the minimum level are greyed out, because they have no effect then.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'After the update', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the log is switched on, and each former category passes its state to the items it split into: a category that was on, with the log on, leaves its items on.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Public CSV Sync Export Limit', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The most rows the synchronous (no-JavaScript) public CSV download may return (default 2000). Larger exports must use the JavaScript batched flow, which runs in steps and survives shared-hosting timeouts.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Certificate editor', 'ffcertificate' ); ?></h4>
	<p class="description"><?php esc_html_e( 'The colour theme of the certificate HTML editor is on the General tab, next to Dark Mode.', 'ffcertificate' ); ?> <a href="#config-general"><?php esc_html_e( 'See General.', 'ffcertificate' ); ?></a></p>
	<p class="description"><?php esc_html_e( 'The tags and fields a certificate requires are set in Settings → Certificates → Required data.', 'ffcertificate' ); ?> <a href="#feature-certificates"><?php esc_html_e( 'See Certificates & Forms.', 'ffcertificate' ); ?></a></p>

	<h4><?php esc_html_e( 'Debug toggles', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A set of per-area debug switches, grouped Client (frontend, geofence, QR, browser environment), Server/Processing (form processor, PDF, email, encryption, REST, user manager) and Admin/Operational (admin, personal calendars, audience calendars, migrations, activity log). All default off — turn one on only while diagnosing, since it increases logging.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Default download limit', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The download quota pre-filled when Public Operator Access is enabled on a new form (each form can override it).', 'ffcertificate' ); ?> <a href="#forms-public-operator-access"><?php esc_html_e( 'See Public Operator Access.', 'ffcertificate' ); ?></a></p>

	<h4><?php esc_html_e( 'Encryption Key Health', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'CPF/RF and e-mail are encrypted at rest. By default FFC derives its encryption key and search-hash salt from your WordPress secret keys (SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY). This panel reports whether that secret material is strong, and lets you optionally decouple FFC from the WordPress salts.', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Status', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'ok / weak / placeholder / missing, taken across the secrets that actually feed the key + salt.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Key / salt source', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the WordPress keys, or the FFC constants once decoupled. An FFC_HASH_SALT defined as empty is used as it is, so it reads as a missing secret.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Active key fingerprint', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'a non-reversible fingerprint (the key itself is never displayed) so you can confirm which key is live and detect a rotation.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Unauthenticated (legacy) reads', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'how many requests read a value stored in the oldest format, which carries no integrity check, and when the last did. Once nothing has been counted for a long while, that format can be refused.', 'ffcertificate' ); ?></li>
	</ul>

	<p>
		<strong><?php esc_html_e( 'Decoupling (optional hardening).', 'ffcertificate' ); ?></strong>
		<?php esc_html_e( 'Define FFC_ENCRYPTION_KEY and FFC_HASH_SALT (32+ chars each) in wp-config.php so a future WordPress salt rotation cannot make your encrypted data unreadable. The panel generates ready-to-paste, self-documented snippets — each value is produced in your browser and is never saved or sent, and the plugin never writes wp-config.php for you.', 'ffcertificate' ); ?>
	</p>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'Defining a constant is effectively one-way: new writes use the new key the moment it is defined. On a site that already stores data, adopt the constants and then run the Encryption Key Rotation (Settings → Data Migrations) to re-encrypt existing records. Removing or blindly changing a constant on a live site makes existing records unreadable until the exact value is restored — it is never a blind edit.', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array(
			'icon'  => 'lock',
			'title' => __( 'One-way — plan before you edit.', 'ffcertificate' ),
		)
	);
	?>

	<p><strong><?php esc_html_e( 'Rotating to a new key (re-key).', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'To replace an in-use encryption key without downtime, use the panel\'s "Rotate to a new key" helper:', 'ffcertificate' ); ?></p>
	<ol>
		<li><?php esc_html_e( 'Copy your current FFC_ENCRYPTION_KEY line in wp-config.php and rename the copy to FFC_ENCRYPTION_KEY_PREVIOUS (keep its value).', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'Replace FFC_ENCRYPTION_KEY with the newly generated value.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'Run the Encryption Key Rotation until it reaches 100% — records under the previous key are read via the fallback and re-encrypted under the new key.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'Remove FFC_ENCRYPTION_KEY_PREVIOUS from wp-config.php.', 'ffcertificate' ); ?></li>
	</ol>
	<p class="description"><?php esc_html_e( 'The rotation is only available when both FFC_ENCRYPTION_KEY and FFC_HASH_SALT are defined, and only one previous key is supported at a time — finish one rotation before starting the next. FFC_HASH_SALT needs no "previous": the search hashes are rebuilt from the decrypted values during the rotation.', 'ffcertificate' ); ?></p>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'Two destructive tools live here, both irreversible and both reserved to accounts holding ffc_manage_settings_dangerzone. "Delete all plugin data on uninstall" is off by default; when on, deleting the plugin drops its tables, options, transients, forms, calendars, certificate templates, roles and capabilities, the export temp folder, and the verification and dashboard pages it created, unless someone edited them. An account whose only role was the plugin\'s receives the site\'s default role. The "Delete submissions" action deletes all submissions, or those of one form, with an option to reset the ID counter. It starts with no target chosen and shows how many submissions each target holds; the confirmation names the target and its count, asks for a typed word and counts down 15 seconds, and the deletion is recorded in the Activity Log.', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array(
			'icon'    => 'lock',
			'title'   => __( 'Danger Zone.', 'ffcertificate' ),
			'classes' => array( 'ffc-mt-2xl' ),
		)
	);
	?>
</div>
