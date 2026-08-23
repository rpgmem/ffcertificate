<?php
/**
 * Appointment waitlisted email — default subject + body (editable via the hub, #965).
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * Tokens: {{user_name}}, {{calendar_title}}, {{appointment_date}}, {{appointment_time}},
 * and the pre-rendered {{waitlist_button}} — the "Leave Waitlist" link, empty when
 * cancellation isn't allowed.
 *
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Added to Waitlist: {{calendar_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #6c5ce7;">' . __( 'You are on the waitlist', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'This time was full, so you have been added to the waitlist. If a spot opens up, the next person in line is promoted automatically and you will be notified by email.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #eeecfb; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #6c5ce7;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Calendar:', 'ffcertificate' ) . '</strong> {{calendar_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date:', 'ffcertificate' ) . '</strong> {{appointment_date}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{appointment_time}}</p>'
		. '</div>'
		. '{{waitlist_button}}',
);
