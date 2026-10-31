<?php
/**
 * Reregistration Rejected Email Template
 *
 * Sent to the member when a reviewer rejects their submission (#2242). The
 * reason is mandatory on the review screen, so `{{reason}}` is never empty.
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * Available placeholders:
 *   {{user_name}}, {{reregistration_title}}, {{audience_name}},
 *   {{start_date}}, {{end_date}}, {{reason}}, {{dashboard_url}}, {{site_name}}
 *
 * @since 6.43.0
 * @package FreeFormCertificate\Reregistration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Reregistration not approved: {{reregistration_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #b32d2e;">' . __( 'Reregistration not approved', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Your reregistration was reviewed and was not approved. You can correct it and submit it again until the deadline.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #fcf0f1; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #b32d2e;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Campaign:', 'ffcertificate' ) . '</strong> {{reregistration_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Deadline:', 'ffcertificate' ) . '</strong> {{end_date}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Reason:', 'ffcertificate' ) . '</strong> {{reason}}</p>'
		. '</div>'
		. '<p style="text-align:center;margin:24px 0;">'
		. '<a href="{{dashboard_url}}" style="display:inline-block;padding:12px 28px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'
		. __( 'Resubmit Reregistration', 'ffcertificate' )
		. '</a></p>',
);
