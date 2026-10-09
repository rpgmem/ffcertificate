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

	use \FreeFormCertificate\Core\EmailHelperTrait;

	/**
	 * Send an HTML email with optional attachments.
	 *
	 * The inner body ("email body") is wrapped in the single, admin-configurable
	 * chrome ({@see \FreeFormCertificate\Core\EmailTemplateOptions} → layout.php)
	 * via {@see EmailHelperTrait::ffc_email_document()}, the same shell every
	 * other plugin email uses (#662 P2). The old class-based `wrap_html` chrome
	 * was retired here.
	 *
	 * @param string               $to          Recipient email.
	 * @param string               $subject     Email subject.
	 * @param string               $body        Email body (inner HTML — wrapped automatically unless $wrap is false).
	 * @param array<string>        $attachments File paths to attach.
	 * @param bool                 $wrap        Whether to wrap body in the standard chrome (default true).
	 * @param string               $source_key  Optional {@see \FreeFormCertificate\Core\EmailSource} key tagging the sending function.
	 * @param array<string, mixed> $document Extra layout arguments, such as a message's `body_appearance` (#1660).
	 * @return bool
	 */
	public static function send(
		string $to,
		string $subject,
		string $body,
		array $attachments = array(),
		bool $wrap = true,
		string $source_key = '',
		array $document = array()
	): bool {
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		$html = $wrap ? self::document( $body, array_merge( $document, array( 'recipient' => $to ) ) ) : $body;

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

		return \FreeFormCertificate\Core\EmailService::send( $email_data['to'], $email_data['subject'], $email_data['body'], $headers, $attachments, $source_key );
	}

	/**
	 * The full e-mail HTML a body becomes, without sending it: what a preview
	 * shows is then what `send()` delivers.
	 *
	 * @param string               $body    Inner HTML.
	 * @param array<string, mixed> $context Layout arguments (`recipient`, `body_appearance`).
	 * @return string
	 */
	public static function document( string $body, array $context = array() ): string {
		return self::ffc_email_document( $body, $context );
	}
}
