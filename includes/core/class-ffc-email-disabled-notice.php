<?php
/**
 * Email-disabled admin notice.
 *
 * Renders a soft, inline warning at the plugin's email-editing surfaces
 * (form Email tab, recruitment settings, self-scheduling and audience
 * calendar editors, the SMTP tab) whenever the global "disable all emails"
 * kill-switch is ON.
 *
 * The kill-switch itself is enforced centrally in {@see EmailService::send()};
 * this class is purely the operator-facing heads-up so a disabled toggle
 * doesn't silently swallow mail while someone is editing a template (#662 P5).
 *
 * Lives in Core (not Admin) on purpose: it is called from five different
 * feature modules, all of which already depend on Core — routing it through
 * Admin would fan out a set of new `<module> → Admin` edges on the
 * module-boundary graph for no conceptual gain.
 *
 * @package FreeFormCertificate\Core
 * @since   6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Soft "emails are globally disabled" notice for admin email surfaces.
 */
final class EmailDisabledNotice {

	/**
	 * Echo the notice when the global email kill-switch is on; no-op otherwise.
	 *
	 * Callers place this at the top of any email-editing surface. The markup is
	 * a self-styled inline box (it renders correctly inside metaboxes and
	 * settings cards alike, unlike a floating `.notice`).
	 */
	public static function render(): void {
		if ( ! SettingsReader::emails_disabled() ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=ffc-settings&tab=smtp' );
		$link         = '<a href="' . esc_url( $settings_url ) . '">'
			. esc_html__( 'Settings → SMTP', 'ffcertificate' ) . '</a>';
		$message      = sprintf(
			/* translators: %s: link to the SMTP settings tab. */
			esc_html__( 'Heads up: all plugin emails are currently turned off in %s. You can still edit the templates here, but nothing will be sent until sending is re-enabled.', 'ffcertificate' ),
			$link
		);

		$html = '<div class="ffc-email-disabled-notice" style="margin:0 0 16px;padding:10px 14px;border-left:4px solid #dba617;background:#fcf9e8;border-radius:2px;">'
			. '<p style="margin:0;">' . $message . '</p>'
			. '</div>';

		// $html is assembled entirely from esc_url() / esc_html__() output plus a
		// single static <a> tag, so it is already output-safe.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
