<?php
/**
 * Reregistration Confirmation Email Template
 *
 * Sent after a user submits (or is auto-approved).
 *
 * Available placeholders:
 *   {user_name}, {reregistration_title}, {audience_name},
 *   {submission_status}, {dashboard_url}, {site_name}
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
        . '</div>'
        . '<p>' . __('You can review your submission details in your dashboard at any time.', 'ffcertificate') . '</p>'
        . '<p style="text-align:center;margin:24px 0;">'
        . '<a href="{dashboard_url}" style="display:inline-block;padding:12px 28px;background:#00a32a;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'
        . __('View Dashboard', 'ffcertificate')
        . '</a></p>',
);
