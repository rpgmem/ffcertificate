<?php
/**
 * Reregistration Invitation Email Template
 *
 * Sent when a reregistration campaign is activated.
 *
 * Available placeholders:
 *   {user_name}, {reregistration_title}, {audience_name},
 *   {start_date}, {end_date}, {dashboard_url}, {site_name}
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'subject' => __('Reregistration Open: {reregistration_title}', 'ffcertificate'),
    'body'    => '<h2>' . __('Hello, {user_name}!', 'ffcertificate') . '</h2>'
        . '<p>' . __('A new reregistration campaign has been opened for your group:', 'ffcertificate') . '</p>'
        . '<div class="info-box">'
        . '<div class="info-row"><span class="info-label">' . __('Campaign:', 'ffcertificate') . '</span> {reregistration_title}</div>'
        . '<div class="info-row"><span class="info-label">' . __('Group:', 'ffcertificate') . '</span> {audience_name}</div>'
        . '<div class="info-row"><span class="info-label">' . __('Period:', 'ffcertificate') . '</span> {start_date} — {end_date}</div>'
        . '</div>'
        . '<p>' . __('Please complete your reregistration before the deadline.', 'ffcertificate') . '</p>'
        . '<p style="text-align:center;margin:24px 0;">'
        . '<a href="{dashboard_url}" style="display:inline-block;padding:12px 28px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'
        . __('Complete Reregistration', 'ffcertificate')
        . '</a></p>',
);
