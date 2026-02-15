<?php
/**
 * Reregistration Confirmation Email Template
 *
 * Sent after a user submits (or is auto-approved).
 *
 * Available placeholders:
 *   {user_name}, {reregistration_title}, {audience_name},
 *   {submission_status}, {auth_code}, {magic_link_url},
 *   {dashboard_url}, {site_name}
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'subject' => __('Reregistration Confirmed: {reregistration_title}', 'ffcertificate'),
    'body'    => '<h2>' . __('Hello, {user_name}!', 'ffcertificate') . '</h2>'
        . '<p>' . __('Your reregistration has been received successfully.', 'ffcertificate') . '</p>'
        . '<div class="info-box">'
        . '<div class="info-row"><span class="info-label">' . __('Campaign:', 'ffcertificate') . '</span> {reregistration_title}</div>'
        . '<div class="info-row"><span class="info-label">' . __('Group:', 'ffcertificate') . '</span> {audience_name}</div>'
        . '<div class="info-row"><span class="info-label">' . __('Status:', 'ffcertificate') . '</span> {submission_status}</div>'
        . '<div class="info-row"><span class="info-label">' . __('Verification Code:', 'ffcertificate') . '</span> <strong>{auth_code}</strong></div>'
        . '</div>'
        . '<p style="text-align:center;margin:24px 0;">'
        . '<a href="{magic_link_url}" style="display:inline-block;padding:12px 28px;background:#0073aa;color:#fff;text-decoration:none;border-radius:5px;font-weight:600;font-size:16px;box-shadow:0 2px 4px rgba(0,115,170,0.3);">'
        . __('View and Download Ficha', 'ffcertificate')
        . '</a></p>'
        . '<p style="text-align:center;font-size:12px;color:#666;">' . __('Click the button above to verify and download your reregistration record (PDF).', 'ffcertificate') . '</p>'
        . '<p>' . __('You can also review your submission details in your dashboard at any time.', 'ffcertificate') . '</p>'
        . '<p style="text-align:center;margin:16px 0;">'
        . '<a href="{dashboard_url}" style="display:inline-block;padding:10px 24px;background:#00a32a;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'
        . __('View Dashboard', 'ffcertificate')
        . '</a></p>',
);
