<?php
/**
 * Reregistration Confirmation Email Template
 *
 * Sent after a user submits (or is auto-approved).
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * Available placeholders:
 *   {{user_name}}, {{reregistration_title}}, {{audience_name}},
 *   {{submission_status}}, {{auth_code}}, {{magic_link_url}},
 *   {{dashboard_url}}, {{site_name}}
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Reregistration Confirmed: {{reregistration_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #1a7f4b;">' . __( 'Reregistration received', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{user_name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Your reregistration has been received successfully.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #e6f4ec; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #1a7f4b;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Campaign:', 'ffcertificate' ) . '</strong> {{reregistration_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Group:', 'ffcertificate' ) . '</strong> {{audience_name}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Status:', 'ffcertificate' ) . '</strong> {{submission_status}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Verification Code:', 'ffcertificate' ) . '</strong> {{auth_code}}</p>'
		. '</div>'
		. '<p style="text-align:center;margin:24px 0;">'
		. '<a href="{{magic_link_url}}" style="display:inline-block;padding:12px 28px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'
		. __( 'View and Download Ficha', 'ffcertificate' )
		. '</a></p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Click the button above to verify and download your reregistration record (PDF). You can also review your submission details in your dashboard at any time.', 'ffcertificate' ) . '</p>'
		. '<p style="text-align:center;margin:16px 0;">'
		. '<a href="{{dashboard_url}}" style="display:inline-block;padding:10px 24px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'
		. __( 'View Dashboard', 'ffcertificate' )
		. '</a></p>',
);
