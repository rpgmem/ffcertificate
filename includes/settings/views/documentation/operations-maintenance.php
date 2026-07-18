<?php
/**
 * Documentation partial — Section 21: Maintenance Tools.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 21. Maintenance Tools Section -->
<div class="card">
	<h3 id="operations-maintenance" class="ffc-icon-wrench"><?php esc_html_e( 'Maintenance Tools', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Settings → Data Migrations hosts a set of one-off maintenance tools for tidying up accumulated data. Every tool that changes data runs a preview (dry run) first: it reports exactly what would be affected, and the destructive button only unlocks for 5 minutes after a successful preview.', 'ffcertificate' ); ?></p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Tool', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What it does', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><strong><?php esc_html_e( 'Obsolete Shortcode Cleanup', 'ffcertificate' ); ?></strong></td>
				<td><?php esc_html_e( 'Removes [ffc_form] shortcodes from posts, pages and reusable blocks when they point to a form whose collection period ended more than the grace window (days) ago. WordPress keeps a revision of every edited post for rollback.', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Short URL Cleanup', 'ffcertificate' ); ?></strong></td>
				<td><?php esc_html_e( 'Deletes obsolete short URLs under three independently-selectable criteria: orphaned (the target post no longer exists), never clicked (and older than the grace window), and trashed. The preview breaks the matches down by reason.', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Disable Public Operator Access on old forms', 'ffcertificate' ); ?></strong></td>
				<td><?php esc_html_e( 'Switches off Public Operator Access (and its sub-features) on published forms whose collection period ended more than the grace window ago. The access token and other configuration are preserved, so access can be re-enabled later — only the behaviour is turned off.', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Submission ↔ user link audit', 'ffcertificate' ); ?></strong></td>
				<td><?php esc_html_e( 'Report-only scan (never changes data) for submissions wrongly linked to WordPress users: a link to a deleted user, one user bound to multiple CPF/RF identities, an unlinked submission whose CPF matches a linked one, and a single CPF shared across users. Detection runs off the stored CPF/RF hashes, so no decryption is involved.', 'ffcertificate' ); ?></td>
			</tr>
		</tbody>
	</table>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Safe by design:', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'Always run the preview first. Deletions (short URLs) are permanent; disabling Public Operator Access is reversible (re-enable on the form editor); the link audit only reports — fix each finding manually.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>
