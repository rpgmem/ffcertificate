<?php
/**
 * Appointment cancellation email — default subject + body (editable via the hub, #965).
 *
 * Tokens: {{calendar_title}}, {{appointment_date}}, {{appointment_time}}, and the
 * pre-rendered {{cancellation_reason_block}} (empty when no reason was given).
 *
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Appointment Cancelled: {{calendar_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; color: #dc3545; font-size: 24px;">❌ ' . __( 'Appointment Cancelled', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0; font-size: 16px;">' . __( 'Your appointment has been cancelled.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #f5c6cb;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Calendar:', 'ffcertificate' ) . '</strong> {{calendar_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date:', 'ffcertificate' ) . '</strong> {{appointment_date}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{appointment_time}}</p>'
		. '</div>'
		. '{{cancellation_reason_block}}',
);
