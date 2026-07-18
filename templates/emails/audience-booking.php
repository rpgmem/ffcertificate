<?php
/**
 * Audience booking-created default email body.
 *
 * Default token-based body an audience schedule falls back to when it has no
 * custom booking template. Wrapped by the configurable chrome (layout.php) at
 * send. Tokens ({{user_name}}, {{schedule_name}}, …) are resolved by
 * AudienceNotificationHandler::render_template().
 *
 * @package FreeFormCertificate\Audience
 * @since   6.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'body' => '
<h3>' . __( 'New Scheduled Activity', 'ffcertificate' ) . '</h3>

<p>' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>

<p>' . __( 'You have been included in a new scheduled activity:', 'ffcertificate' ) . "</p>

<div style='background:#f0f6fc;padding:15px;border-radius:4px;margin:20px 0;'>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Calendar:', 'ffcertificate' ) . "</span> {{schedule_name}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>{{environment_label}}:</span> {{environment_name}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Date:', 'ffcertificate' ) . "</span> {{booking_date}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Time:', 'ffcertificate' ) . "</span> {{start_time}} - {{end_time}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Description:', 'ffcertificate' ) . "</span> {{description}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Audiences:', 'ffcertificate' ) . "</span> {{audiences}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Scheduled by:', 'ffcertificate' ) . '</span> {{creator_name}}</div>
</div>

<p>' . __( 'Please add this event to your calendar.', 'ffcertificate' ) . '</p>

<p>' . __( 'Best regards,', 'ffcertificate' ) . '<br>{{site_name}}</p>',
);
