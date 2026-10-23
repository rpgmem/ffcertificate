<?php
/**
 * Audience post-event form invitation — default subject + body (#1948).
 *
 * Sent to each participant of a booking when its last day ends, with a link
 * to the calendar's (or the booking's) certificate form. Wrapped by the
 * configurable chrome (layout.php) at send. Tokens are resolved by
 * AudienceFormInviteMailer: {{user_name}}, {{event}}, {{schedule_name}},
 * {{form_title}}, {{form_link}}, {{closes_at}} and {{site_name}}.
 *
 * The link is personal: it fills in the participant's name and e-mail and
 * works without logging in, which the body says.
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Your certificate for {{event}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;">' . __( 'Your certificate is waiting', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Thank you for taking part in {{event}}. Fill in the form "{{form_title}}" to receive your certificate.', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 25px 0; text-align: center;"><a href="{{form_link}}" style="display: inline-block; padding: 12px 24px; background: #2271b1; color: #ffffff; text-decoration: none; border-radius: 6px;">' . __( 'Open the form', 'ffcertificate' ) . '</a></p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'The link is open until {{closes_at}}. It is personal: it fills in your name and e-mail, so do not forward it.', 'ffcertificate' ) . '</p>',
);
