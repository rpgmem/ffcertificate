<?php
/**
 * "Calendar deleted → appointment cancelled" email — default subject + body
 * (editable via the hub, #965).
 *
 * Sent to booked users when an admin deletes a self-scheduling calendar.
 * Wrapped by the configurable chrome (layout.php) at send. Rendered by
 * SelfSchedulingCPT::send_calendar_deletion_notification.
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
	'body'    => '<p>' . __( 'Hello,', 'ffcertificate' ) . '</p>'
		. '<p>' . sprintf( /* translators: %s: calendar title */ __( 'We regret to inform you that your appointment has been cancelled because the calendar "%s" is no longer available.', 'ffcertificate' ), '{{calendar_title}}' ) . '</p>'
		. '<div style="background:#fef2f2;padding:15px;border-radius:4px;margin:20px 0;border-left:4px solid #dc3545;">'
		. '<div style="margin:8px 0;"><span style="font-weight:600;">' . __( 'Date:', 'ffcertificate' ) . '</span> {{appointment_date}}</div>'
		. '<div style="margin:8px 0;"><span style="font-weight:600;">' . __( 'Time:', 'ffcertificate' ) . '</span> {{appointment_time}}</div>'
		. '<div style="margin:8px 0;"><span style="font-weight:600;">' . __( 'Calendar:', 'ffcertificate' ) . '</span> {{calendar_title}}</div>'
		. '</div>'
		. '<p>' . __( 'We apologize for any inconvenience this may cause.', 'ffcertificate' ) . '</p>',
);
