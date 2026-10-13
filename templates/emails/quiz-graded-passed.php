<?php
/**
 * Quiz graded, passed — default body (#1732).
 *
 * Sent when a person finishes grading an attempt's open answers and the
 * attempt reaches the passing score: it is the certificate email of an
 * attempt whose result was held back. Wrapped by the configurable chrome
 * (layout.php) at send. Placeholders ({{name}}, {{form_title}}, {{percent}},
 * {{auth_code}}) + the {{validation_url …}} link DSL resolve via
 * Generators\TemplateRenderer::email().
 *
 * @package FreeFormCertificate\Core
 * @since   6.39.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'subject' => __( 'Your answers were graded: {{form_title}}', 'ffcertificate' ),
	'body'    => '<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;">' . __( 'Your document is ready', 'ffcertificate' ) . '</h2>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Hello {{name}},', 'ffcertificate' ) . '</p>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Your answers to {{form_title}} were graded and you passed.', 'ffcertificate' ) . '</p>'
		. '<div style="background: #eaf2fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2271b1;">'
		. '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Document:', 'ffcertificate' ) . '</strong> {{form_title}}</p>'
		. '<p style="margin: 0;"><strong>' . __( 'Authentication code:', 'ffcertificate' ) . '</strong> {{auth_code}}</p>'
		. '</div>'
		. '<p style="margin: 0 0 15px 0;">' . __( 'Click the button below to download it.', 'ffcertificate' ) . '</p>'
		. '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr>'
		. '<td style="background:#2271b1;border-radius:6px;padding:14px 28px;">'
		. '{{validation_url link:m>"' . __( 'Download document (PDF)', 'ffcertificate' ) . '" color:#ffffff}}'
		. '</td></tr></table>'
		. '<p style="margin: 0;">' . __( 'You can verify this document anytime at', 'ffcertificate' ) . ' {{validation_url link:v>v}}.</p>',
);
