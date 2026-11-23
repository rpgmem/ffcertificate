<?php
/**
 * Presence device replaced — default subject + body (#2741).
 *
 * Sent to a user when a new device is activated for presence check-in, which
 * revokes the one they had. Wrapped by the configurable chrome (layout.php)
 * at send. Tokens are resolved by AudiencePresenceEnrolment: {{user_name}},
 * {{activated_at}} and {{site_name}}.
 *
 * It names no device and no key: an e-mail is the place to say that
 * something changed, and the activity log is where the detail lives.
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'A new device was activated for your presence code', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;">' . __( 'New presence device', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'A new device was activated for your presence code on {{activated_at}}. The device you used before no longer shows a valid code.', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'If this was not you, contact the administrator of {{site_name}}.', 'ffcertificate' ) . '</p>',
);
