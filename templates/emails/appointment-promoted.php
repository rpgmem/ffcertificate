<?php
/**
 * Appointment waitlist-promotion email — default subject + body (editable via the hub, #965).
 *
 * Tokens: {{status_message}} (confirmed vs. pending-approval), {{calendar_title}},
 * {{appointment_date}}, {{appointment_time}}, and the pre-rendered {{receipt_button}}
 * / {{cancel_button}} (each empty when its URL is absent).
 *
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'A Spot Opened Up: {{calendar_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; color: #28a745; font-size: 24px;">🎉 ' . __( 'A Spot Opened Up!', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0; font-size: 16px;">{{status_message}}</p>'
		. '<div style="background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #c3e6cb;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Calendar:', 'ffcertificate' ) . '</strong> {{calendar_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date:', 'ffcertificate' ) . '</strong> {{appointment_date}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{appointment_time}}</p>'
		. '</div>'
		. '{{receipt_button}}'
		. '{{cancel_button}}',
);
