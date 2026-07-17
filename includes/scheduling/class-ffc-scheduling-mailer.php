<?php
/**
 * Scheduling Mailer
 *
 * Shared HTML-email chrome + transport for the scheduling-domain notifications
 * (audience booking, reregistration). Wraps the inner body in the standard
 * scheduling layout, exposes the `ffcertificate_scheduling_email` filter, and
 * hands the result to the plugin-wide transport chokepoint Core\EmailService.
 *
 * Extracted from the retired EmailTemplateService so the mailer chrome/transport
 * and the ICS generation each have a single, focused home (#653).
 *
 * @package FreeFormCertificate\Scheduling
 * @since 6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Scheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps and sends scheduling-domain HTML emails.
 */
class SchedulingMailer {

	/**
	 * Wrap email body in the standard scheduling HTML layout.
	 *
	 * @param string $body Inner HTML content.
	 * @return string Complete HTML email.
	 */
	public static function wrap_html( string $body ): string {
		$site_name = esc_html( get_bloginfo( 'name' ) );

		return "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2271b1; color: #fff; padding: 20px; text-align: center; border-radius: 4px 4px 0 0; }
        .content { background: #fff; padding: 30px; border: 1px solid #e0e0e0; }
        .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 4px 4px; }
        .info-box { background: #f0f6fc; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .info-row { margin: 8px 0; }
        .info-label { font-weight: 600; }
        .cancelled { background: #fef2f2; border-left: 4px solid #dc3545; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h2>{$site_name}</h2>
        </div>
        <div class='content'>
            {$body}
        </div>
        <div class='footer'>
            <p>" . esc_html__( 'This is an automated notification from', 'ffcertificate' ) . " {$site_name}</p>
        </div>
    </div>
</body>
</html>";
	}

	/**
	 * Send an HTML email with optional attachments.
	 *
	 * @param string        $to          Recipient email.
	 * @param string        $subject     Email subject.
	 * @param string        $body        Email body (inner HTML — wrapped automatically unless $wrap is false).
	 * @param array<string> $attachments File paths to attach.
	 * @param bool          $wrap        Whether to wrap body in the standard HTML layout (default true).
	 * @return bool
	 */
	public static function send(
		string $to,
		string $subject,
		string $body,
		array $attachments = array(),
		bool $wrap = true
	): bool {
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		$html = $wrap ? self::wrap_html( $body ) : $body;

		/**
		 * Filters scheduling email data before sending.
		 *
		 * @since 4.6.4
		 * @param array $email_data {
		 *     @type string $to      Recipient email.
		 *     @type string $subject Email subject.
		 *     @type string $body    Email HTML body.
		 * }
		 */
		$email_data = apply_filters(
			'ffcertificate_scheduling_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => $html,
			)
		);

		return \FreeFormCertificate\Core\EmailService::send( $email_data['to'], $email_data['subject'], $email_data['body'], $headers, $attachments );
	}
}
