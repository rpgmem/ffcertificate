<?php
/**
 * Appointment reminder email — default subject + body (editable via the hub, #965).
 *
 * Tokens: {{calendar_title}}, {{appointment_date}}, {{appointment_time}}, and the
 * pre-rendered {{cancel_button}} (empty when cancellation isn't allowed).
 *
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Reminder: Appointment Tomorrow - {{calendar_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; color: #ff9800; font-size: 24px;">⏰ ' . __( 'Appointment Reminder', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0; font-size: 16px;">' . __( 'This is a reminder about your upcoming appointment.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #ffeaa7;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Calendar:', 'ffcertificate' ) . '</strong> {{calendar_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date:', 'ffcertificate' ) . '</strong> {{appointment_date}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{appointment_time}}</p>'
		. '</div>'
		. '{{cancel_button}}',
);
