<?php
/**
 * Recruitment convocation email — default body.
 *
 * The editable default for the recruitment convocation email (Recruitment →
 * Settings). Wrapped by the configurable chrome (layout.php) at send.
 * All {{placeholder}} markers resolve via Core\TokenResolver at send time.
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Recruitment Call: {{notice_name}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;">' . __( 'Recruitment call', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'You have been called for notice {{notice_code}} — {{notice_name}} in adjutancy {{adjutancy}}.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #eaf2fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2271b1;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Rank:', 'ffcertificate' ) . '</strong> {{rank}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Score:', 'ffcertificate' ) . '</strong> {{score}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date to assume:', 'ffcertificate' ) . '</strong> {{date_to_assume}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Time:', 'ffcertificate' ) . '</strong> {{time_to_assume}}</p>'
		. '</div>'
		. '<p style="margin: 0;">{{notes}}</p>',
);
