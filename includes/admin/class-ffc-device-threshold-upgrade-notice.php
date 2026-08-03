<?php
/**
 * One-shot admin notice nudging admins of pre-6.3.2 installs to bump
 * their device fingerprint match threshold from 5 to 7 after the v6.3.2
 * upgrade added 4 new signals (plugins, permissions, mediaqueries, math).
 *
 * The bump default for fresh installs already moved from 5 to 7 in
 * RateLimiter::get_settings() defaults; existing installs keep their
 * persisted value so we don't change behaviour unilaterally. This notice
 * surfaces the suggestion once, dismissable, and only on sites that:
 *   - actively use the device limit (device.enabled === true)
 *   - still have the legacy default (match_threshold === 5)
 *
 * The shared hook/dismiss/gate plumbing lives in
 * {@see AbstractDismissibleNotice} (#849); this class supplies only the gate,
 * the message, and the one-shot dismiss flag.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.3.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders + persists a dismissable admin notice for the v6.3.2 device
 * threshold default change.
 */
class DeviceThresholdUpgradeNotice extends AbstractDismissibleNotice {

	const OPTION_DISMISSED = 'ffc_device_threshold_v632_notice_dismissed';
	const NONCE_ACTION     = 'ffc_dismiss_device_threshold_v632';
	const AJAX_ACTION      = 'ffc_dismiss_device_threshold_v632';

	/**
	 * Option key the dismissed flag is stored under.
	 */
	protected static function option_key(): string {
		return self::OPTION_DISMISSED;
	}

	/**
	 * Nonce + `wp_ajax_{action}` hook suffix.
	 */
	protected static function action(): string {
		return self::AJAX_ACTION;
	}

	/**
	 * Stable class for styling / test hooks.
	 */
	protected static function extra_class(): string {
		return 'ffc-device-threshold-notice';
	}

	/**
	 * One-shot: a plain flag, so once dismissed it stays dismissed.
	 */
	protected static function dismiss_signature(): string {
		return '1';
	}

	/**
	 * Only nudge sites actively using the device limit that still hold the
	 * legacy default threshold of 5.
	 */
	protected static function should_show(): bool {
		if ( ! class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			return false;
		}

		$device = \FreeFormCertificate\Security\RateLimiter::get_settings()['device'] ?? array();
		if ( empty( $device['enabled'] ) ) {
			return false;
		}

		// Sites that already moved to 7 (or any other value) made an active choice.
		return 5 === (int) ( $device['match_threshold'] ?? 0 );
	}

	/**
	 * The inner notice HTML (one paragraph, already escaped).
	 */
	protected static function notice_message(): string {
		$rate_limit_url = admin_url( 'admin.php?page=ffc-settings&tab=rate_limit' );

		return '<p><strong>'
			. esc_html__( 'Free Form Certificate v6.3.2', 'ffcertificate' )
			. '</strong> — '
			. wp_kses(
				sprintf(
					/* translators: %s: link to the rate-limit settings tab */
					__( 'Four new device fingerprint signals are now active (plugins, permissions, media queries, math precision). Consider raising the match threshold from 5 to 7 in %s to maintain the same false-positive ratio against the larger 13-signal palette.', 'ffcertificate' ),
					'<a href="' . esc_url( $rate_limit_url ) . '">' . esc_html__( 'Settings → Rate Limit → Device Fingerprint', 'ffcertificate' ) . '</a>'
				),
				array( 'a' => array( 'href' => array() ) )
			)
			. '</p>';
	}
}
