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
 * @since 4.11.2
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
	 * @return bool Whether the email was sent.
	 */
	protected static function ffc_send_mail( string $to, string $subject, string $body, array $attachments = array() ): bool {
		return EmailService::send( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ), $attachments );
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
	 * Wrap inner email content ("miolo") in the shared chrome shell.
	 *
	 * The shell (templates/emails/layout.php) provides the standard header
	 * band and the site-name footer card; callers supply only the body.
	 *
	 * @param string $content Pre-built inner HTML.
	 * @return string Full email document HTML.
	 */
	protected static function ffc_email_document( string $content ): string {
		return self::ffc_render_email_partial( 'layout', array( 'content' => $content ) );
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
