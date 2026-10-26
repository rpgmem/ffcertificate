<?php
/**
 * Announcement date message — default subject + body for a new announcement
 * rule (#1949).
 *
 * Copied into a rule when it is created, then edited per rule; the hub edits
 * this default. Wrapped by the configurable chrome (layout.php) at send.
 *
 * Tokens: {{name}}, {{first_name}}, {{last_name}}, {{full_name}}, {{email}},
 * {{site_name}}, {{dashboard_url}} and {{unsubscribe_url}}. An announcement has
 * no date, so the date tokens are not offered. A body without
 * {{unsubscribe_url}} still gets the link: the runner appends it.
 *
 * @package FreeFormCertificate\DateMessages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( '{{site_name}}: announcement', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;">' . __( 'Announcement', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . sprintf( /* translators: %s: name */ __( 'Hello %s,', 'ffcertificate' ), '{{name}}' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . sprintf( /* translators: %s: site name */ __( 'There is a new announcement from %s.', 'ffcertificate' ), '{{site_name}}' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . sprintf( /* translators: %s: dashboard link URL */ __( 'You can read the details on your dashboard: <a href="%s">open your dashboard</a>.', 'ffcertificate' ), '{{dashboard_url}}' ) . '</p>'
		. '<p style="margin: 20px 0 0 0; font-size: 12px; color: #646970;">' . sprintf( /* translators: %s: unsubscribe link URL */ __( 'Prefer not to receive announcements? <a href="%s">Unsubscribe</a>.', 'ffcertificate' ), '{{unsubscribe_url}}' ) . '</p>',
);
