<?php
/**
 * Reregistration Reminder Email Template
 *
 * Sent as a reminder before the campaign deadline.
 *
 * Available placeholders:
 *   {user_name}, {reregistration_title}, {audience_name},
 *   {start_date}, {end_date}, {days_left}, {dashboard_url}, {site_name}
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'subject' => __('Reminder: {reregistration_title} — {days_left} days left', 'ffcertificate'),
    'body'    => '<h2>' . __('Hello, {user_name}!', 'ffcertificate') . '</h2>'
        . '<p>' . __('This is a friendly reminder that the following reregistration campaign is still pending:', 'ffcertificate') . '</p>'
        . '<div class="info-box">'
        . '<div class="info-row"><span class="info-label">' . __('Campaign:', 'ffcertificate') . '</span> {reregistration_title}</div>'
        . '<div class="info-row"><span class="info-label">' . __('Deadline:', 'ffcertificate') . '</span> {end_date}</div>'
        . '<div class="info-row"><span class="info-label">' . __('Days remaining:', 'ffcertificate') . '</span> <strong>{days_left}</strong></div>'
        . '</div>'
        . '<p>' . __('Please complete your reregistration before the deadline to avoid any issues.', 'ffcertificate') . '</p>'
        . '<p style="text-align:center;margin:24px 0;">'
        . '<a href="{dashboard_url}" style="display:inline-block;padding:12px 28px;background:#dba617;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'
        . __('Complete Now', 'ffcertificate')
        . '</a></p>',
);
