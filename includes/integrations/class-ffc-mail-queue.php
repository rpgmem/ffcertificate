<?php
/**
 * Mail-queue integration detection.
 *
 * Detects whether a mail-queue plugin — specifically the sibling
 * `rpgmem/total-mail-queue` — is active. Every plugin email already funnels
 * through `wp_mail()` (via `Core\EmailService::send()`), so such a plugin
 * captures them all for queueing / retry / backoff for free and the plugin
 * deliberately does **not** ship its own queue (#673). This single detector
 * is the one place that answer lives, reused by the "install total-mail-queue"
 * recommendation surface (shown only when it is NOT present) and any future
 * queue-aware logic.
 *
 * @package FreeFormCertificate\Integrations
 * @since 6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects the total-mail-queue sibling plugin.
 */
final class MailQueue {

	/**
	 * Whether a supported mail-queue plugin is active.
	 *
	 * The answer lives in {@see \FreeFormCertificate\Core\EmailService::mail_queue_active()},
	 * which also decides whether a message carries the queue's source headers,
	 * so the SMTP screen and the send path cannot disagree about the queue.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return \FreeFormCertificate\Core\EmailService::mail_queue_active();
	}
}
