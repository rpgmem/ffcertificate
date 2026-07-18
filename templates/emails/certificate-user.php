<?php
/**
 * Certificate submitter email — default body.
 *
 * The editable default for the per-form certificate email (Form editor → Email
 * tab). Wrapped by the configurable chrome (layout.php) at send. Placeholders
 * ({{name}}, {{form_title}}, {{date}}, {{auth_code}}) + the {{validation_url …}}
 * link DSL resolve via Generators\TemplateRenderer::email().
 *
 * @package FreeFormCertificate\Core
 * @since   6.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'body' => '<p>' . __( 'Hello {{name}},', 'ffcertificate' ) . '</p>'
		. '<p>' . __( 'Your document for {{form_title}} was issued on {{date}} ✅', 'ffcertificate' ) . '</p>'
		. '<p>' . __( 'Click the button below to download it.', 'ffcertificate' ) . '</p>'
		. '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr>'
		. '<td style="background:#0073aa;border-radius:6px;padding:14px 28px;">'
		. '{{validation_url link:m>"' . __( '⬇️ Download document (PDF)', 'ffcertificate' ) . '" color:#ffffff}}'
		. '</td></tr></table>'
		. '<p>' . __( 'Keep your authentication code for future verification:', 'ffcertificate' )
		. ' <strong>{{auth_code}}</strong> — '
		. __( 'you can check it anytime at', 'ffcertificate' ) . ' {{validation_url link:v>v}}.</p>',
);
