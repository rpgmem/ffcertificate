<?php
/**
 * Birthday date message — default subject + body for a new rule (#1538).
 *
 * Copied into a rule when it is created, then edited per rule; the hub edits
 * this default. Wrapped by the configurable chrome (layout.php) at send.
 *
 * Tokens: {{name}}, {{first_name}}, {{last_name}}, {{full_name}}, {{email}},
 * {{date}} (the birthday),
 * {{age}}, {{days_until}}, {{site_name}}, {{dashboard_url}} and
 * {{unsubscribe_url}}. A body without {{unsubscribe_url}} still gets the
 * link: the runner appends it.
 *
 * @package FreeFormCertificate\DateMessages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Happy birthday, {{first_name}}!', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;">' . __( 'Happy birthday!', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . sprintf( /* translators: %s: first name */ __( 'Hello %s,', 'ffcertificate' ), '{{first_name}}' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . sprintf( /* translators: %s: site name */ __( 'Everyone at %s wishes you a very happy birthday and a wonderful year ahead.', 'ffcertificate' ), '{{site_name}}' ) . '</p>'
		. '<p style="margin: 20px 0 0 0; font-size: 12px; color: #646970;">' . sprintf( /* translators: %s: unsubscribe link URL */ __( 'Prefer not to receive these messages? <a href="%s">Unsubscribe</a>.', 'ffcertificate' ), '{{unsubscribe_url}}' ) . '</p>',
);
