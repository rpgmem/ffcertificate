<?php
/**
 * Recruitment call cancelled — default body.
 *
 * Sent to the candidate when a call they could still act on is cancelled
 * (#2245). Wrapped by the configurable chrome (layout.php) at send; every
 * {{placeholder}} resolves at send time, like the convocation it answers.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.44.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Recruitment call cancelled: {{notice_name}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #b32d2e;">' . __( 'Recruitment call cancelled', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Your call for notice {{notice_code}} — {{notice_name}} in adjutancy {{adjutancy}} was cancelled. Do not attend on the date it gave.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #fcf0f1; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #b32d2e;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date to assume:', 'ffcertificate' ) . '</strong> {{date_to_assume}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{time_to_assume}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Reason:', 'ffcertificate' ) . '</strong> {{cancellation_reason}}</p>'
		. '</div>'
		. '<p style="margin: 0;">' . __( 'You keep your place on the list.', 'ffcertificate' ) . '</p>',
);
