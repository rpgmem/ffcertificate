<?php
/**
 * Audience booking-cancelled default email body (miolo).
 *
 * Default token-based body an audience schedule falls back to when it has no
 * custom cancellation template. Wrapped by the configurable chrome (layout.php)
 * at send. Tokens are resolved by AudienceNotificationHandler::render_template().
 *
 * @package FreeFormCertificate\Audience
 * @since   6.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'body' => '
<h3>' . __( 'Activity Cancelled', 'ffcertificate' ) . '</h3>

<p>' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>

<p>' . __( 'A scheduled activity you were included in has been cancelled:', 'ffcertificate' ) . "</p>

<div style='background:#fef2f2;padding:15px;border-radius:4px;margin:20px 0;border-left:4px solid #dc3545;'>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Calendar:', 'ffcertificate' ) . "</span> {{schedule_name}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>{{environment_label}}:</span> {{environment_name}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Date:', 'ffcertificate' ) . "</span> {{booking_date}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Time:', 'ffcertificate' ) . "</span> {{start_time}} - {{end_time}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Description:', 'ffcertificate' ) . "</span> {{description}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Cancelled by:', 'ffcertificate' ) . "</span> {{cancelled_by_name}}</div>
    <div style='margin:8px 0;'><span style='font-weight:600;'>" . __( 'Reason:', 'ffcertificate' ) . '</span> {{cancellation_reason}}</div>
</div>

<p>' . __( 'Please remove this event from your calendar.', 'ffcertificate' ) . '</p>

<p>' . __( 'Best regards,', 'ffcertificate' ) . '<br>{{site_name}}</p>',
);
