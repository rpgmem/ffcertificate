<?php
/**
 * Audience booking cancelled-by-an-administrator default email body (#1859).
 *
 * Sent to the owner of a booking that gave way when a holder of the audience
 * bypass or of "Override Conflicts" confirmed a booking over it. Editable in
 * the email-body hub; wrapped by the configurable chrome (layout.php) at send.
 * Tokens are resolved by AudienceNotificationHandler::render_template().
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Your booking was cancelled by an administrator: {{schedule_name}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #b3261e;">' . __( 'Your booking was cancelled by an administrator', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'An administrator booked this space at the same time as your booking, so your booking was cancelled.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #fdecea; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #b3261e;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Reason:', 'ffcertificate' ) . '</strong> {{cancellation_reason}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Calendar:', 'ffcertificate' ) . '</strong> {{schedule_name}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>{{environment_label}}:</strong> {{environment_name}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date:', 'ffcertificate' ) . '</strong> {{booking_date}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{time}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Description:', 'ffcertificate' ) . '</strong> {{description}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Cancelled by:', 'ffcertificate' ) . '</strong> {{cancelled_by_name}}</p>'
		. '</div>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Please choose another time or space for your activity.', 'ffcertificate' ) . '</p>',
);
