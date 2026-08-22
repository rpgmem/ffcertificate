<?php
/**
 * Self-scheduling confirmation email — default subject + body.
 *
 * The editable default for the booking-confirmation email: the text the hub's
 * "Restore Default Text" button seeds and the send path falls back to when a
 * calendar has no custom confirmation body. Wrapped by the configurable chrome
 * (layout.php) at send.
 *
 * Reproduces the former `appointment-booking-confirmation` echo partial (details
 * box + optional notes + receipt/cancel buttons) as ONE editable string via
 * pre-rendered button/notes tokens (#965): `{{status_message}}`,
 * `{{calendar_title}}`, `{{appointment_date}}`, `{{appointment_time}}`,
 * `{{status_label}}`, `{{user_notes_block}}`, `{{receipt_button}}`,
 * `{{cancel_button}}` (also `{{user_name}}` / `{{user_email}}`). The block tokens
 * are empty when their URL / value is absent, so the button/notes simply
 * disappear. All resolve via AppointmentEmailHandler::render_confirmation_template().
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since   6.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Appointment Confirmation: {{calendar_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; color: #0073aa; font-size: 24px;">📅 ' . __( 'Appointment Booked!', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0; font-size: 16px;">{{status_message}}</p>'
		. '<div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Calendar:', 'ffcertificate' ) . '</strong> {{calendar_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date:', 'ffcertificate' ) . '</strong> {{appointment_date}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{appointment_time}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Status:', 'ffcertificate' ) . '</strong> {{status_label}}</p>'
		. '</div>'
		. '{{user_notes_block}}'
		. '{{receipt_button}}'
		. '{{cancel_button}}',
);
