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
	<h3 id="reference-privacy" class="ffc-icon-shield"><?php esc_html_e( 'Privacy & LGPD', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'The plugin is built to help you meet data-protection obligations (the Brazilian LGPD and equivalents). Sensitive data is encrypted at rest, and the plugin plugs into WordPress\'s own Privacy Tools so you can answer access and erasure requests with the standard core screens.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Personal data at rest', 'ffcertificate' ); ?></h4>
		<ul>
			<li><?php esc_html_e( 'Sensitive identifiers — e-mail, CPF and RF — are encrypted (AES-256-CBC with a per-record random IV and an encrypt-then-HMAC integrity check), never stored in the clear. Certificate submissions also encrypt the client IP and the extra form data.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A separate salted one-way hash of each identifier is kept so records can be located and de-duplicated without exposing the original value.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'On screen, CPF/RF, e-mail, phone and the other sensitive fields start masked for every member of staff, WordPress administrators and FFC Administrator included: nobody sees another person\'s data without revealing it. Each module reveals through its own capability (ffc_view_<module>_pii), held by the module\'s Manager and Administrator roles and by FFC Administrator; FFC Read-only never reveals. Every reveal on submissions, appointments, reregistration, the audience screens, the user-edit screen and Date Messages writes an activity-log entry. Revealing recruitment PII is logged too (repeat reveals of the same field within a minute are not logged again, and a recruitment setting can switch this audit off).', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Each kind of data has one masked shape, the same on every screen, export and e-mail: CPF 123.***.***-45, RF 123.***-4, CIN/RG 12.***.***-*, e-mail m***@domain, phone (**) *****-**21, birth date with the year hidden in the site\'s date format (15/03/****), IP 192.168.***.***; any other sensitive value keeps only its first character. A value that does not fit its shape is masked whole rather than shown. A custom field masked as a CPF, RF, CIN or phone takes that shape whatever its key, and the public verification page masks a phone, birth date or IP field it shows.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A list offers "Reveal this page" above its masked values: it reveals every value listed at once and writes one activity-log entry naming the records.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'The person the data belongs to sees their own data masked in their dashboard too, and reveals it with the same button, without an activity-log entry. An administrator using "View as user" sees it masked, and each reveal is audited. Documents the person asks for themselves (a certificate PDF, a receipt, an e-mail) carry their data in clear.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'The activity log and identity resolution belong to FFC Administrator and WordPress administrators alone. Those screens show the data they need unmasked, because the screens themselves are restricted.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A CSV export carries personal data in clear only for a holder of the module\'s PII capability, and each such export writes a "Personal Data Exported" activity-log entry; anyone else allowed to export receives the same columns masked.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'E-mails to staff (new submission and new appointment notices, the Date Messages summary) carry personal data masked, with a link to the screen where the recipient can reveal it.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'The REST API returns personal data masked too. A caller allowed to reveal adds reveal=1 to a submission or appointment request to receive it in clear, and that read is audited.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Activity-log entries store the client IP address encrypted, and an entry whose details carry a sensitive field (such as CPF or an IP address) has those details encrypted too; the log is still personal data. Entries written before 6.34.0 kept the address in clear until the "Activity Log: Encrypt Client IPs" migration runs. The debug logs hash IP addresses instead.', 'ffcertificate' ); ?> <a href="#config-advanced"><?php esc_html_e( 'Key health & rotation is on the Advanced page', 'ffcertificate' ); ?></a>.</li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Answering a data request (WordPress Privacy Tools)', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Under Tools → Export Personal Data and Tools → Erase Personal Data, the plugin registers its own handlers. Each one works on the records linked to the WordPress account that has the requested e-mail address and, for records linked to no account, on those whose stored e-mail matches it — so a certificate or appointment made without logging in is found too:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Export', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'exporters gather the person\'s FFC data: profile, certificates, appointments, audience groups, audience bookings, audience attendance, post-event form invitations, reregistration submissions (with their answers decrypted), recruitment candidacies and their classifications, and FFC user settings.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Erase', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'an eraser clears the encrypted personal data (e-mail and its lookup hash, CPF/RF, the client IP, …) from the certificates and appointments and unlinks them from the account, deletes the person\'s audience memberships, booking links, post-event form invitations, schedule permissions, stored profile and FFC user settings, and unlinks their attendance lines and activity-log entries. Authentication codes and verification tokens may be kept in anonymised form so certificates already issued remain verifiable after the person\'s data is erased.', 'ffcertificate' ); ?></li>
		</ul>
		<?php ob_start(); ?>
			<p>
				<?php esc_html_e( 'Reregistration submissions and recruitment candidacies are exported but not erased: they are institutional records an organisation is usually required to keep, so the eraser reports them as retained, with a message, and changes nothing. Remove them from the campaign or from Recruitment when the obligation no longer applies. A record linked to a different account is that account\'s, even when it carries the same e-mail address.', 'ffcertificate' ); ?>
			</p>
		<?php
		\FreeFormCertificate\Core\AdminNotice::callout(
			'info',
			(string) ob_get_clean(),
			array( 'title' => __( 'What the eraser keeps.', 'ffcertificate' ) )
		);
		?>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'What happens when a user account is deleted', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Deleting a WordPress user is treated separately from an erasure request. The guiding rule is "retain the record, drop the relationship":', 'ffcertificate' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Records that must survive (submissions, appointments, audit lines, reregistrations) are kept, and the deleted user\'s link on them is anonymised — the account reference and actor-attribution fields are cleared.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Pure relationships (audience memberships, permission grants, the stored user profile) are removed with the account.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A database foreign-key backstop enforces the same anonymise/remove behaviour even if the application hook is bypassed.', 'ffcertificate' ); ?></li>
		</ul>
		<?php ob_start(); ?>
			<p>
				<?php esc_html_e( 'Account deletion anonymises the link so records stay verifiable; it deliberately leaves the encrypted personal data in the retained records untouched. To also clear that encrypted data, run the Erase Personal Data tool above — that is the path that honours an actual erasure request.', 'ffcertificate' ); ?>
			</p>
		<?php
		\FreeFormCertificate\Core\AdminNotice::callout(
			'info',
			(string) ob_get_clean(),
			array( 'title' => __( 'Deleting the account is not the same as erasing the person.', 'ffcertificate' ) )
		);
		?>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Suggested privacy-policy text', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The plugin contributes ready-to-adapt privacy-policy wording to Settings → Privacy → Policy Guide, describing what personal data it collects, how it is stored, how long it is kept (using your configured activity-log retention), who can access it and the subject\'s rights. Review it, trim the sections for modules you do not use, and copy it into your site\'s own privacy page.', 'ffcertificate' ); ?></p>
	</div>
</div>
