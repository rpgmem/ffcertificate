<?php
/**
 * Capability "access granted" email — default subject + body (editable via the hub, #965).
 *
 * Wrapped by the configurable chrome (layout.php) at send. Rendered by
 * CapabilityManager when a user is granted plugin access.
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * Tokens: {{user_name}}, {{context_label}} (feature name), {{site_name}}, and the
 * pre-rendered {{dashboard_button}} (empty when no dashboard URL is configured).
 *
 * @package FreeFormCertificate\UserDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => sprintf(
		/* translators: %1$s: site name, %2$s: feature name */
		__( '[%1$s] Access granted: %2$s', 'ffcertificate' ),
		'{{site_name}}',
		'{{context_label}}'
	),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;">' . __( 'Access granted', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . sprintf( /* translators: %s: user display name */ __( 'Hello %s,', 'ffcertificate' ), '{{user_name}}' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . sprintf( /* translators: %1$s: feature name, %2$s: site name */ __( 'You now have access to %1$s on %2$s.', 'ffcertificate' ), '{{context_label}}', '{{site_name}}' ) . '</p>'
		. '<div style="background: #eaf2fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2271b1;">'
		. '<p style="margin: 0;"><strong>' . __( 'Access to:', 'ffcertificate' ) . '</strong> {{context_label}}</p>'
		. '</div>'
		. '{{dashboard_button}}',
);
