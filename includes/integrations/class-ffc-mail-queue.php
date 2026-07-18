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
	 * Plugin directory (folder) of the sibling queue plugin.
	 */
	private const PLUGIN_DIR = 'total-mail-queue';

	/**
	 * Whether a supported mail-queue plugin is active.
	 *
	 * Matches on the plugin *folder* (not a specific main-file name) across
	 * both per-site and network activations, so a point-release rename of the
	 * bootstrap file does not break detection. Overridable via the
	 * `ffcertificate_mail_queue_active` filter.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		$active = self::detect();

		/**
		 * Filter whether a mail-queue plugin is considered active.
		 *
		 * Lets an integration force the answer — e.g. a queue plugin that ships
		 * under a different folder can hook this to advertise itself.
		 *
		 * @since 6.14.0
		 * @param bool $active Whether total-mail-queue was detected.
		 */
		return (bool) apply_filters( 'ffcertificate_mail_queue_active', $active );
	}

	/**
	 * Scan the per-site and network-active plugin lists for the queue plugin.
	 *
	 * @return bool
	 */
	private static function detect(): bool {
		$prefix = self::PLUGIN_DIR . '/';

		$active = get_option( 'active_plugins', array() );
		if ( is_array( $active ) ) {
			foreach ( $active as $plugin ) {
				if ( is_string( $plugin ) && str_starts_with( $plugin, $prefix ) ) {
					return true;
				}
			}
		}

		$network = get_site_option( 'active_sitewide_plugins', array() );
		if ( is_array( $network ) ) {
			foreach ( array_keys( $network ) as $plugin ) {
				if ( is_string( $plugin ) && str_starts_with( $plugin, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
