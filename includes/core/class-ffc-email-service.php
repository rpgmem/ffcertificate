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
	 * Enforces the global "disable all emails" kill-switch here, at the single
	 * transport chokepoint, so it is bypass-proof: every path (including
	 * `SchedulingMailer::send`, recruitment, capability-manager and the
	 * certificate send-site) funnels through this method and honours the
	 * toggle regardless of whether the caller remembered to check it. This
	 * deliberately reverses the earlier "kill-switch stays caller-side"
	 * decision (#655) — see #662 P1. Template rendering remains the caller's
	 * concern; this is pure transport plus the master gate.
	 *
	 * @param string             $to          Recipient.
	 * @param string             $subject     Subject.
	 * @param string             $body        Body (already rendered).
	 * @param array<int, string> $headers     Mail headers (caller decides content-type).
	 * @param array<int, string> $attachments Attachment paths.
	 * @return bool Whether `wp_mail` accepted the message (false when emails are globally disabled).
	 */
	public static function send( string $to, string $subject, string $body, array $headers = array(), array $attachments = array() ): bool {
		if ( \FreeFormCertificate\Settings\SettingsReader::emails_disabled() ) {
			return false;
		}

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
