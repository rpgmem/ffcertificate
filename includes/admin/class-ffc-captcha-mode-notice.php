<?php
/**
 * Dismissible nudge toward the ALTCHA-only captcha mode (#1053).
 *
 * The math challenge has a 46-value answer space; a script that does
 * arithmetic passes it. Signed, expiring, single-use tokens close replay, but
 * they do not make the question harder — so on a site that can run the widget,
 * staying on the math challenge is leaving the weakest option in place.
 *
 * Three gates, and each has a reason:
 *
 *   - HTTPS only. The widget refuses to run outside a secure context, so on a
 *     plain-HTTP site this would be advice the administrator cannot take.
 *   - Not already on ALTCHA-only. Nothing to suggest.
 *   - Dismissible, one-shot. A site on the composite mode may have chosen
 *     reach deliberately; the suggestion is worth making once, not every
 *     time they open the admin.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.23.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Captcha\AltchaCaptcha;
use FreeFormCertificate\Core\Captcha\CaptchaProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggests the ALTCHA-only mode on sites that can run it.
 */
class CaptchaModeNotice extends AbstractDismissibleNotice {

	const OPTION_DISMISSED = 'ffc_captcha_mode_notice_dismissed';
	const NONCE_ACTION     = 'ffc_dismiss_captcha_mode';
	const AJAX_ACTION      = 'ffc_dismiss_captcha_mode';

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
		return 'ffc-captcha-mode-notice';
	}

	/**
	 * One-shot: a plain flag, so once dismissed it stays dismissed.
	 */
	protected static function dismiss_signature(): string {
		return '1';
	}

	/**
	 * Only where the advice can actually be taken, and is not already taken.
	 */
	protected static function should_show(): bool {
		if ( ! class_exists( '\FreeFormCertificate\Core\Captcha\CaptchaProvider' ) ) {
			return false;
		}

		// Advice an administrator cannot act on is noise: without a secure
		// context the widget throws rather than degrading.
		if ( ! is_ssl() ) {
			return false;
		}

		return AltchaCaptcha::ID !== CaptchaProvider::resolve()->id();
	}

	/**
	 * The inner notice HTML (one paragraph, already escaped).
	 */
	protected static function notice_message(): string {
		$captcha_url = admin_url( 'admin.php?page=ffc-settings&tab=captcha' );

		return '<p><strong>'
			. esc_html__( 'Free Form Certificate — captcha', 'ffcertificate' )
			. '</strong> — '
			. wp_kses(
				sprintf(
					/* translators: %s: link to the captcha settings tab */
					__( 'This site is served over HTTPS, so it can run the ALTCHA proof-of-work challenge — a stronger guard than the arithmetic question, and still served entirely from this site with nothing sent to a third party. Consider switching in %s. Keep the fallback mode if some of your visitors browse without JavaScript.', 'ffcertificate' ),
					'<a href="' . esc_url( $captcha_url ) . '">' . esc_html__( 'Settings → Captcha', 'ffcertificate' ) . '</a>'
				),
				array( 'a' => array( 'href' => array() ) )
			)
			. '</p>';
	}
}
