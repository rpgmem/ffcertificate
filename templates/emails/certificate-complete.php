<?php
/**
 * Complete your certificate — default subject + body (#2891).
 *
 * Sent when an event terminal recorded the participant's attendance but the
 * profile could not fill every required field of the certificate form. The
 * link is personal and opens the form for seven days after the reading,
 * whatever the form's own hours. Wrapped by the configurable chrome
 * (layout.php) at send. Tokens are resolved by CertificateCompletionLink:
 * {{user_name}}, {{form_title}}, {{form_link}}, {{missing}}, {{closes_at}}
 * and {{site_name}}.
 *
 * @package FreeFormCertificate\Frontend
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Complete your certificate: {{form_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;">' . __( 'Your attendance is recorded', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'The terminal recorded your attendance for "{{form_title}}". To issue your certificate, fill in what your profile does not have: {{missing}}.', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 25px 0; text-align: center;"><a href="{{form_link}}" style="display: inline-block; padding: 12px 24px; background: #2271b1; color: #ffffff; text-decoration: none; border-radius: 6px;">' . __( 'Complete my certificate', 'ffcertificate' ) . '</a></p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'The link is open until {{closes_at}}. It is personal: it fills in your name and e-mail, so do not forward it. Once issued, the certificate is in the app, under Certificates.', 'ffcertificate' ) . '</p>',
);
