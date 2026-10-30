<?php
/**
 * Audience booking-changed default email body.
 *
 * Sent once to a booking's participants when its date, hours, space or
 * description is edited (#2243). Wrapped by the configurable chrome
 * (layout.php) at send. Tokens are resolved by
 * AudienceNotificationHandler::render_template().
 *
 * Standard layout (#976): h2 event title + greeting + one context line + a
 * details box with the booking as it now stands, then what it was before.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.43.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Activity changed: {{schedule_name}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #1d5fa8;">' . __( 'Activity changed', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'A scheduled activity you were included in has changed. This is how it stands now.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #e8f0fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #1d5fa8;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Calendar:', 'ffcertificate' ) . '</strong> {{schedule_name}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>{{environment_label}}:</strong> {{environment_name}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date:', 'ffcertificate' ) . '</strong> {{booking_date}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{time}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Description:', 'ffcertificate' ) . '</strong> {{description}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Changed by:', 'ffcertificate' ) . '</strong> {{changed_by_name}}</p>'
		. '</div>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Before the change:', 'ffcertificate' ) . '</strong> {{previous_environment}}, {{previous_date}}, {{previous_time}}</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'The attached calendar file updates the event already in your calendar.', 'ffcertificate' ) . '</p>',
);
