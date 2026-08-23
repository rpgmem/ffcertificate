<?php
/**
 * Audience booking-cancelled default email body.
 *
 * Default token-based body an audience schedule falls back to when it has no
 * custom cancellation template. Wrapped by the configurable chrome (layout.php)
 * at send. Tokens are resolved by AudienceNotificationHandler::render_template().
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box. The sign-off comes from the shared
 * Email Model footer, not the body.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Activity cancelled: {{schedule_name}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #b3261e;">' . __( 'Activity cancelled', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'A scheduled activity you were included in has been cancelled.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #fdecea; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #b3261e;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Calendar:', 'ffcertificate' ) . '</strong> {{schedule_name}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>{{environment_label}}:</strong> {{environment_name}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date:', 'ffcertificate' ) . '</strong> {{booking_date}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{start_time}} - {{end_time}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Description:', 'ffcertificate' ) . '</strong> {{description}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Cancelled by:', 'ffcertificate' ) . '</strong> {{cancelled_by_name}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Reason:', 'ffcertificate' ) . '</strong> {{cancellation_reason}}</p>'
		. '</div>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Please remove this event from your calendar.', 'ffcertificate' ) . '</p>',
);
