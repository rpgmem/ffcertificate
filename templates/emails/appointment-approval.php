<?php
/**
 * Appointment approval email — default subject + body (editable via the hub, #965).
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * Tokens (resolved by AppointmentEmailHandler::render_confirmation_template()):
 * {{user_name}}, {{calendar_title}}, {{appointment_date}}, {{appointment_time}},
 * and the pre-rendered {{receipt_button}} (empty when no receipt URL).
 *
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Appointment Approved: {{calendar_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #1a7f4b;">' . __( 'Appointment confirmed', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Your appointment has been approved and confirmed.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #e6f4ec; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #1a7f4b;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Calendar:', 'ffcertificate' ) . '</strong> {{calendar_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date:', 'ffcertificate' ) . '</strong> {{appointment_date}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{appointment_time}}</p>'
		. '</div>'
		. '{{receipt_button}}',
);
