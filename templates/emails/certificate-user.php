<?php
/**
 * Certificate submitter email — default body.
 *
 * The editable default for the per-form certificate email (Form editor → Email
 * tab). Wrapped by the configurable chrome (layout.php) at send. Placeholders
 * ({{name}}, {{form_title}}, {{date}}, {{auth_code}}) + the {{validation_url …}}
 * link DSL resolve via Generators\TemplateRenderer::email().
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour) + greeting +
 * one context line + a semantic details box.
 *
 * @package FreeFormCertificate\Core
 * @since   6.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Your document is ready — {{form_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;">' . __( 'Your document is ready', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Your document for {{form_title}} was issued on {{date}}.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #eaf2fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2271b1;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Document:', 'ffcertificate' ) . '</strong> {{form_title}}</p>'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Date:', 'ffcertificate' ) . '</strong> {{date}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Authentication code:', 'ffcertificate' ) . '</strong> {{auth_code}}</p>'
		. '</div>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Click the button below to download it.', 'ffcertificate' ) . '</p>'
		. '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr>'
		. '<td style="background:#2271b1;border-radius:6px;padding:14px 28px;">'
		. '{{validation_url link:m>"' . __( 'Download document (PDF)', 'ffcertificate' ) . '" color:#ffffff}}'
		. '</td></tr></table>'
		. '<p style="margin: 0;">' . __( 'You can verify this document anytime at', 'ffcertificate' ) . ' {{validation_url link:v>v}}.</p>',
);
