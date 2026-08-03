<?php
/**
 * Documentation partial — Reference: Privacy & LGPD.
 *
 * How the plugin handles personal data: encryption at rest, the WordPress
 * Privacy Tools integration (export + erase), what happens to a user's data
 * when their account is deleted, and the suggested privacy-policy text.
 * Reviewed against PrivacyHandler / PrivacyExporters / PrivacyErasers /
 * UserCleanup and the Encryption stack.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Privacy & LGPD Section -->
<div class="card">
	<h3 id="reference-privacy"><span class="dashicons dashicons-privacy" aria-hidden="true"></span> <?php esc_html_e( 'Privacy & LGPD', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'The plugin is built to help you meet data-protection obligations (the Brazilian LGPD and equivalents). Sensitive data is encrypted at rest, and the plugin plugs into WordPress\'s own Privacy Tools so you can answer access and erasure requests with the standard core screens.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Personal data at rest', 'ffcertificate' ); ?></h4>
		<ul>
			<li><?php esc_html_e( 'Sensitive identifiers — e-mail, CPF and RF — are encrypted (AES-256-CBC with a per-record random IV and an encrypt-then-HMAC integrity check), never stored in the clear.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A separate salted one-way hash of each identifier is kept so records can be located and de-duplicated without exposing the original value.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'On screen, CPF/RF and e-mail are masked unless the operator holds the matching PII capability, and every reveal of recruitment PII is itself audited.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Activity-log entries hash IP addresses and never store raw CPF, so the audit trail cannot leak the identifiers it protects.', 'ffcertificate' ); ?> <a href="#config-advanced"><?php esc_html_e( 'Key health & rotation is on the Advanced page', 'ffcertificate' ); ?></a>.</li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Answering a data request (WordPress Privacy Tools)', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Under Tools → Export Personal Data and Tools → Erase Personal Data, the plugin registers handlers keyed by e-mail address, so a subject request also covers the data this plugin stores:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Export', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'six exporters gather the person\'s FFC data: profile, certificates, appointments, audience groups, audience bookings and FFC user settings.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Erase', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'one eraser clears the encrypted personal data (e-mail, CPF/RF, …) from those records. Authentication codes and verification tokens may be kept in anonymised form so certificates already issued remain verifiable after the person\'s data is erased.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'What happens when a user account is deleted', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Deleting a WordPress user is treated separately from an erasure request. The guiding rule is "retain the record, drop the relationship":', 'ffcertificate' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Records that must survive (submissions, appointments, audit lines, reregistrations) are kept, and the deleted user\'s link on them is anonymised — the account reference and actor-attribution fields are cleared.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Pure relationships (audience memberships, permission grants, the stored user profile) are removed with the account.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A database foreign-key backstop enforces the same anonymise/remove behaviour even if the application hook is bypassed.', 'ffcertificate' ); ?></li>
		</ul>
		<div class="ffc-doc-note">
			<p>
				<strong class="ffc-icon-info"><?php esc_html_e( 'Deleting the account is not the same as erasing the person.', 'ffcertificate' ); ?></strong><br>
				<?php esc_html_e( 'Account deletion anonymises the link so records stay verifiable; it deliberately leaves the encrypted personal data in the retained records untouched. To also clear that encrypted data, run the Erase Personal Data tool above — that is the path that honours an actual erasure request.', 'ffcertificate' ); ?>
			</p>
		</div>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Suggested privacy-policy text', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The plugin contributes ready-to-adapt privacy-policy wording to Settings → Privacy → Policy Guide, describing what personal data it collects, how it is stored, how long it is kept (using your configured activity-log retention), who can access it and the subject\'s rights. Review it, trim the sections for modules you do not use, and copy it into your site\'s own privacy page.', 'ffcertificate' ); ?></p>
	</div>
</div>
