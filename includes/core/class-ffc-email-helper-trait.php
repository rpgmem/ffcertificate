<?php
/**
 * Email Helper Trait
 *
 * Shared email utilities used across EmailHandler, AppointmentEmailHandler,
 * ReregistrationEmailHandler and AudienceNotificationHandler.
 *
 * Eliminates duplicated code for:
 * - Global email disable check
 * - wp_mail() wrapper with failure logging
 * - Admin email parsing (comma-separated string → array)
 * - Rendering templates/emails/ partials + the shared chrome shell
 *
 * @package FreeFormCertificate\Core
 * @since 4.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait EmailHelperTrait {

	/**
	 * Check if all emails are globally disabled.
	 *
	 * @return bool
	 */
	protected static function ffc_emails_disabled(): bool {
		return \FreeFormCertificate\Settings\SettingsReader::emails_disabled();
	}

	/**
	 * Send an HTML email with failure logging.
	 *
	 * @param string        $to          Recipient email.
	 * @param string        $subject     Email subject.
	 * @param string        $body        Email body (HTML).
	 * @param array<string> $attachments Optional file paths to attach.
	 * @param string        $source_key  Optional {@see EmailSource} key tagging the sending function.
	 * @return bool Whether the email was sent.
	 */
	protected static function ffc_send_mail( string $to, string $subject, string $body, array $attachments = array(), string $source_key = '' ): bool {
		return EmailService::send( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ), $attachments, $source_key );
	}

	/**
	 * Parse a comma-separated admin email string into a validated array.
	 *
	 * @param string $emails_string Comma-separated email addresses.
	 * @param string $fallback      Fallback email if string is empty (default: admin_email option).
	 * @return array<string> Valid email addresses.
	 */
	protected static function ffc_parse_admin_emails( string $emails_string, string $fallback = '' ): array {
		if ( empty( $emails_string ) ) {
			$fallback_email = $fallback ? $fallback : (string) get_option( 'admin_email' );
			return $fallback_email ? array( $fallback_email ) : array();
		}

		return array_filter(
			array_map( 'trim', explode( ',', $emails_string ) ),
			static function ( string $email ): bool {
				return is_email( $email ) !== false;
			}
		);
	}

	/**
	 * Render an email template partial from templates/emails/ into a string.
	 *
	 * The partial receives the caller-supplied values as `$args` and is
	 * responsible for escaping each one at its output point. Keeping the markup
	 * in templates/ (outside the coverage scope) lets the handler classes stay
	 * thin data-prep orchestrators.
	 *
	 * @param string               $template Template basename (no path/extension).
	 * @param array<string, mixed> $args     Variables exposed to the partial as $args.
	 * @return string Rendered HTML (empty string when the template is missing).
	 */
	protected static function ffc_render_email_partial( string $template, array $args = array() ): string {
		$file = FFC_PLUGIN_DIR . 'templates/emails/' . $template . '.php';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}

	/**
	 * Wrap inner email content ("email body") in the shared, configurable chrome.
	 *
	 * The shell (templates/emails/layout.php) provides the header band, body
	 * card, footer and outer wrapper styled from `EmailTemplateOptions`; callers
	 * supply only the body. `$context` passes extra values to the shell — e.g.
	 * `array( 'recipient' => $to )` to resolve the `{{recipient}}` footer token.
	 *
	 * @param string                $content Pre-built inner HTML.
	 * @param array<string, string> $context Extra values for the chrome (e.g. 'recipient').
	 * @return string Full email document HTML.
	 */
	protected static function ffc_email_document( string $content, array $context = array() ): string {
		return self::ffc_render_email_partial( 'layout', array_merge( $context, array( 'content' => $content ) ) );
	}

	/**
	 * Render a centered call-to-action button, or `''` when the URL is empty.
	 *
	 * The pre-rendered value for the `{{…_button}}` tokens that let an otherwise
	 * code-built email (receipt / cancel / dashboard buttons, whose show-or-hide
	 * depends on a URL) become a single editable body string in the email-body
	 * hub (#965): the handler builds the button HTML here and passes it as a
	 * token, so the admin's template only carries `{{receipt_button}}` etc. The
	 * href, label and lead-in are escaped here (trusted, non-editable markup);
	 * an empty URL yields `''` so the button simply disappears.
	 *
	 * @param string               $url   Destination URL. Empty ⇒ returns ''.
	 * @param string               $label Button label (plain text; may include an emoji).
	 * @param array<string, mixed> $args  Optional: bg, padding, font_size, bold, lead_in.
	 * @return string Button HTML, or '' when `$url` is empty.
	 */
	protected static function ffc_email_button( string $url, string $label, array $args = array() ): string {
		if ( '' === trim( $url ) ) {
			return '';
		}

		$bg        = isset( $args['bg'] ) ? (string) $args['bg'] : '#0073aa';
		$padding   = isset( $args['padding'] ) ? (string) $args['padding'] : '12px 24px';
		$font_size = isset( $args['font_size'] ) ? (string) $args['font_size'] : '16px';
		$weight    = empty( $args['bold'] ) ? '' : ' font-weight: bold;';
		$lead_in   = isset( $args['lead_in'] ) ? (string) $args['lead_in'] : '';

		$html = '<div style="text-align: center; margin: 30px 0;">';
		if ( '' !== $lead_in ) {
			$html .= '<p style="margin: 0 0 10px 0; font-size: 14px; color: #666;">' . esc_html( $lead_in ) . '</p>';
		}
		$html .= '<a href="' . esc_url( $url ) . '" style="display: inline-block; background: ' . esc_attr( $bg )
			. '; color: white; padding: ' . esc_attr( $padding ) . '; text-decoration: none; border-radius: 5px; font-size: '
			. esc_attr( $font_size ) . ';' . $weight . '">' . esc_html( $label ) . '</a>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Build an admin notification table from key-value pairs.
	 *
	 * Matches the format used in EmailHandler::send_admin_notification()
	 * and AppointmentEmailHandler::send_admin_notification().
	 *
	 * @param array<string, string> $details Label => Value pairs.
	 * @return string HTML table.
	 */
	protected static function ffc_admin_notification_table( array $details ): string {
		$body = '<table border="1" cellpadding="10" style="border-collapse:collapse; width:100%; font-family: sans-serif; border: 1px solid #ddd;">';

		foreach ( $details as $label => $value ) {
			$body .= '<tr>';
			$body .= '<td style="background:#f9f9f9; width:30%; font-weight: bold; border: 1px solid #ddd;">' . esc_html( $label ) . '</td>';
			$body .= '<td style="border: 1px solid #ddd;">' . esc_html( $value ) . '</td>';
			$body .= '</tr>';
		}

		$body .= '</table>';
		return $body;
	}
}
