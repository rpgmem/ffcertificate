<?php
/**
 * Capability "access granted" email — default subject + body (editable via the hub, #965).
 *
 * Wrapped by the configurable chrome (layout.php) at send. Rendered by
 * CapabilityManager when a user is granted plugin access.
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
	'body'    => '<p>' . sprintf( /* translators: %s: user display name */ __( 'Hello %s,', 'ffcertificate' ), '{{user_name}}' ) . '</p>'
		. '<p>' . sprintf( /* translators: %1$s: feature name, %2$s: site name */ __( 'You now have access to %1$s on %2$s.', 'ffcertificate' ), '{{context_label}}', '{{site_name}}' ) . '</p>'
		. '{{dashboard_button}}'
		. '<p style="color:#666666;font-size:13px;">' . __( 'This is an automated message.', 'ffcertificate' ) . '</p>',
);
