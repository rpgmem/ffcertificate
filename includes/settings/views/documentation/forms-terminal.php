<?php
/**
 * Documentation partial — Forms: Terminal.
 *
 * The form editor's Terminal tab (#2892): an event terminal issuing the
 * certificate from the participant's app QR, its window, who may receive,
 * the restrictions that apply there and what happens to a missing field.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Forms: Terminal Section -->
<div class="card">
	<h3 id="forms-terminal" class="ffc-icon-qr"><?php esc_html_e( 'Certificate at the terminal', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'The form editor\'s Terminal tab lets an event terminal read the QR in the participant\'s app and issue the certificate at once, from the profile. The form still accepts entries on the site at the same time.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'When it can be turned on', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The terminal reads while the form is open: the date and hours are the Time tab\'s, with no window of their own, and the opening and closing must fall on the same day. A form with a quiz cannot take the terminal, because the certificate needs the answers. The switch refuses to turn on and says why; a form that stops qualifying when it is saved has the terminal turned off, with a notice.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The switch is on by default: a form that never set it takes the terminal whenever it qualifies. Turn it off to keep a form out of every terminal. Pairing a terminal lists these forms beside the events, and only what is open today or later, within the next 7 days, ever shows.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Who may receive at the terminal', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Any account, or only the people on the Security tab\'s allow list. The deny list, the submission limits and the ticket apply to a reading; the password and the GPS area do not, because standing at the terminal proves both. With the ticket on, the terminal asks for the code before the QR and keeps it for 60 seconds, each code once, and pauses reading while it is offline. The result names what happened: certificate issued, already issued, attendance recorded with data still missing (in amber, the terminal free for the next person), or refused with the reason.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'A field the profile cannot fill', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The tab names each required field with no profile source. Whoever lacks it has the attendance recorded and receives "Complete your certificate". The side box shows the reading hours, the paired terminals and how many certificates the terminal issued.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The readings screen', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'View readings, in the Terminal tab\'s side box, opens what the terminals read for this form: how many readings, certificates issued, readings awaiting profile data and refusals; a warning for each offline terminal that still holds readings; and the list, searchable by name, e-mail or full RF and filtered by result, with the reading time, the terminal, the reason for a refusal and the certificate\'s validation code.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Export CSV writes the same list, with the search and the result filter on screen, for any number of readings. The RF comes masked unless the exporter holds ffc_view_certificates_pii; a file with it in clear is recorded in the activity log.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'A person read twice shows once, with the result of the first valid reading. The RF is masked; a holder of ffc_view_certificates_pii reveals it, and every reveal is recorded in the activity log. The screen needs ffc_checkin_certificates, held by Certificates - Submissions Operator and above, Certificates - Forms Manager and FFC Administrator.', 'ffcertificate' ); ?></p>
</div>
