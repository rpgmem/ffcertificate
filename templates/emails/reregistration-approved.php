<?php
/**
 * Reregistration Approved Email Template
 *
 * Sent to the member when a reviewer approves their submission, one at a
 * time or in a selection (#2688). An auto-approved submission is told by the
 * confirmation e-mail instead, which already carries its status.
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * Available placeholders:
 *   {{user_name}}, {{reregistration_title}}, {{audience_name}},
 *   {{start_date}}, {{end_date}}, {{auth_code}}, {{dashboard_url}}, {{site_name}}
 *
 * @since 6.45.0
 * @package FreeFormCertificate\Reregistration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Reregistration approved: {{reregistration_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #1a7f4b;">' . __( 'Reregistration approved', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Your reregistration was reviewed and approved. Your record (PDF) is ready to download from your dashboard.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #e6f4ec; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #1a7f4b;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Campaign:', 'ffcertificate' ) . '</strong> {{reregistration_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Group:', 'ffcertificate' ) . '</strong> {{audience_name}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Verification Code:', 'ffcertificate' ) . '</strong> {{auth_code}}</p>'
		. '</div>'
		. '<p style="text-align:center;margin:24px 0;">'
		. '<a href="{{dashboard_url}}" style="display:inline-block;padding:12px 28px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'
		. __( 'View Dashboard', 'ffcertificate' )
		. '</a></p>',
);
