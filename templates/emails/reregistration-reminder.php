<?php
/**
 * Reregistration Reminder Email Template
 *
 * Sent as a reminder before the campaign deadline.
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * Available placeholders:
 *   {{user_name}}, {{reregistration_title}}, {{audience_name}},
 *   {{start_date}}, {{end_date}}, {{days_left}}, {{dashboard_url}}, {{site_name}}
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Reregistration reminder: {{reregistration_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #8a6d00;">' . __( 'Reregistration reminder', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'This is a friendly reminder that the following reregistration campaign is still pending.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #fbf3d8; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #8a6d00;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Campaign:', 'ffcertificate' ) . '</strong> {{reregistration_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Deadline:', 'ffcertificate' ) . '</strong> {{end_date}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Days remaining:', 'ffcertificate' ) . '</strong> {{days_left}}</p>'
		. '</div>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Please complete your reregistration before the deadline to avoid any issues.', 'ffcertificate' ) . '</p>'
		. '<p style="text-align:center;margin:24px 0;">'
		. '<a href="{{dashboard_url}}" style="display:inline-block;padding:12px 28px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'
		. __( 'Complete Now', 'ffcertificate' )
		. '</a></p>',
);
