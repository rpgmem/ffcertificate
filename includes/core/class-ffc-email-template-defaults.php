<?php
/**
 * Default user-email template
 *
 * The shipped subject + body for the certificate confirmation email, as
 * translatable (`__()`) source strings so Loco can capture them. Shared by the
 * form-editor email metabox (seeds the editor) and the email handler (used when
 * a form has no custom body of its own). Lives in Core so both the Admin and
 * Integrations modules reach it without a cross-module edge (#649).
 *
 * The body is intentionally a plain, inline-styled HTML template — every visible
 * string, the download button label and the links live here (or in the operator's
 * per-form override), nothing is locked in the send path. Placeholders:
 * `{{name}}`, `{{form_title}}`, `{{auth_code}}`, `{{date}}`, and the
 * `{{validation_url ...}}` DSL (`m` = magic/download link, `v` = public /valid).
 *
 * @package FreeFormCertificate\Core
 * @since 6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shipped defaults for the submitter confirmation email.
 */
final class EmailTemplateDefaults {

	/**
	 * Default email subject (with `{{form_title}}` placeholder).
	 *
	 * @return string
	 */
	public static function user_email_subject(): string {
		return __( 'Your document is ready — {{form_title}}', 'ffcertificate' );
	}

	/**
	 * Default email body HTML (with placeholders + the download-button box).
	 *
	 * @return string
	 */
	public static function user_email_body(): string {
		return '<p>' . __( 'Hello {{name}},', 'ffcertificate' ) . '</p>'
			. '<p>' . __( 'Your document for {{form_title}} was issued on {{date}} ✅', 'ffcertificate' ) . '</p>'
			. '<p>' . __( 'Click the button below to download it.', 'ffcertificate' ) . '</p>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr>'
			. '<td style="background:#0073aa;border-radius:6px;padding:14px 28px;">'
			. '{{validation_url link:m>"' . __( '⬇️ Download document (PDF)', 'ffcertificate' ) . '" color:#ffffff}}'
			. '</td></tr></table>'
			. '<p>' . __( 'Keep your authentication code for future verification:', 'ffcertificate' )
			. ' <strong>{{auth_code}}</strong> — '
			. __( 'you can check it anytime at', 'ffcertificate' ) . ' {{validation_url link:v>v}}.</p>';
	}
}
