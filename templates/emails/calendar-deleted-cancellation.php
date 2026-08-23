<?php
/**
 * "Calendar deleted → appointment cancelled" email — default subject + body
 * (editable via the hub, #965).
 *
 * Sent to booked users when an admin deletes a self-scheduling calendar.
 * Wrapped by the configurable chrome (layout.php) at send. Rendered by
 * SelfSchedulingCPT::send_calendar_deletion_notification.
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * Tokens: {{site_name}} (subject only), {{calendar_title}}, {{appointment_date}},
 * {{appointment_time}}.
 *
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => sprintf(
		/* translators: %s: site name */
		__( '[%s] Appointment Cancelled - Calendar No Longer Available', 'ffcertificate' ),
		'{{site_name}}'
	),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #b3261e;">' . __( 'Appointment cancelled', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello,', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Your appointment has been cancelled because the calendar "{{calendar_title}}" is no longer available.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #fdecea; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #b3261e;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Calendar:', 'ffcertificate' ) . '</strong> {{calendar_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date:', 'ffcertificate' ) . '</strong> {{appointment_date}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{appointment_time}}</p>'
		. '</div>'
		. '<p style="margin: 15px 0 0 0;">' . __( 'We apologize for any inconvenience this may cause.', 'ffcertificate' ) . '</p>',
);
