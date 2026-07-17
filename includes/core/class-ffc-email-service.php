<?php
/**
 * Email Service
 *
 * The single email transport chokepoint (#653). Every outbound email funnels
 * through {@see self::send()} — the low-level `wp_mail` wrapper plus failure
 * logging — instead of the previous three paths (`EmailHelperTrait::ffc_send_mail`,
 * `EmailTemplateService::send`, and raw `wp_mail` calls). Content-type/headers
 * stay caller-supplied so each existing send keeps its exact behaviour (some
 * are text/html, some default text/plain, recruitment is multipart).
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
 * Low-level email transport.
 */
final class EmailService {

	/**
	 * Send an email via `wp_mail`, logging failures.
	 *
	 * The global "disable all emails" kill-switch and any template rendering
	 * are the caller's / higher layer's concern — this is pure transport.
	 *
	 * @param string             $to          Recipient.
	 * @param string             $subject     Subject.
	 * @param string             $body        Body (already rendered).
	 * @param array<int, string> $headers     Mail headers (caller decides content-type).
	 * @param array<int, string> $attachments Attachment paths.
	 * @return bool Whether `wp_mail` accepted the message.
	 */
	public static function send( string $to, string $subject, string $body, array $headers = array(), array $attachments = array() ): bool {
		$sent = wp_mail( $to, $subject, $body, $headers, $attachments );

		if ( ! $sent && class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
			Debug::log_email(
				'Email send failed',
				array(
					'to'      => $to,
					'subject' => $subject,
				)
			);
		}

		return $sent;
	}
}
