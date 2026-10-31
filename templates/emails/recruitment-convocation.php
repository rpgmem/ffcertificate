<?php
/**
 * Recruitment convocation email — default body.
 *
 * The editable default for the recruitment convocation email (Recruitment →
 * Settings). Wrapped by the configurable chrome (layout.php) at send.
 * All {{placeholder}} markers resolve via Core\TokenResolver at send time.
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * Since #2245 the body also says how to answer, with the optional deadline
 * in `{{respond_by_line}}`, links to "My calls" and offers the password-set
 * link a candidate whose account the import created needs to sign in for
 * the first time. The call's `.ics` travels as an attachment.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Recruitment Call: {{notice_name}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;">' . __( 'Recruitment call', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'You have been called for notice {{notice_code}} — {{notice_name}} in adjutancy {{adjutancy}}.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #eaf2fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2271b1;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Rank:', 'ffcertificate' ) . '</strong> {{rank}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Score:', 'ffcertificate' ) . '</strong> {{score}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date to assume:', 'ffcertificate' ) . '</strong> {{date_to_assume}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{time_to_assume}}</p>'
		. '</div>'
		. '<p style="margin: 0 0 15px 0;">{{notes}}</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Please confirm whether you will attend by replying to this email.', 'ffcertificate' ) . ' {{respond_by_line}}</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'The calendar file attached adds the presentation to your calendar.', 'ffcertificate' ) . '</p>'
		. '<p style="text-align:center;margin:24px 0;">'
		. '<a href="{{my_calls_url}}" style="display:inline-block;padding:12px 28px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'
		. __( 'Open My Calls', 'ffcertificate' )
		. '</a></p>'
		. '<p style="margin:0;font-size:13px;color:#50575e;text-align:center;">'
		. __( 'First time here, or forgot your password?', 'ffcertificate' )
		. ' <a href="{{set_password_url}}" style="color:#2271b1;">' . __( 'Define your password', 'ffcertificate' ) . '</a>.'
		. '</p>',
);
